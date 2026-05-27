<?php

class Thirdlevel_Pluggto_NotificationController extends Mage_Core_Controller_Front_Action
{
    private $productData;

    public function _construct()
    {
        parent::_construct();
    }

    public function indexAction()
    {
        $request = file_get_contents('php://input');
        $content = json_decode($request);

        if (!is_object($content)) {
            return;
        }
        if ($content->type != 'orders' && $content->type != 'products') {
            return;
        }

        // [4.0.6] Anti-flood pra produtos ja marcados como 404 "not_found"
        // pela API do PluggTo. Sem essa checagem, cada webhook de produto
        // deletado/inexistente cria uma linha pendente que vai falhar do mesmo
        // jeito, gerando acumulo permanente de status=2 na fila. Janela de
        // 24h evita reprocessamento desnecessario, mas ainda permite retentar
        // diariamente caso o produto tenha voltado.
        if ($content->type == 'products') {
            $recentFail = Mage::getModel('pluggto/line')->getCollection()
                ->addFieldToFilter('pluggtoid', $content->id)
                ->addFieldToFilter('status', 2)
                ->addFieldToFilter('code', 404)
                ->addFieldToFilter('created', [
                    'gteq' => date('Y-m-d H:i:s', strtotime('-24 hours'))
                ])
                ->setPageSize(1);
            if ($recentFail->getSize() > 0) {
                return; // descartado silenciosamente — produto ainda esta 404
            }
        }

        // Reaproveita linha pendente existente se houver (mantem comportamento original)
        $alline = Mage::getModel('pluggto/line')->getCollection()
            ->addFieldToFilter('pluggtoid', $content->id)
            ->addFieldToFilter('status', 0);
        $id = $alline->getFirstItem()->getId();

        $line = Mage::getModel('pluggto/line');
        if ($id != null) {
            $line->load($id);
        }

        $line->setWhat($content->type);
        $line->setPluggtoid($content->id);
        $line->setDirection('from');
        $line->setUrl($content->type . '/' . $content->id);
        $line->setOpt('GET');
        $line->setReason($content->action);
        $line->setCreated(date('Y-m-d H:i:s'));
        $line->save();
    }

    public function playAction()
    {
        Mage::getSingleton('pluggto/bulkexport')->runBulkExport();
        Mage::getSingleton('pluggto/line')->playline();
        Mage::getSingleton('pluggto/line')->clearQueue();
    }

    public function clearLineAction()
    {
        Mage::getSingleton('pluggto/line')->clearQueue();
    }

    public function versionAction()
    {
        echo Mage::getConfig()->getNode()->modules->Thirdlevel_Pluggto->version;
    }

    public function forceSyncProductsAction()
    {
        $product_model = Mage::getModel('pluggto/product');
        $product_model->syncPriceStock();
    }

    public function updateOrdersAction()
    {
        Mage::getModel('pluggto/export')->updateOrders();
    }

    public function forceSyncOrdersAction()
    {
        Mage::getSingleton('pluggto/order')->forceSyncOrders();
    }

    public function resetAction()
    {
        if (Mage::getStoreConfig('pluggto/configs/allow_reset')) {

            $write = Mage::getSingleton('core/resource')->getConnection('core_write');
            $write->query("DELETE FROM core_resource WHERE code = 'pluggto_setup'");

            echo true;
        } else {
            echo 'Not Allowed Operation';
        }
    }
}