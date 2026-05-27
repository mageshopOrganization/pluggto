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
class Thirdlevel_Pluggto_Model_Source_VisibilityFormat
{
    public function toOptionArray()
    {
        $opts = array();
        $opts[] = array('value' => '', 'label' => Mage::helper('pluggto')->__('Todos'));
        $opts[] = array('value' => Mage_Catalog_Model_Product_Visibility::VISIBILITY_NOT_VISIBLE, 'label' => Mage::helper('pluggto')->__('Não visível individualmente'));
        $opts[] = array('value' => Mage_Catalog_Model_Product_Visibility::VISIBILITY_IN_CATALOG, 'label' => Mage::helper('pluggto')->__('Catálogo'));
        $opts[] = array('value' => Mage_Catalog_Model_Product_Visibility::VISIBILITY_IN_SEARCH, 'label' => Mage::helper('pluggto')->__('Pesquisa'));
        $opts[] = array('value' => Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH, 'label' => Mage::helper('pluggto')->__('Catálogo, Busca'));
        
        return $opts;
    }
}