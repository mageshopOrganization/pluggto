<?php

class Thirdlevel_Pluggto_Model_Mysql4_Line_Collection extends Mage_Core_Model_Mysql4_Collection_Abstract
{
    public function _construct()
    {
        $this->_init("pluggto/line");
    }

    public function massUpdate(array $data, $ids)
    {
        $this->getConnection()->update($this->getResource()->getMainTable(), $data, $this->getResource()->getIdFieldName() . ' IN(' . implode(',', $ids) . ')');
        
        return $this;
    }
}