<?php

class Thirdlevel_Pluggto_Model_AttributeSet extends Mage_Core_Model_Abstract
{
    public $rootCategory;

    public function _construct()
    {
        $this->_init("pluggto/attributeSet");
    }

    public function getAttributeSet()
    {
        return Mage::getModel('catalog/attributeSet');
    }

    public function saveCategorySet($observer)
    {
        // get request
        $request = $observer->getRequest()->getPost();
        if (!isset($request['category_id']) || !isset($request['attributeset']) || $request['category_id'] == null || $request['category_id'] == '' || $request['attributeset'] == null || $request['attributeset'] == '') {
            return;
        }

        // Mage::log('Error: '.print_r($request,TRUE), null, 'thirdlevel.log');

        // load category colletcion basead on store id
        $allcat = $this->getCollection()->addFieldToFilter('category_id', $request['category_id']);

        // get category
        $cat = $allcat->getFirstItem();

        // get category id
        if ($cat->getId() != null) {
            $this->load($cat->getId());
        }

        // save category id on mercadolivre category table
        $this->setCategoryId($request['category_id']);

        // save category id on mercadolivre category table
        $this->setAttributesetId($request['attributeset']);

        $this->save();
    }

    public function getAttributeSetByCategoryId($catId)
    {
        $allcat = $this->getCollection()->addFieldToFilter('category_id', $catId);

        // get category
        return $allcat->getFirstItem()->getAttributesetId();
    }

    /*
     * return defaure store attrribute set
     */
    public function getDefaultAttributeSet()
    {
        $configs = Mage::helper('pluggto')->config();
        $attributeSet = $configs['marketplace']['attribute_set_to_create_attribute'];

        // if default not set, get first from store
        if (empty($attributeSet)) {
            $attribute_api = new Mage_Catalog_Model_Product_Attribute_Set_Api();
            $attribute_sets = $attribute_api->items();
            return $attribute_sets[0]['set_id'];
        }

        // if set, return
        return $attributeSet;
    }

    public function getBestAttributeSetFromProductCategory($ProductCategories)
    {
        if (!empty($ProductCategories) && is_array($ProductCategories)) {
            $categoryByLevel = $this->organizeCategoriesByRelavance($ProductCategories);
            return $this->findCorrectAttributeSet($categoryByLevel);
        } else {
            /// mean that no attributeSet was found
            return $this->getDefaultAttributeSet();;
        }
    }

    /*
     *
     * Method to organize pluggtoCategoy by most especific first
     *
     */
    public function organizeCategoriesByRelavance($ProductCategories)
    {
        $categoryByLevel = array();
        foreach ($ProductCategories as $cat) {
            $parents = array();
            $explode = explode('>', $cat['name']);
            $level = 0;

            foreach ($explode as $exploded) {
                $categoryByLevel[$level][] = array('name' => $exploded, 'parents' => $parents);
                $parents[] =  $exploded;
                $level++;
            }
        }

        krsort($categoryByLevel);
        return $categoryByLevel;
    }

    /*
     *
     * Method to find attribute by a category name, return the first found
     */
    private function findCorrectAttributeSet($categoryByLevel)
    {
        foreach ($categoryByLevel as $levelcats) {
            foreach ($levelcats as $cats) {
                $category = Mage::getSingleton('pluggto/category')->getStoreCategoryByName(trim($cats['name']), $cats['parents']);
                if ($category != null) {
                    $attrSet = $this->getAttributeSetByCategoryId($category);
                    if ($attrSet != null) {
                        return $attrSet;
                    }
                }
            }
        }

        // mean that no attributeSet was found
        return $this->getDefaultAttributeSet();
    }
}