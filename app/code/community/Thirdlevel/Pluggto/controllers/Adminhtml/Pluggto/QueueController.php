<?php

class Thirdlevel_Pluggto_Adminhtml_Pluggto_QueueController extends Mage_Adminhtml_Controller_Action
{
    public function _construct()
    {
        parent::_construct();
    }

    protected function _isAllowed(): bool
    {
        return true;
    }

    public function indexAction()
    {
        $this->loadLayout();
        $this->_setActiveMenu('pluggto/queue');
        $this->_addContent($this->getLayout()->createBlock('pluggto/queue_index'));
        $this->renderLayout();
    }

    public function saveAction()
    {

    }

    public function gridAction()
    {
        $this->loadLayout();
        $this->getResponse()->setBody(
            $this->getLayout()->createBlock('pluggto/queue_grid')->toHtml()
        );
    }

    public function processLineAction()
    {
        $line = Mage::getSingleton('pluggto/line');
        $line->playline(true);
        Mage::getSingleton('core/session')->addSuccess(Mage::helper('pluggto')->__('A fila foi executada'));
        $this->_redirect('*/pluggto_queue');
        return;
    }


    public function processAction()
    {
        $post = $this->getRequest()->getParams();
        $line = Mage::getSingleton('pluggto/line');

        try {
            $line->processNotification($post['id']);
            Mage::getSingleton('core/session')->addSuccess(Mage::helper('pluggto')->__('A notificação foi processada'));
            $this->_redirect('*/pluggto_queue');
        } catch (exception $e) {
            Mage::helper('pluggto')->WriteLogForModule('Error', 'processAction: ' . print_r($e->getMessage(), 1));
            Mage::getSingleton('core/session')->addError(Mage::helper('pluggto')->__('Ocorreu um erro, não foi possível processar a notificação<br>' . print_r($e->getMessage(), 1)));
            $this->_redirect('*/pluggto_queue');
        }
    }

    public function deleteManyAction()
    {
        $post = $this->getRequest()->getParams();

        foreach ($post['id'] as $not) {
            $queue = Mage::getSingleton('pluggto/line')->load($not);

            if (!$queue->getId()) {
                continue;
            }

            $queue->delete();
        }

        Mage::getSingleton('adminhtml/session')->addSuccess(Mage::helper('pluggto')->__('As notificações foram apagadas'));
        $this->_redirect('*/*/');
        return;
    }

    public function processManyAction()
    {
        $post = $this->getRequest()->getParams();
        $line = Mage::getSingleton('pluggto/line');

        try {
            foreach ($post['id'] as $not) {
                $line->processNotification($not);
            }

            Mage::getSingleton('core/session')->addSuccess(Mage::helper('pluggto')->__('As notificações foram processadas com sucesso.'));
            $this->_redirect('*/pluggto_queue/');
        } catch (exception $e) {
            Mage::helper('pluggto')->WriteLogForModule('Error', 'processAction: ' . print_r($e->getMessage(), 1));
            Mage::getSingleton('core/session')->addError(Mage::helper('pluggto')->__('Ocorreu um erro, não foi possível processar a notificação<br>' . print_r($e->getMessage(), 1)));
            $this->_redirect('*/pluggto_queue/');
        }
    }

    public function editAction()
    {
        $id  = $this->getRequest()->getParam('id');
        $queue = Mage::getSingleton('pluggto/line')->load($id);

        if (!$queue->getId()) {
            Mage::getSingleton('adminhtml/session')->addError(Mage::helper('pluggto')->__('This page no longer exists.'));
            $this->_redirect('*/pluggto_queue/');
            return;
        }

        Mage::register('pluggto/queue', $queue);

        $this->loadLayout();
        $this->renderLayout();
    }

    public function requeueAction()
    {
        $resource = Mage::getSingleton('core/resource');
        $writeConnection = $resource->getConnection('core_write');
        $tableName = $resource->getTableName('pluggto/line');

        $query = "UPDATE {$tableName} SET status = 0, code = '', result = '' WHERE status = 2";
        $writeConnection->query($query);

        Mage::getSingleton('core/session')->addSuccess(Mage::helper('pluggto')->__('As notificações com erro foram reenfileradas'));
        $this->_redirect('*/pluggto_queue');
        return;
    }

    public function deleteAction()
    {
        $id  = $this->getRequest()->getParam('id');
        $queue = Mage::getSingleton('pluggto/line')->load($id);

        if (!$queue->getId()) {
            Mage::getSingleton('adminhtml/session')->addError(Mage::helper('pluggto')->__('This page no longer exists.'));
            $this->_redirect('*/*/');
            return;
        }

        $queue->delete();

        Mage::getSingleton('adminhtml/session')->addSuccess(Mage::helper('pluggto')->__('Chamada apagada com sucesso'));
        $this->_redirect('*/*/');
        return;
    }

    public function resetAllLineAction()
    {
        $resource = Mage::getSingleton('core/resource');
        $writeConnection = $resource->getConnection('core_write');
        $tableName = $resource->getTableName('pluggto/line');

        $query = "DELETE from {$tableName}";
        $writeConnection->query($query);

        Mage::getSingleton('core/session')->addSuccess(Mage::helper('pluggto')->__('A fila foi excluida com sucesso'));
        $this->_redirect('*/pluggto_queue');
    }
}
