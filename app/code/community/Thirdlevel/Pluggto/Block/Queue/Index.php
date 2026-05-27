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
class Thirdlevel_Pluggto_Block_Queue_Index extends Mage_Adminhtml_Block_Widget_Grid_Container
{
    /**
     * Initialize cms page edit block
     *
     * @return void
     */

    public function __construct()
    {
        $this->_objectId   = 'service-id';
        $this->_controller = 'queue';
        $this->_blockGroup  = 'pluggto';
        $this->_mode = 'view';

        if (is_null($this->_addButtonLabel)) {
            $this->_addButtonLabel = $this->__('Add New');
        }
        if (is_null($this->_backButtonLabel)) {
            $this->_backButtonLabel = $this->__('Back');
        }

        parent::__construct();
        $this->_removeButton('add');
        $this->setTemplate('widget/grid/container.phtml');

        $this->_addButton('process', array(
            'label'     => Mage::helper('adminhtml')->__('Process Queue'),
            'onclick'   => 'setLocation(\'' . $this->getProcessLine() . '\')',
            'class'     => 'save',
        ), -100);

        $this->_addButton('resetAll', array(
            'label'     => Mage::helper('adminhtml')->__('Delete Queue'),
            'onclick'   => 'setLocation(\'' . $this->getResetAllline() . '\')',
            'class'     => 'delete',
        ), -100);

        $this->_addButton('requeue', array(
            'label'     => Mage::helper('adminhtml')->__('ReQueue failed'),
            'onclick'   => 'setLocation(\'' . $this->getRequeue() . '\')',
            'class'     => 'save',
        ), -100);
    }

    public function getRequeue()
    {
        return $this->getUrl('*/*/requeue');
    }

    public function getProcessLine()
    {
        return $this->getUrl('*/*/processLine');
    }

    public function getResetAllline()
    {
        return $this->getUrl('*/*/resetAllLine');
    }

    public function getResetProcessed()
    {
        return $this->getUrl('*/*/resetProcessedLine');
    }

    /**
     * Retrieve text for header element depending on loaded page
     *
     * @return string
     */
    public function getHeaderText()
    {
        return Mage::helper('pluggto')->__("Queue");
    }
}