<?php

/**
 *
 * NOTICE OF LICENSE
 *
 * Todos direitos reservados para Thirdlevel | ThirdLevel All Rights Reserved
 *
 * @company   	ThirdLevel
 * @package    	PluggTo
 * @author      André Fuhrman (andrefuhrman@gmail.com)
 * @copyright  	Copyright (c) ThirdLevel [http://www.thirdlevel.com.br]
 * 
 */
class Thirdlevel_Pluggto_Model_Source_Mode
{
	public function toOptionArray()
	{
		$opts = array();
		$opts[] = array('value' => 'prod', 'label' => Mage::helper('pluggto')->__('Ambiente de Produção'));
		$opts[] = array('value' => 'deb', 'label' => Mage::helper('pluggto')->__('Ambiente de testes'));
		
		return $opts;
	}
}