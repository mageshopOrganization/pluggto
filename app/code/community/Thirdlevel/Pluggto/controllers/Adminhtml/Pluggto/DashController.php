<?php

class Thirdlevel_Pluggto_Adminhtml_Pluggto_DashController extends Mage_Adminhtml_Controller_Action
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
        Mage::getModel('pluggto/call')->Autenticate(true);
        $api = Mage::getModel('pluggto/api')->load(1);

        Mage::register('pluggto/access_token', $api->getAccesstoken());

        $this->loadLayout();
        $this->renderLayout();
    }
}