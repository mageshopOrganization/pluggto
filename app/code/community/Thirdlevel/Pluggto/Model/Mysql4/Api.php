<?php

class Thirdlevel_Pluggto_Model_Mysql4_Api extends Mage_Core_Model_Mysql4_Abstract
{
    protected function _construct()
    {
        $this->_init("pluggto/api", "id");
    }
}