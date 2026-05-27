<?php

try {
    Mage::app()->setCurrentStore(Mage::getModel('core/store')->load(Mage_Core_Model_App::ADMIN_STORE_ID));
    $installer = new Mage_Sales_Model_Mysql4_Setup('core_setup');

    $installer->startSetup();

    $installer->addAttribute('catalog_product', 'export_pluggto', array(
        'position'      => 1,
        'label'         => 'Export to Plugg.to',
        'source' =>        'eav/entity_attribute_source_boolean',
        'input' =>          'select',
        'type' =>          'int',
        'default'           => '1',
        'visible'           => 1,
        'required'          => 0,
        'user_defined'      => 1,
        'global'            => 1,
        'visible_on_front'  => 1,
        'group'         => 'PluggTo',
    ));

    $installer->endSetup();

    // update this attribute in all existing products
    $products = Mage::getModel('catalog/product')->getCollection();

    foreach ($products as $product) {
        $product->setExportPluggto(true);
        $product->getResource()->saveAttribute($product, 'export_pluggto');
    }

} catch (exception $e) {
    Mage::log(print_r($e, true));
    Mage::getSingleton('adminhtml/session')->addSuccess(Mage::helper('pluggto')->__('A atualização do Pluggto falhou, verifique o log de erro.'));
}