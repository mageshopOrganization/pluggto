<?php

class Thirdlevel_Pluggto_Block_Order_Grid extends Mage_Adminhtml_Block_Sales_Order_Grid
{
    protected function _prepareCollection()
    {
        if (Mage::getStoreConfig('pluggto/configs/disable_order_grid')) {
            return parent::_prepareCollection();
        }

        $collection = Mage::getResourceModel($this->_getCollectionClass());
        $collection->join('order', 'order.entity_id = main_table.entity_id', array('canal' => 'canal', 'canal_id' => 'canal_id'), null, 'left');
        $this->setCollection($collection);

        if ($this->getCollection()) {
            $this->_preparePage();

            $columnId = $this->getParam($this->getVarNameSort(), $this->_defaultSort);
            $dir      = $this->getParam($this->getVarNameDir(), $this->_defaultDir);
            $filter   = $this->getParam($this->getVarNameFilter(), null);

            if (is_null($filter)) {
                $filter = $this->_defaultFilter;
            }

            if (is_string($filter)) {
                $data = $this->helper('adminhtml')->prepareFilterString($filter);
                $this->_setFilterValues($data);
            } else if ($filter && is_array($filter)) {
                $this->_setFilterValues($filter);
            } else if (0 !== sizeof($this->_defaultFilter)) {
                $this->_setFilterValues($this->_defaultFilter);
            }

            if (isset($this->_columns[$columnId]) && $this->_columns[$columnId]->getIndex()) {
                $dir = (strtolower($dir) == 'desc') ? 'desc' : 'asc';
                $this->_columns[$columnId]->setDir($dir);
                $this->_setCollectionOrder($this->_columns[$columnId]);
            }

            if (!$this->_isExport) {
                $this->getCollection()->load();
                $this->_afterLoadCollection();
            }
        }

        // Mage::dispatchEvent('adminhtml_sales_order_grid_setcollection', array('collection' => $collection));

        return $this;
        //   return
    }

    protected function _prepareColumns()
    {
        if (Mage::getStoreConfig('pluggto/configs/disable_order_grid')) {
            return parent::_prepareColumns();
        }

        $this->addColumnAfter('canal', array(
            'header' => Mage::helper('sales')->__('Canal'),
            'width' => '80px',
            'type'  => 'text',
            'filter_index' => 'order.canal',
            'index' => 'canal',
        ), 'status');

        $this->addColumnAfter('canal_id', array(
            'header' => Mage::helper('sales')->__('Id do Canal'),
            'width' => '80px',
            'type'  => 'text',
            'index' => 'canal_id',
            'filter_index' => 'order.canal_id',
        ), 'canal');

        parent::_prepareColumns();

        $this->addColumn('real_order_id', array(
            'header' => Mage::helper('sales')->__('Order #'),
            'width' => '80px',
            'type'  => 'text',
            'filter_index' => 'main_table.increment_id',
            'index' => 'increment_id',
        ));

        if (!Mage::app()->isSingleStoreMode()) {
            $this->addColumn('store_id', array(
                'header'    => Mage::helper('sales')->__('Purchased From (Store)'),
                'index'     => 'store_id',
                'filter_index' => 'main_table.store_id',
                'type'      => 'store',
                'store_view' => true,
                'display_deleted' => true,
            ));
        }

        $this->addColumn('created_at', array(
            'header' => Mage::helper('sales')->__('Purchased On'),
            'index' => 'created_at',
            'filter_index' => 'main_table.created_at',
            'type' => 'datetime',
            'width' => '100px',
        ));

        $this->addColumn('billing_name', array(
            'header' => Mage::helper('sales')->__('Bill to Name'),
            'index' => 'billing_name',
            'filter_index' => 'main_table.billing_name'
        ));

        $this->addColumn('shipping_name', array(
            'header' => Mage::helper('sales')->__('Ship to Name'),
            'index' => 'shipping_name',
            'filter_index' => 'main_table.shipping_name'
        ));

        $this->addColumn('base_grand_total', array(
            'header' => Mage::helper('sales')->__('G.T. (Base)'),
            'index' => 'base_grand_total',
            'type'  => 'currency',
            'currency' => 'base_currency_code',
            'filter_index' => 'main_table.base_grand_total'
        ));

        $this->addColumn('grand_total', array(
            'header' => Mage::helper('sales')->__('G.T. (Purchased)'),
            'index' => 'grand_total',
            'type'  => 'currency',
            'currency' => 'order_currency_code',
            'filter_index' => 'main_table.grand_total'
        ));

        $this->addColumn('status', array(
            'header' => Mage::helper('sales')->__('Status'),
            'index' => 'status',
            'type'  => 'options',
            'width' => '70px',
            'filter_index' => 'main_table.status',
            'options' => Mage::getSingleton('sales/order_config')->getStatuses(),
        ));

        Mage::dispatchEvent('adminhtml_sales_order_grid_preparecolumns', array('grid' => $this));
        return $this;
    }

    public function setCollection($collection)
    {
        if (Mage::getStoreConfig('pluggto/configs/disable_order_grid')) {
            return parent::setCollection($collection);
        }

        parent::setCollection($collection);
        Mage::dispatchEvent('adminhtml_sales_order_grid_setcollection', array('collection' => $collection));
    }

    protected function _prepareMassaction()
    {
        parent::_prepareMassaction();

        if (Mage::getStoreConfig('pluggto/configs/disable_order_grid')) {
            return parent::_prepareMassaction();
        }

        Mage::dispatchEvent('adminhtml_sales_order_grid_preparemassaction', array('block' => $this, 'mass_action_block' => $this->getMassactionBlock()));
        return $this;
    }
}