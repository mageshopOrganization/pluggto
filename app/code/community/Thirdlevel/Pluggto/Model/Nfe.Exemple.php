<?php

class Thirdlevel_Pluggto_Model_Nfe extends Mage_Core_Model_Abstract
{
    protected function _construct()
    {
        $this->_init("pluggto/nfe");
    }

    public function getNfe($orderObj, $shippingObj)
    {
        $nfe = array();

        //  $nfe['nfe_key'] =
        //  $nfe['nfe_number'] =
        //  $nfe['nfe_serie'] =
        //  $nfe['nfe_date'] =
        //  $nfe['nfe_link'] =

        return $nfe;
    }
}