<?php

try {
    $installer = new Mage_Sales_Model_Mysql4_Setup('core_setup');

    $installer->addAttribute('catalog_product', 'seller_id', array(
        'position'      => 1,
        'label'         => 'Seller Id',
        'source' =>        'eav/entity_attribute_source_boolean',
        'type' =>          'int',
        'visible'           => 1,
        'required'          => 0,
        'user_defined'      => 1,
        'global'            => 0,
        'visible_on_front'  => 1,
        'group'         => 'PluggTo',
    ));

    $installer->startSetup();
    $installer->run("CREATE UNIQUE INDEX plugg_id ON sales_flat_order (plugg_id)");
    $installer->endSetup();

    $installer = new Mage_Sales_Model_Mysql4_Setup('core_setup');
    $installer->startSetup();
    $installer->run("CREATE INDEX status ON thirdlevel_pluggto_line (status)");
    $installer->endSetup();

} catch (exception $e) {
    Mage::log(print_r($e, true));
    Mage::getSingleton('adminhtml/session')->addSuccess(Mage::helper('pluggto')->__('A atualização do Pluggto falhou, verifique o log de erro.'));
}