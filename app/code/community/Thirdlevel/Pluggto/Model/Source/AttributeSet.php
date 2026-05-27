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
class Thirdlevel_Pluggto_Model_Source_AttributeSet
{
    public function toOptionArray()
    {
        $attribute_api = new Mage_Catalog_Model_Product_Attribute_Set_Api();
        $attribute_sets = $attribute_api->items();

        $opts = array();
        $opts[] = array('value' => '', 'label' => 'Selecione');

        foreach ($attribute_sets as $value) {
            $opts[] = array('value' => $value['set_id'], 'label' => $value['name']);
        }

        sort($opts);
        return $opts;
    }
}