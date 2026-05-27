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
class Thirdlevel_Pluggto_Model_Source_Websites
{
    public function toOptionArray()
    {
        $websites = Mage::app()->getWebsites();
        $cur[] = array('value' => '', 'label' => Mage::helper('adminhtml')->__('Selecione um website'));

        foreach ($websites as $website) {
            foreach ($website->getGroups() as $group) {
                $stores = $group->getStores();

                foreach ($stores as $store) {
                    $cur[] = array('value' => $store->getWebSiteId(), 'label' => Mage::helper('adminhtml')->__($store->getName()));
                    //$store is a store object
                }
            }
        }

        return $cur;
    }
}