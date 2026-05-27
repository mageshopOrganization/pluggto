<?php

class Thirdlevel_Pluggto_Model_Rewrite_Order extends Mage_Sales_Model_Order
{
    public function setStatus($value)
    {
        $status = $this->getStatus();

        if ($value != $status) {
            $orderModel = Mage::getModel('pluggto/export');
            $orderModel->exportOrderToQueue($this->getId(), $value);
        }

        return parent::setStatus($value);
    }
}