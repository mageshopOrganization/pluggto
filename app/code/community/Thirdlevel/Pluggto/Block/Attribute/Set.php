<?php

/**
 *
 * NOTICE OF LICENSE
 *
 * Todos direitos reservados para Thirdlevel | ThirdLevel All Rights Reserved
 *
 * @company   	ThirdLevel
 * @package    	MercadoLivre
 * @author      André Fuhrman (andrefuhrman@gmail.com)
 * @copyright  	Copyright (c) ThirdLevel [http://www.thirdlevel.com.br]
 *
 */

class  Thirdlevel_Pluggto_Block_Attribute_Set  extends Mage_Adminhtml_Block_Template
{
    public $_category;

    public function __construct()
    {
        parent::__construct();
        $this->setTemplate('pluggto/attribute/set.phtml');
        $this->setShowGlobalIcon(true);
    }

    protected function _beforeToHtml()
    {
        if (!$this->_category) {
            $this->_category = Mage::registry('category');
        }

        $AttSet = Mage::getSingleton('pluggto/attributeSet')->getAttributeSetByCategoryId($this->_category['entity_id']);

        $this->setAttSet($AttSet);
        $this->setCategory($this->_category->getData());
    }


    protected function getAttributeSets()
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
