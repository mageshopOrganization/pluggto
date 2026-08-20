<?php

class Thirdlevel_Pluggto_Model_Observer
{
    /**
     * Loja de fluxo inverso (so RECEBE produtos da Plugg.To): nao deve exportar
     * produto/estoque de volta. Gated por pluggto/mageshop/skip_product_export.
     * Nao afeta os eventos de PEDIDO (esses continuam indo para a Plugg.To).
     */
    protected function _skipProductExport()
    {
        return (bool) Mage::getStoreConfig('pluggto/mageshop/skip_product_export');
    }

    /**
     * Campos do produto que a Plugg.To realmente consome no export.
     * Se NENHUM deles mudou entre origData e data, o PUT que sera enfileirado
     * vai retornar "No changes found" da Plugg.To - puro desperdicio.
     * Estoque nao entra aqui: tem observer proprio (stockChange) com filtro proprio.
     */
    protected function _getWatchedFields()
    {
        return array('price', 'special_price', 'name', 'description', 'status', 'visibility');
    }

    /**
     * Compara valor antigo x novo respeitando o tipo do campo.
     * - price/special_price: normaliza pra float com 4 casas (evita 1.99 vs "1.9900" falso positivo)
     * - status/visibility: cast pra int
     * - resto: string
     * NULL e '' sao tratados como iguais.
     */
    protected function _valuesDiffer($field, $orig, $new)
    {
        if ($orig === null) { $orig = ''; }
        if ($new  === null) { $new  = ''; }

        if ($field === 'price' || $field === 'special_price') {
            $o = ($orig === '' || $orig === false) ? 0.0 : (float) $orig;
            $n = ($new  === '' || $new  === false) ? 0.0 : (float) $new;
            return round($o, 4) !== round($n, 4);
        }

        if ($field === 'status' || $field === 'visibility') {
            $o = ($orig === '' || $orig === false) ? 0 : (int) $orig;
            $n = ($new  === '' || $new  === false) ? 0 : (int) $new;
            return $o !== $n;
        }

        return (string) $orig !== (string) $new;
    }

    /**
     * Formata um valor de campo pra sair legivel/compacto no log de auditoria.
     * - description: sempre "len=X:md5=Y" (a descricao pode ter varios KB de HTML)
     * - name/outros textos: aspas + trunca em 60 chars com sufixo (len=N)
     * - price/special_price: numero arredondado a 4 casas (mesma escala do comparador)
     * - status/visibility: inteiro
     * - null/'' explicitos pra distinguir "nao setado" de "string vazia"
     */
    protected function _formatValueForLog($field, $value)
    {
        if ($value === null) { return 'null'; }
        if ($value === '')   { return '""'; }

        if ($field === 'description') {
            $str = (string) $value;
            return 'len=' . strlen($str) . ':md5=' . substr(md5($str), 0, 8);
        }

        if ($field === 'price' || $field === 'special_price') {
            return (string) round((float) $value, 4);
        }

        if ($field === 'status' || $field === 'visibility') {
            return (string) (int) $value;
        }

        $str = (string) $value;
        if (strlen($str) > 60) {
            return '"' . substr($str, 0, 60) . '...(len=' . strlen($str) . ')"';
        }
        return '"' . $str . '"';
    }

    /**
     * Gera relatorio de diff completo pra auditoria:
     * - 'diff'   => [campo => "orig->novo"] (so os campos que mudaram)
     * - 'values' => [campo => "valor_atual"] (TODOS os observados, snapshot pos-save)
     * Serve pros logs de ENQUEUE (diff) e SKIP (values).
     */
    protected function _getDiffReport($product)
    {
        $diff   = array();
        $values = array();

        foreach ($this->_getWatchedFields() as $f) {
            $orig = $product->getOrigData($f);
            $new  = $product->getData($f);
            $values[$f] = $this->_formatValueForLog($f, $new);
            if ($this->_valuesDiffer($f, $orig, $new)) {
                $diff[$f] = $this->_formatValueForLog($f, $orig) . '->' . $this->_formatValueForLog($f, $new);
            }
        }

        return array('diff' => $diff, 'values' => $values);
    }

    /**
     * Serializa um mapa [chave => valor_ja_formatado] pra "chave=valor, chave2=valor2".
     */
    protected function _kvString($map)
    {
        $out = array();
        foreach ($map as $k => $v) {
            $out[] = $k . '=' . $v;
        }
        return implode(', ', $out);
    }

    /**
     * Idem, mas pra diff: "campo: orig->novo; campo2: orig->novo".
     */
    protected function _diffString($map)
    {
        $out = array();
        foreach ($map as $k => $v) {
            $out[] = $k . ': ' . $v;
        }
        return implode('; ', $out);
    }

    // update item order when a order is placed (ok)
    public function placeOrder(Varien_Event_Observer $observer)
    {
        $notsave = Mage::getSingleton('core/session')->getPluggToNotSave();

        // not quee when was saved by pluggto
        if (!empty($notsave)) {
            return;
        }

        try {
            $order = $observer->getOrder();
            $items  = $order->getAllVisibleItems();

            if (is_array($items)):
                foreach ($items as $item) {
                    $product = Mage::getModel('catalog/product')->load($item->getProductId());
                    
                    if ($product->getStockItem()->getProductTypeId() == 'configurable') {
                        // preciso saber o id da variação
                        $variacao = Mage::getModel('catalog/product')->getCollection()
                            ->addAttributeToFilter('sku', $item->getSku())
                            ->addAttributeToSelect('*')
                            ->getFirstItem();

                        Mage::getModel('pluggto/export')->decreaseProductStock($product, $item->getQtyOrdered(), $variacao);
                    } else {
                        Mage::getModel('pluggto/export')->decreaseProductStock($product, $item->getQtyOrdered());
                    }
                }
            endif;

            return;
        } catch (exception $e) {
            Mage::helper('pluggto')->WriteLogForModule('Error', 'Erro sincronizar estoque: ' . print_r($e->getMessage(), 1));
        }
    }

    // Chamado quando um pedido é criado/alterado (ok)
    public function saveorder(Varien_Event_Observer $observer)
    {
        // not quee when was saved by pluggto
        if (!empty($notsave)) {
            return;
        }

        try {
            $notsave = Mage::getSingleton('core/session')->getPluggToNotSave();
            // not quee when was saved by pluggto
            if (!empty($notsave)) {
                return;
            }

            $order = $observer->getOrder();
            $orderid = $order->getId();

            if (!empty($orderid)) {
                Mage::getModel('pluggto/export')->exportOrderToQueue($orderid, $observer);
            }

            return;
        } catch (exception $e) {
            Mage::helper('pluggto')->WriteLogForModule('Error', print_r($e->getMessage(), 1));
        }
    }

    // Event Fired where (ok)
    public function cancelorder(Varien_Event_Observer $observer)
    {
        try {
            $order = $observer->getEvent()->getItem();
            // exporta pedido
            $orderModel = Mage::getModel('pluggto/export');
            $orderModel->exportOrderToQueue($order->getOrderId());

            $item = $observer->getEvent()->getItem();
            $qty = $item->getQtyOrdered() - max($item->getQtyShipped(), $item->getQtyInvoiced()) - $item->getQtyCanceled();

            if ($item->getId() && ($productId = $item->getProductId()) && $qty) {
                $Product = Mage::getModel('catalog/product')->load($item->getProductId());

                if ($Product->getTypeId() == 'simple') {
                    Mage::getModel('pluggto/export')->decreaseProductStock($Product, $item->getQtyOrdered(), null, 'increase');
                }
            }
        } catch (exception $e) {
            Mage::helper('pluggto')->WriteLogForModule('Error', 'Erro cancelar pedido: ' . print_r($e->getMessage(), 1));
        }
    }

    // Usado apenas para deletar variações do PluggTo, não produtos
    // TODO não funcionando versão 1.5, testar nas demais
    public function productDelete(Varien_Event_Observer $observer)
    {
        if ($this->_skipProductExport()) {
            return;
        }

        $product = $observer->getProduct();
        $productids = Mage::getResourceModel('catalog/product_type_configurable')->getParentIdsByChild($product->getId());
        $deletePluggto = Mage::getStoreConfig('pluggto/products/delete_pluggto');

        if (!empty($productids)) {
            try {
                $export = Mage::getModel('pluggto/export');
                $export->exportProductToQueue($product);
            } catch (exception $e) {
                Mage::helper('pluggto')->WriteLogForModule('Error', 'Erro Produto Excluido: ' . print_r($e->getMessage(), 1));
            }
        } elseif ($deletePluggto) {
            try {
                $export = Mage::getModel('pluggto/export');
                $export->exportProductToQueue($product, false, 'DEL');
            } catch (exception $e) {
                Mage::helper('pluggto')->WriteLogForModule('Error', 'Erro Produto Excluido: ' . print_r($e->getMessage(), 1));
            }
        }
    }

    // SINCRONIZAÇÃO MANUAL DE ESTOQUE (ok) NOT MORE IN USE
    public function stockChange(Varien_Event_Observer $observer)
    {
        if ($this->_skipProductExport()) {
            return;
        }

        $saveStockChange  = Mage::helper('pluggto')->config();
        if (!isset($saveStockChange['products']['use_stock_event']) || (isset($saveStockChange['products']['use_stock_event']) && $saveStockChange['products']['use_stock_event'] == false)) {
            return;
        }

        $notsave = Mage::getSingleton('core/session')->getPluggToNotSave();
        // not quee when was saved by pluggto
        if (!empty($notsave)) {
            return;
        }

        $newstock = $observer->getItem()->getData();
        $oldstock = $observer->getItem()->getOrigData();
        $Product = Mage::getModel('catalog/product')->load($newstock['product_id']);

        // nao atualiza caso produto esteja marcado para nao exportar para pluggto
        if ($Product->getExportPluggto() == 0) {
            return;
        }

        if (isset($oldstock['qty']) && isset($newstock['qty']) && !empty($newstock['qty']) && (int)$newstock['qty'] != (int)$oldstock['qty']) {
            $product = Mage::getModel('catalog/product')->load($newstock['product_id']);
            Mage::getSingleton('pluggto/export')->decreaseProductStock($product, $newstock['qty'], null, 'update');
        }
    }

    // Quando clicadl em alterar atributos de produto em massa
    public function afterSaveAttribute(Varien_Event_Observer $observer)
    {
        if ($this->_skipProductExport()) {
            return;
        }

        $notsave = Mage::getSingleton('core/session')->getPluggToNotSave();
        // not quee when was saved by pluggto
        if (!empty($notsave)) {
            return;
        }

        $productIds = $observer->getEvent()->getProductIds();

        foreach ($productIds as $id) {
            try {
                $product = Mage::getSingleton('catalog/product')->load($id);
                Mage::getSingleton('pluggto/export')->exportProductToQueue($product);
            } catch (exception $e) {
                Mage::helper('pluggto')->WriteLogForModule('Error', 'beforesaveAttribute: ' . print_r($e->getMessage(), 1));
            }
        }
    }

    public function addMassAction($observer)
    {
        $block = $observer->getEvent()->getBlock();

        if (
            get_class($block) == 'Mage_Adminhtml_Block_Widget_Grid_Massaction'
            && $block->getRequest()->getControllerName() == 'catalog_product'
        ) {
            $block->addItem('pluggto', array(
                'label' => 'Exportar para o PluggTo',
                'url' => Mage::helper("adminhtml")->getUrl("*/pluggto_sync/manual")
            ));
        }
    }

    // send to pluggto to actualizate
    public function aftersaveproduct(Varien_Event_Observer $observer)
    {
        if ($this->_skipProductExport()) {
            return;
        }

        $notsave = Mage::getSingleton('core/session')->getPluggToNotSave();
        // not quee when was saved by pluggto
        if (!empty($notsave)) {
            Mage::getSingleton('core/session')->setPluggToNotSave(0);
            return;
        }

        try {
            $product = $observer->getProduct();

            // [MageShop] Anti-desperdicio pra loja que tem ERP externo salvando
            // produtos em massa (ex.: T5 via SOAP): se o save nao mudou nenhum
            // campo que a Plugg.To consome, o PUT enfileirado vai retornar
            // "No changes found" - pura latencia. Gated por mageshop/observer_skip_unchanged.
            // Estoque nao entra: tem rota propria em stockChange.
            //
            // Auditoria (respeita mageshop/perf_log via WriteLogForModule('PERF', ...)):
            // - SKIP:    values={price=1.99, name="Foo", description=len=250:md5=abcd1234, ...}
            //            snapshot pos-save de TODOS os campos observados, pra voce comparar
            //            entre eventos e enxergar se o T5 esta bombardeando o mesmo produto.
            // - ENQUEUE: diff={price: 1.99->2.49; name: "Foo"->"Foo V2"}
            //            so os campos que mudaram, com valor antigo -> novo.
            if (Mage::getStoreConfig('pluggto/mageshop/observer_skip_unchanged')) {
                $report = $this->_getDiffReport($product);

                if (empty($report['diff'])) {
                    Mage::helper('pluggto')->WriteLogForModule(
                        'PERF',
                        'observer_skip: sku=' . $product->getSku()
                        . ' entity_id=' . $product->getId()
                        . ' values={' . $this->_kvString($report['values']) . '}'
                    );
                    return;
                }

                Mage::helper('pluggto')->WriteLogForModule(
                    'PERF',
                    'observer_enqueue: sku=' . $product->getSku()
                    . ' entity_id=' . $product->getId()
                    . ' diff={' . $this->_diffString($report['diff']) . '}'
                );
            }

            Mage::getSingleton('pluggto/export')->exportProductToQueue($product);
        } catch (exception $e) {
            Mage::helper('pluggto')->WriteLogForModule('Error', 'Aftersaveproduct: ' . print_r($e->getMessage(), 1));
        }
    }

    // salvar código de rastreioi do pedido (ok)
    public function shippingtrack(Varien_Event_Observer $observer)
    {
        try {
            $track        = $observer->getEvent()->getTrack();
            $orderModel = Mage::getModel('pluggto/export');
            $orderModel->exportOrderToQueue($track->getOrderId());
        } catch (exception $e) {
            Mage::helper('pluggto')->WriteLogForModule('Error', print_r($e->getMessage(), 1));
        }
    }

    public function saveCategorySet($observer)
    {
        try {
            Mage::getModel('pluggto/attributeSet')->saveCategorySet($observer);
        } catch (exception $e) {
            Mage::helper('pluggto')->WriteLogForModule('Error', __METHOD__, ' ' . $e->getMessage());
        }
    }

    public function pluggtoCategoryTab($observer)
    {
        $tabs = $observer->getTabs();
        $tabs->addTab('pluggto', array(
            'label'     => Mage::helper('catalog')->__('Attribute Set'),
            'content'   => $tabs->getLayout()->createBlock('pluggto/attribute_set')->toHtml(),
        ));
    }
}