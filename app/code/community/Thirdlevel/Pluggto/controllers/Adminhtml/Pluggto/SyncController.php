<?php

class Thirdlevel_Pluggto_Adminhtml_Pluggto_SyncController extends Mage_Adminhtml_Controller_Action
{
    private $productData;

    public function _construct()
    {
        parent::_construct();
    }

    protected function _isAllowed()
    {
        return true;
    }

    public function getTableData()
    {
        if (empty($this->productData)) {
            $api = Mage::getSingleton('pluggto/api');
            $this->productData = $api->get('products/tabledata', null, null, true);
            $this->productData = $this->productData['Body'];
        }

        return $this->productData;
    }


    public function forceExportAction()
    {
        $product_model = Mage::getModel('pluggto/product');

        $product_model->forceExport();

        Mage::getSingleton('core/session')->addSuccess(Mage::helper('pluggto')->__('A exportação foi agendada.'));
        $this->_redirect('adminhtml/system_config/edit/section/pluggto');
    }

    public function stockPriceSyncAction()
    {
        $product_model = Mage::getModel('pluggto/product');
        $product_model->syncPriceStock();

        Mage::getSingleton('core/session')->addSuccess(Mage::helper('pluggto')->__('A sincronização foi agendada.'));
        $this->_redirect('adminhtml/system_config/edit/section/pluggto');
    }

    public function unlinkAllAction()
    {
        $product_model = Mage::getModel('pluggto/product');
        $product_model->unLinkAll();

        Mage::getSingleton('core/session')->addSuccess(Mage::helper('pluggto')->__('Todos produtos foram desvinculados do Plugg.To'));
        $this->_redirect('adminhtml/system_config/edit/section/pluggto');
    }

    public function  importAllAction()
    {
        $product_model = Mage::getModel('pluggto/product');
        $product_model->import();

        Mage::getSingleton('core/session')->addSuccess(Mage::helper('pluggto')->__('A importação foi agendada.'));
        $this->_redirect('adminhtml/system_config/edit/section/pluggto');
    }

    public function runLineAction()
    {
        $line = Mage::getSingleton('pluggto/line');
        $line->playline(true);
        Mage::getSingleton('core/session')->addSuccess(Mage::helper('pluggto')->__('A fila foi executada'));
        $this->_redirect('adminhtml/system_config/edit/section/pluggto');
        return;
    }

    public function importOrdersAction()
    {
        Mage::getSingleton('pluggto/order')->forceSyncOrders();
        Mage::getSingleton('core/session')->addSuccess(Mage::helper('pluggto')->__('Os pedidos foram capturados'));
        $this->_redirect('adminhtml/system_config/edit/section/pluggto');
        return;
    }


    public function exportOrdersAction()
    {
        Mage::getSingleton('pluggto/order')->forceUpdateOrders();
        Mage::getSingleton('core/session')->addSuccess(Mage::helper('pluggto')->__('Os pedidos foram agendados para exportação'));
        $this->_redirect('adminhtml/system_config/edit/section/pluggto');
        return;
    }


    public function manualAction()
    {
        $produts = $this->getRequest()->getParam('product');
        $export = Mage::getSingleton('pluggto/export');
        foreach ($produts as $prodId) {
            $product = Mage::getModel('catalog/product')->load($prodId);
            $export->exportProductToQueue($product);
        }

        Mage::getSingleton('core/session')->addSuccess(Mage::helper('pluggto')->__('Produtos agendados para exportação'));
        $this->_redirect('adminhtml/catalog_product');
    }



    public function testAction()
    {
        $call = Mage::getSingleton('pluggto/call');
        $result = $call->Autenticate(true);
        if ($result) {
            Mage::getSingleton('core/session')->addSuccess(Mage::helper('pluggto')->__('O módulo pode se autenticar no PluggTo com sucesso.'));
        } else {
            Mage::getSingleton('core/session')->addError(Mage::helper('pluggto')->__('Não foi possível a autenticação no PluggTo, por favor, verifique as credenciais cadastradas'));
        }

        $this->_redirect('adminhtml/system_config/edit/section/pluggto');
    }

    public function reinstallAction()
    {
        $write = Mage::getSingleton('core/resource')->getConnection('core_write');
        $write->query("DELETE FROM core_resource WHERE code = 'pluggto_setup'");

        Mage::getSingleton('core/session')->addSuccess(Mage::helper('pluggto')->__('O módulo reinstalado com sucesso'));
        $this->_redirect('adminhtml/system_config/edit/section/pluggto');
    }

    public function createPluggOrderUniqueAction()
    {
        $write = Mage::getSingleton('core/resource')->getConnection('core_write');

        $write->query("DELETE n1 FROM " . Mage::getSingleton('core/resource')->getTableName('sales_flat_order') . " n1, " . Mage::getSingleton('core/resource')->getTableName('sales_flat_order') . " n2 WHERE n1.entity_id > n2.entity_id AND n1.plugg_id = n2.plugg_id AND n1.plugg_id IS NOT NULL AND n2.plugg_id IS NOT NULL");

        $write = Mage::getSingleton('core/resource')->getConnection('core_write');

        $write->query("CREATE UNIQUE INDEX plugg_uk ON " . Mage::getSingleton('core/resource')->getTableName('sales_flat_order') . " (plugg_id)");

        Mage::getSingleton('core/session')->addSuccess(Mage::helper('pluggto')->__('Foi criado o index com sucesso'));
        $this->_redirect('adminhtml/system_config/edit/section/pluggto');
    }

    public function setNotSyncAction()
    {
        $products = Mage::getModel('catalog/product')->getCollection();

        foreach ($products as $product) {
            $product->setExportPluggto(0);
            $product->getResource()->saveAttribute($product, 'export_pluggto');
        }

        Mage::getSingleton('core/session')->addSuccess(Mage::helper('pluggto')->__('Produtos marcados para não exportados com sucesso.'));
        $this->_redirect('adminhtml/system_config/edit/section/pluggto');
    }
}