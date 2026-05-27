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
class Thirdlevel_Pluggto_Block_Queue_Edit extends Mage_Adminhtml_Block_Template
{
    /**
     * Initialize cms page edit block
     *
     * @return void
     */

    public function __construct()
    {

    }

    protected function _beforeToHtml()
    {
        $model = Mage::registry('pluggto/queue')->getData();
        $this->setData($model);
    }

    public function getBackurl()
    {
        return $this->getUrl('*/*/index');
    }
}
