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
class Thirdlevel_Pluggto_Block_Dash_Index extends Mage_Adminhtml_Block_Template
{
    /**
     * Initialize cms page edit block
     *
     * @return void
     */

    public function __construct()
    {
        parent::__construct();
    }

    public function getCode()
    {
        return  Mage::registry('pluggto/access_token');
    }
}