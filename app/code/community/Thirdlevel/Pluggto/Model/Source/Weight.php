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
class Thirdlevel_Pluggto_Model_Source_Weight
{
	public function toOptionArray()
	{
		$opts = array();
		$opts[] = array('value' => 'kilo', 'label' => Mage::helper('pluggto')->__('Kilo'));
		$opts[] = array('value' => 'grama', 'label' => Mage::helper('pluggto')->__('Grama'));

		return $opts;
	}
}