<?php

class Thirdlevel_Pluggto_Model_Export extends Mage_Core_Model_Abstract
{
    // COMMON
    protected function writeToQueue($what, $resource, $body, $opt, $rewrite = true, $pluggtoid = null, $storeid = null)
    {
        $newversion = Mage::getStoreConfig('pluggto/configs/magento_old_version');

        // caso possa fazer apenas uma chamada
        if ($rewrite && !$newversion):
            $alline = Mage::getModel('pluggto/line')->getCollection();
            $alline->addFieldToFilter('url', $resource)
                ->addFieldToFilter('what', $what)
                ->addFieldToFilter('status', 0);

            if (!is_null($storeid)) {
                $alline->addFieldToFilter('storeid', $storeid);
            }

            $id = $alline->getFirstItem()->getId();

        endif;

        $line = Mage::getModel('pluggto/line');

        if (isset($id) && $id != null) {
            $line->load($id);
        }

        $line->setWhat($what);
        $line->setUrl($resource);
        $line->setStoreid($storeid);
        $line->setPluggtoid($pluggtoid);
        $line->setOpt($opt);
        $line->setDirection('to');
        $line->setCode('');
        $line->setStatus(0);
        $line->setResult('');
        $line->setCreated(date("Y-m-d H:i:s"));
        if (!empty($body)) {
            $line->setBody(json_encode($body));
        }

        $line->save();
    }

    // STOCK DECREASE
    public function decreaseProductStock($product, $qtd, $variation = null, $type = 'decrease')
    {
        // check if should update quantity in pluggto
        if (Mage::getStoreConfig('pluggto/products/update_quantity') == '0') {
            return;
        }

        // se não tiver um produto retorna.
        if ($product->getEntityId() == null) {
            return;
        }

        // check website before send product
        $StoreId = Mage::getStoreConfig('pluggto/products/product_store_id');

        // if empety, should not be send
        if (!empty($StoreId)) {
            $store = Mage::getModel('Core/store')->load($StoreId);
            if (!in_array($store->getWebsiteId(), $product->getWebsiteIds())) {
                return;
            }
        }

        if ($variation != null) {
            $url = 'skus/' . rawurlencode(trim($variation->getSku())) . '/stock';
            $body = array(
                'action' => $type,
                'quantity' => $qtd
            );

            $this->writeToQueue('stock/update', $url, $body, 'PUT', false, $product->getPluggtoId(), $product->getEntityId());
        } else {
            $url = 'skus/' . rawurlencode(trim($product->getSku())) . '/stock';
            $body = array(
                'action' => $type,
                'quantity' => $qtd
            );

            $this->writeToQueue('stock/update', $url, $body, 'PUT', false, $product->getPluggtoId(), $product->getEntityId());
        }
    }

    public function exportOrderExternalId($order)
    {
        $PluggToorderId = $order->getPluggId();

        if (!empty($PluggToorderId)) {
            $body = array(
                'external' => $order->getIncrementId(),
                'update' => false,
                'ack'   => true
            );

            if ($order->getPluggId() == null || $order->getPluggId() == '') {
                return;
            }

            $url = 'orders/' . $order->getPluggId();
            $this->writeToQueue('orders', $url, $body, 'PUT', true, $PluggToorderId, $order->getEntityId());
        }
    }

    // PRODUCT
    // sync -> sincronizacao massiva de produto nao exibe mensagem
    public function exportProductToQueue($product, $forceSimple = false, $type = 'PUT', $sync = true)
    {
        if ($product->getEntityId() == null) {
            return;
        }

        $exportVisibles = Mage::getStoreConfig('pluggto/products/export_not_visible');

        if ($exportVisibles) {
            $forceSimple = true;
        }

        if ($exportVisibles && $product->getTypeId() == 'configurable') {
            if (!$sync) Mage::getSingleton('adminhtml/session')->addError(Mage::helper('pluggto')->__('O Produto configurável') . $product->getSku() . Mage::helper('pluggto')->__(' não foi enviado pois as configurações do plugin estão para enviar produtos apenas simples'));
            return;
        }

        // check website before send product
        $StoreId = Mage::getStoreConfig('pluggto/products/product_store_id');

        // if empety, should not be send
        if (!empty($StoreId)) {
            $store = Mage::getModel('Core/store')->load($StoreId);
            $webSitesIds = $product->getWebsiteIds();

            if (!in_array($store->getWebsiteId(), $product->getWebsiteIds()) &&  $type != 'DEL') {
                return;
            }
        }

        $exportToPluggTo = $product->getExportPluggto();
        if ($exportToPluggTo != null && $exportToPluggTo == false) {
            if (!$sync) Mage::getSingleton('adminhtml/session')->addError(Mage::helper('pluggto')->__('O Produto ') . $product->getSku() . Mage::helper('pluggto')->__(' está configurado para não ser exportado para o Pluggto.'));
            return;
        }

        $send_disable_product = Mage::getStoreConfig('pluggto/products/send_disable_product');
        if (!$send_disable_product) {

            if ($product->getStatus() != Mage_Catalog_Model_Product_Status::STATUS_ENABLED) {
                if (!$sync) Mage::getSingleton('adminhtml/session')->addError(Mage::helper('pluggto')->__('O Produto ') . $product->getSku() . Mage::helper('pluggto')->__(' está desabilitado e não vai ser exportado para o Pluggto.'));
                return;
            }
        }

        if ($product->getTypeId() == 'grouped') {
            $associatedProducts = $product->getTypeInstance()->getAssociatedProducts($product);

            foreach ($associatedProducts as $option) {
                $stock = $option->getStockItem();

                if (!empty($stock)) {
                    $gproduct = Mage::getModel('catalog/product')->load($stock->getProductId());
                    $this->exportProductToQueue($gproduct, true, $type);
                }
            }
        } else if ($product->getTypeId() == 'bundle') {
            $selectionCollection = $product->getTypeInstance()->getSelectionsCollection(
                $product->getTypeInstance()->getOptionsIds($product),
                $product
            );

            foreach ($selectionCollection as $option) {
                $stock = $option->getStockItem();

                if (!empty($stock)) {
                    $gproduct = Mage::getModel('catalog/product')->load($stock->getProductId());
                    $this->exportProductToQueue($gproduct, true, $type);
                }
            }

            // is simple or configurable
        } else {
            $productids = Mage::getResourceSingleton('catalog/product_type_configurable')
                ->getParentIdsByChild($product->getEntityId());

            $sendGrupedProducts = Mage::getStoreConfig('pluggto/products/send_gruped_products');
            $sendGrupedProductsVisibility = Mage::getStoreConfig('pluggto/products/gruped_products_visibility');

            // é um produto configuravel
            if (!empty($productids) && !$forceSimple) {
                $confId = null;

                foreach ($productids as $opid) {
                    $productParent = Mage::getModel('catalog/product')->load($opid);

                    if ($productParent->getEntityId() != null) {
                        // avoid to sent to pluggto a configurable product that is not really a configurable product
                        if ($productParent->getVisibility() != Mage_Catalog_Model_Product_Visibility::VISIBILITY_NOT_VISIBLE) {
                            $idfound = $opid;

                            if (!empty($sendGrupedProducts) && !empty($sendGrupedProductsVisibility)) {
                                if ($productParent->getVisibility() !=  $sendGrupedProductsVisibility) {
                                    $confId = $opid;
                                    break;
                                }
                            } else {
                                break;
                            }
                        }
                    }
                }

                if (!empty($confId)) {
                    $idfound = $confId;
                }

                if (isset($idfound)) {
                    // not export if main product is mark to not export
                    if ($productParent->getExportPluggto() == false) {
                        if (!$sync) Mage::getSingleton('adminhtml/session')->addError(Mage::helper('pluggto')->__('O Produto Configurável ') . $productParent->getSku() . Mage::helper('pluggto')->__(' está configurado para não ser exportado para o Pluggto.'));
                        return;
                    }

                    if (!$send_disable_product) {
                        if ($productParent->getStatus() != Mage_Catalog_Model_Product_Status::STATUS_ENABLED) {
                            if (!$sync) Mage::getSingleton('adminhtml/session')->addError(Mage::helper('pluggto')->__('O Produto Configurável ') . $product->getSku() . Mage::helper('pluggto')->__(' está desabilitado e não vair ser exportado para o Pluggto.'));
                            return;
                        }
                    }

                    if ($productParent->getSku() == '' || $productParent->getSku() == null) {
                        return;
                    }

                    $url = 'skus/' . rawurlencode(trim($productParent->getSku()));
                    $this->writeToQueue('products', $url, null, $type, true, $productParent->getEntityId(), $idfound);
                } else {
                    $this->exportProductToQueue($product, true, $type);
                }

                // é um produto simples
            } else {
                $bundleProductIds = Mage::getResourceSingleton('bundle/selection')
                    ->getParentIdsByChild($product->getId());

                $groupedProductIds = Mage::getResourceSingleton('catalog/product_link')
                    ->getParentIdsByChild($product->getId(), Mage_Catalog_Model_Product_Link::LINK_TYPE_GROUPED);

                // envia produtos do tipo pacote
                if (!empty($bundleProductIds)) {
                    foreach ($bundleProductIds as $opid) {
                        // avoid to one cause error in all
                        try {
                            $bundleProduct = Mage::getModel('catalog/product')->load($opid);
                            if ($bundleProduct->getEntityId() != null) {
                                // not send sku empty to pluggto
                                if ($bundleProduct->getSku() == null || $bundleProduct->getSku() == '') {
                                    continue;
                                }

                                // Alwayras try to put, if not find will be a post after
                                $url = 'skus/' . rawurlencode(trim($bundleProduct->getSku()));
                                $this->writeToQueue('products', $url, null, $type, true, null, $bundleProduct->getEntityId());
                            }
                        } catch (Exception $e) {
                            // avoid to one cause error in all
                        }
                    }
                }

                // envia produtos agrupados
                if (!empty($groupedProductIds)) {
                    foreach ($groupedProductIds as $gpid) {
                        // avoid to one cause error in all
                        try {
                            $groupedProduct = Mage::getModel('catalog/product')->load($gpid);
                            if ($groupedProduct->getEntityId() != null) {
                                // not send sku empty to pluggto
                                if ($groupedProduct->getSku() == null || $groupedProduct->getSku() == '') {
                                    continue;
                                }

                                // Alwayras try to put, if not find will be a post after
                                $url = 'skus/' . rawurlencode(trim($groupedProduct->getSku()));
                                $this->writeToQueue('products', $url, null, $type, true, null, $groupedProduct->getEntityId());
                            }
                        } catch (Exception $e) {
                            // avoid to one cause error in all
                        }
                    }
                }

                if ($product->getVisibility() == Mage_Catalog_Model_Product_Visibility::VISIBILITY_NOT_VISIBLE && !$exportVisibles) {
                    if (!$sync) Mage::getSingleton('adminhtml/session')->addError(Mage::helper('pluggto')->__('O Produto ') . $product->getSku() . Mage::helper('pluggto')->__(' não será exportado para o Pluggto pois está configurado para não ser exibido individualmente'));
                    return;
                }

                // not send sku empty to pluggto
                if ($product->getSku() == null || $product->getSku() == '') {
                    return;
                }

                // Alwayras try to put, if not find will be a post after
                $url = 'skus/' . rawurlencode(trim($product->getSku()));
                $this->writeToQueue('products', $url, null, $type, true, null, $product->getEntityId());
            }
        }
    }

    public function exportOrderToQueue($orderid, $observer = null)
    {
        $order = Mage::getModel('sales/order');
        $order->load($orderid);
        $new = false;

        // verifica se pedido existe
        if ($order->getEntityId() == null) {
            Mage::helper('pluggto')->WriteLogForModule('Error', 'Pedido não encontrado');
            return;
        }

        // verifica se pedido é novo, caso positivo, verifica se pode ser enviado
        if ($order->getExtOrderId() == null && $order->getPluggId() == null && !empty($observer) && is_object($observer)) {
            try {
                $MagentoOrder = $observer->getOrder();
                if (is_object($MagentoOrder)) {
                    $MagentoOrder->setCanalId($order->getIncrementId());
                    $MagentoOrder->setCanal('Loja');
                }
            } catch (\Exception $e) {
            }

            // save order id in pluggto field if order belongs to store
            if (!Mage::getStoreConfig('pluggto/orders/allowsend')) {
                return;
            }

            $new = true;
        }

        $body = Mage::getSingleton('pluggto/order')->update($order, $new);

        if ($order->getPluggId() != null &&  $order->getPluggId() != '') {
            if ($new) {
                $resource = 'orders';
                $opt = 'POST';
                $pluggId = null;
            } else {
                $resource = 'orders/' . $order->getPluggId();
                $opt = 'PUT';
                $pluggId = $order->getPluggId();
            }
        } else {
            if ($new) {
                $resource = 'orders';
                $opt = 'POST';
                $pluggId = null;
            } else {
                if ($order->getExtOrderId() == null && $order->getExtOrderId() == '') {
                    return;
                }

                $resource = 'orders/' . $order->getExtOrderId();
                $opt = 'PUT';
                $pluggId = $order->getExtOrderId();
            }
        }

        $this->writeToQueue('orders', $resource, $body, $opt, true, $pluggId, $orderid);
    }

    public function updateOrders()
    {
        $fromDate = date('Y-m-d H:i:s', strtotime('-24 hour'));
        $dateEnd = date('Y-m-d' . ' 23:59:59',  Mage::getModel('core/date')->timestamp(time()));

        $pending = Mage::getStoreConfig('pluggto/orderstatus/pending');
        $approved = Mage::getStoreConfig('pluggto/orderstatus/approved');
        $canceled = Mage::getStoreConfig('pluggto/orderstatus/canceled');

        $modelOrder = Mage::getModel('sales/order');
        $modelOrderCollection = $modelOrder->getCollection()->addAttributeToFilter('plugg_id', array('neq' => ''))->addAttributeToFilter('updated_at', array('from' => $fromDate, 'to' => $dateEnd, 'date' => true))->addFieldToFilter('status', array('nin' => array($pending, $approved, $canceled)))->setOrder('entity_id', 'ASC');

        foreach ($modelOrderCollection as $thisOrder) {
            $this->exportOrderToQueue($thisOrder->getEntityId());
        }

        return true;
    }
}