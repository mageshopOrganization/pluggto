<?php

class Thirdlevel_Pluggto_Model_Order extends Mage_Core_Model_Abstract
{
    public $weight;
    public $totalqtd;
    public $configs;

    protected function _construct()
    {
        $this->_init("pluggto/order");
    }

    public function getConfig()
    {
        if (empty($this->configs)) {
            $this->configs = Mage::helper('pluggto')->config();
        }

        return $this->configs;
    }

    public function convertToStateLogName($shortName)
    {
        if (strlen($shortName) > 2) {
            return $shortName;
        }

        $shortName = strtoupper($shortName);

        $estadosBrasileiros = array(
            'AC' => 'Acre',
            'AL' => 'Alagoas',
            'AP' => 'Amapá',
            'AM' => 'Amazonas',
            'BA' => 'Bahia',
            'CE' => 'Ceará',
            'DF' => 'Distrito Federal',
            'ES' => 'Espírito Santo',
            'GO' => 'Goiás',
            'MA' => 'Maranhão',
            'MT' => 'Mato Grosso',
            'MS' => 'Mato Grosso do Sul',
            'MG' => 'Minas Gerais',
            'PA' => 'Pará',
            'PB' => 'Paraíba',
            'PR' => 'Paraná',
            'PE' => 'Pernambuco',
            'PI' => 'Piauí',
            'RJ' => 'Rio de Janeiro',
            'RN' => 'Rio Grande do Norte',
            'RS' => 'Rio Grande do Sul',
            'RO' => 'Rondônia',
            'RR' => 'Roraima',
            'SC' => 'Santa Catarina',
            'SP' => 'São Paulo',
            'SE' => 'Sergipe',
            'TO' => 'Tocantins'
        );

        if (isset($estadosBrasileiros[$shortName])) {
            return $estadosBrasileiros[$shortName];
        } else {
            return $shortName;
        }
    }

    public function convertToStateShortName($name)
    {
        if (strlen($name) == 2) {
            return $name;
        }

        $estadosBrasileiros = array(
            'AC' => 'acre',
            'AL' => 'alagoas',
            'AP' => 'amapa',
            'AM' => 'amazonas',
            'BA' => 'bahia',
            'CE' => 'ceara',
            'DF' => 'distrito federal',
            'ES' => 'espírito santo',
            'GO' => 'goias',
            'MA' => 'maranhao',
            'MT' => 'mato grosso',
            'MS' => 'mato grosso do sul',
            'MG' => 'minas gerais',
            'PA' => 'para',
            'PB' => 'paraiba',
            'PR' => 'parana',
            'PE' => 'pernambuco',
            'PI' => 'piaui',
            'RJ' => 'rio de janeiro',
            'RN' => 'rio grande do norte',
            'RS' => 'rio grande do sul',
            'RO' => 'rondonia',
            'RR' => 'roraima',
            'SC' => 'santa catarina',
            'SP' => 'sao paulo',
            'SE' => 'sergipe',
            'TO' => 'tocantins'
        );
        // retira acentos para fazer a busca
        $newname = preg_replace(array("/(á|à|ã|â|ä)/", "/(Á|À|Ã|Â|Ä)/", "/(é|è|ê|ë)/", "/(É|È|Ê|Ë)/", "/(í|ì|î|ï)/", "/(Í|Ì|Î|Ï)/", "/(ó|ò|õ|ô|ö)/", "/(Ó|Ò|Õ|Ô|Ö)/", "/(ú|ù|û|ü)/", "/(Ú|Ù|Û|Ü)/", "/(ñ)/", "/(Ñ)/"), explode(" ", "a A e E i I o O u U n N"), $name);
        // deixa tudo em minuscula

        $newname = strtolower($newname);

        // faz busca

        if (array_search($newname, $estadosBrasileiros)) {
            return array_search($newname, $estadosBrasileiros);
        } else {
            return $name;
        }
    }

    // create item
    private function importItem($unitem, $product = null)
    {
        $items = new Mage_Sales_Model_Order_Item();

        if (isset($unitem['variation']['sku'])) {
            $sku = $unitem['variation']['sku'];
        } elseif (isset($unitem['sku'])) {
            $sku = $unitem['sku'];
        }

        if (isset($sku) && empty($product)) {
            $product = Mage::getModel('pluggto/product')->findProduct($sku);
        }

        if (!$product && isset($unitem['sku'])) {
            $product = Mage::getModel('catalog/product')->loadByAttribute('sku', $unitem['sku']);
        }

        if (isset($product) && is_object($product) && $product->getEntityId() != null) {
            $items->setProductId($product->getEntityId());
            $items->setProductType($product->getTypeId());
            $items->setProductWeight($product->getWeight());
            $this->weight += $product->getWeight();
        }

        $items->setBaseWeeeTaxAppliedAmount(0);
        $items->setBaseWeeeTaxAppliedRowAmnt(0);
        $items->setWeeeTaxAppliedAmount(0);
        $items->setWeeeTaxAppliedRowAmount(0);
        $items->setWeeeTaxApplied(serialize(array()));
        $items->setWeeeTaxDisposition(0);
        $items->setWeeeTaxRowDisposition(0);
        $items->setBaseWeeeTaxDisposition(0);
        $items->setBaseWeeeTaxRowDisposition(0);

        if (!empty($product)) {
            $name = $product->getName();

            if (!empty($name)) {
                if (isset($unitem['name'])) $items->setName($name);
            } else {
                if (isset($unitem['name'])) $items->setName($unitem['name']);
            }
        } else {
            if (isset($unitem['name'])) $items->setName($unitem['name']);
        }

        if (isset($unitem['price'])) $items->setBasePrice($unitem['price']);
        if (isset($unitem['price']) && isset($unitem['quantity'])) $items->setRowTotal($unitem['price'] * $unitem['quantity']);
        if (isset($unitem['price'])) $items->setOriginalPrice($unitem['price']);
        if (isset($unitem['price'])) $items->setPrice($unitem['price']);
        if (isset($unitem['quantity'])) $items->setQtyOrdered($unitem['quantity']);
        if (isset($unitem['quantity'])) $this->totalqtd += $unitem['quantity'];
        if (isset($unitem['total'])) $items->setRowTotal($unitem['total']);
        if (isset($unitem['total'])) $items->setBaseRowTotal($unitem['total']);
        if (isset($unitem['total'])) $items->setSubtotal($unitem['total']);
        if (isset($unitem['discount'])) $items->setBaseDiscountAmount($unitem['discount']);
        if (isset($unitem['discount'])) $items->setDiscountAmount($unitem['discount']);

        if (isset($unitem['variation']['sku'])) {
            $subproduct = Mage::getModel('catalog/product')->loadByAttribute('sku', $unitem['variation']['sku']);

            if ($subproduct) {
                $items->setProductId($subproduct->getEntityId());
                $items->setProductType($subproduct->getTypeId());
                $items->setProductWeight($subproduct->getWeight());
            }

            $items->setSku($unitem['variation']['sku']);
        } elseif (isset($unitem['sku'])) {
            $items->setSku($unitem['sku']);
        }

        $attributes = array();

        if (isset($unitem['variation']['attributes']) && is_array($unitem['variation']['attributes'])) {
            foreach ($unitem['variation']['attributes'] as $att) {
                if (isset($att['label']) && isset($att['value']['label'])) $attributes[] = $att['label'] . ':' . $att['value']['label'] . ' ';
            }

            $items->setAdditionalData(implode(',', $attributes));
        }

        return $items;
    }

    private function updateStoreAtStore($order, $data)
    {
        $invoice = false;

        switch ($data['status']) {
            case 'approved':
            case 'paid':
                $status = Mage::getStoreConfig('pluggto/orderstatus/approved');
                $state = Mage_Sales_Model_Order::STATE_PROCESSING;

                $invoice = true;
                if (Mage::getStoreConfig('pluggto/orders/invoice') == 1) {
                    $notifyCustomerOrderUpdate = false;
                    Mage::getSingleton('core/session')->setPluggToNotSave(1);
                    // Cria invoice (fatura) para o pedido se já não houver alguma criada.

                    try {
                        if (!$order->hasInvoices()) {
                            foreach ($order->getAllItems() as $item) {
                                $Allitems[$item->getId()] = $item->getQtyOrdered();
                            }

                            $invoice = $order->prepareInvoice();
                            $invoice->register()->pay();

                            Mage::getModel('core/resource_transaction')->addObject($invoice)->addObject($invoice->getOrder())->save();
                        }
                    } catch (exception $e) {
                    }
                    Mage::getSingleton('core/session')->setPluggToNotSave();
                }

                break;
            case 'partial_payment':
                $state = Mage_Sales_Model_Order::STATE_PROCESSING;
                $status = Mage::getStoreConfig('pluggto/orderstatus/partial_payment');
                break;
            case 'refunded':
                $state = Mage_Sales_Model_Order::STATE_CANCELED;
                $status = Mage::getStoreConfig('pluggto/orderstatus/canceled');
                break;
            case 'picking':
                $state = Mage_Sales_Model_Order::STATE_PROCESSING;
                $status = Mage::getStoreConfig('pluggto/orderstatus/picking');
                break;
            case 'pending':
                $status = Mage::getStoreConfig('pluggto/orderstatus/pending');
                $state = Mage_Sales_Model_Order::STATE_PENDING_PAYMENT;
                break;
            case 'invoiced':
                $status = Mage::getStoreConfig('pluggto/orderstatus/invoiced');
                $state = Mage_Sales_Model_Order::STATE_PROCESSING;
                $invoice = true;
                break;
            case 'invoice_error':
                $status = Mage::getStoreConfig('pluggto/orderstatus/invoice_error');
                $state = Mage_Sales_Model_Order::STATE_PROCESSING;
                $invoice = true;
                break;
            case 'under_review':
                $status = Mage::getStoreConfig('pluggto/orderstatus/under_review');
                $state = Mage_Sales_Model_Order::STATE_HOLDED;
                break;
            case 'canceled':
                $status = Mage::getStoreConfig('pluggto/orderstatus/canceled');
                $state = Mage_Sales_Model_Order::STATE_CANCELED;
                break;
            case 'delivered':
                $status = Mage::getStoreConfig('pluggto/orderstatus/delivered');
                $state = Mage_Sales_Model_Order::STATE_COMPLETE;
                $invoice = true;
                break;
            case 'shipped':
            case 'shipping_informed':
            case 'shipping_error':
                $status = Mage::getStoreConfig('pluggto/orderstatus/shipped');
                $invoice = true;
                break;
            case 'partial_shipped':
                $status = Mage::getStoreConfig('pluggto/orderstatus/partial_shipped');
                $invoice = true;
                break;
            case 'partial_delivered':
                $status = Mage::getStoreConfig('pluggto/orderstatus/partial_delivered');
                $invoice = true;
                break;
            case 'partial_invoiced':
                $status = Mage::getStoreConfig('pluggto/orderstatus/partial_invoiced');
                $invoice = true;
                break;
            default:
                $status = '';
                $state = Mage_Sales_Model_Order::STATE_PENDING_PAYMENT;
                break;
        }

        if ($invoice) {
            if (Mage::getStoreConfig('pluggto/orders/invoice') == 1) {
                $notifyCustomerOrderUpdate = false;
                Mage::getSingleton('core/session')->setPluggToNotSave(1);
                // Cria invoice (fatura) para o pedido se já não houver alguma criada.

                try {
                    if (!$order->hasInvoices()) {
                        foreach ($order->getAllItems() as $item) {
                            $Allitems[$item->getId()] = $item->getQtyOrdered();
                        }

                        $invoice = $order->prepareInvoice();
                        $invoice->register()->pay();

                        Mage::getModel('core/resource_transaction')->addObject($invoice)->addObject($invoice->getOrder())->save();
                    }
                } catch (exception $e) {
                }
                Mage::getSingleton('core/session')->setPluggToNotSave();
            }
        }

        $orderHistory = Mage::getModel('sales/order_status_history')->getCollection()
            ->addFieldToFilter('parent_id', $order->getId());

        $orderHistory = $orderHistory->getData();

        $statusHistory = array();
        $statusComment = array();

        if (is_array($orderHistory)) {
            foreach ($orderHistory as $history) {
                $statusHistory[] = $history['status'];
                $statusComment[] = $history['comment'];
            }
        }

        if (in_array('Order has grouped products', $statusComment) || in_array('Order has bundle products', $statusComment)) {
            $grouped = true;
        } else {
            $grouped = false;
        }

        try {
            if (!in_array($status, $statusHistory)) {
                if (isset($state) && $state != 'complete') {
                    $order->setState($state);
                }

                if ($order->getStatus() != $status) {
                    // repõe stock de produtos agrupados ou configuraveis no caso de cancelamento de pedidos
                    if (
                        $status == Mage::getStoreConfig('pluggto/orderstatus/canceled') && $grouped
                    ) {
                        $items = $order->getAllVisibleItems();

                        foreach ($items as $item) {
                            $product = Mage::getModel('catalog/product')->load($item->getProductId());
                            $oldq = Mage::getModel('pluggto/product')->getProducQtd($product);
                            $stock = $oldq['qty'];

                            $qtd = (int) $item->getQtyOrdered();

                            $finalQuantity = $stock + $qtd;
                            $array_product = array('quantity' => $finalQuantity);

                            Mage::getModel('pluggto/product')->setProductStock($product, $array_product);
                        }
                    }

                    $order->addStatusToHistory($status, 'PluggTo has updated this status', false);
                }
            }
        } catch (exception $e) {
        }

        Mage::getSingleton('core/session')->setPluggToNotSave(1);
        $order->save();
        Mage::getSingleton('core/session')->setPluggToNotSave();

        try {
            if ($order->canShip() && $data['status'] != 'pending' && $data['status'] != 'approved' && $data['status'] != 'canceled' && $data['status'] != 'picking') {
                if (isset($data['shipments'][0]['shipping_company']) && !empty($data['shipments'][0]['shipping_company'])) {
                    $shippingCompany = $data['shipments'][0]['shipping_company'];
                } else {
                    $shippingCompany = '';
                }

                if (isset($data['shipments'][0]['shipping_method']) && !empty($data['shipments'][0]['shipping_method'])) {
                    $shippingMethod = $data['shipments'][0]['shipping_method'];
                } else {
                    $shippingMethod = '';
                }

                if (isset($data['shipments'][0]['track_code']) && !empty($data['shipments'][0]['track_code'])) {
                    $trackNumber = $data['shipments'][0]['track_code'];
                } else {
                    $trackNumber = '';
                }

                if (!empty($trackNumber)) {
                    $itemQty =  $order->getItemsCollection()->count();

                    $shipment = Mage::getModel('sales/service_order', $order)->prepareShipment($itemQty);
                    Mage::getSingleton('core/session')->setPluggToNotSave(1);
                    $shipment = new Mage_Sales_Model_Order_Shipment_Api();
                    $shipmentId = $shipment->create($order->getIncrementId());

                    $shipment->addTrack($shipmentId, 'custom', $shippingCompany . '-' .  $shippingMethod, $trackNumber);
                    Mage::getSingleton('core/session')->setPluggToNotSave();
                }
            }
        } catch (Exception $e) {
        }
    }

    // create at store
    public function create($data)
    {
        if (!Mage::getStoreConfig('pluggto/orders/allowcreate')) {
            return;
        }

        $order = new Mage_Sales_Model_Order();
        $col = $order->getCollection();

        $order = $col->addFieldToFilter('plugg_id', $data['id'])->getFirstItem();

        $new = false;

        // nao salva dados no pedido se criado pela loja
        if ($data['created_by'] == Mage::getStoreConfig('pluggto/configuration/client_id') && $order->getEntityId() != null) {
            return $this->updateStoreAtStore($order, $data);
        }

        if ($order->getEntityId() == null) {
            $new = true;
            $order = new Mage_Sales_Model_Order();
        }

        $groupId = Mage::getStoreConfig('pluggto/configs/customer_group');

        if ($groupId != 0) {
            try {
                $customer = Mage::getModel('pluggto/customer')->getCustomer($data);
            } catch (exception $e) {
                Mage::helper('pluggto')->WriteLogForModule('Error', print_r($e->getTraceAsString(), 1));
                $customer = false;
            }
        }

        if ($order->getCanalId() == null) {
            if (isset($data['external'][$data['created_by']])) {
                $order->setCanalId($data['external'][$data['created_by']]);
                $order->setExtOrderId($data['external'][$data['created_by']]);
            }
        }

        if ($order->getCanal() == null) {
            if (Mage::getStoreConfig('pluggto/orders/substore') && isset($data['channel_account']) && !empty($data['channel_account'])) {
                $order->setCanal($data['channel'] . '-' . $data['channel_account']);
                $order->setExtOrderId($data['channel'] . '-' . $data['channel_account'] . ' ' . $data['original_id']);
            } else {
                $order->setCanal($data['channel']);
                $order->setExtOrderId($data['channel'] . ' ' . $data['original_id']);
            }
        }

        if (!empty($data['payer_email'])) {
            $order->setCustomerEmail($data['payer_email']);
        } elseif (!empty($data['receiver_email'])) {
            $order->setCustomerEmail($data['receiver_email']);
        } else {
            $order->setCustomerEmail('customer@email.com');
        }

        $order->setCustomerFirstname($data['payer_name']);
        $order->setCustomerLastname($data['payer_lastname']);
        $order->setPluggId($data['id']);

        if (isset($customer) && is_array($customer) && isset($customer['id'])) {
            $order->setCustomerId($customer['id']);

            if (!empty($groupId)) {
                $order->setCustomerGroupId($groupId);
            }
        } else {
            $order->setCustomerIsGuest(1);
        }

        if (isset($customer['id'])) {
            $customer = Mage::getModel('customer/customer')->load($customer['id']);
            $order->setCustomer($customer);
        }

        $order->setBaseToGlobalRate(1);

        if (isset($data['payer_cpf']) && !empty($data['payer_cpf'])) {
            $order->setCustomerTaxvat($data['payer_cpf']);
        }

        if (isset($data['payer_cnpj']) && !empty($data['payer_cnpj'])) {
            $order->setCustomerTaxvat($data['payer_cnpj']);
        }

        if (isset($data['payer_tax_id']) && !empty($data['payer_tax_id'])) {
            // $order->setCustomerTaxvat($data['payer_tax_id']);
        }

        if (isset($data['payer_tax_id']) && !empty($data['payer_tax_id'])) $document = $data['payer_tax_id'];
        if (isset($data['payer_cpf']) && !empty($data['payer_cpf'])) $document = $data['payer_cpf'];
        if (isset($data['payer_cnpj']) && !empty($data['payer_cnpj'])) $document = $data['payer_cnpj'];

        $customFieldToStoreCFPorCNPJ = Mage::getStoreConfig('pluggto/configs/custom_document_field');

        if (isset($document) && $customFieldToStoreCFPorCNPJ != '' && $customFieldToStoreCFPorCNPJ != null) {
            $order->addData(array(trim($customFieldToStoreCFPorCNPJ) => $document));
        }

        if (isset($data['items'][0]['price_code']) && !empty($data['items'][0]['price_code'])) {
            $store = $this->getStoreByCode($data['items'][0]['price_code']);

            if (empty($store)) {
                $store = Mage::getStoreConfig('pluggto/configs/default_store');
            }
        } else {
            $store = Mage::getStoreConfig('pluggto/configs/default_store');
        }

        if (!empty($store)) {
            $currencies_array = Mage::app()->getStore($store)->getDefaultCurrency();
        } else {
            $store = Mage::app()->getStore();
            $currencies_array = Mage::app()->getStore()->getDefaultCurrency();
        }

        $currencycode = $currencies_array->getCurrencyCode();

        if (empty($data['subtotal']) || $data['subtotal'] == 0) {
            $data['subtotal'] = $data['total'] - $data['shipping'];
        }

        if (isset($data['discount']) && !empty($data['discount'])) {
            $order->setBaseDiscountAmount($data['discount']);
            $order->setDiscountAmount($data['discount']);
        }

        if (!isset($data['total_paid']) || empty($data['total_paid'])) {
            $data['total_paid'] = $data['total'];
        }

        // total amount informatiom
        $order->setTotalDue($data['total_paid']);
        $order->setSubtotal($data['subtotal']);
        $order->setGrandTotal($data['total_paid']);
        $order->setTotalDue($data['total']);
        $order->setBaseTaxAmount(0.00);
        $order->setBaseGrandTotal($data['total_paid']);
        $order->setStoreCurrencyCode($currencycode);
        $order->setShippingAmount($data['shipping']);
        $order->setBaseShippingAmount($data['shipping']);

        $order->setBaseSubtotalInclTax($data['subtotal']);
        $order->setSubtotalInclTax($data['subtotal']);
        $order->setShippingDiscount(0);
        $order->setStoreId($store);
        $order->setCurrenyCode($currencycode);
        $order->setOrderCurrencyCode($currencycode);
        $order->setGlobalCurrencyCode($currencycode);
        $order->setBaseCurrencyCode($currencycode);
        $order->setBaseSubtotal($data['subtotal']);
        $order->setBaseToOrderRate(1);
        $order->setBaseToGlobalRated(1);
        $order->setBaseTaxAmount(0);

        // billing information

        if ($order->getIncrementId()) {
            $billing = Mage::getModel('sales/order_address')->load($order->getBillingAddress()->getId());
        } else {
            $billing = new Mage_Sales_Model_Order_Address;
        }

        $billing->setFirstname($data['payer_name']);
        $billing->setLastname($data['payer_lastname']);

        $PayerAddressLine = array();

        // receiver address line
        if (!empty($data['payer_address'])) {
            $PayerAddressLine[] = $data['payer_address'];
        } else {
            $PayerAddressLine[] = '';
        }

        if (!empty($data['payer_address_number'])) {
            $PayerAddressLine[] = $data['payer_address_number'];
        } else {
            $PayerAddressLine[] = '';
        }

        if (!empty($data['payer_address_complement'])) {
            if (!empty($data['payer_additional_info']) && $data['payer_additional_info'] != $data['payer_address_complement']) {
                $PayerAddressLine[] = $data['payer_address_complement'] . '-' . $data['payer_additional_info'];
            } else {
                $PayerAddressLine[] = $data['payer_address_complement'];
            }
        } else {
            if (!empty($data['payer_additional_info'])) {
                $PayerAddressLine[] = $data['payer_additional_info'];
            } else {
                $PayerAddressLine[] = '';
            }
        }

        if (!empty($data['payer_neighborhood'])) {
            $PayerAddressLine[] = $data['payer_neighborhood'];
        } else {
            $PayerAddressLine[] = '';
        }

        if (!empty($PayerAddressLine)) {
            $billing->setStreet($PayerAddressLine);
        }

        if (isset($data['payer_zipcode'])) {
            $billing->setPostcode($data['payer_zipcode']);
        }
        if (isset($data['payer_city'])) {
            $billing->setCity($data['payer_city']);
        }

        $stateFormat = Mage::getStoreConfig('pluggto/configs/state_format');

        if (isset($data['payer_state'])) {
            if (!empty($stateFormat)) {
                if ($stateFormat == 'short') {
                    $BillingState = $this->convertToStateShortName($data['payer_state']);
                } else if ($stateFormat == 'long') {
                    $BillingState = $this->convertToStateLogName($data['payer_state']);
                } else {
                    $BillingState = $data['payer_state'];
                }
            } else {
                $BillingState = $data['payer_state'];
            }
        }

        if (isset($BillingState)) {
            $billing->setRegion($BillingState);
        }

        if (isset($data['payer_country'])) {
            $billing->setCountry($data['payer_country']);
        }

        if (isset($data['payer_phone']) && isset($data['payer_phone_area'])) {
            $billing->setTelephone($data['payer_phone_area'] . $data['payer_phone']);
        }
        if (isset($data['payer_email'])) {
            $billing->setEmail($data['payer_email']);
        }
        if (isset($data['payer_cpf'])) {
            $billing->setVatId($data['payer_cpf']);
        }

        if (isset($data['payer_cnpj'])) {
            $billing->setVatId($data['payer_cnpj']);
        }

        $billing->setCountryId($data['payer_country']);

        $regionModel = Mage::getModel('directory/region')->loadByCode($data['payer_state'], $data['payer_country']);
        $regionId = $regionModel->getId();

        $billing->setRegionId($regionId);

        if (!$order->getIncrementId()) {
            $order->setBillingAddress($billing);
        }

        // shipping information
        if ($order->getIncrementId() && $order->getShippingAddress()) {
            $shipping = Mage::getModel('sales/order_address')->load($order->getShippingAddress()->getId());
        } else {
            $shipping = new Mage_Sales_Model_Order_Address;
        }

        $shipping->setFirstname($data['receiver_name']);
        $shipping->setLastname($data['receiver_lastname']);

        $ReceiverAddressLine = array();
        // receiver address line

        if (!empty($data['receiver_address'])) {
            $ReceiverAddressLine[] = $data['receiver_address'];
        } else {
            $ReceiverAddressLine[] = '';
        }

        if ($data['receiver_address_number'] != null && $data['receiver_address_number'] != '') {
            $ReceiverAddressLine[] = $data['receiver_address_number'];
        } else {
            $ReceiverAddressLine[] = '';
        }

        if (!empty($data['receiver_address_complement'])) {
            if (!empty($data['receiver_additional_info'])) {
                if (!empty($data['receiver_address_reference'])) {
                    $ReceiverAddressLine[] = $data['receiver_address_complement'] . '-' . $data['receiver_additional_info'] . '-' . $data['receiver_address_reference'];
                } else {
                    $ReceiverAddressLine[] = $data['receiver_address_complement'] . '-' . $data['receiver_additional_info'];
                }
            } else {
                if (!empty($data['receiver_address_reference'])) {
                    $ReceiverAddressLine[] = $data['receiver_address_complement'] . '-' . $data['receiver_address_reference'];
                } else {
                    $ReceiverAddressLine[] = $data['receiver_address_complement'];
                }
            }
        } else {
            if (!empty($data['receiver_additional_info'])) {
                if (!empty($data['receiver_address_reference'])) {
                    $ReceiverAddressLine[] = $data['receiver_additional_info'] . '-' . $data['receiver_address_reference'];
                } else {
                    $ReceiverAddressLine[] = $data['receiver_additional_info'];
                }
            } else {
                if (!empty($data['receiver_address_reference'])) {
                    $ReceiverAddressLine[] = $data['receiver_address_reference'];
                } else {
                    $ReceiverAddressLine[] = '';
                }
            }
        }

        if (!empty($data['receiver_neighborhood'])) {
            $ReceiverAddressLine[] = $data['receiver_neighborhood'];
        } else {
            $ReceiverAddressLine[] = '';
        }

        if (!empty($ReceiverAddressLine)) {
            $shipping->setStreet($ReceiverAddressLine);
        }

        if (isset($data['receiver_zipcode'])) {
            $shipping->setPostcode($data['receiver_zipcode']);
        }

        if (isset($data['receiver_city'])) {
            $shipping->setCity($data['receiver_city']);
        }

        if (isset($data['receiver_state'])) {
            if (!empty($stateFormat)) {
                if ($stateFormat == 'short') {
                    $ReceiverState = $this->convertToStateShortName($data['receiver_state']);
                } else if ($stateFormat == 'long') {
                    $ReceiverState = $this->convertToStateLogName($data['receiver_state']);
                } else {
                    $ReceiverState = $data['receiver_state'];
                }
            } else {
                $ReceiverState = $data['receiver_state'];
            }
        }

        if (isset($ReceiverState)) {
            $shipping->setRegion($ReceiverState);
        }

        if (isset($data['receiver_phone']) && isset($data['receiver_phone_area'])) {
            $shipping->setTelephone($data['receiver_phone_area'] . $data['receiver_phone']);
        }

        if (isset($data['receiver_email'])) {
            $shipping->setEmail($data['receiver_email']);
        }

        if (is_null($data['receiver_country'])) {
            $data['receiver_country'] = 'BR';
        }

        $shipping->setCountryId($data['receiver_country']);

        $regionModel = Mage::getModel('directory/region')->loadByCode($data['receiver_state'], $data['receiver_country']);
        $regionId = $regionModel->getId();

        $shipping->setRegionId($regionId);

        if (!$order->getIncrementId()) {
            $order->setShippingAddress($shipping);
        }

        $shippingData = '';

        if (isset($data['shipments'][0]['shipping_company']) && !empty($data['shipments'][0]['shipping_company'])) {
            $shippingData .= 'Shipping Company: ' . $data['shipments'][0]['shipping_company'] . '<br>';
        }

        if (isset($data['shipments'][0]['shipping_method']) && !empty($data['shipments'][0]['shipping_method'])) {
            $shippingData .= 'Shipping Method: ' . $data['shipments'][0]['shipping_method'] . '<br>';
        }

        if (isset($data['expected_send_date']) && !empty($data['expected_send_date'])) {
            $shippingData .= 'Expected send date: ' . $data['expected_send_date'] . '<br>';
        }

        if (isset($data['expected_delivery_date']) && !empty($data['expected_delivery_date'])) {
            $shippingData .= 'Expected delivery date: ' . $data['expected_delivery_date'] . '<br>';
        }

        if (isset($data['expected_delivery_date']) && !empty($data['expected_delivery_date'])) {
            $shippingData .= 'Expected delivery date: ' . $data['expected_delivery_date'] . '<br>';
        }

        if (isset($data['sale_intermediary']) && !empty($data['sale_intermediary'])) {
            $shippingData .= 'Sale Intermediary: ' . $data['sale_intermediary'] . '<br>';
        }

        if (isset($data['payment_intermediary']) && !empty($data['payment_intermediary'])) {
            $shippingData .= 'Payment Intermediary: ' . $data['payment_intermediary'] . '<br>';
        }

        if (isset($data['intermediary_seller_id']) && !empty($data['intermediary_seller_id'])) {
            $shippingData .= 'Intermediary Seller id: ' . $data['intermediary_seller_id'] . '<br>';
        }

        if (isset($data['shipments'][0]['track_code']) && !empty($data['shipments'][0]['track_code'])) {
            $shippingData .= 'Código de rastreio: ' . $data['shipments'][0]['track_code'] . '<br>';
        }

        if (isset($data['shipments'][0]['nfe_key']) && !empty($data['shipments'][0]['nfe_key'])) {
            $shippingData .= 'Chave de Acesso:' . $data['shipments'][0]['nfe_key'] . '<br>';
        }

        if (isset($data['shipments'][0]['nfe_link']) && !empty($data['shipments'][0]['nfe_link'])) {
            $shippingData .= 'Nota Fiscal Xml:' . $data['shipments'][0]['nfe_link'] . '<br>';
        }

        if (isset($data['shipments'][0]['nfe_number']) && !empty($data['shipments'][0]['nfe_number'])) {
            $shippingData .= 'Nota fiscal:' . $data['shipments'][0]['nfe_number'] . '<br>';
        }

        if (isset($data['shipments'][0]['nfe_serie']) && !empty($data['shipments'][0]['nfe_serie'])) {
            $shippingData .= 'Serie:' . $data['shipments'][0]['nfe_serie'] . '<br>';
        }

        if (isset($data['shipments'][0]['nfe_date']) && !empty($data['shipments'][0]['nfe_date'])) {
            $shippingData .= 'Data da emissao:' . $data['shipments'][0]['nfe_date'] . '<br>';
        }

        if (isset($data['payments'][0]['payment_method'])) {
            $shippingData .= 'Metodo de Pagamento:' . $data['payments'][0]['payment_method'] . '<br>';
        }

        if (isset($data['payments'][0]['payment_type'])) {
            $shippingData .= 'Tipo de pagamento:' . $data['payments'][0]['payment_type'] . '<br>';
        }

        if (isset($data['payments'][0]['payment_additional_info'])) {
            $shippingData .= 'Informações Adicionais de pagamento:' . $data['payments'][0]['payment_additional_info'] . '<br>';
        }

        if (isset($data['payments'][0]['payment_installments'])) {
            $shippingData .= 'Parcelas:' . $data['payments'][0]['payment_installments'] . '<br>';
        }

        if (isset($data['payments'][0]['payment_total'])) {
            $shippingData .= 'Pagamento Recebido:' . $data['payments'][0]['payment_total'] . '<br>';
        }

        if (isset($data['payments'][0]['payment_quota'])) {
            $shippingData .= 'Valor da parcela:' . $data['payments'][0]['payment_quota'] . '<br>';
        }

        if (isset($data['payments'][0]['payment_interest'])) {
            $shippingData .= 'Juros do pagamento:' . $data['payments'][0]['payment_interest'] . '<br>';
        }

        $items = $order->getAllVisibleItems();

        if (!$order->getIncrementId() || count($items) == 0) {
            if (count($data['items']) == 0) {
                $items = new Mage_Sales_Model_Order_Item();
                $items->setBasePrice($data['subtotal']);
                $items->setProductId(0);
                $items->setRowTotal($data['subtotal']);
                $items->setOriginalPrice($data['subtotal']);
                $items->setPrice($data['subtotal']);
                $items->setQtyOrdered();
                $items->setProductWeight(0.1);
                $items->setSku('pluggto');
                $items->setName(Mage::helper('pluggto')->__('Produto do PluggTo'));
                $order->addItem($items);
            } else {
                foreach ($data['items'] as $unitem) {
                    if (isset($unitem['sku'])) {
                        $product = Mage::getModel('pluggto/product')->findProduct($unitem['sku']);

                        if (!$product && isset($unitem['variation']['sku']) && !empty($unitem['variation']['sku'])) {
                            $product = Mage::getModel('pluggto/product')->findProduct($unitem['variation']['sku']);
                        }

                        if ($product) {
                            // need to upadte stock from here
                            if ($product->getTypeId() == 'grouped') {
                                $associatedProducts = $product->getTypeInstance(true)->getAssociatedProducts($product);

                                foreach ($associatedProducts as $option) {
                                    if ($option->getQty() > 0) {
                                        $quantityPerItem = $option->getQty();
                                    } else {
                                        $quantityPerItem = 1;
                                    }

                                    $stock = $option->getStockItem();

                                    $atualQuantidadeEmEstoque = $stock->getQty();

                                    $totaldeQuantidadeComprada = $quantityPerItem * $unitem['quantity'];

                                    $finalQuantidade = $atualQuantidadeEmEstoque - $totaldeQuantidadeComprada;

                                    $stock->setQty($finalQuantidade);
                                    $stock->save();

                                    $gproduct = Mage::getModel('catalog/product')->load($stock->getProductId());

                                    Mage::dispatchEvent('catalog_product_save_after', array('product' => $gproduct));

                                    $bunitem = $unitem;
                                    $bunitem['sku'] = $gproduct->getSku();
                                    $bunitem['name'] = $product->getName() . '-' . $gproduct->getName();
                                    $bunitem['price'] = $gproduct->getPrice();
                                    $bunitem['total'] = $gproduct->getPrice() * $totaldeQuantidadeComprada;
                                    $bunitem['quantity'] = $totaldeQuantidadeComprada;

                                    $items = $this->importItem($bunitem, $gproduct);
                                    $order->addItem($items, $product);
                                }

                                //     $order->addStatusHistoryComment('Order has grouped products');

                                // need to upadte stock from here
                            } else if ($product->getTypeId() == 'bundle') {
                                $bunitem = $unitem;
                                $bunitem['sku'] = $product->getSku();
                                $bunitem['name'] = $product->getName();
                                $bunitem['quantity'] = $unitem['quantity'];
                                $bunitem['product_type'] = $product->getTypeId();
                                $bunitem['product_options'] = serialize($product->getProductOptions());

                                $parerntItem = $this->importItem($bunitem, $product);
                                $order->addItem($parerntItem, $product);
                                $parerntItem->setProductOptions(array(new Varien_Object(array('qty' => $unitem['quantity']))));

                                $selectionCollection = $product->getTypeInstance(true)->getSelectionsCollection(
                                    $product->getTypeInstance(true)->getOptionsIds($product),
                                    $product
                                );

                                foreach ($selectionCollection as $option) {
                                    if ($option->getSelectionQty() > 0) {
                                        $quantidadePorPacote = $option->getSelectionQty();
                                    } else {
                                        $quantidadePorPacote = 1;
                                    }

                                    $stock = $option->getStockItem();
                                    $atualQuantidadeEmEstoque = $stock->getQty();

                                    $totaldeQuantidadeComprada = $quantidadePorPacote * $unitem['quantity'];

                                    $finalQuantidade = $atualQuantidadeEmEstoque - $totaldeQuantidadeComprada;

                                    $stock->setQty($finalQuantidade);
                                    $stock->save();

                                    $gproduct = Mage::getModel('catalog/product')->load($stock->getProductId());

                                    Mage::dispatchEvent('catalog_product_save_after', array('product' => $gproduct));

                                    $bunitem = $unitem;
                                    $bunitem['sku'] = $gproduct->getSku();
                                    $bunitem['name'] = $product->getName() . '-' . $gproduct->getName();
                                    $bunitem['quantity'] = $totaldeQuantidadeComprada;
                                    $bunitem['price'] = 0.00;
                                    $bunitem['total'] = 0.00;

                                    $items = $this->importItem($bunitem, $gproduct);
                                    $order->addItem($items, $product);
                                    $items->setParentItem($parerntItem);
                                }

                                if (empty($status)) {
                                    //          $status = Mage::getStoreConfig('pluggto/orderstatus/pending');
                                }

                                //    $order->addStatusHistoryComment('Order has bundle products');

                            } else {
                                $items = $this->importItem($unitem, $product);

                                $order->addItem($items, $product);
                            }
                        } else {
                            $items = new Mage_Sales_Model_Order_Item();

                            if (isset($unitem['name']))
                                if (isset($unitem['price'])) $items->setBasePrice($unitem['price']);
                            if (isset($unitem['price'])) $items->setOriginalPrice($unitem['price']);
                            if (isset($unitem['price'])) $items->setPrice($unitem['price']);
                            if (isset($unitem['quantity'])) $items->setQtyOrdered($unitem['quantity']);
                            if (isset($unitem['quantity'])) $this->totalqtd += $unitem['quantity'];
                            if (isset($unitem['total'])) $items->setRowTotal($unitem['total']);
                            if (isset($unitem['total'])) $items->setSubtotal($unitem['total']);
                            if (isset($unitem['discount'])) $items->setBaseDiscountAmount($unitem['discount']);
                            if (isset($unitem['discount'])) $items->setDiscountAmount($unitem['discount']);

                            $items->setBaseWeeeTaxAppliedAmount(0);
                            $items->setBaseWeeeTaxAppliedRowAmnt(0);
                            $items->setWeeeTaxAppliedAmount(0);
                            $items->setWeeeTaxAppliedRowAmount(0);
                            $items->setWeeeTaxApplied(serialize(array()));
                            $items->setWeeeTaxDisposition(0);
                            $items->setWeeeTaxRowDisposition(0);
                            $items->setBaseWeeeTaxDisposition(0);
                            $items->setBaseWeeeTaxRowDisposition(0);

                            if (!empty($unitem['sku'])) {
                                $items->setSku($unitem['sku']);
                            } else {
                                $items->setSku('pluggto');
                            }

                            if (!empty($unitem['name'])) {
                                $items->setName($unitem['name']);
                            } else {
                                $items->setName(Mage::helper('pluggto')->__('Produto do PluggTo'));
                            }

                            $order->addItem($items);
                        }
                    }
                }
            }

            $order->setTotalQtyOrdered($this->totalqtd);
            $order->setWeight($this->weight);

            if (!empty($data['total']) && !empty($data['total_paid'])) {
                if ($data['total_paid'] > $data['total']) {
                    $feeAsProduct = Mage::getStoreConfig('pluggto/configs/insert_fee_as_product');

                    if ($feeAsProduct) {
                        $fee = $data['total_paid'] - $data['total'];

                        $items = new Mage_Sales_Model_Order_Item();
                        $items->setBasePrice($fee);
                        $items->setProductId(0);
                        $items->setRowTotal($fee);
                        $items->setOriginalPrice($fee);
                        $items->setPrice($fee);
                        $items->setQtyOrdered(1);
                        $items->setProductWeight(0.1);
                        $items->setSku('fee');
                        $items->setName(Mage::helper('pluggto')->__('Custos Financeiros'));

                        $order->addItem($items);

                        $data['subtotal'] +=  $fee;

                        $data['total'] =  $data['total_paid'];
                    }
                }
            }
        }

        if (!empty($data['delivery_type'])) {
            switch ($data['delivery_type']) {
                case 'standard':
                    $method = Mage::getStoreConfig('pluggto/shipping/standard');
                    break;
                case 'express':
                    $method = Mage::getStoreConfig('pluggto/shipping/express');
                    break;
                case 'onehour':
                    $method = Mage::getStoreConfig('pluggto/shipping/onehour');
                    break;
                case 'pickup':
                    $method = Mage::getStoreConfig('pluggto/shipping/pickup');
                    break;
                case 'economy':
                    $method = Mage::getStoreConfig('pluggto/shipping/economy');
                    break;
                case 'guaranteed':
                    $method = Mage::getStoreConfig('pluggto/shipping/guaranteed');
                    break;
                case 'scheduled':
                    $method = Mage::getStoreConfig('pluggto/shipping/scheduled');
                    break;
                case 'fulfillment':
                    $method = Mage::getStoreConfig('pluggto/shipping/fulfillment');
                    break;
                default:
                    $method = Mage::getStoreConfig('pluggto/shipping/standard');
            }
        }

        if (!isset($method) || empty($method)) {
            $method = Mage::getStoreConfig('pluggto/shipping/standard');

            $methodArray = explode('{}', $method);

            if (isset($methodArray[1])) {
                $method = $methodArray[1];
                $description = $methodArray[0];
            }

            if (empty($method)) {
                $method = Mage::getModel('pluggto/source_ShippingMethods')->toOptionArray();

                if (isset($method[1]) && isset($method[1]['value'])) {
                    $method = $method[1]['value'];

                    $methodArray = explode('{}', $method);

                    if (isset($methodArray[1])) {
                        $method = $methodArray[1];
                        $description = $methodArray[0];
                    }
                }
            }
        } else {
            $methodArray = explode('{}', $method);

            if (isset($methodArray[1])) {
                $method = $methodArray[1];
                $description = $methodArray[0];
            }
        }

        $shippingDescription = '';

        if (isset($data['shipments'][0]['shipping_method'])) {
            $shippingDescription = $data['shipments'][0]['shipping_method'];
        }

        if (isset($data['shipments'][0]['shipping_company'])) {
            $shippingDescription .= '(' . $data['shipments'][0]['shipping_company'] . ')';
        }

        if (empty($shippingDescription)) {
            $shippingDescription = $data['delivery_type'];
        }

        if (isset($description) && isset($method)) {
            $order->setShippingMethod($method);

            $order->setShippingDescription($description . ' ' . $shippingDescription);
        } else if (isset($method) && !empty($shippingDescription)) {
            $order->setShippingMethod($method);

            $order->setShippingDescription($shippingDescription);
        } else if (isset($method)) {
            $order->setShippingMethod($method);

            $order->setShippingDescription($shippingDescription);
        } else {
            $order->setShippingMethod('Pluggto');

            $order->setShippingDescription('Pluggto');
        }

        if (isset($data['shipments'][0]['id'])) {
            $order->setShipmentId($data['shipments'][0]['id']);
        }

        // payment info
        $payment = new Mage_Sales_Model_Order_Payment();

        $storemmethod = Mage::getStoreConfig('pluggto/configs/paymentdefault');

        if (!empty($storemmethod)) {
            $payment->setMethod($storemmethod);
        } else {
            $payment->setMethod('pluggto');
        }

        if (isset($data['payment_method'])) {
            if ($data['payment_method']) {
                // caso pagamento tenha sido realizado por MercadoPago

                $payment->setAdditionalData($data['payment_method']);
            }
        }

        Mage::getSingleton('core/session')->setPluggToNotSave(1);
        $payment->setOrder($order);
        Mage::getSingleton('core/session')->setPluggToNotSave();

        if ($new || $order->getPayment() == false) {
            $order->addPayment($payment->place());
            //$order->setStatus(Mage::getStoreConfig('pluggto/orderstatus/pending'));
        }

        // set quote for compatibility issues
        $quote = new Mage_Sales_Model_Quote();
        $order->setQuote($quote);

        Mage::getSingleton('core/session')->setPluggToNotSave(1);

        $order->save();
        Mage::getSingleton('core/session')->setPluggToNotSave();

        if (
            !isset($data['external']) ||
            !is_array($data['external']) ||
            !in_array($order->getIncrementId(), $data['external'])
        ) {
            // export order id
            Mage::getSingleton('pluggto/export')->exportOrderExternalId($order);
        }

        $shipping->save();
        $billing->save();
        $invoice = false;

        switch ($data['status']) {
            case 'approved':
            case 'paid':
                $status = Mage::getStoreConfig('pluggto/orderstatus/approved');
                $state = Mage_Sales_Model_Order::STATE_PROCESSING;
                $invoice = true;
                break;
            case 'partial_payment':
                $state = Mage_Sales_Model_Order::STATE_PROCESSING;
                $status = Mage::getStoreConfig('pluggto/orderstatus/partial_payment');
                break;
            case 'refunded':
                $state = Mage_Sales_Model_Order::STATE_CANCELED;
                $status = Mage::getStoreConfig('pluggto/orderstatus/canceled');
                break;
            case 'pending':
                $status = Mage::getStoreConfig('pluggto/orderstatus/pending');
                $state = Mage_Sales_Model_Order::STATE_PENDING_PAYMENT;
                break;
            case 'invoiced':
                $status = Mage::getStoreConfig('pluggto/orderstatus/invoiced');
                $state = Mage_Sales_Model_Order::STATE_PROCESSING;
                $invoice = true;
                break;
            case 'picking':
                $state = Mage_Sales_Model_Order::STATE_PROCESSING;
                $status = Mage::getStoreConfig('pluggto/orderstatus/picking');
                break;
            case 'invoice_error':
                $status = Mage::getStoreConfig('pluggto/orderstatus/invoice_error');
                $state = Mage_Sales_Model_Order::STATE_PROCESSING;
                $invoice = true;
                break;
            case 'under_review':
                $status = Mage::getStoreConfig('pluggto/orderstatus/under_review');
                $state = Mage_Sales_Model_Order::STATE_HOLDED;
                break;
            case 'canceled':
                $status = Mage::getStoreConfig('pluggto/orderstatus/canceled');
                $state = Mage_Sales_Model_Order::STATE_CANCELED;
                break;
            case 'delivered':
                $status = Mage::getStoreConfig('pluggto/orderstatus/delivered');
                $state = Mage_Sales_Model_Order::STATE_COMPLETE;
                $invoice = true;
                break;
            case 'shipped':
            case 'shipping_informed':
            case 'shipping_error':
                $status = Mage::getStoreConfig('pluggto/orderstatus/shipped');
                $invoice = true;
                break;
            case 'partial_shipped':
                $status = Mage::getStoreConfig('pluggto/orderstatus/partial_shipped');
                $invoice = true;
                break;
            case 'partial_delivered':
                $status = Mage::getStoreConfig('pluggto/orderstatus/partial_delivered');
                $invoice = true;
                break;
            case 'partial_invoiced':
                $status = Mage::getStoreConfig('pluggto/orderstatus/partial_invoiced');
                $invoice = true;
                break;
            case 'partial_canceled':
                $status = Mage::getStoreConfig('pluggto/orderstatus/partial_canceled');
                $invoice = true;
                break;
            default:
                $status = '';
                $state = Mage_Sales_Model_Order::STATE_PENDING_PAYMENT;
                break;
        }

        if ($invoice == true) {
            if (Mage::getStoreConfig('pluggto/orders/invoice') == 1) {
                $notifyCustomerOrderUpdate = false;
                Mage::getSingleton('core/session')->setPluggToNotSave(1);
                // Cria invoice (fatura) para o pedido se já não houver alguma criada.
                try {
                    if (!$order->hasInvoices()) {
                        foreach ($order->getAllItems() as $item) {
                            $Allitems[$item->getId()] = $item->getQtyOrdered();
                        }

                        $invoice = $order->prepareInvoice();
                        $invoice->register()->pay();

                        Mage::getModel('core/resource_transaction')->addObject($invoice)->addObject($invoice->getOrder())->save();
                    }
                } catch (exception $e) {
                }
                Mage::getSingleton('core/session')->setPluggToNotSave();
            }
        }

        $orderHistory = Mage::getModel('sales/order_status_history')->getCollection()
            ->addFieldToFilter('parent_id', $order->getId());

        $orderHistory = $orderHistory->getData();

        $statusHistory = array();
        $statusComment = array();

        if (is_array($orderHistory)) {
            foreach ($orderHistory as $history) {
                $statusHistory[] = $history['status'];
                $statusComment[] = $history['comment'];
            }
        }

        if (in_array('Order has grouped products', $statusComment) || in_array('Order has bundle products', $statusComment)) {
            $grouped = true;
        } else {
            $grouped = false;
        }

        try {
            if (!in_array($status, $statusHistory)) {
                if (isset($state) && $state != 'complete') {
                    $order->setState($state);
                }

                if ($order->getStatus() != $status) {
                    // repõe stock de produtos agrupados ou configuraveis no caso de cancelamento de pedidos
                    if (
                        !$new && $status == Mage::getStoreConfig('pluggto/orderstatus/canceled') && $grouped
                    ) {
                        $items = $order->getAllVisibleItems();

                        foreach ($items as $item) {
                            $product = Mage::getModel('catalog/product')->load($item->getProductId());
                            $oldq = Mage::getModel('pluggto/product')->getProducQtd($product);
                            $stock = $oldq['qty'];

                            $qtd = (int) $item->getQtyOrdered();

                            $finalQuantity = $stock + $qtd;
                            $array_product = array('quantity' => $finalQuantity);

                            Mage::getModel('pluggto/product')->setProductStock($product, $array_product);
                        }
                    }

                    if (!empty($status)) {
                        $order->addStatusToHistory($status, 'PluggTo has updated this status', false);

                        if (!empty($shippingData)) {
                            $order->addStatusToHistory($status, $shippingData, false);
                        }
                    }
                }
            }
        } catch (exception $e) {
        }

        Mage::getSingleton('core/session')->setPluggToNotSave(1);
        $order->save();

        Mage::dispatchEvent('sales_order_place_after', array('order' => $order));
        Mage::getSingleton('core/session')->setPluggToNotSave();

        //add tracking

        try {
            if ($order->canShip() && $data['status'] != 'pending' && $data['status'] != 'approved' && $data['status'] != 'canceled' && $data['status'] != 'picking') {
                if (isset($data['shipments'][0]['shipping_company']) && !empty($data['shipments'][0]['shipping_company'])) {
                    $shippingCompany = $data['shipments'][0]['shipping_company'];
                } else {
                    $shippingCompany = '';
                }

                if (isset($data['shipments'][0]['shipping_method']) && !empty($data['shipments'][0]['shipping_method'])) {
                    $shippingMethod = $data['shipments'][0]['shipping_method'];
                } else {
                    $shippingMethod = '';
                }

                if (isset($data['shipments'][0]['track_code']) && !empty($data['shipments'][0]['track_code'])) {
                    $trackNumber = $data['shipments'][0]['track_code'];
                } else {
                    $trackNumber = '';
                }

                if (!empty($trackNumber)) {
                    $itemQty =  $order->getItemsCollection()->count();

                    $shipment = Mage::getModel('sales/service_order', $order)->prepareShipment($itemQty);

                    $shipment = new Mage_Sales_Model_Order_Shipment_Api();
                    $shipmentId = $shipment->create($order->getIncrementId());

                    $shipment->addTrack($shipmentId, 'custom', $shippingCompany . '-' .  $shippingMethod, $trackNumber);
                }
            }
        } catch (Exception $e) {
            //   var_dump($e->getFile());
            // var_dump($e->getLine());
            // var_dump($e->getMessage());

        }
    }

    public function getStoreByCode($code)
    {
        $readConnection = Mage::getSingleton('core/resource')->getConnection('core_read');

        $result = $readConnection->fetchAll("select * from core_config_data where path = 'pluggto/tables_price_customization/table_price' and value = '" . $code . "' and scope = 'stores' ");

        if (isset($result[0]['scope_id'])) {
            return $result[0]['scope_id'];
        } else {
            return null;
        }
    }

    public function savePluggToid($OrderFromPluggto)
    {
        $order = Mage::getModel('sales/order')->load($OrderFromPluggto['external'], 'increment_id');
        $order->setPluggId($OrderFromPluggto['id']);
        $order->save();
    }

    function sanitizeString($string)
    {
        // matriz de entrada
        $what = array('ä', 'ã', 'à', 'á', 'â', 'ê', 'ë', 'è', 'é', 'ï', 'ì', 'í', 'ö', 'õ', 'ò', 'ó', 'ô', 'ü', 'ù', 'ú', 'û', 'À', 'Á', 'É', 'Í', 'Ó', 'Ú', 'ñ', 'Ñ', 'ç', 'Ç', '(', ')', ',', ';', '|', '!', '"', '#', '$', '%', '~', '^', '>', '<', 'ª', 'º');

        // matriz de saída
        $by   = array('a', 'a', 'a', 'a', 'a', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u', 'A', 'A', 'E', 'I', 'O', 'U', 'n', 'n', 'c', 'C', '_', '_', '_', '_', '_', '_', '_', '_', '_', '_', '_', '_', '_', '_', '_', '_');

        // devolver a string
        return str_replace($what, $by, $string);
    }

    private function toSepareDDDandNumber($phoneWithDDD)
    {
        $number = array();
        // Retira tudo que não for numérico
        $phoneWithDDD = ltrim(preg_replace("/[^0-9]/", "", $phoneWithDDD), 0);
        // Se o primeiro número for 0, é removido
        $phoneWithDDD = preg_replace("/^0/", '', $phoneWithDDD);
        // Pega o tamanho da string
        $sizeOfNumber = strlen($phoneWithDDD);
        // Se o número for maior que 10, ele possuí código de área(DDD)
        if ($sizeOfNumber >= 10) {
            $number['DDD'] = substr($phoneWithDDD, 0, 2);
            $number['phone'] = substr($phoneWithDDD, 2);
        } else {
            $number['DDD'] = '00';
            $number['phone'] = $phoneWithDDD;
        }

        return $number;
    }

    // send to pluggtoTo
    public function update($order, $new = false, $status = false)
    {
        if (!is_object($order)) {
            return;
        }

        if ($new) {
            $toPlugg['channel'] = $_SERVER['HTTP_HOST'];
            $toPlugg['original_id'] = $order->getIncrementId();
        }

        $toPlugg['external'] = $order->getIncrementId();

        switch ($order->getStatus()) {
            case Mage::getStoreConfig('pluggto/orderstatus/partial_payment'):
                $toPlugg['status'] = 'partial_payment';
                break;
            case Mage::getStoreConfig('pluggto/orderstatus/pending'):
                $toPlugg['status'] = 'pending';
                break;
            case Mage::getStoreConfig('pluggto/orderstatus/approved'):
                $toPlugg['status'] = 'approved';
                break;
            case Mage::getStoreConfig('pluggto/orderstatus/invoiced'):
                $toPlugg['status'] = 'invoiced';
                break;
            case Mage::getStoreConfig('pluggto/orderstatus/invoice_error'):
                $toPlugg['status'] = 'invoice_error';
                break;
            case Mage::getStoreConfig('pluggto/orderstatus/shipped'):
                $toPlugg['status'] = 'shipping_informed';
                break;
            case Mage::getStoreConfig('pluggto/orderstatus/delivered'):
                $toPlugg['status'] = 'delivered';
                break;
            case Mage::getStoreConfig('pluggto/orderstatus/canceled'):
                $toPlugg['status'] = 'canceled';
                break;
            case Mage::getStoreConfig('pluggto/orderstatus/under_review'):
                $toPlugg['status'] = 'under_review';
                break;
            case Mage::getStoreConfig('pluggto/orderstatus/picking');
                $toPlugg['status'] = 'picking';
                break;
            default:
                $toPlugg['status'] = 'pending';
                break;
        }

        $toPlugg['receiver_name'] = $order->getCustomerFirstname();
        $toPlugg['receiver_lastname'] = $order->getCustomerLastname();

        // shipping address

        $shiping = $order->getShippingAddress();

        if (!empty($shiping) && is_object($shiping)) {
            $DelStree = $shiping->getStreet();

            $toPlugg['receiver_address'] = $DelStree[0];

            if (isset($DelStree[1])) $toPlugg['receiver_address_number'] = $DelStree[1];
            if (isset($DelStree[2])) $toPlugg['receiver_additional_info'] = $DelStree[2];
            if (isset($DelStree[3])) $toPlugg['receiver_neighborhood'] = $DelStree[3];

            $phone = $this->toSepareDDDandNumber($shiping->getTelephone());

            if (is_array($phone) && isset($phone['DDD'])) {
                $toPlugg['receiver_phone_area'] = $phone['DDD'];
            }

            if (is_array($phone) && isset($phone['phone'])) {
                $toPlugg['receiver_phone'] = $phone['phone'];
            }

            $toPlugg['receiver_city'] = $shiping->getCity();
            $toPlugg['receiver_state'] = $shiping->getRegion();
            $toPlugg['receiver_country'] = $shiping->getCountryId();
            $toPlugg['receiver_zipcode'] = $shiping->getPostcode();
            $toPlugg['receiver_email'] = $shiping->getEmail();
        }

        $billing = $order->getBillingAddress();

        if (!empty($billing) && is_object($billing)) {
            $customer = Mage::getModel('customer/customer');
            $customerid = $billing->getCustomerId();

            if (!empty($customerid)) {
                $customer->load($customerid);
                $toPlugg['payer_name'] = $customer->getFirstname();
                $toPlugg['payer_lastname'] = $customer->getLastname();
                $toPlugg['payer_email'] = $customer->getEmail();
            } else {
                $toPlugg['payer_name'] = $order->getCustomerFirstname();
                $toPlugg['payer_lastname'] = $order->getCustomerLastname();
                $toPlugg['payer_email'] = $order->getCustomerEmail();
            }

            $Billstreet = $billing->getStreet();
            $toPlugg['payer_address'] = $Billstreet[0];
            if (isset($Billstreet[1])) $toPlugg['payer_address_number'] = $Billstreet[1];
            if (isset($Billstreet[2])) $toPlugg['payer_address_complement'] = $Billstreet[2];
            if (isset($Billstreet[3])) $toPlugg['payer_neighborhood'] = $Billstreet[3];
            $toPlugg['payer_city'] = $billing->getCity();
            $toPlugg['payer_state'] = $billing->getRegion();
            $toPlugg['payer_country'] = $billing->getCountryId();
            $toPlugg['payer_zipcode'] = $billing->getPostcode();
            $toPlugg['payer_phone'] = $billing->getTelephone();

            $Payerphone = $this->toSepareDDDandNumber($billing->getTelephone());

            if (is_array($Payerphone) && isset($Payerphone['DDD'])) {
                $toPlugg['payer_phone_area'] = $Payerphone['DDD'];
            }

            if (is_array($Payerphone) && isset($Payerphone['phone'])) {
                $toPlugg['payer_phone'] = $Payerphone['phone'];
            }
        }

        $customFieldToStoreCFPorCNPJ = Mage::getStoreConfig('pluggto/configs/custom_document_field');

        if (!empty($customFieldToStoreCFPorCNPJ)) {
            $orderData = $order->getData();
            if (isset($orderData[$customFieldToStoreCFPorCNPJ]) && !empty($orderData[$customFieldToStoreCFPorCNPJ])) {
                $toPlugg['payer_cpf'] = $orderData[$customFieldToStoreCFPorCNPJ];
                $toPlugg['payer_tax_id'] = $orderData[$customFieldToStoreCFPorCNPJ];
            } else {
                $toPlugg['payer_cpf'] =   $order->getVatId();
                $toPlugg['payer_tax_id'] = $orderData[$customFieldToStoreCFPorCNPJ];
            }
        }

        $toPlugg['total'] = $order->getGrandTotal();
        $toPlugg['shipping'] = $order->getShippingAmount();
        $toPlugg['subtotal'] = $order->getGrandTotal() - $order->getShippingAmount();

        $payment = $order->getPayment();
        $method = $payment->getMethood();
        $addicional = $payment->getAdditionalData();

        if (($method == 'pluggto' || empty($method)) && !empty($addicional)) {
            $method = $addicional;
        }

        $toPlugg['payment_method'] = $method;

        $shipmentCollection = Mage::getResourceModel('sales/order_shipment_collection')
            ->setOrderFilter($order)
            ->load();

        $toshipmentArray = array();

        foreach ($shipmentCollection as $shipment) {
            foreach ($shipment->getAllTracks() as $trackns) {
                if (!is_null($trackns->getDescription())) {
                    $shipping['shipping_method'] = $trackns->getDescription();
                } else {
                    $shipping['shipping_method'] = $order->getShippingDescription();
                }
                if (!is_null($trackns->getTitle())) $shipping['shipping_company'] = $trackns->getTitle();
                if (!is_null($trackns->getTrackNumber())) $shipping['track_code'] = $trackns->getTrackNumber();
                if (!is_null($trackns->getNumber()) && !isset($shipping['track_code'])) $shipping['track_code'] = $trackns->getNumber();
                break;
            }

            if (mageFindClassFile('Thirdlevel_Pluggto_Model_Nfe') != false) {
                $nefClass = Mage::getModel('pluggto/nfe');
                $nef = $nefClass->getNfe($order, $shipment);
                if (isset($nef['nfe_key']) && !empty($nef['nfe_key'])) $shipping['nfe_key'] = $nef['nfe_key'];
                if (isset($nef['nfe_number']) && !empty($nef['nfe_number'])) $shipping['nfe_number'] = $nef['nfe_number'];
                if (isset($nef['nfe_serie']) && !empty($nef['nfe_serie'])) $shipping['nfe_serie'] = $nef['nfe_serie'];
                if (isset($nef['nfe_date']) && !empty($nef['nfe_date'])) $shipping['nfe_date'] = $nef['nfe_date'];
                if (isset($nef['nfe_key']) && !empty($nef['nfe_key'])) $shipping['nfe_key'] = $nef['nfe_key'];
                if (isset($nef['nfe_link']) && !empty($nef['nfe_link'])) $shipping['nfe_link'] = $nef['nfe_link'];
            }
        }

        if (!isset($shipping['nfe_number']) || empty($shipping['nfe_serie'])) { {
                $_history = $order->getAllStatusHistory();

                foreach ($_history as $_historyItem) {
                    $_historyItemAllComment = $_historyItem->getData('comment');

                    if (!empty($_historyItemAllComment)) {
                        $_historyItemEachLine = explode('\r\n', $_historyItemAllComment);

                        if (!empty($_historyItemEachLine)) {
                            foreach ($_historyItemEachLine as $line) {
                                $_historyItemEachBR = explode('<br/>', $line);

                                if (!empty($_historyItemEachBR)) {
                                    foreach ($_historyItemEachBR as $_historyItemEachBRComBarra) {
                                        $_historyItemEachBRSEMBARRA = explode('<br>', $_historyItemEachBRComBarra);

                                        if (!empty($_historyItemEachBRSEMBARRA)) {
                                            foreach ($_historyItemEachBRSEMBARRA as $_historyItem) {
                                                $this_historyItem = $this->sanitizeString(strip_tags($_historyItem));

                                                if (preg_match("/Nota fiscal/", trim($this_historyItem))) {
                                                    $notfiscal = explode(':', $this_historyItem);

                                                    if (isset($notfiscal[1])) {
                                                        $nfe_number = strip_tags(trim($notfiscal[1]));
                                                    }
                                                }

                                                if (preg_match("/Nr NF-e/", trim($this_historyItem))) {
                                                    $notfiscal = explode(':', $this_historyItem);

                                                    if (isset($notfiscal[1])) {
                                                        $nfe_number = strip_tags(trim($notfiscal[1]));
                                                    }
                                                }

                                                if (preg_match("/Serie/", trim($this_historyItem))) {
                                                    $serie = explode(':', $this_historyItem);

                                                    if (isset($serie[1])) {
                                                        $nfe_serie = strip_tags(trim($serie[1]));
                                                    }
                                                }

                                                if (preg_match("/Chave de Acesso/", trim($this_historyItem))) {
                                                    $chave = explode(':', trim($this_historyItem));

                                                    if (isset($chave[1])) {
                                                        $nfe_key = strip_tags(trim($chave[1]));
                                                    }
                                                }

                                                if (preg_match("/Link da DANFE/", trim($this_historyItem))) {
                                                    $link = explode(':', trim($this_historyItem), 2);

                                                    if (isset($link[1])) {
                                                        $cleanedLink = strip_tags(ltrim($link[1], ' <'));
                                                        $nfe_link = rtrim($cleanedLink, ' >');
                                                    }
                                                }

                                                if (preg_match("/CFOP/i", trim($this_historyItem)) || preg_match("/CFOPS/i", trim($this_historyItem))) {
                                                    $cfops = explode(':', trim($this_historyItem), 2);

                                                    if (isset($cfops[1])) {
                                                        $cfops = strip_tags(trim($cfops[1]));
                                                    }
                                                }

                                                if (preg_match("/Data da emissao/", trim($this_historyItem))) {
                                                    $data_emissao = explode(':', trim($this_historyItem));

                                                    if (isset($data_emissao[1])) {
                                                        $data = strip_tags(trim($data_emissao[1]));
                                                    }
                                                }

                                                if (preg_match("/Codigo de Rastreio/", trim($this_historyItem))) {
                                                    $data_emissao = explode(':', trim($this_historyItem));

                                                    if (isset($data_emissao[1])) {
                                                        $trackCodeFromComment = strip_tags(trim($data_emissao[1]));
                                                    }
                                                }

                                                if (preg_match("/Link de Rastreio/", trim($this_historyItem))) {
                                                    $link = explode(':', trim($this_historyItem), 2);

                                                    if (isset($link[1])) {
                                                        $cleanedLink = strip_tags(ltrim($link[1], ' <'));
                                                        $trackUrlFromComent = rtrim($cleanedLink, ' >');
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }

            if (!isset($shipping)) $shipping = array();
            if (isset($trackCodeFromComment)) $shipping['track_code'] = $trackCodeFromComment;
            if (isset($trackUrlFromComent)) $shipping['track_url'] = $trackUrlFromComent;
            if (isset($nfe_number)) $shipping['nfe_number'] = $nfe_number;
            if (isset($nfe_serie)) $shipping['nfe_serie'] = $nfe_serie;
            if (isset($nfe_key)) $shipping['nfe_key'] = $nfe_key;
            if (isset($nfe_link)) $shipping['nfe_link'] = $nfe_link;
            if (isset($data)) $shipping['nfe_date'] = $data;
            if (isset($cfops)) $shipping['cfops'] = $cfops;
        }

        if (isset($shipment) && is_object($shipment) && !is_null($shipment->getIncrementId())) $shipping['external'] = $shipment->getIncrementId();
        if (isset($shipment) && is_object($shipment) && !is_null($shipment->getCreatedAt())) $shipping['date_shipped'] = $shipment->getCreatedAt();

        if (!empty($shipping)) {
            $toshipmentArray[] = $shipping;
        }

        if (!empty($toshipmentArray)) {
            $toPlugg['shipments'] = $toshipmentArray;
        }

        $pluggtoId = $order->getPluggId();

        // preserve ID, Shipping Method and Shipping Company
        if (!empty($pluggtoId) && isset($toPlugg['shipments']) && !empty($toPlugg['shipments'])) {
            $old = Mage::getModel('pluggto/api')->get('orders/' . $pluggtoId, null, null, true);

            if (isset($old['Body']['Order']['shipments'][0]['id'])) {
                $toPlugg['shipments'][0]['id'] = $old['Body']['Order']['shipments'][0]['id'];
            }

            if (
                isset($old['Body']['Order']['shipments'][0]['shipping_company']) &&
                isset($old['Body']['Order']['shipments'][0]['shipping_method']) &&
                !empty($old['Body']['Order']['shipments'][0]['shipping_method']) &&
                !empty($old['Body']['Order']['shipments'][0]['shipping_company'])
            ) {
                $toPlugg['shipments'][0]['shipping_company'] = $old['Body']['Order']['shipments'][0]['shipping_company'];
                $toPlugg['shipments'][0]['shipping_method'] = $old['Body']['Order']['shipments'][0]['shipping_method'];
            }
        }

        $toPlugg['purchased'] = $order->getCreatedAt();
        if ($new) $toPlugg['created'] = $order->getCreatedAt();
        $toPlugg['modified'] = $order->getUpdatedAt();

        $items = $order->getAllVisibleItems();

        $i = 0;

        if ($new):
            foreach ($items as $item):
                $product = Mage::getModel('catalog/product')->load($item->getProductId());

                if ($product->getSellerId()) {
                    $toPlugg['items'][$i]['supplier_id'] = $product->getSellerId();
                }

                $toPlugg['items'][$i]['name'] = $item->getName();
                $toPlugg['items'][$i]['price'] = $item->getPrice();
                $toPlugg['items'][$i]['quantity'] = $item->getQtyOrdered();
                $toPlugg['items'][$i]['total'] = $item->getQtyOrdered() * $item->getPrice();
                $toPlugg['items'][$i]['sku'] = $product->getSku();
                $toPlugg['items'][$i]['external'] = $product->getId();

                if ($product->getStockItem()->getProductTypeId() == 'configurable') {
                    $options = $item->getProductOptions();

                    try {
                        $frompluggto = Mage::getSingleton('pluggto/api')->get('products/' . $product->getPluggtoId(), null, null, true);
                    } catch (exception $e) {
                        Mage::helper('pluggto')->WriteLogForModule('Error', 'Item não encontrado no plugg.to');
                    }

                    $vari = array();
                    if (isset($frompluggto['Product']['variations']) && is_array($frompluggto['Product']['variations'])) {
                        foreach ($frompluggto['Product']['variations'] as $varis) {
                            $vari[$varis['id']] = $varis;
                        }
                    }

                    $subproduct = Mage::getSingleton('catalog/product')->load($product->getIdBySku($options['simple_sku']));

                    $toPlugg['items'][$i]['variation']['id'] = $subproduct->getPluggtoId();
                    $toPlugg['items'][$i]['variation']['sku'] = $subproduct->getSku();
                    $toPlugg['items'][$i]['variation']['name'] = $subproduct->getName();

                    if (isset($vari[$subproduct->getPluggtoId()])) {
                        if (isset($vari[$subproduct->getPluggtoId()]['attributes']) && is_array($vari[$subproduct->getPluggtoId()]['attributes'])) {
                            $j = 0;
                            foreach ($vari[$subproduct->getPluggtoId()]['attributes'] as $attribute) {
                                if (isset($attribute['code'])) $toPlugg['items'][$i]['variation']['attributes'][$j]['code'] = $attribute['code'];
                                if (isset($attribute['label'])) $toPlugg['items'][$i]['variation']['attributes'][$j]['label'] = $attribute['label'];
                                if (isset($attribute['value']['code'])) $toPlugg['items'][$i]['variation']['attributes'][$j]['value']['code'] = $attribute['value']['code'];
                                if (isset($attribute['value']['label'])) $toPlugg['items'][$i]['variation']['attributes'][$j]['value']['label'] = $attribute['value']['label'];
                                $j++;
                            }
                        }
                    }
                }

                $i++;

            endforeach;
        endif; // if new

        return $toPlugg;
    }

    public function forceSyncOrders()
    {
        $api = Mage::getSingleton('pluggto/api');
        $post['order'] = 'desc';
        $post['limit'] = 100;

        $orders = $api->get('orders', $post, 'field', true);

        foreach ($orders['Body']['result'] as $order) {
            try {
                $this->create($order['Order']);
            } catch (\Exception $e) {
            }
        }
    }

    public function forceUpdateOrders()
    {
        $modelOrder = Mage::getModel('sales/order');
        $modelOrderCollection = $modelOrder->getCollection()->addAttributeToFilter('plugg_id', array('neq' => ''))->setOrder('entity_id', 'DESC')->setPageSize(300);
        $queue =  Mage::getModel('pluggto/export');

        foreach ($modelOrderCollection as $thisOrder) {
            $queue->exportOrderToQueue($thisOrder->getEntityId());
        }

        return true;
    }
}