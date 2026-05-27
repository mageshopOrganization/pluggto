<?php


try {
    $installer = $this;
    $installer->startSetup();
    $installer->run("

  DROP TABLE IF EXISTS `{$installer->getTable('pluggto/attributeSet')}`;

  CREATE TABLE `{$installer->getTable('pluggto/attributeSet')}` (
  `id` int(3) NOT NULL AUTO_INCREMENT,
  `category_id` int(10) NOT NULL,
  `attributeset_id` int(10) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `category_id` (`category_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;

 ");

    Mage::getSingleton('adminhtml/session')->addSuccess(Mage::helper('pluggto')->__('O Pluggto foi atualizado com successo'));

    $installer->endSetup();
} catch (exception $e) {
    Mage::log(print_r($e, true));
    Mage::getSingleton('adminhtml/session')->addError(Mage::helper('pluggto')->__('A atualização do Pluggto falhou, verifique o log de erro.'));
}