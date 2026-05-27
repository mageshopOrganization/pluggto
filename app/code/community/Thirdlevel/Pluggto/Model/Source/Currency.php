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
class Thirdlevel_Pluggto_Model_Source_Currency
{
    public function toOptionArray()
    {
        foreach ($currencies_array as $key => $val) {
            $_storeName = Mage::app()->getStore($key)->getName();
            $_storeId = Mage::app()->getStore($key)->getId();
            $cur[] = array('value' => $_storeId, 'label' => Mage::helper('adminhtml')->__($_storeName));
        }

        echo "<pre>";
        print_r($currencies_array);
        echo "</pre>";
    }
}