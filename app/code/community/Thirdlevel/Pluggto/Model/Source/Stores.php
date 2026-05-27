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

class Thirdlevel_Pluggto_Model_Source_Stores
{
    public function toOptionArray()
    {
        $allStores = Mage::app()->getStores();
        $cur[] = array('value' => '', 'label' => Mage::helper('adminhtml')->__('Selecione uma View'));

        foreach ($allStores as $_eachStoreId => $val) {
            $_storeName = Mage::app()->getStore($_eachStoreId)->getName();
            $_storeId = Mage::app()->getStore($_eachStoreId)->getId();
            $cur[] = array('value' => $_storeId, 'label' => Mage::helper('adminhtml')->__($_storeName));
        }

        return $cur;
    }
}