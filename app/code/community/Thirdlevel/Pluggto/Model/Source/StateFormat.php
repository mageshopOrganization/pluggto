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
class Thirdlevel_Pluggto_Model_Source_StateFormat
{
	public function toOptionArray()
	{
		$opts = array();
		$opts[] = array('value' => '', 'label' => Mage::helper('pluggto')->__('Selecione'));
		$opts[] = array('value' => 'short', 'label' => Mage::helper('pluggto')->__('Salvar como Sigla'));
		$opts[] = array('value' => 'long', 'label' => Mage::helper('pluggto')->__('Salvar com nome por extenso'));
		return $opts;
	}
}
