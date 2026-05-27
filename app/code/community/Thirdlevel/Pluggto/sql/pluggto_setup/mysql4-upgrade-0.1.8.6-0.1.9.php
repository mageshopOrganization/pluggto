<?php

try {
    $installer = new Mage_Sales_Model_Mysql4_Setup('core_setup');
    $installer->startSetup();
    $installer->updateAttribute('catalog_product', 'export_pluggto', 'is_configurable', 0);
    $installer->updateAttribute('catalog_product', 'export_pluggto', 'visible_on_front', 0);
    $installer->endSetup();

    Mage::getSingleton('adminhtml/session')->addSuccess(Mage::helper('pluggto')->__('Pluggto atualizado com sucesso'));
} catch (exception $e) {
    Mage::log(print_r($e, true));
    Mage::getSingleton('adminhtml/session')->addSuccess(Mage::helper('pluggto')->__('A atualização do Pluggto falhou, verifique o log de erro.'));
}