<?php

class Thirdlevel_Pluggto_Block_Queue_View_Form extends Mage_Adminhtml_Block_Widget_Form
{
    protected function _prepareForm()
    {
        $form = new Varien_Data_Form(array('id' => 'view_form', 'action' => $this->_getSave(), 'method' => 'post'));
        $form->setUseContainer(true);
        $this->setForm($form);
        return parent::_prepareForm();
    }

    protected function _getSave()
    {
        return $this->getUrl('*/pluggto_queue/save', array(
            '_current'   => true
        ));
    }
}