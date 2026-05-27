<?php

try {
    $installer = $this;
    $installer->startSetup();
    $installer->run("

  DROP TABLE IF EXISTS `{$installer->getTable('pluggto/bulkexport')}`;
    
  CREATE TABLE `{$installer->getTable('pluggto/bulkexport')}` (
  `id` int(3) NOT NULL AUTO_INCREMENT,
  `product_id` int(20) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;
	
 ");

    Mage::getSingleton('adminhtml/session')->addSuccess(Mage::helper('pluggto')->__('O Pluggto foi instalado com successo'));

    $installer->endSetup();
} catch (exception $e) {
    Mage::log(print_r($e, true));
    Mage::getSingleton('adminhtml/session')->addSuccess(Mage::helper('pluggto')->__('A instalação do Pluggto falhou, verifique o log de erro.'));
}