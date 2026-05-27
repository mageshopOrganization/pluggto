<?php

class Thirdlevel_Pluggto_ShippingController extends Mage_Core_Controller_Front_Action
{
    private $productData;

    public function _construct()
    {
        parent::_construct();
    }

    public function calculateAction()
    {
        $request = file_get_contents('php://input');
        $content = json_decode($request);

        if (is_object($content)) {
            $weight = 0;
            $quantity = 0;

            if (isset($content->products) && is_array($content->products)) {
                foreach ($content->products as $product) {
                    if (!empty($product->weight)) {
                        $weight += $product->weight;
                    }

                    if (!empty($product->quantity)) {
                        $quantity += $product->quantity;
                    }
                }
            }

            if (empty($quantity)) {
                $quantity = 1;
            }

            $address = new Varien_Object();

            if (isset($content->destination_country) && !empty($content->destination_country) && isset($content->destination_state) && !empty($content->destination_state)) {
                if ($content->destination_country == 'BRA') {
                    $content->destination_country = 'BR';
                }

                $regionModel = Mage::getModel('directory/region')->loadByCode($content->destination_state, $content->destination_country);

                $regionId = $regionModel->getId();

                if (!empty($regionId)) {
                    $address->$regionId($regionId);
                }
            }

            if (isset($content->destination_country) && !empty($content->destination_country)) {
            } else {
                $address->setCountryId('BR');
            }

            if (isset($weight) && !empty($weight)) {
                $address->setWeight($weight);
            }

            $address->setPackageQty($quantity);
            $address->setItemQty($quantity);

            if (isset($content->destination_postalcode) && !empty($content->destination_postalcode) && isset($content->destination_postalcode) && !empty($content->destination_postalcode)) {
                $address->setPostcode($content->destination_postalcode);
            }

            if (isset($content->destination_state) && !empty($content->destination_state) && isset($content->destination_state) && !empty($content->destination_state)) {
                $address->setRegionId($content->destination_state);
            }

            $shipping = Mage::getSingleton('shipping/shipping');

            $rates = $shipping->collectRatesByAddress($address)->getResult();
            $allRates = $rates->getAllRates();

            foreach ($allRates as $rate) {
                $tojoson = array();

                if (isset($rate['error_message'])) {
                    $tojoson['mensage'] = $rate['error_message'];
                } else {
                    $tojoson['company'] = $rate['carrier_title'];
                    $tojoson['method']  = $rate['method_title'];
                    $tojoson['estimate'] = "";
                    $tojoson['price'] = $rate['price'];
                }

                $toreturn[] = $tojoson;
            }

            echo json_encode($toreturn);
        }
    }
}