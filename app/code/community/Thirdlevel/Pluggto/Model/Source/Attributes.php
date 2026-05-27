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
class Thirdlevel_Pluggto_Model_Source_Attributes
{
    public function toOptionArray()
    {
        $attributes = Mage::getResourceModel('catalog/product_attribute_collection')
            ->getItems();
        $opts = array();
        $opts[] = array('value' => '', 'label' => 'Selecione');

        foreach ($attributes as $attribute) {
            $front = $attribute->getFrontendLabel();

            if (!empty($front)) {
                $opts[] = array('value' => $attribute->getAttributecode(), 'label' => $attribute->getAttributecode());
            } else {
                $opts[] = array('value' => $attribute->getAttributecode(), 'label' => $attribute->getAttributecode());
            }
        }

        sort($opts);
        return $opts;
    }
}