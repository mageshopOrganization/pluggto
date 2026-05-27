<?php

try {
    $installer = $this;
    $installer->startSetup();
    $installer->run("

  DROP TABLE IF EXISTS `{$installer->getTable('pluggto/api')}`; 
    
  CREATE TABLE `{$installer->getTable('pluggto/api')}` (
  `id` int(3) NOT NULL AUTO_INCREMENT,
  `client_id` varchar(245) NOT NULL,
  `expire` int(3) DEFAULT NULL ,
  `accesstoken` varchar(245) DEFAULT NULL,
  `refreshtoken` varchar(245) DEFAULT NULL,
  `line` int(20) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;
	
   DROP TABLE IF EXISTS `{$installer->getTable('pluggto/line')}`; 
  
  CREATE TABLE `{$installer->getTable('pluggto/line')}` (
  `id` int(3) NOT NULL AUTO_INCREMENT,
  `what` varchar(12) NOT NULL,
  `storeid` varchar(245) DEFAULT NULL ,
  `status` int(1) DEFAULT '0' ,
  `pluggtoid` varchar(245) DEFAULT NULL ,
  `opt` varchar(4) DEFAULT NULL,
  `direction` varchar(10) DEFAULT NULL,
  `created` DATETIME DEFAULT NULL,
  `reason` varchar(12) NOT NULL,
  `attemps` int(2) DEFAULT NULL,
  `body` LONGTEXT DEFAULT NULL,
  `code` int(3) DEFAULT NULL,
  `result` LONGTEXT DEFAULT NULL,
  `url` varchar(100) DEFAULT NULL,
   PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;
		
 ");

    Mage::getSingleton('adminhtml/session')->addSuccess(Mage::helper('pluggto')->__('O Pluggto foi atualizado com successo'));

    $installer->endSetup();
} catch (exception $e) {
    Mage::log(print_r($e, true));
    Mage::getSingleton('adminhtml/session')->addSuccess(Mage::helper('pluggto')->__('A atualização do Pluggto falhou, verifique o log de erro.'));
}
