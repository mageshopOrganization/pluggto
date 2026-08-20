<?php

class Thirdlevel_Pluggto_Model_Mysql4_Pluggto_Collection extends Mage_Core_Model_Resource_Db_Collection_Abstract
{
    public function _construct()
    {
        $this->_init("pluggto/pluggto");
    }
}