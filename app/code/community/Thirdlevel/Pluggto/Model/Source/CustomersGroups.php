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

class Thirdlevel_Pluggto_Model_Source_CustomersGroups
{
    public function toOptionArray()
    {
        $group = Mage::getModel('customer/group')->getCollection();
        $cur = array();
        $cur[] = array('value' => '', 'label' => Mage::helper('adminhtml')->__('Selecione uma Groupo de Cliente'));

        foreach ($group as $eachGroup) {
            $cur[]  = array(
                'value' => $eachGroup->getCustomerGroupId(),
                'label' => $eachGroup->getCustomerGroupCode(),
            );
        }

        return $cur;
    }
}