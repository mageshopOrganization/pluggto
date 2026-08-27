<?php

class Thirdlevel_Pluggto_Model_Bulkexport extends Mage_Core_Model_Abstract
{
    protected function _construct()
    {
        $this->_init("pluggto/bulkexport");
    }

    public function write($id)
    {
        $this->setProductId($id);
        $this->save();
    }

    public function runBulkExport()
    {
        // [4.0.6] Bulk processing — antes carregava 1 produto por vez com
        // Mage::getModel('catalog/product')->load() e fazia 1 DELETE por item.
        // Em batches grandes (centenas de produtos), isso dominava o tempo
        // de execucao do cron. Agora:
        //   - Carrega TODOS os produtos do batch em 1 query (collection).
        //   - Faz 1 DELETE em batch ao inves de N.
        $bulkstocks = $this->getCollection()->setPageSize(50);
        if (!$bulkstocks->getSize()) {
            return;
        }

        $bulksByProductId = [];
        foreach ($bulkstocks as $bulk) {
            $bulksByProductId[$bulk->getProductId()] = $bulk;
        }

        $products = Mage::getResourceModel('catalog/product_collection')
            ->addAttributeToSelect('*')
            ->addFieldToFilter('entity_id', ['in' => array_keys($bulksByProductId)]);

        $exporter = Mage::getSingleton('pluggto/export');
        $bulkIdsToDelete = [];

        foreach ($products as $product) {
            try {
                $exporter->exportProductToQueue($product);
                $bulk = $bulksByProductId[$product->getId()];
                $bulkIdsToDelete[] = $bulk->getId();
            } catch (Exception $e) {
                Mage::helper('pluggto')->WriteLogForModule(
                    'Error',
                    'Bulkexport: ' . $e->getMessage()
                );
            }
        }

        if (!empty($bulkIdsToDelete)) {
            $resource = Mage::getSingleton('core/resource');
            $writeConnection = $resource->getConnection('core_write');
            $tableName = $resource->getTableName('pluggto/bulkexport');
            // A PK dessa tabela e `id` (declarada em Mysql4/Bulkexport::_init).
            // Antes usava `entity_id`, o que travava o cron com
            // SQLSTATE[42S22] em lojas onde a coluna nao existe.
            $writeConnection->delete(
                $tableName,
                ['id IN (?)' => $bulkIdsToDelete]
            );
        }
    }
}