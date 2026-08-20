<?php

class Thirdlevel_Pluggto_Block_Queue_Grid extends Mage_Adminhtml_Block_Widget_Grid
{
    public function __construct()
    {
        parent::__construct();
        $this->setId('pluggto_queue_view_grid');
        $this->setUseAjax(false);
        $this->setDefaultSort('id');
        $this->setDefaultFilter(array('status' => '0',));
        $this->setSaveParametersInSession(true);
    }

    /**
     * Retrieve collection class
     *
     * @return string
     */
    protected function _prepareCollection()
    {
        $collection = Mage::getModel('pluggto/line')->getCollection();
        $this->setCollection($collection);

        return parent::_prepareCollection();
    }

    protected function _getStore()
    {
        $storeId = (int) $this->getRequest()->getParam('store', 0);
        return Mage::app()->getStore($storeId);
    }

    protected function _prepareColumns()
    {
        $store = $this->_getStore();

        $this->addColumn('id', array(
            'header' => Mage::helper('pluggto')->__('Id #'),
            'width' => '40px',
            'type'  => 'text',
            'index' => 'id',
        ));

        $this->addColumn('type', array(
            'header' => Mage::helper('pluggto')->__('Type #'),
            'width' => '40px',
            'type'  => 'text',
            'index' => 'what',
        ));

        $this->addColumn('status', array(
            'header' => Mage::helper('pluggto')->__('Status #'),
            'width' => '40px',
            'type'  => 'text',
            'index' => 'status',
        ));

        $this->addColumn('storeid', array(
            'header' => Mage::helper('pluggto')->__('StoreId #'),
            'width' => '40px',
            'type'  => 'text',
            'index' => 'storeid',
        ));

        $this->addColumn('pluggtoid', array(
            'header' => Mage::helper('pluggto')->__('PluggTo #'),
            'width' => '40px',
            'type'  => 'text',
            'index' => 'pluggtoid',
        ));

        $this->addColumn('opt', array(
            'header' => Mage::helper('pluggto')->__('Operation #'),
            'width' => '40px',
            'type'  => 'text',
            'index' => 'opt',
        ));

        $this->addColumn('url', array(
            'header' => Mage::helper('pluggto')->__('Resource #'),
            'width' => '100px',
            'type'  => 'text',
            'index' => 'url',
        ));

        $this->addColumn('code', array(
            'header' => Mage::helper('pluggto')->__('Status code #'),
            'width' => '10px',
            'type'  => 'text',
            'index' => 'code',
        ));

        $this->addColumn('date_created', array(
            'header' => Mage::helper('pluggto')->__('Created'),
            'index' => 'created',
            'type' => 'datetime',
            'width' => '100px',
        ));

        // [4.0.7 MageShop] Coluna de rejeicao em cache. So aparece se a loja
        // ja rodou a migration 4.0.6->4.0.7 (feature-detection multi-tenant).
        // Linhas com is_cached_reject=1 sao preservadas pelo clearQueue - elas
        // sao a memoria de rejeicoes silenciosas usada pelo syncPriceStock.
        if (Mage::getModel('pluggto/line')->hasCachedRejectColumn()) {
            $this->addColumn('is_cached_reject', array(
                'header'   => Mage::helper('pluggto')->__('Rejeitado (cache)'),
                'width'    => '80px',
                'type'     => 'options',
                'index'    => 'is_cached_reject',
                'options'  => array(
                    0 => Mage::helper('pluggto')->__('Não'),
                    1 => Mage::helper('pluggto')->__('SIM (cache)'),
                ),
                'frame_callback' => array($this, 'decorateCachedReject'),
            ));
        }

        $this->addColumn(
            'action',
            array(
                'header'    => Mage::helper('pluggto')->__('Action'),
                'width'     => '50px',
                'type'      => 'action',
                'getter'     => 'getId',
                'actions'   => array(
                    array(
                        'caption' => Mage::helper('pluggto')->__('Process'),
                        'url'     => array('base' => '*/pluggto_queue/process'),
                        'field'   => 'id'
                    ),
                    array(
                        'caption' => Mage::helper('pluggto')->__('Ver Detalhes'),
                        'url'     => array('base' => '*/pluggto_queue/edit'),
                        'field'   => 'id'
                    ),
                    array(
                        'caption' => Mage::helper('pluggto')->__('Apagar Chamada'),
                        'url'     => array('base' => '*/pluggto_queue/delete'),
                        'field'   => 'id'
                    ),

                ),
                'filter'    => false,
                'sortable'  => false,
                'index'     => 'stores',
                'is_system' => true,
            )
        );

        return parent::_prepareColumns();
    }

    protected function _prepareMassaction()
    {
        $this->setMassactionIdField('id');
        $this->getMassactionBlock()->setFormFieldName('id');
        $this->getMassactionBlock()->setUseSelectAll(true);

        $this->getMassactionBlock()->addItem('processarm', array(
            'label' => Mage::helper('pluggto')->__('Processar Selecionados'),
            'url'  => $this->getUrl('*/pluggto_queue/processMany')
        ));

        $this->getMassactionBlock()->addItem('deletarm', array(
            'label' => Mage::helper('pluggto')->__('Deletar Selecionados'),
            'url'  => $this->getUrl('*/pluggto_queue/deleteMany')
        ));

        return $this;
    }


    /**
     * [4.0.7 MageShop] Renderer visual pra coluna is_cached_reject.
     * Destaca em amarelo quando a linha esta cacheada como rejeicao.
     */
    public function decorateCachedReject($value, $row, $column, $isExport)
    {
        if ((int) $row->getIsCachedReject() === 1) {
            return '<span style="background:#fff3b0;color:#8a6d00;padding:2px 6px;border-radius:3px;font-weight:bold;">' . $value . '</span>';
        }
        return $value;
    }

    public function getRowUrl($row)
    {
        return $this->getUrl('*/pluggto_queue/process', array('id' => $row->getId()));
    }

    public function getGridUrl()
    {
        return $this->getUrl('*/*/index', array('_current' => true));
    }
}