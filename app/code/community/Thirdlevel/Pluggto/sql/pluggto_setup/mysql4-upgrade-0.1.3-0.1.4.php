<?php

try {
    Mage::app()->setCurrentStore(Mage::getModel('core/store')->load(Mage_Core_Model_App::ADMIN_STORE_ID));
    $installer = new Mage_Sales_Model_Mysql4_Setup('sales_setup');

    $installer->startSetup();

    $installer->addAttribute("order", "canal", array("type" => "varchar", 'is_user_defined' => false, 'required' => false, 'searchable' => true, 'label' => 'Canal de venda', 'visible' => true));

    $installer->addAttribute("order", "canal_id", array("type" => "varchar", 'is_user_defined' => false, 'required' => false, 'searchable' => true, 'label' => 'Id no canal de venda', 'visible' => true));

    $installer->addAttribute("order", "plugg_id", array("type" => "varchar", 'is_user_defined' => false, 'required' => false, 'searchable' => true, 'label' => 'Id no Plugg.To ', 'visible' => true, 'unique' => true));

    $installer->addAttribute('order', 'shipment_id', array(
        'position'      => 1,
        'input'         => 'text',
        'type'          => 'varchar',
        'label'         => 'PluggTo Shipment Id',
        'visible'       => 0,
        'required'      => 0,
        'user_defined' => 1,
        'global'        => 1,
        'visible_on_front'  => 0,
    ));

    $installer->endSetup();

    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $write->query("CREATE UNIQUE INDEX plugg_uk ON sales_flat_order (plugg_id)");
} catch (exception $e) {
    Mage::log(print_r($e, true));
    Mage::getSingleton('adminhtml/session')->addSuccess(Mage::helper('pluggto')->__('A instalação do Pluggto falhou, verifique o log de erro.'));
}