<?php

class Thirdlevel_Pluggto_Model_Mysql4_AttributeSet extends Mage_Core_Model_Resource_Db_Abstract
{
    protected function _construct()
    {
        $this->_init("pluggto/attributeSet", "id");
    }
}