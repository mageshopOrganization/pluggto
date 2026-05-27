<?php

class Thirdlevel_Pluggto_Model_Attribute extends Mage_Core_Model_Abstract
{
    protected function _construct()
    {
        $this->_init("pluggto/attribute");
    }

    public function listAtributs()
    {
        $attributes = Mage::getSingleton('eav/config')
            ->getEntityType(Mage_Catalog_Model_Product::ENTITY)->getAttributeCollection();
        $codes = array();

        // Loop over all attributes
        foreach ($attributes as $attr) {
            if ($attr->getFrontendInput() != 'select') {
                $codes[] = $attr->getAttributeCode();
            }
        }

        return $codes;
    }

    public function listSelectAttributs()
    {
        $attributes = Mage::getSingleton('eav/config')
            ->getEntityType(Mage_Catalog_Model_Product::ENTITY)->getAttributeCollection();
        $codes = array();

        foreach ($attributes as $attr) {
            if ($attr->getFrontendInput() == 'select') {
                $codes[] = $attr->getAttributeCode();
            }
        }

        return $codes;
    }

    public function getProductAttributes($product)
    {
        $atr = $this->listAtributs();
        $return = array();

        foreach ($atr as $atribute) {
            $return[$atribute]['label'] = $product[$atribute];
            $return[$atribute]['type'] = 'simple';
            $return[$atribute]['value'] = $product[$atribute];
        }

        $selectattributes = $this->listSelectAttributs();
        foreach ($selectattributes as $selattribute) {
            if ($product[$selattribute] != null) {
                $return[$selattribute]['label'] = $this->getAttributeLabel($product[$selattribute]);
                $return[$selattribute]['typle'] = 'select';
                $return[$selattribute]['value'] = $product[$selattribute];
            }
        }

        return $return;
    }

    public function getAttributeLabel($id)
    {
        $resource = Mage::getModel('core/resource');
        $read = $resource->getConnection('core_read');
        $query = "Select * from " . $resource->getTableName('eav/attribute_option_value') . " WHERE option_id = '" . $id . "' LIMIT 1";
        $result = $read->fetchAll($query);
        if (count($result) > 0) {
            return $result[0]['value'];
        } else {
            return null;
        }
    }
}