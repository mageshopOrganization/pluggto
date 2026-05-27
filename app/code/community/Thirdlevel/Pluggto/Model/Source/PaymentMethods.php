<?php

class Thirdlevel_Pluggto_Model_Source_PaymentMethods
{
    public function toOptionArray()
    {
        try {
            $payments = Mage::getSingleton('payment/config')->getAllMethods();
            $methods  = array(array('value' => '', 'label' => Mage::helper('adminhtml')->__('--Selecione--')));
            //$method = array();

            foreach ($payments as $paymentCode => $paymentModel) {
                $paymentTitle          = Mage::getStoreConfig('payment/' . $paymentCode . '/title');
                $methods[$paymentCode] = array('label' => $paymentTitle, 'value' => $paymentCode,);
            }

            $cur = array();

            foreach ($methods as $v) {
                $cur[] = array('value' => $v['value'], 'label' => Mage::helper('adminhtml')->__($v['label']));
            }

            return $cur;
        } catch (exception $e) {
            $cur[] = array(
                'value' => 0,
                'label' => Mage::helper('adminhtml')->__('Impossible to retrive store payment methods')
            );

            return $cur;
        }
    }
}