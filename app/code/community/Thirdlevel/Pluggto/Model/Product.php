<?php

class Thirdlevel_Pluggto_Model_Product extends Mage_Core_Model_Abstract
{
    public $products;
    protected $productData = null;
    protected $attributeSetId;
    protected $attributesIds;
    protected $simpleProduts;
    protected $price;
    protected $attributeCode;
    protected $values;
    protected $new = true;
    protected $setdata;
    protected $tovar;
    protected $configs;
    protected $StartTime;
    protected $weight;
    protected $categoryArray;
    protected $allMagentoProducts;
    protected $categories;
    protected $width;
    protected $height;
    protected $length;

    public function getConfig()
    {
        if (empty($this->configs)) {
            $this->configs = Mage::helper('pluggto')->config();
        }

        return $this->configs;
    }

    // sera desativada
    public function exportAll()
    {
        $this->getProducts();
        $this->export();
        return;
    }

    public function forceExport()
    {
        $this->getProducts();
        foreach ($this->products as $product) {
            Mage::getModel('pluggto/bulkexport')->write($product->getId());
        }

        Mage::getSingleton('pluggto/bulkexport')->runBulkExport();
        return;
    }

    // sera desativada
    public function process()
    {
        ini_set('max_execution_time', 0);

        $this->getTableData();

        // exporta para o pluggto
        $this->export();

        // sincroniza
        $this->sync();

        //  Mage::getSingleton('pluggto/line')->playline();

        return;
    }

    public function getProducts()
    {
        if (empty($this->products)) {
            $this->products = $this->getProductsCollection();
        }

        return $this->products;
    }

    public function getProductsCollection()
    {
        $allowed = array();
        $StoreId = Mage::getStoreConfig('pluggto/products/product_store_id');

        if (!empty($StoreId)) {
            $modelProduct = Mage::getModel('catalog/product')->setStoreId($StoreId);
        } else {
            $modelProduct = Mage::getModel('catalog/product');
        }

        $products = $modelProduct->getCollection()
            ->addAttributeToSelect('sku')
            ->addAttributeToSelect('price')
            ->addAttributeToSelect('special_price')
            ->addAttributeToSelect('status')
            ->addAttributeToSelect('export_pluggto')
            ->addAttributeToSelect('type_id')
            ->addAttributeToSelect('website_ids')
            ->joinField(
                'qty',
                'cataloginventory/stock_item',
                'qty',
                'product_id=entity_id',
                '{{table}}.stock_id=1',
                'left'
            );

        foreach ($products as $product) {
            $allowed[] = $product;
        }

        return $allowed;
    }

    public function getOneTableDataFromPluggTo($next, $limit, $attempt = 1)
    {
        // [4.0.6] Limite de tentativas + backoff. Antes a recursao era infinita
        // em caso de falha da API (timeout/500/firewall) — travava o cron e o
        // orquestrador ate o timeout global matar o processo.
        $maxAttempts = 3;

        try {
            $api = Mage::getSingleton('pluggto/api');
            if ($next == null) {
                $data = array('limit' => $limit);
            } else {
                $data = array('next' => $next, 'limit' => $limit);
            }

            $result = $api->get('products/tabledata', $data, 'query', true);
        } catch (Exception $e) {
            $result = false;
        }

        if (!$result) {
            if ($attempt >= $maxAttempts) {
                Mage::helper('pluggto')->WriteLogForModule(
                    'Error',
                    "getOneTableDataFromPluggTo: API falhou apos {$maxAttempts} tentativas (next=" . var_export($next, true) . ")"
                );
                return false;
            }
            sleep($attempt * 2); // backoff progressivo: 2s, 4s
            return $this->getOneTableDataFromPluggTo($next, $limit, $attempt + 1);
        }

        if (isset($result['Body']['Products'])) {
            return $result['Body']['Products'];
        }

        return false;
    }

    public function gellTableDataFromPluggTo()
    {
        $products = $this->getOneTableDataFromPluggTo(null, 100);

        // [4.0.6] Protege contra retorno false (API falhou). Sem isso, o
        // count($lastResult) abaixo lancaria TypeError no PHP 8.
        if (!is_array($products)) {
            return array();
        }

        $result = $products;
        $_limit = 100;

        if (count($products) == $_limit) {
            $lastResult = $products;

            while (is_array($lastResult) && count($lastResult) == $_limit) {
                $cursor = array_pop($result);
                if (!is_array($cursor) || !isset($cursor['id'])) {
                    break;
                }

                $result = $this->getOneTableDataFromPluggTo($cursor['id'], $_limit);

                // API falhou no meio da paginacao — retorna o que ja temos
                if (!is_array($result)) {
                    break;
                }

                $lastResult = $result;
                $products = array_merge($lastResult, $products);
            }
        }

        return $products;
    }

    public function getTableData()
    {
        if (empty($this->productData)) {
            $this->productData = $this->gellTableDataFromPluggTo();
        }

        return $this->productData;
    }

    public function import()
    {
        $this->getTableData();

        // se tiver vazio retorna por que não tem nada para importar
        if (empty($this->productData)) {
            return;
        }

        foreach ($this->productData as $value) {
            $this->carregaProduto($value);

            // check if dont't have external in pluggto
            if ($this->new) {
                // lock first to see if already is not in database
                $alline = Mage::getModel('pluggto/line')->getCollection();
                $alline->addFieldToFilter('pluggtoid', $value['sku']);
                $id = $alline->getFirstItem()->getId();

                $line = Mage::getModel('pluggto/line');

                if ($id != null) {
                    $line->load($id);
                }

                $line->setWhat('products');
                $line->setUrl('skus' . '/' . $value['sku']);
                $line->setPluggtoid($value['sku']);
                $line->setOpt('GET');
                $line->setDirection('from');
                $line->setCreated(date("Y-m-d H:i:s"));
                $line->save();
            }
        }

        return;
    }

    public function syncPriceStock($dryRun = false)
    {
        ini_set('max_execution_time', 0);
        ini_set("memory_limit", "1G");

        $__t = microtime(true); // [PERF] tempo de execucao (gated: mageshop/perf_log)
        $this->getTableData();
        Mage::helper('pluggto')->WriteLogForModule('PERF', 'sync.getTableData: '
            . round(microtime(true) - $__t, 2) . 's (pluggto=' . (is_array($this->productData) ? count($this->productData) : 0) . ')');

        $configs = $this->getConfig();
        $chanceDisable = Mage::getStoreConfig('pluggto/products/disable_product');

        $__t = microtime(true); // [PERF] tempo de execucao (gated: mageshop/perf_log)
        $this->getProducts();
        Mage::helper('pluggto')->WriteLogForModule('PERF', 'sync.getProducts: '
            . round(microtime(true) - $__t, 2) . 's (locais=' . (is_array($this->products) ? count($this->products) : 0) . ')');

        // Quando ligado, produtos desabilitados sao ignorados neste sync. Util
        // para lojas com base=0 (Plugg.To como origem), onde o estoque forcado a 0
        // de um desabilitado gera diff permanente que o GET nunca reconcilia.
        // Default desligado: preserva o comportamento de lojas base=1 (push), que
        // usam o disable_product para zerar o produto no marketplace.
        $skipDisabled = (bool) Mage::getStoreConfig('pluggto/mageshop/sync_skip_disabled');

        $__loopT = microtime(true); // [PERF] tempo de execucao (gated: mageshop/perf_log)
        $__dryTotal = 0; $__dryBy = array(); $__drySamples = array(); $__drySkippable = 0; // [DRY diag]
        $productDataArray = array();

        // prepare data array
        foreach ($this->productData as $data) {
            if (isset($data['variations']) && !empty($data['variations']) && is_array($data['variations'])) {
                foreach ($data['variations'] as $vari) {
                    if (isset($vari['sku'])) {
                        $productDataArray[$vari['sku']] = $vari;
                    }
                }
            } else {
                // [4.0.6 BUGFIX] antes era "} {" (bloco solto), que indexava as
                // variacoes E o produto pai. Agora produto com variacao indexa
                // SO as variacoes; produto simples indexa a si mesmo.
                if (isset($data['sku'])) {
                    $productDataArray[$data['sku']] = $data;
                }
            }
        }

        foreach ($this->products as $product) {
            $needSync = false;
            $__reasons = array(); // [DRY diag]

            // Ignora desabilitados quando a config sync_skip_disabled estiver ligada
            // (ver nota acima). Desligada = comportamento original preservado.
            if ($skipDisabled
                && (int) $product->getStatus() != Mage_Catalog_Model_Product_Status::STATUS_ENABLED) {
                continue;
            }

            if (isset($productDataArray[trim($product->getSku())])) {
                $thisData = $productDataArray[trim($product->getSku())];
                $price = $this->numberFormat($this->getPriceWithTax($product->getPrice(), $product));
                $specialPrice = $this->numberFormat($this->getPriceWithTax($this->getProductPriceRule($product), $product));

                // Normaliza o valor da Plugg.To na MESMA precisao (2 casas) antes de
                // comparar: a loja arredonda em numberFormat (ex 1.34) e a Plugg.To
                // guarda 3 casas (1.344) — sem isso, gera diff falso que nunca some.
                if (!empty($thisData['price']) && !empty($price) && $price != $this->numberFormat($thisData['price'])) {
                    $needSync = true;
                    if ($dryRun) { $__reasons[] = "price(loja='{$price}' plug='{$thisData['price']}')"; }
                }

                if (!empty($thisData['special_price']) && !empty($specialPrice) && $specialPrice != $this->numberFormat($thisData['special_price'])) {
                    $needSync = true;
                    if ($dryRun) { $__reasons[] = "special(loja='{$specialPrice}' plug='{$thisData['special_price']}')"; }
                }

                if ($product->getTypeId() == 'simple') {
                    $stock = (int)$product->getQty();
                    $status = (int)$product->getStatus();

                    if ($chanceDisable && $status != Mage_Catalog_Model_Product_Status::STATUS_ENABLED) {
                        $stock = (int)0;
                    } else {
                        // do nothing
                    }

                    if (isset($thisData['quantity'])) $thisData['quantity'] = (int)$thisData['quantity'];

                    if (isset($thisData['quantity']) && $stock != $thisData['quantity']) {
                        $needSync = true;
                        if ($dryRun) { $__reasons[] = "stock(loja={$stock} plug={$thisData['quantity']})"; }
                    }
                }
            }

            // check if dont't have external in pluggto
            if ($needSync) {
                if ($dryRun) {
                    // [DRY diag] nao enfileira; so contabiliza o que detectaria
                    $__dryTotal++;
                    // O import (saveSimpleProduct) pula quando export_pluggto e falso/nulo:
                    // esses GETs nunca reconciliam o diff = churn.
                    $__expFalse = !$product->getExportPluggto();
                    if ($__expFalse) { $__drySkippable++; }
                    foreach ($__reasons as $r) {
                        $field = substr($r, 0, strpos($r, '('));
                        $__dryBy[$field] = ($__dryBy[$field] ?? 0) + 1;
                    }
                    if (count($__drySamples) < 20) {
                        $__drySamples[] = trim($product->getSku())
                            . ($__expFalse ? ' [exp=0]' : ' [exp=1]') . ' => ' . implode(' ', $__reasons);
                    }
                } elseif ($configs['configuration']['base']) {
                    Mage::getSingleton('pluggto/export')->exportProductToQueue($product, false, 'PUT', true);
                } else {
                    // lock first to see if already is not in database
                    $line = Mage::getModel('pluggto/line');
                    $line->setWhat('products');
                    $line->setUrl('products' . '/' . rawurlencode(trim($thisData['id'])));
                    $line->setPluggtoid($thisData['id']);
                    $line->setOpt('GET');
                    $line->setDirection('from');
                    $line->setCreated(date("Y-m-d H:i:s"));
                    $line->save();
                }
            } // end if

        } // end foreach

        // [PERF] tempo de execucao (gated: mageshop/perf_log)
        Mage::helper('pluggto')->WriteLogForModule('PERF', 'sync.loop: '
            . round(microtime(true) - $__loopT, 2) . 's');

        if ($dryRun) {
            // [DRY diag] resumo do que o syncPriceStock ENFILEIRARIA (sem enfileirar)
            $by = array();
            foreach ($__dryBy as $f => $c) { $by[] = "{$f}={$c}"; }
            Mage::helper('pluggto')->WriteLogForModule('PERF', sprintf(
                'sync.DRY: precisariam sincronizar=%d de %d locais | por campo: %s | export_pluggto=falso (import pularia)=%d',
                $__dryTotal, (is_array($this->products) ? count($this->products) : 0), implode(' ', $by), $__drySkippable
            ));
            foreach ($__drySamples as $s) {
                Mage::helper('pluggto')->WriteLogForModule('PERF', 'sync.DRY amostra: ' . $s);
            }
        }
    }

    public function sync()
    {
        $pluggids = array();

        // nada para sincronizar
        if (empty($this->productData)) {
            return;
        }

        if (is_array($this->productData)) {
            // pluggto ids that have ids in the store
            foreach ($this->productData as $key => $value) {
                $product = $this->getOneByPluggtoId($key);
                if ($product->getEntityId() != null) {
                    $pluggids[$value['timestamp']] = $key;
                }
            }
        }

        if (is_array($this->products)) {
            foreach ($this->products as $product) {
                $plugtime = null;
                $plugtime = array_search($product->getPluggtoId(), $pluggids);

                if ($plugtime != null) {
                    // se o timestamp na loja estiver maior, envia para o pluggto
                    if ((int)$product->getPluggtoTime() > $plugtime) {
                        Mage::getSingleton('pluggto/export')->exportProductToQueue($product);

                        // se na loja estiver menor, recebe do pluggto
                    } elseif ((int)$product->getPluggtoTime() < $plugtime) {
                        $alline = Mage::getModel('pluggto/line')->getCollection();
                        $alline->addFieldToFilter('pluggtoid', $product->getPluggtoId());
                        $alline->addFieldToFilter('storeid', $product->getEntityId());
                        $id = $alline->getFirstItem()->getId();

                        $line = Mage::getModel('pluggto/line');

                        if ($id != null) {
                            $line->load($id);
                        }

                        $line->setWhat('products');
                        $line->setStoreid($product->getEntityId());
                        $line->setPluggtoid($product->getPluggtoId());
                        $line->setDirection('from');
                        $line->setOpt('GET');
                        $line->setUrl('products' . '/' . $product->getPluggtoId());
                        $line->setCreated(date("Y-m-d H:i:s"));
                        $line->save();
                    }
                }
            }
        }

        return;
    }

    public function export($force = false)
    {
        $this->getProducts();

        // nada para exportar
        if (empty($this->products)) {
            return;
        }

        $configs = $this->getConfig();
        $storeView = $configs['products']['product_store_default'];

        foreach ($this->products as $product) {
            if (($product['pluggto_id'] == null || $product['pluggto_id'] == '') || $force) {
                $product = Mage::getModel('catalog/product');

                if (!empty($storeview)) {
                    $product->setStoreId($storeview);
                }

                $product->load($product['entity_id']);
                Mage::getSingleton('pluggto/export')->exportProductToQueue($product);
            }
        }

        return;
    }

    // return a array with pluggtoid and timestamp
    public function getProductsIndexData()
    {
        $products = $this->getProductsCollection();
        $return = array();

        foreach ($products as $product) {
            $return[$product->getEntityId()]['pluggtoid'] = $product->getPluggtoId();
            $return[$product->getEntityId()]['timestamp'] = $product->getPluggtoTime();
        }

        return $return;
    }

    /*
     * Salva ou atualiza o Produto
     *
     */
    public function getAttributeSetId()
    {
        if (!empty($this->attributeSetId)) {
            return $this->attributeSetId;
        }

        $this->attributeSetId = Mage::getSingleton('pluggto/attributeSet')->getBestAttributeSetFromProductCategory($this->categories);

        return $this->attributeSetId;
    }

    function findValueId($attribute, $label)
    {
        $attribute_options_model = Mage::getModel('eav/entity_attribute_source_table');
        $attribute_options_model->setAttribute($attribute);
        $options = $attribute_options_model->getAllOptions();
        $optionId = null;

        foreach ($options as $option) {
            if ($option['label'] == $label) {
                $optionId = $option['value'];

                break;
            }
        }

        return $optionId;
    }

    function getOptionId($attribute_code, $label, $simple)
    {
        $attribute_model = Mage::getModel('eav/entity_attribute');
        $attribute_id = $attribute_model->getIdByCode('catalog_product', $this->sanitize($attribute_code));

        // load atribute
        $attribute = $attribute_model->load($attribute_id);

        if (empty($this->attributeSetId)) {
            $this->attributeSetId = $this->getAttributeSetId();
        }

        // if has not attribute, create
        if ($attribute->getAttributeId() == null) {
            $group = Mage::getModel('eav/entity_attribute_set');
            $groupId = $group->getDefaultGroupId($this->attributeSetId);

            $attribute->setEntityTypeId(Mage::getModel('eav/entity')->setType('catalog_product')->getTypeId());
            $attribute->setAttributeCode($this->sanitize($attribute_code));
            $attribute->setFrontendInput('select');
            $attribute->setFrontendLabel($attribute_code);
            $attribute->setIsGlobal(1);
            $attribute->setIsVisible(1);

            if ($simple) {
                $attribute->setIsConfigurable(1);
                $attribute->setApplyTo('simple', 'bundle', 'grouped', 'configurable');
            } else {
                $attribute->setApplyTo('configurable');
                $attribute->setIsConfigurable(0);
            }

            $attribute->setIsUserDefined(1);
            $attribute->setIsRequired(0);
            $attribute->setBackEndType('int');
            $attribute->setAttributeSetId($this->attributeSetId);
            $attribute->setAttributeGroupId($groupId);

            $attribute->save();
        }

        // check if value existws
        $optionId = $this->findValueId($attribute, $label);
        $allowedAttributesToCreateConfigurable = explode(',', $this->configs['marketplace']['allowed_configurable_attributes']);
        $newAllowToLowCase = array();

        foreach ($allowedAttributesToCreateConfigurable as $allowedAtt) {
            $newAllowToLowCase[] = strtolower($allowedAtt);
        }

        // if exists
        if ($optionId != null) {
            $this->attributeCode = $attribute->getAttributeCode();
            $this->attributeId = $attribute->getAttributeId();

            if ($simple && in_array(strtolower($attribute_code), $newAllowToLowCase)) {
                if (is_array($this->attributesIds)) {
                    if (!in_array($attribute->getAttributeId(), $this->attributesIds)) {
                        $this->attributesIds[] = $attribute->getAttributeId();
                    }
                } else {
                    $this->attributesIds[] = $attribute->getAttributeId();
                }
            }

            return $optionId;
        } else {
            $this->addAttributeOptions($attribute->getAttributeCode(), array($label));
            $option = $this->findValueId($attribute, $label);
        }

        if (!empty($option) && $simple && in_array(strtolower($attribute_code), $newAllowToLowCase)) {
            if (is_array($this->attributesIds)) {
                if (!in_array($attribute->getAttributeId(), $this->attributesIds)) {
                    $this->attributesIds[] = $attribute->getAttributeId();
                }
            } else {
                $this->attributesIds[] = $attribute->getAttributeId();
            }

            $this->attributeCode = $attribute->getAttributeCode();
            $this->attributeId = $attribute->getAttributeId();

            return $option;
        } else { // wil be null
            return $option;
        }
    }

    public function addAttributeOptions($attribute_code, array $optionsArray)
    {
        $setup = new Mage_Sales_Model_Mysql4_Setup('core_setup');
        $tableOptions = $setup->getTable('eav_attribute_option');
        $tableOptionValues = $setup->getTable('eav_attribute_option_value');
        $attributeId = (int)$setup->getAttribute('catalog_product', $attribute_code, 'attribute_id');
        
        foreach ($optionsArray as $sortOrder => $label) {
            // add option
            $data = array(
                'attribute_id' => $attributeId,
                'sort_order' => $sortOrder,
            );

            $setup->getConnection()->insert($tableOptions, $data);

            // add option label
            $optionId = (int)$setup->getConnection()->lastInsertId($tableOptions, 'option_id');
            $configs = $this->getConfig();

            $storeId = $configs['products']['product_store_default'];
            if (empty($storeId)) {
                $storeId = 1;
            }

            // save first in admin
            $data = array(
                'option_id' => $optionId,
                'store_id' => 0,
                'value' => $label,
            );

            $setup->getConnection()->insert($tableOptionValues, $data);

            // save first in the store
            $data = array(
                'option_id' => $optionId,
                'store_id' => $storeId,
                'value' => $label,
            );

            $setup->getConnection()->insert($tableOptionValues, $data);
        }
    }

    // isola o nome do arquivo para tentar ver se não falta colocar no external array
    public function getImageFileNameFromMagento($imageUrl)
    {
        $imagepces = explode('_', $imageUrl);
        $filenamearray = explode('/', $imagepces[0]);
        $withOutExtension = explode('.', end($filenamearray));
        return $withOutExtension[0];
    }

    public function getImagePluggtoFileName($imageUrl)
    {
        $imagepces = explode('-', $imageUrl);
        $img = explode('.', end($imagepces));
        return $img[0];
    }

    public function updatePhotoExternal($PluggtoUrl, $item, $product, $array_product, $parent)
    {
        $api = Mage::getSingleton('pluggto/api')->load(1);

        if ($parent) {
            $toPlugg['id'] = $parent['id'];
            $toPlugg['variations'][0]['id'] = $array_product['id'];
            $toPlugg['variations'][0]['photos'][0]['url'] = $PluggtoUrl['url'];

            if (isset($item['url'])) $toPlugg['variations'][0]['photos'][0]['external'] = $item['url'];
            if (isset($item['disabled'])) $toPlugg['variations'][0]['photos'][0]['disabled'] = $item['disabled'];
            if (isset($item['position'])) $toPlugg['variations'][0]['photos'][0]['order'] = $item['position'];
            if (isset($item['label'])) $toPlugg['variations'][0]['photos'][0]['name'] = $item['label'];

            if ($item['file'] == $product->getThumbnail()) {
                $toPlugg['variations'][0]['photos'][0]['thumb'] = (bool)true;
            } else {
                $toPlugg['variations'][0]['photos'][0]['thumb'] = (bool)false;
            }
        } else {
            $toPlugg['id'] = $array_product['id'];
            if (isset($item['url'])) $toPlugg['photos'][0]['url'] = $PluggtoUrl['url'];
            if (isset($item['url'])) $toPlugg['photos'][0]['external'] = $item['url'];
            if (isset($item['disabled'])) $toPlugg['photos'][0]['disabled'] = $item['disabled'];
            if (isset($item['position'])) $toPlugg['photos'][0]['order'] = $item['position'];
            if (isset($item['label'])) $toPlugg['photos'][0]['name'] = $item['label'];

            if ($item['file'] == $product->getThumbnail()) {
                $toPlugg['photos'][0]['thumb'] = (bool)true;
            } else {
                $toPlugg['photos'][0]['thumb'] = (bool)false;
            }
        }

        Mage::getModel('pluggto/call')->doCall('products/' . $toPlugg['id'], $toPlugg, 'json', 'PUT', true);
    }

    public function saveImage($product, $array_product, $parent = false)
    {
        // check to see if have pictures
        if (isset($array_product['photos'])) {
            $mediaApi = Mage::getModel("catalog/product_attribute_media_api");
            $mediaApiItems = $mediaApi->items($product->getId());
            // delete first old pictures
            $arrayphotos = array();
            $arrayphotosUrl = array();

            foreach ($array_product['photos'] as $pluggphotos) {
                if (isset($pluggphotos['external'])) {
                    $arrayphotos[$pluggphotos['external']]['url'] = $pluggphotos['url'];
                    if (isset($pluggphotos['thumb'])) $arrayphotos[$pluggphotos['external']]['thumb'] = $pluggphotos['thumb'];
                } else {
                    $tophoto['url'] = $pluggphotos['url'];
                    if (isset($pluggphotos['thumb'])) $tophoto['thumb'] = $pluggphotos['thumb'];
                    $arrayphotos[] = $tophoto;
                }
            }

            // primeiro tento excluir pelo external
            $externalArray = array();

            foreach ($mediaApiItems as $item) {
                // se possui external code não precisa fazer nada já pula para o próximo
                if (isset($arrayphotos[$item['url']])) {
                    unset($arrayphotos[$item['url']]);
                    continue;
                }

                // tenta busca pelo nome do arquivo
                $file = $this->getImageFileNameFromMagento($item['url']);
                $finded = false;
                foreach ($arrayphotos as $photo) {
                    $finded = strpos($photo['url'], $file);

                    if ($finded) {
                        unset($arrayphotos[array_search($photo, $arrayphotos)]);
                        $this->updatePhotoExternal($photo, $item, $product, $array_product, $parent);
                        break;
                    }
                }

                // esta na loja, mas não no pluggto, deve ser apagado.
                if (!$finded) {
                    $this->lockSave();
                    $mediaApi->remove($product->getId(), $item['file']);
                    $product->save();
                    $this->unlockSave();

                    $configs = $this->getConfig();
                    $storeView = $configs['products']['product_store_default'];
                    $product = Mage::getModel('catalog/product');

                    if (!$storeView) {
                        $product->setStoreId($storeView);
                    }

                    $product->load($product->getId());
                }
            }

            if (count($arrayphotos) > 0) {
                // salvar novas images
                $dir = Mage::getBaseDir('base') . '/media/pluggto';
                if (!is_dir($dir)) {
                    mkdir($dir, 0700);
                }

                foreach ($arrayphotos as $picture) {
                    $parts = explode('/', $picture['url']);

                    foreach ($parts as $part) {
                        $name = $part;
                    }

                    $files = explode('.', $name);

                    if (!isset($files[1])) {
                        $ext = '.jpg';
                    } else {
                        $ext = '.' . $files[1];
                    }

                    $img = $dir . '/' . $files[0] . $ext;

                    $arrContextOptions = array(
                        "ssl" => array(
                            "verify_peer" => false,
                            "verify_peer_name" => false,
                        ),
                    );

                    try {
                        file_put_contents($img, file_get_contents($picture['url'], false, stream_context_create($arrContextOptions)));
                    } catch (exception $e) {
                        continue;
                    }

                    if (isset($picture['disabled'])) {
                        $disable = $picture['disabled'];
                    } else {
                        $disable = false;
                    }

                    $scope = array('image', 'small_image', 'thumbnail');
                    $product->addImageToMediaGallery($img, $scope, false, $disable);
                    $product->getMediaGalleryImages();
                }
            }

            $this->lockSave();
            $product->save();
            $this->unlockSave();
        }
    }

    // create a single category
    public function checkCategory($arraycat)
    {
        $category = Mage::getModel('catalog/category');

        if (isset($arraycat['external']) && !empty($arraycat['external'])) {
            $category->load($arraycat['external']);
        }

        if ($category->getEntityId() != null) {
            if ($category->getName() != $arraycat['name']) {
                $category = Mage::getModel('catalog/category');

                $col = $category->getCollection();
                $col->addFieldToFilter('name', $arraycat['name']);
                $id = $col->getFirstItem()->getEntityId();

                if (!empty($id)) {
                    return $id;
                }
            } else {
                return $category->getEntityId();
            }
        } else {
            $col = $category->getCollection();
            $col->addFieldToFilter('name', $arraycat['name']);
            $id = $col->getFirstItem()->getEntityId();

            if (!empty($id)) {
                return $id;
            }
        }

        $category->setStoreId(Mage::app()->getStore()->getId());

        $cat['name'] = $arraycat['name'];
        $cat['path'] = "1"; //parent relationship..
        $cat['description'] = $arraycat['name'];
        $cat['is_active'] = 1;
        $cat['is_anchor'] = 0; //for layered navigation

        $category->addData($cat);
        $category->save();

        return $category->getEntityId();
    }

    public function getPriceWithTax($price, $product)
    {
        $insertTax = Mage::getStoreConfig('pluggto/products/calculate_tax');

        if (!empty($insertTax) && $insertTax) {
            $store = Mage::app()->getStore('default');
            $taxCalculation = Mage::getModel('tax/calculation');
            $request = $taxCalculation->getRateRequest(null, null, null, $store);
            $taxClassId = $product->getTaxClassId();

            $percent = $taxCalculation->getRate($request->setProductClassId($taxClassId));

            if (!empty($percent)) {
                $price = $price + ($price * ($percent / 100));
                return $price;
            } else {
                return $price;
            }
        } else {
            return $price;
        }
    }

    public function getProductPriceRule($prod)
    {
        // Verifica se há preços especiais (ofertas) para o produto
        if ($prod->getSpecialPrice() != null && $prod->getSpecialPrice() != '') {
            // Verifica se o preço especial está em um determinado range de datas
            if ($prod->getSpecialFromDate() != null && $prod->getSpecialFromDate() != '' && $prod->getSpecialToDate() != null && $prod->getSpecialToDate() != '') {
                $now = strtotime(date('c'));
                if (strtotime($prod->getSpecialFromDate()) < $now && $now < strtotime($prod->getSpecialToDate())) {
                    // is special price
                    $price = $prod->getSpecialPrice();
                } else {
                    // if  have special price but is out of date, return price
                    $price = $prod->getPrice();
                }
            } else {
                // Há preço especial, porém sem range de datas, logo, retorna o preço especial
                $price = $prod->getSpecialPrice();
            }
        } else {
            // Não há ofertas para o produto, logo, retorna o preço do produto
            $price = $prod->getPrice();
        }

        return $price;
    }

    public function findProduct($sku)
    {
        $product = Mage::getModel('catalog/product');
        $collection = $product->getCollection();
        $collection->addFieldToFilter('sku', $sku);
        $id = $collection->getFirstItem()->getEntityId();

        if ($id != null) {
            $configs = $this->getConfig();
            $storeView = $configs['products']['product_store_default'];

            $product = Mage::getModel('catalog/product');

            if (!$storeView) {
                $product->setStoreId($storeView);
            }

            $product->load($product->getId());

            return $product->load($id);
        }

        return false;
    }

    // retorna a colocação de produtos com um id do pluggto
    public function getAllByPluggtoId($id)
    {
        $collection = Mage::getModel('catalog/product')->getCollection();
        $collection->addFieldToFilter('pluggto_id', $id);
        return $collection;
    }

    // retorna o primeiro produto com o id do pluggto
    public function getOneByPluggtoId($id)
    {
        return $this->getAllByPluggtoId($id)->getFirstItem();
    }

    public static function numberFormat($number)
    {
        return (float)number_format($number, 2, '.', '');
    }

    public function carregaProduto($array_product)
    {
        // carrega o model
        $product = Mage::getModel('catalog/product');
        $configs = $this->getConfig();
        $storeView = $configs['products']['product_store_default'];

        // first try to load by sku
        if (isset($array_product['sku']) && !empty($array_product['sku'])) {
            $collection = $product->getCollection();
            $collection->addFieldToFilter('sku', $array_product['sku']);

            $id = $collection->getFirstItem();

            if ($id->getEntityId() != null) {
                $entityId = $id->getEntityId();
                $product = Mage::getModel('catalog/product');

                if (!empty($storeView)) {
                    $product->setStoreId($storeView);
                }

                $product = $product->load($entityId);
            }
        } else {
            return $product;
        }

        return $product;
    }

    public function getWebSites()
    {
        $configs = $this->getConfig();
        if ($configs['marketplace']['store_to_create'] != null) {
            return array($configs['marketplace']['store_to_create']);
        } else {
            $websites = Mage::app()->getWebsites();
            $websiteIds = array();
            foreach ($websites as $website) {
                $websiteIds[] = $website->getWebsiteId();
                break;
            }

            return $websiteIds;
        }
    }

    public function saveProductAttributes($array_product, $simple = true)
    {
        $i = 0;
        if (!isset($array_product['attributes'])) {
            return;
        }
        // marca alguns campos nativos do pluggto como atributos para serem salvos
        // marca
        foreach ($array_product['attributes'] as $attribute) {
            try {
                if (isset($attribute['code'])) {
                    $this->attributeCode = null;

                    if (isset($attribute['value']['code'])) {
                        $optionId = $this->getOptionId($attribute['code'], $attribute['value']['code'], $simple);
                    }

                    if (!empty($optionId) && $this->attributeCode != 'sku') {
                        if (!empty($this->attributeCode)) {
                            $this->setdata[$this->attributeCode] = (int)$optionId;
                        } else {
                            $this->setdata[$attribute['code']] = (int)$optionId;
                        }
                        $allowedAttributesToCreateConfigurable = explode(',', $this->configs['marketplace']['allowed_configurable_attributes']);

                        $newAllowToLowCase = array();

                        foreach ($allowedAttributesToCreateConfigurable as $allowedAtt) {
                            $newAllowToLowCase[] = strtolower($allowedAtt);
                        }

                        if ($simple && in_array(strtolower($this->sanitize($attribute['code'])), $newAllowToLowCase)) {
                            $this->tovar[$i] = array(
                                'label' => $attribute['value']['label'], //attribute label
                                'attribute_id' => $this->attributeId, //attribute ID of attribute 'color' in my store
                                'value_index' => $optionId, //value of 'Green' index of the attribute 'color'
                                'is_percent' => '0', //fixed/percent price for this option
                                'pricing_value' => '0.00'
                            );

                            $i++;
                        }
                    }
                }
            } catch (\Exception $e) {
            }
        }
    }

    public function getProductCategoriesId($array_product)
    {
        $category = array();
        if (isset($array_product['categories']) && is_array($array_product['categories'])) {
            foreach ($array_product['categories'] as $categories) {
                $ids = Mage::getModel('pluggto/category')->getCategoryByNameOrNew($categories);
                if (is_array($ids)) {
                    foreach ($ids as $id) {
                        $category[] = $id;
                    }
                }
            }

            if (!empty($category)) {
                return $category;
            }
        }
    }

    public function setProductStock($product, $array_product, $simple = false)
    {
        $qtd = null;

        // seta a quantidade
        if (isset($array_product['quantity'])) {
            $qtd = $array_product['quantity'];
        }

        // if quantity is equal a zero, should return
        if ($qtd == null) {
            $qtd = 0;
        }

        if ($product->getEntityId() != null) {
            $stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product);

            $stockItemData = $stockItem->getData();
            if (empty($stockItemData) || (isset($stockItemData[0]) && empty($stockItemData[0]))) {
                // Create the initial stock item object
                $stockItem->setManageStock(1);

                if ($qtd > 0 || $product->getTypeId() == 'configurable' || $product->getTypeId() == 'grouped' || $product->getTypeId() == 'bundle') {
                    $stockItem->setIsInStock(1);
                } else {
                    $stockItem->setIsInStock(0);
                }

                if ($stockItem->getUseConfigManageStock() == null) {
                    $stockItem->setUseConfigManageStock(0);
                }

                $stockItem->setStockId(1);

                if ($stockItem->getProductId() == null) {
                    $stockItem->setProductId($product->getEntityId());
                }

                if ($product->getTypeId() == 'simple') {
                    $stockItem->setQty($qtd);
                }

                $this->lockSave();
                $stockItem->save();
                $this->unlockSave();

                Mage::getSingleton('core/session')->setPluggToNotSave(0);
                Mage::getSingleton('core/session')->setPluggToNotSaveStock(0);

                // Init the object again after it has been saved so we get the full object
                $stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product);
            }

            // Set the quantity
            if ($qtd > 0 || $product->getTypeId() == 'configurable') {
                $stockItem->setIsInStock(1);
            } else {
                $stockItem->setIsInStock(0);
            }

            $stockItem->setQty($qtd);

            $bundleProductIds = Mage::getResourceSingleton('bundle/selection')
                ->getParentIdsByChild($product->getEntityId());

            $groupedProductIds = Mage::getResourceSingleton('catalog/product_link')
                ->getParentIdsByChild($product->getEntityId(), Mage_Catalog_Model_Product_Link::LINK_TYPE_GROUPED);

            $this->lockSave();
            $stockItem->save();
            $this->unlockSave();

            if (!empty($bundleProductIds)) {
                foreach ($bundleProductIds as $bundle) {
                    $product = Mage::getModel('catalog/product');
                    $export = Mage::getModel('pluggto/export');

                    $export->exportProductToQueue($product->load($bundle));
                }
            } else if (!empty($groupedProductIds)) {
                foreach ($groupedProductIds as $grouped) {
                    $product = Mage::getModel('catalog/product');
                    $export = Mage::getModel('pluggto/export');
                    $export->exportProductToQueue($product->load($grouped));
                }
            }
        }
    }

    public function saveProductPrice($product, $array_product)
    {
        $save = false;

        if (isset($array_product['price']) && $array_product['price'] > 0 && $product->getPrice() != $array_product['price']) {
            $product->setPrice($array_product['price']);
            $save = true;
        }

        if (isset($array_product['special_price']) && $array_product['special_price'] > 0 &&  $product->getSpecialPrice() != $array_product['special_price']) {
            $product->setSpecialPrice($array_product['special_price']);
            $save = true;
        }

        if ($save) {
            $this->lockSave();
            $product->save();
            $this->unlockSave();
        }
    }

    public function saveSimpleProduct($array_product, $parent = false)
    {
        if (Mage::getStoreConfig('pluggto/marketplace/save_external_products') == true && (!empty($array_product['supplier_id']) ||  (isset($parent['supplier_id']) && !empty($parent['supplier_id'])))) {
            $allowsaveBecauseIsExternal = true;
        } else {
            $allowsaveBecauseIsExternal = false;
        }

        if (Mage::getStoreConfig('pluggto/products/no_update') && !$allowsaveBecauseIsExternal) {
            return;
        }

        $save = false;
        $this->setdata = array();

        $product = $this->carregaProduto($array_product);

        if ($product->getEntityId() != null && $product->getEntityId() != '') {
            $new = false;
        } else {
            $new = true;
        }

        // nao atualiza produtos de marketplaces se forem antigos e estiver marcado para nao atualizar nada
        if (Mage::getStoreConfig('pluggto/products/no_update') == true && !$new) {
            return;
        }

        //  se o produto está marcado para ser exportado para plugg.to = 0 nao atualiza nenhuma informacao na loja
        $exportToPluggto =  $product->getExportPluggto();

        if ($exportToPluggto == false && !$new) {
            return;
        }

        // atualiza apenas estoque de produtos de terceiros já antigos
        if (Mage::getStoreConfig('pluggto/products/only_qtd') && !$new) {
            // se for produto de terceiro, atualiza preço também
            if ($allowsaveBecauseIsExternal) {
                // salva preço
                $this->saveProductPrice($product, $array_product);
            }

            $allowsaveBecauseIsExternal = false;
        }

        if (!$product) {
            $product = Mage::getModel('catalog/product');
            $configs = $this->getConfig();
            $storeView = $configs['products']['product_store_default'];
            if (!$storeView) {
                $product->setStoreId($storeView);
            }
        }

        // verifica se só deve ser atualizado quantidade
        if (Mage::getStoreConfig('pluggto/products/only_qtd') && !$allowsaveBecauseIsExternal) {
            if ($product->getEntityId() != null && $product->getEntityId() != '') {
                // salva stock
                $this->setProductStock($product, $array_product);

                return;
            } else if (!$allowsaveBecauseIsExternal) {
                return;
            }
        }

        if ($product->getPluggtoId() != $array_product['id']) {
            $this->setdata['pluggto_id'] = $array_product['id'];
            $save = true;
        }

        if (isset($array_product['timestamp']) && $product->getPluggtoTime() != $array_product['timestamp']) {
            $this->setdata['pluggto_time'] = $array_product['timestamp'];
            $save = true;
        }

        if (isset($array_product['sku']) && $product->getSku() != $array_product['sku']) {
            $this->setdata['sku'] = $array_product['sku'];
            $save = true;
        }

        if (isset($array_product['name']) && $product->getName() != $array_product['name']) {
            $this->setdata['name'] = $array_product['name'];
            $save = true;
        }

        $descriptionField = Mage::getStoreConfig('pluggto/fields/description');

        if (empty($descriptionField)) {
            $descriptionField = 'description';
        }

        $productData = $product->getData();

        if (isset($array_product['supplier_id']) && !empty($array_product['supplier_id'])) {
            $this->setdata['seller_id'] = $array_product['supplier_id'];
        } else if (isset($array_product['stock_code']) && !empty($array_product['stock_code'])) {
            $this->setdata['seller_id'] = $array_product['stock_code'];
        }

        if (isset($array_product['description']) && isset($productData[$descriptionField]) && $productData[$descriptionField] != $array_product['description']) {
            $this->setdata[$descriptionField] = $array_product['description'];
            $save = true;
        } else if (isset($array_product['description'])) {
            $this->setdata[$descriptionField] = $array_product['description'];
        } else {
            $this->setdata[$descriptionField] = $array_product['name'];
        }

        if (isset($array_product['description']) && !empty($array_product['description'])) {
            $this->setdata['short_description'] = $array_product['description'];
        }

        if (isset($array_product['price']) && $product->getPrice() != $array_product['price']) {
            $this->setdata['price'] = $array_product['price'];
            $save = true;
        } elseif (($product->getPrice() == '' || $product->getPrice() == null)) {
            $this->setdata['price'] = $this->price;
            $save = true;
        }

        if (isset($array_product['special_price']) && $product->getSpecialPrice() != $array_product['special_price']) {
            $this->setdata['special_price'] = $array_product['special_price'];
            $save = true;
        } elseif (($product->getSpecialPrice() == '' || $product->getSpecialPrice() == null)) {
            $this->setdata['special_price'] = $this->price;
            $save = true;
        }

        if ($product->getEntityId() == null || $product->getEntityId() == '') {
            // informações só do Magento
            $this->setdata['status'] = Mage_Catalog_Model_Product_Status::STATUS_ENABLED;
            $this->setdata['type_id'] = Mage_Catalog_Model_Product_Type::TYPE_SIMPLE;
            $this->setdata['tax_class_id'] = 0;
            $this->setdata['attribute_set_id'] = $this->getAttributeSetId();

            // assign product to the default website
            $this->setdata['website_ids'] = $this->getWebSites();

            if (isset($array_product['categories']) && !empty($array_product['categories'])) {
                // categorias do produto
                $this->setdata['category_ids'] = $this->getProductCategoriesId($array_product);
            }

            $configs = $this->getConfig();
            foreach ($configs['fields'] as $key => $value) {
                if (!empty($value)) {
                    if ($key != 'width' && $key != 'height' && $key != 'length' && $key != 'weight') {
                        if (isset($array_product[$key])) {
                            $array_product['attributes'][] = array('code' => $value, 'label' => $value, 'value' => array('code' => $array_product[$key], 'value' => $array_product[$key]));
                            // $this->setdata[$value] = $array_product[$key];
                        }
                    } else {
                        if (isset($array_product['dimension'][$key])) {
                            $this->setdata[$value] = $array_product['dimension'][$key];
                        }
                    }
                }
            }

            // informações de produtos variaveis
            if ($parent) {
                $this->setdata['visibility'] = Mage_Catalog_Model_Product_Visibility::VISIBILITY_NOT_VISIBLE;
                $this->saveProductAttributes($array_product);
            } else {
                $this->setdata['visibility'] = Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH;
                $this->saveProductAttributes($array_product);
            }
        }

        if ($save) {
            $product->addData($this->setdata);
            $this->lockSave();
            $product->save();
            $this->unlockSave();
        }

        if ($parent) {
            foreach ($array_product['attributes'] as $attribute) {
                if (isset($attribute['code'])) {
                    $this->simpleProduts[$product->getEntityId()] = $this->tovar;
                }
            }
        }

        $this->setProductStock($product, $array_product);
        $this->saveImage($product, $array_product, $parent);
    }

    public function sanitize($code)
    {
        $code = str_replace(' ', '', $code);
        $code = str_replace('/', '', $code);
        $code = str_replace('\\', '', $code);
        $code = str_replace('_', '', $code);
        $code = str_replace('.', '', $code);
        $repl = explode(" ", "a A e E i I o O u U n N - ");
        $repl[] = "";
        $repl[] = "";
        $code = preg_replace(array(
            "/(á|à|ã|â|ä)/",
            "/(Á|À|Ã|Â|Ä)/",
            "/(é|è|ê|ë)/",
            "/(É|È|Ê|Ë)/",
            "/(í|ì|î|ï)/",
            "/(Í|Ì|Î|Ï)/",
            "/(ó|ò|õ|ô|ö)/",
            "/(Ó|Ò|Õ|Ô|Ö)/",
            "/(ú|ù|û|ü)/",
            "/(Ú|Ù|Û|Ü)/",
            "/(ñ)/",
            "/(Ñ)/",
            "/(&)/",
            '/;/'
        ), $repl, $code);

        return $code;
    }

    public function saveConfigurableProduct($array_product)
    {
        $save = false;

        if (Mage::getStoreConfig('pluggto/marketplace/save_external_products') == true && !empty($array_product['supplier_id'])) {
            $allowsaveBecauseIsExternal = true;
        } else {
            $allowsaveBecauseIsExternal = false;
        }

        if (Mage::getStoreConfig('pluggto/products/no_update') && !$allowsaveBecauseIsExternal) {
            return;
        }

        if (isset($array_product['price'])) {
            $this->price = $array_product['price'];
        }

        $this->attributeSetId = null;
        $this->attributesIds = array();
        $this->categories = $array_product['categories'];

        // salva primeiro os produtos filhos
        foreach ($array_product['variations'] as $variation) {
            $this->saveSimpleProduct($variation, $array_product);
        }

        $product = $this->carregaProduto($array_product);

        if ($product->getEntityId() != null && $product->getEntityId() != '') {
            $new = false;
        } else {
            $new = true;
        }

        // nao atualiza produtos de marketplaces se forem antigos e estiver marcado para nao atualizar nada
        if (Mage::getStoreConfig('pluggto/products/no_update') == true && !$new) {
            return;
        }

        // atualiza apenas estoque de produtos de terceiros já antigos
        if (Mage::getStoreConfig('pluggto/products/only_qtd') && !$new) {
            // se for produto de terceiro, atualiza preço também
            if ($allowsaveBecauseIsExternal) {
                // salva preço
                $this->saveProductPrice($product, $array_product);
            }

            $allowsaveBecauseIsExternal = false;
        }

        if (Mage::getStoreConfig('pluggto/products/only_qtd') && !$allowsaveBecauseIsExternal) {
            if ($product->getEntityId() != null && $product->getEntityId() != '') {
                $this->setProductStock($product, $array_product);
                return;
            } else if (!$allowsaveBecauseIsExternal) {
                return;
            }
        };

        $product->setSpecialPrice($array_product['special_price']);

        if ($product == null) {
            // Serve para ZERAR objeto Product, sem isso não funciona
            $product = Mage::getModel('catalog/product');
        }

        $this->setdata = array();

        // informações que devem ser sincronizadas
        if (isset($array_product['sku']) && $product->getSku() != $array_product['sku']) {
            $product->setSku($array_product['sku']);
            $save = true;
        }

        // informações que devem ser sincronizadas
        if (isset($array_product['name']) && $product->getName() != $array_product['name']) {
            $product->setName($array_product['name']);
            $save = true;
        }

        $descriptionField = Mage::getStoreConfig('pluggto/fields/description');
        $productData = $product->getData();

        if (empty($descriptionField)) {
            $descriptionField = 'description';
        }

        if (isset($array_product['supplier_id']) && !empty($array_product['supplier_id'])) {
            $product->setSellerId($array_product['supplier_id']);
        } else if (isset($array_product['stock_code']) && !empty($array_product['stock_code'])) {
            $product->setSellerId($array_product['stock_code']);
        }

        // informações que devem ser sincronizadas
        if (isset($array_product['description']) && isset($productData[$descriptionField]) && $productData[$descriptionField] != $array_product['description']) {
            $product->addData(array($descriptionField => $array_product['description']));
            ///     $product->setDescription($array_product['description']);
            $save = true;
        } elseif (!isset($productData[$descriptionField])) {
            $product->addData(array($descriptionField => $array_product['description']));
        }

        if (isset($array_product['description']) && !empty($array_product['description'])) {
            $product->setShortDescription($array_product['description']);
        }

        // informações que devem ser sincronizadas
        if (isset($array_product['dimension']['weight']) && $product->getWeight() != $array_product['dimension']['weight']) {
            $product->setWeight($array_product['dimension']['weight']);
            $save = true;
        }

        // informações que devem ser sincronizadas
        if (isset($array_product['price']) && $product->getPrice() != $array_product['price']) {
            $product->setPrice($array_product['price']);
            $save = true;
        } elseif ($product->getPrice() == null) {
            $product->setPrice(0.00);
            $save = true;
        }

        $new = false;
        $entityId = $product->getEntityId();

        if (empty($entityId)) {
            $save = true;
            $new = true;
            // assign product to the default website

            $catsIds = $this->getProductCategoriesId($array_product);

            // categorias do produto
            if (!empty($catsIds)) {
                $product->setCategoryIds($catsIds);
            }

            $product->setStatus(Mage_Catalog_Model_Product_Status::STATUS_ENABLED);
            $product->setCreatedAt(strtotime('now'));
            $product->setTypeId(Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE);
            $product->setAttributeSetId($this->getAttributeSetId());
            $product->setVisibility(Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH);
            $product->setTaxClassId(0);
            $product->setMsrpEnabled(1);
            $product->setWebsiteIds($this->getWebSites());

            if (!empty($this->attributesIds)) {
                foreach ($this->attributesIds as $attrCode) {
                    $super_attribute = Mage::getModel('eav/entity_attribute')->load($attrCode);
                    $configurableAtt = Mage::getModel('catalog/product_type_configurable_attribute')->setProductAttribute($super_attribute);

                    $newAttributes[] = array(
                        'id'             => $configurableAtt->getId(),
                        'label'          => $configurableAtt->getLabel(),
                        'position'       => $super_attribute->getPosition(),
                        'values'         => $configurableAtt->getPrices() ? $product->getPrices() : array(),
                        'attribute_id'   => $super_attribute->getId(),
                        'attribute_code' => $super_attribute->getAttributeCode(),
                        'frontend_label' => $super_attribute->getFrontend()->getLabel(),
                    );
                }

                $product->getTypeInstance()->setUsedProductAttributeIds($this->attributesIds, $product);
                $configurableAttributesData = $product->getTypeInstance()->getConfigurableAttributesAsArray();
                $product->setCanSaveConfigurableAttributes(true);
                $product->setConfigurableAttributesData($configurableAttributesData);
            }

            $product->setConfigurableProductsData($this->simpleProduts);
            $configs = $this->getConfig();

            foreach ($configs['fields'] as $key => $value) {
                if (!empty($value)) {
                    if ($key != 'width' && $key != 'height' && $key != 'length' && $key != 'weight') {
                        if (isset($array_product[$key])) {
                            $array_product['attributes'][] = array('code' => $value, 'label' => $value, 'value' => array('code' => $array_product[$key], 'value' => $array_product[$key]));
                        } //$varProduct[$value] = $array_product[$key];
                    } else {
                        if (isset($array_product['dimension'][$key])) $varProduct[$value] = $array_product['dimension'][$key];
                    }
                }
            }

            if (isset($varProduct)) {
                $product->addData($varProduct);
            }
        }

        $this->setdata = array();
        $this->saveProductAttributes($array_product, false);

        $product->addData($this->setdata);

        if ($save) {
            $this->lockSave();
            $product->save();
            $this->unlockSave();
        }

        // salvar imagem
        $this->saveImage($product, $array_product);

        // salvar estoque
        if ($new) {
            $this->setProductStock($product, $array_product);
        }

        if ($new) {
            $resource = Mage::getSingleton('core/resource');
            $writeConnection = $resource->getConnection('core_write');
            $tableName = $resource->getTableName('catalog_product_super_attribute');

            foreach ($this->attributesIds as $id) {
                $query = "Insert into {$tableName}  (product_id,attribute_id) VALUES (" . $product->getEntityId() . "," . $id . ")";
                $writeConnection->query($query);
            }

            $newids = array();
            foreach ($this->simpleProduts as $key => $value) {
                $newids[$key] = 1;
            }

            Mage::getResourceModel('catalog/product_type_configurable')->saveProducts($product, array_keys($newids));
        }
    }

    public function saveProduct($array_product)
    {
        // check if is simple or configurable
        if (isset($array_product['variations']) && count($array_product['variations']) > 0) {
            // é configurabel
            $this->saveConfigurableProduct($array_product);
        } else {
            // é simples
            $this->saveSimpleProduct($array_product);
        }
    }

    public function getGroupedPrice($prod)
    {
        $totalprice = 0;
        $associatedProducts = $prod->getTypeInstance(true)->getAssociatedProducts($prod);

        foreach ($associatedProducts as $option) {
            if ($option->getQty() > 0) {
                $qtd = $option->getQty();
            } else {
                $qtd = 1;
            }

            $rowprice = $this->getProductPriceRule($option) * $qtd;
            $totalprice += $rowprice;
        }

        $data['price'] = $totalprice;
        $data['special_price'] = $totalprice;

        return $data;
    }

    public function getBundlePrice($product)
    {
        $price = $product->getPrice();
        $special_rice = $product->getPrice();

        if (isset($price) && !empty($special_rice)) {
            $data['price'] = $this->numberFormat($this->getPriceWithTax($product->getPrice(), $product));
            $specialPrice = $this->getProductPriceRule($product);

            // check if special price is greather than zero and if different the price (if is the same is returning the price because de dete
            if ($specialPrice > 0 && $specialPrice != $data['price']) {
                $data['special_price'] =  $data['price'] * ($product->getSpecialPrice() / 100);
            } else {
                $data['special_price'] =  $data['price'];
            }

            return $data;
        } else {
            $selectionCollection = $product->getTypeInstance(true)->getSelectionsCollection($product->getTypeInstance(true)->getOptionsIds($product), $product);
            $totalprice = 0;
            $specialTotalprice = 0;
            foreach ($selectionCollection as $option) {
                if ($option->getSelectionQty() > 0) {
                    $qtd = $option->getSelectionQty();
                } else {
                    $qtd = 1;
                }

                $rowprice = $option->getPrice();

                $specialTotalprice += $this->getProductPriceRule($option) * $qtd;;
                $totalprice += $rowprice;
            }

            if ($product->getSpecialPrice() > 0) {
                $specialTotalprice *= $product->getSpecialPrice() / 100;
            }

            $data['price'] = $totalprice;

            if (isset($specialTotalprice)) {
                // só coloca no objeto para ser corretamente validado
                $product->setPrice($totalprice);
                $product->setSpecialPrice($specialTotalprice);

                // verifica se preço especial pode ser enviado
                $specialPrice = $this->getProductPriceRule($product);

                if ($specialPrice > 0) {
                    $data['special_price'] = $specialPrice;
                } else {
                    $data['special_price'] = $totalprice;
                }
            }

            return $data;
        }
    }

    public function getGroupedQuantity($prod)
    {
        $associatedProducts = $prod->getTypeInstance(true)->getAssociatedProducts($prod);
        $lastqtd = 99999999;

        foreach ($associatedProducts as $option) {
            if ($option->getQty() > 0) {
                $default = $option->getQty();
            } else {
                $default = 1;
            }

            $stockItem = $option->getStockItem();
            if (is_object($stockItem)) {
                $gproduct = Mage::getModel('catalog/product')->load($stockItem->getProductId());
                $this->weight += ($gproduct->getWeight() * $option->getQty());
                $stock = $stockItem->getQty();
                $qtd_to_send = $stock / $default;

                if ($qtd_to_send < $lastqtd) {
                    $lastqtd = $qtd_to_send;
                }
            }
        }

        return (int)$lastqtd;
    }

    public function getBundleQuantity($prod)
    {
        $selectionCollection = $prod->getTypeInstance(true)->getSelectionsCollection($prod->getTypeInstance(true)->getOptionsIds($prod), $prod);
        $lastqtd = 99999999;

        if (!$selectionCollection || $selectionCollection->count() === 0) {
            return 0;
        }
        foreach ($selectionCollection as $option) {
            $stock = $option->getStockItem();

            if (is_object($stock)) {
                $gproduct = Mage::getModel('catalog/product')->load($stock->getProductId());
                $this->weight += ($gproduct->getWeight() * $option->getSelectionQty());
                $stockqtd = $stock->getQty();

                if ($stockqtd > 0) {
                    (int)$qtd_to_send = (int)$stockqtd / (int)$option->getSelectionQty();
                } else {
                    (int)$qtd_to_send = 0;
                }

                if ($qtd_to_send < $lastqtd) {
                    $lastqtd = $qtd_to_send;
                }
            }
        }

        return (int)$lastqtd;
    }

    public function formatGroupedToPluggto($product, &$data)
    {
        $configs = $this->getConfig();
        $storeView = $configs['products']['product_store_default'];
        $send_disable_product = Mage::getStoreConfig('pluggto/products/send_disable_product');

        $allaVaris = array();
        $childProducts = $product->getTypeInstance()->getUsedProducts();

        $groupedSkus = array();
        $groupedName = array();

        foreach ($childProducts as $Msubproduct) {
            $subproduct = Mage::getModel('catalog/product');

            if (!empty($storeView)) {
                $subproduct->setStoreId($storeView);
            }

            $subproduct->load($Msubproduct->getEntityId());
            $exportToPluggTo = $subproduct->getExportPluggto();

            // verifia se produtos desabilitados
            if (!$send_disable_product && $subproduct->getStatus() != Mage_Catalog_Model_Product_Status::STATUS_ENABLED) {
                continue;
            }

            // nao exporta variacoes marcadas para nao exportar
            if ($exportToPluggTo == false) {
                continue;
            }

            $productInPluggTo = $this->getProductInPluggto($subproduct->getSku());

            if ($productInPluggTo) {
                if (!in_array($productInPluggTo['sku'], $groupedSkus)) {
                    $groupedSkus[] =  $productInPluggTo['sku'];
                    $groupedName[] = array('sku' => $productInPluggTo['sku'], 'name' => $productInPluggTo['name']);
                }
            }
        }

        if (!empty($groupedName)) {
            $data['grouped_skus'] = $groupedName;
        }
    }

    public function formateToPluggto($product, $old = null)
    {
        $this->weight = 0;

        $product = $this->setProductStore($product);
        $Productsconfigs = Mage::getStoreConfig('pluggto/products');
        $descriptionField = Mage::getStoreConfig('pluggto/fields/description');
        $productData = $product->getData();

        if (empty($descriptionField)) {
            $descriptionField = 'description';
        }

        if (!$old || $Productsconfigs['update_title']) {
            $data['name'] = trim($product->getName());
        }

        if ((!$old || $Productsconfigs['update_description']) && isset($productData[$descriptionField])) {
            $data['description'] = Mage::helper('cms')->getBlockTemplateProcessor()->filter(trim($productData[$descriptionField]));
        }

        $data['external'] = $product->getEntityId();

        if ($product->getSku() != null) {
            $data['sku'] = trim($product->getSku());
        }

        /**
         * Verifica a configuração do cliente para validar se ele quer que atualize o preço do produto
         */
        if (!$old || $Productsconfigs['update_price']) {
            $data['price'] = $this->numberFormat($this->getPriceWithTax($product->getPrice(), $product));
        }

        /**
         * Verifica a configuração do cliente para validar se ele quer que atualize o preço especial do produto
         */
        if ($Productsconfigs['update_special_price']) {
            $data['special_price'] = $this->numberFormat($this->getPriceWithTax($this->getProductPriceRule($product), $product));
        }

        if (!$old || $Productsconfigs['update_price']) {
            $data['price'] = $this->numberFormat($this->getPriceWithTax($product->getPrice(), $product));
        }

        if ($Productsconfigs['update_special_price']) {
            $data['special_price'] = $this->numberFormat($this->getPriceWithTax($this->getProductPriceRule($product), $product));
        }

        $productUrl = $product->getProductUrl();

        $data['link'] = trim($productUrl);
        /**
         * Verifica a configuração do cliente para validar se ele quer que atualize as fotos do produto
         */

        if (!$old || $Productsconfigs['update_photos']) {
            if (isset($old['photos'])) {
                $fotos = $this->getProducImages($product, $old['photos']);
            } else {
                $fotos = $this->getProducImages($product);
            }

            // images
            if (!empty($fotos)) {
                $data['photos'] = $fotos;
            }
        }

        //  stock
        $stock = $this->getProducQtd($product);

        // check if should sent 0 when product has no quantity
        $chanceDisable = Mage::getStoreConfig('pluggto/products/disable_product');

        if (!$old || $Productsconfigs['update_quantity']) {
            if ($chanceDisable) {
                if ($productData['status'] == Mage_Catalog_Model_Product_Status::STATUS_ENABLED) {
                    $data['quantity'] = $stock['quantity'];
                } else {
                    $data['quantity'] = 0;
                }
            } else {
                $data['quantity'] = $stock['quantity'];
            }
        }

        // check if should consider stock availability
        $considerAvailability = Mage::getStoreConfig('pluggto/products/stock_availability');

        if (isset($data['quantity']) && $stock['is_in_stock'] == 0 && $considerAvailability) {
            $data['quantity'] = 0;
        }

        if (!$old || $Productsconfigs['update_categories']) {
            // get categories
            $categories = $product->getCategoryCollection();
            $categoModel = Mage::getSingleton('pluggto/category');
            $j = 0;

            foreach ($categories as $category) {
                if (is_object($category) && $category->getEntityId() != null && $category->getEntityId() != 1) {
                    $CategoryTree = array();
                    $category = Mage::getModel('catalog/category')->load($category->getEntityId());
                    $paths = explode('/', $category->getPath());

                    if (is_array($paths)) {
                        foreach ($paths as $categ) {
                            if ($categ != 1) {
                                if (isset($this->categoryArray[$categ])) {
                                    $CategoryTree[] = $this->categoryArray[$categ]->getName();
                                } else {
                                    $category = Mage::getModel('catalog/category')->load($categ);
                                    if ($category->getEntityId() != null) {
                                        $this->categoryArray[$category->getEntityId()] = $category;
                                        $CategoryTree[] = $category->getName();
                                    }
                                }
                            }
                        }
                    }

                    $fullcategory = implode(' > ', $CategoryTree);
                    if (!empty($fullcategory)) {
                        $data['categories'][$j]['name'] = $fullcategory;
                        $j++;
                    }
                }
            }
        }

        $configs = $this->getConfig();
        // $allowedAttributes = explode(',', $configs['fields']['allowed_attributes']);
        
        $allowedAttributes = explode(',', isset($configs['fields']['allowed_attributes'])
            ? $configs['fields']['allowed_attributes']
            : ''
        );

        // variations
        if ($product->getTypeId() == 'configurable') {
            $shouldGroup = false;
            if (isset($Productsconfigs['send_gruped_products']) && $Productsconfigs['send_gruped_products'] == 1 && $Productsconfigs['gruped_products_visibility'] != '') {
                $prodVisibility = $product->getVisibility();
                if ($prodVisibility == $Productsconfigs['gruped_products_visibility']) {
                    $this->formatGroupedToPluggto($product, $data);
                    $shouldGroup = true;
                }
            }

            if ($shouldGroup == false) {
                $allaVaris = array();
                $childProducts = $product->getTypeInstance()->getUsedProducts();
                $vararray = array();

                if (isset($old['variations'])) {
                    $variacoesBySku = array();

                    foreach ($old['variations'] as $oldvar) {
                        $allaVaris[] = trim($oldvar['sku']);
                        $variacoesBySku[trim($oldvar['sku'])] = $oldvar;

                        if (isset($oldvar['attributes']) && is_array($oldvar['attributes'])) {
                            foreach ($oldvar['attributes'] as $attributes) {
                                if (!in_array($attributes['code'], $vararray)) {
                                    $vararray[trim($oldvar['sku'])][] = $attributes['code'];
                                }
                            }
                        }
                    }
                }

                $k = 0;
                $configs = $this->getConfig();
                // $storeView = $configs['products']['product_store_default'];
                
                $storeView = isset($configs['products']['product_store_default'])
                    ? $configs['products']['product_store_default']
                    : null;
                    
                $send_disable_product = Mage::getStoreConfig('pluggto/products/send_disable_product');

                foreach ($childProducts as $Msubproduct) {
                    $subproduct = Mage::getModel('catalog/product');

                    if (!empty($storeView)) {
                        $subproduct->setStoreId($storeView);
                    }

                    $subproduct->load($Msubproduct->getEntityId());
                    $exportToPluggTo = $subproduct->getExportPluggto();

                    // verifia se produtos desabilitados
                    if (!$send_disable_product && $subproduct->getStatus() != Mage_Catalog_Model_Product_Status::STATUS_ENABLED) {
                        continue;
                    }

                    // nao exporta variacoes marcadas para nao exportar
                    if ($exportToPluggTo == false) {
                        continue;
                    }

                    if (!isset($variacoesBySku[trim($subproduct->getSku())])) {
                        $productInPluggTo = $this->getProductInPluggto($subproduct->getSku());

                        // check if sku is not in another product, if yes, skip this one
                        if ($productInPluggTo && $productInPluggTo['sku'] != $product->getSku()) {
                            continue;
                        } else {
                            $data['variations'][$k]['sku'] = $subproduct->getSku();
                        }
                    } else {
                        $data['variations'][$k]['sku'] = $subproduct->getSku();
                    }

                    $stock = $this->getProducQtd($subproduct);

                    // first try to find by sky that is already setuped in the product
                    if (isset($variacoesBySku[trim($subproduct->getSku())])) {
                        $data['variations'][$k]['sku'] = trim($subproduct->getSku());
                        $sku = $subproduct->getSku();
                        unset($allaVaris[array_search(trim($sku), $allaVaris)]);
                        // then try to find by sku not setup
                    }

                    if (!$old || $Productsconfigs['update_title']) {
                        $data['variations'][$k]['name'] = $subproduct->getName();
                    }

                    $data['variations'][$k]['external'] = $subproduct->getEntityId();

                    // check if should sent 0 when product has no quantity
                    $chanceDisable = Mage::getStoreConfig('pluggto/products/disable_product');

                    if (!$old || $Productsconfigs['update_quantity']) {
                        if ($chanceDisable) {
                            if ($subproduct->getStatus() == Mage_Catalog_Model_Product_Status::STATUS_ENABLED) {
                                $data['variations'][$k]['quantity'] = $stock['quantity'];
                            } else {
                                $data['variations'][$k]['quantity'] = 0;
                            }
                        } else {
                            $data['variations'][$k]['quantity'] = $stock['quantity'];
                        }

                        // check if should consider stock availability
                        $considerAvailability = Mage::getStoreConfig('pluggto/products/stock_availability');
                        if (isset($data['variations'][$k]['quantity']) &&  $subproduct->getIsInStock() == 0 && $considerAvailability) {
                            $data['variations'][$k]['quantity'] = 0;
                        }
                    }

                    $varprice = $this->numberFormat($this->getPriceWithTax($subproduct->getPrice(), $subproduct));
                    $varspecialprice = $this->numberFormat($this->getPriceWithTax($this->getProductPriceRule($subproduct), $subproduct));

                    if (!$old || $Productsconfigs['update_price']) {
                        $data['variations'][$k]['price'] = $varprice;
                    }

                    if ($Productsconfigs['update_special_price']) {
                        $data['variations'][$k]['special_price'] = $varspecialprice;
                    }

                    if ($Productsconfigs['update_photos']) {
                        if (isset($variacoesBySku[$subproduct->getSku()]['photos'])) {
                            $data['variations'][$k]['photos'] = $this->getProducImages($subproduct, $variacoesBySku[$subproduct->getSku()]['photos']);
                        } else {
                            $data['variations'][$k]['photos'] = $this->getProducImages($subproduct);
                        }
                    }

                    $variacao = array();
                    $this->insertPluggAttributes($subproduct, $variacao, $old);

                    $data['variations'][$k] = array_merge($data['variations'][$k], $variacao);

                    if (!$old || $Productsconfigs['update_attributes']) {
                        // changes statys
                        $attributes = $subproduct->getAttributes();
                        $attributeArray = array();

                        foreach ($attributes as $attribute) {
                            $toattribute = array();
                            $code = $attribute->getAttributeCode();

                            if (in_array($code, $allowedAttributes)) {
                                $code = $attribute->getAttributeCode();

                                if (in_array($code, $allowedAttributes)) {
                                    $value = $attribute->getFrontend()->getValue($subproduct);
                                    $name = $attribute->getFrontend()->getLabel($subproduct);

                                    if (!empty($value) && !empty($name) && !empty($code)):
                                        $toattribute['label'] = $name;
                                        $toattribute['code'] = $code;
                                        $toattribute['value']['code'] = $value;
                                        $toattribute['value']['label'] = $value;
                                        $attributeArray[] = $toattribute;
                                    endif;
                                }

                                $data['variations'][$k]['attributes'] = $attributeArray;
                            }
                        }
                    }
                    $k++;
                }

                // variações para excluir
                foreach ($allaVaris as $all) {
                    $data['variations'][$k]['sku'] = trim($all);
                    $data['variations'][$k]['quantity'] = 0;
                    $k++;
                }
            }
        } else if ($product->getTypeId() == 'bundle') {
            $priceData = $this->getBundlePrice($product);

            if (!$old || $Productsconfigs['update_price']) {
                $data['price'] = $priceData['price'];
            }

            if ($Productsconfigs['update_special_price']) {
                $data['special_price'] = $priceData['special_price'];
            }

            if ($Productsconfigs['update_quantity']) {
                $data['quantity'] = $this->getBundleQuantity($product);
            }

            $SubWeight = $this->weight;
        } else if ($product->getTypeId() == 'grouped') {
            $priceData = $this->getGroupedPrice($product);

            if (!$old || $Productsconfigs['update_price']) {
                $data['price'] = $priceData['price'];
            }

            if ($Productsconfigs['update_special_price']) {
                $data['special_price'] = $priceData['special_price'];
            }

            if ($Productsconfigs['update_quantity']) {
                $data['quantity'] = $this->getGroupedQuantity($product);
            }
        } else {
            // case is not a configurable product
            if (isset($old['variations']) && is_array($old['variations'])) {
                $ki = 0;
                foreach ($old['variations'] as $vari) {
                    $data['variations'][$ki]['sku'] = $vari['sku'];
                    $data['variations'][$ki]['remove'] = true;
                    $ki++;
                }
            }
        }

        if (!$old || $Productsconfigs['update_attributes']) {
            // get attributes por parent
            $attributes = $product->getAttributes();
            $attributeArray = array();

            foreach ($attributes as $attribute) {
                $code = '';
                $toattribute = array();
                $code = $attribute->getAttributeCode();

                if (in_array($code, $allowedAttributes)) {
                    $value = $attribute->getFrontend()->getValue($product);
                    $name = $attribute->getFrontend()->getLabel($product);

                    // do something with $value here
                    if (!empty($value) && !empty($name) && !empty($code)):
                        $toattribute['label'] = $name;
                        $toattribute['code'] = $code;
                        $toattribute['value']['code'] = $value;
                        $toattribute['value']['label'] = $value;
                        $attributeArray[] = $toattribute;
                    endif;
                }
            }

            $data['attributes'] = $attributeArray;
        }

        $this->insertPluggAttributes($product, $data, $old);

        if ($Productsconfigs['update_attributes'] && (!isset($data['dimension']['weight']) || empty($data['dimension']['weight']) || $data['dimension']['weight'] == 0)) {
            $data['dimension']['weight'] = $this->weight;
        }

        if ($Productsconfigs['update_attributes'] && (!isset($data['dimension']['length']) || empty($data['dimension']['length']) || $data['dimension']['length'] == 0)) {
            $data['dimension']['length'] = $this->length;
        }

        if ($Productsconfigs['update_attributes'] && (!isset($data['dimension']['height']) || empty($data['dimension']['height']) || $data['dimension']['height'] == 0)) {
            $data['dimension']['height'] = $this->height;
        }

        if ($Productsconfigs['update_attributes'] && (!isset($data['dimension']['width']) || empty($data['dimension']['width']) || $data['dimension']['width'] == 0)) {
            $data['dimension']['width'] = $this->width;
        }

        return $data;
    }

    public function insertPluggAttributes($product, &$data, $old)
    {
        $configs = $this->getConfig();
        $pweigh = $product->getWeight();

        if ($pweigh != 0 && !empty($pweigh)) {
            $this->weight = $product->getWeight();
        }

        $magentoProductData = $product->getData();

        foreach ($magentoProductData as $key => $toReg) {
            $magentoProductData[strtolower($key)] = $toReg;
        }

        if (isset($configs['pricetable']) && !empty($configs['pricetable'])) {
            $tableData = array();

            foreach ($configs['pricetable'] as $code => $tabledata) {
                $thiscode = array();
                $thiscode['code'] = $code;

                if (empty($code)) {
                    continue;
                }

                foreach ($tabledata as $key => $attribute) {
                    $attribute = strtolower($attribute);

                    if (isset($magentoProductData[strtolower($attribute)])) {
                        if ($key == 'action') {
                            $attr = $product->getResource()->getAttribute($attribute);

                            if (is_object($attr)) {
                                $attrValue = $attr->getSource()->getOptionText($magentoProductData[$attribute]);
                            }

                            // valida que o valor é correto e aceito pela api
                            if ($attrValue == 'overwrite' || $attrValue == 'decrease' || $attrValue == 'increase') {
                                $thiscode[$key] = $attrValue;
                            }
                        } else {
                            $thiscode[$key] = $magentoProductData[$attribute];
                        }
                    }
                }

                // valida que existe uma acao
                if (isset($thiscode['action'])) {
                    $tableData[] = $thiscode;
                }
            }
        }

        /// verifica se é para enviar um preço diferente por loja
        if (isset($configs['tables_price_customization']['active']) && $configs['tables_price_customization']['active'] == 1) {
            if (!isset($tableData)) {
                $tableData = array();
            }

            //se for, navega pelas lojas
            $storesIds = $product->getStoreIds();

            foreach ($storesIds as $websiteId) {
                $tableCodePrice = Mage::app()->getStore($websiteId)->getConfig('pluggto/tables_price_customization/table_price');

                if (empty($tableCodePrice)) {
                    continue;
                }

                $modelStore = Mage::getModel('catalog/product')->setStoreId($websiteId);
                $thisStoreProduct = $modelStore->load($product->getEntityId());
                $varprice = $this->numberFormat($this->getPriceWithTax($thisStoreProduct->getPrice(), $thisStoreProduct));
                $varspecialprice = $this->numberFormat($this->getPriceWithTax($this->getProductPriceRule($thisStoreProduct), $thisStoreProduct));

                $thisTableData = array();
                $thisTableData['code'] = $tableCodePrice;
                $thisTableData['action'] = 'overwrite';
                $thisTableData['price'] = $varprice;
                $thisTableData['special_price'] = $varspecialprice;

                $tableData[] = $thisTableData;
            }
        }

        if (isset($tableData) && !empty($tableData)) {
            $data['price_table'] = $tableData;
        }
        
        if (isset($configs['fields'])) {
            foreach ($configs['fields'] as $key => $value) {
                if (isset($configs['products']['update_' . $key]) && $configs['products']['update_' . $key] == false && $old) {
                    continue;
                }

                if (!empty($value)) {
                    try {
                        $attr = $product->getResource()->getAttribute($value);
                        $attrValue = false;

                        if (is_object($attr) && isset($magentoProductData[$value])) {
                            $attrValue = $attr->getSource()->getOptionText($magentoProductData[$value]);
                        }

                        if (isset($attrValue) && $attrValue != false) {
                            $magentoProductData[$value] = $attrValue;
                        }
                    } catch (\Exception $e) {
                    }

                    if ($key == 'origin') {
                        if (isset($magentoProductData[$value])) {
                            $op = explode('-', $magentoProductData[$value]);
                            $or = trim($op[0]);
                            if ($or = '0' || $or = '1' || $or = '2') {
                                $data['origin'] = (int)$or;
                            }
                        }
                    } elseif ($key != 'width' && $key != 'height' && $key != 'length' && $key != 'weight') {
                        if (isset($magentoProductData[$value])) {
                            $data[$key] = $magentoProductData[$value];
                        }
                    } else {
                        if (isset($magentoProductData[$value])) {
                            $data['dimension'][$key] = round($magentoProductData[$value], 2);

                            if ($key == 'width') {
                                $this->width = $data['dimension'][$key];
                            } else if ($key == 'height') {
                                $this->height = $data['dimension'][$key];
                            } else if ($key == 'length') {
                                $this->length = $data['dimension'][$key];
                            } else if ($key == 'weight') {
                                $this->weight = $data['dimension'][$key];
                            }
                        }
                    }
                }
            }
        }
    }

    // insert product entity id
    // return product ready to pluggto
    public function getProductDimensions($product)
    {
        $lengthfield = Mage::getStoreConfig('pluggto/fields/length');
        $widthfield = Mage::getStoreConfig('pluggto/fields/width');
        $heightfield = Mage::getStoreConfig('pluggto/fields/height');
        $weightfield = Mage::getStoreConfig('pluggto/fields/weight');
        $productdata = $product->getData();

        $length = null;
        $width = null;
        $height = null;
        $weight = null;

        foreach ($productdata as $key => $value) {
            $length = null;
            $width = null;
            $height = null;
            $weight = null;

            if ($key == $lengthfield) {
                $length = $value;
            } elseif ($key == $widthfield) {
                $width = $value;
            } elseif ($key == $heightfield) {
                $height = $value;
            } elseif ($key == $weightfield) {
                $weight = $value;
            }
        }

        $dimension['weight'] = $weight;
        $dimension['height'] = $height;
        $dimension['lenght'] = $length;
        $dimension['width'] = $width;

        return $dimension;
    } // end formaToPluggto

    // format raw data to pluggto to
    public function getProducImages($product, $olds = false)
    {
        $media = $product->getData('media_gallery');
        $count = 0;
        $arrayphotos = array();
        $images = null;
        $configs = $this->getConfig();

        if (isset($configs['configs']['send_disable_imagem'])) {
            $senddisable = $configs['configs']['send_disable_imagem'];
        } else {
            $senddisable = 1;
        }

        if ($olds) {
            foreach ($olds as $pluggphotos) {
                if (isset($pluggphotos['external'])) {
                    $arrayphotos[$pluggphotos['external']] = $pluggphotos;
                } else {
                    $arrayphotos[] = $pluggphotos;
                }
            }
        }

        if (count($media) > 0) {
            if (isset($configs['products']['product_store_default']) && !empty($configs['products']['product_store_default'])) {
                $storeView = $configs['products']['product_store_default'];
                Mage::app()->setCurrentStore($storeView);
            }

            foreach ($media['images'] as $image) {
                $imageUrl = str_replace('https://seguro', 'http://www', $product->getMediaConfig()->getMediaUrl($image['file']));

                // 1) se possui external code não precisa fazer nada já pula para o próximo
                if (isset($arrayphotos[$imageUrl])) {
                    if (isset($image['position_default'])) {
                        $position = $image['position_default'];
                    } else {
                        $position = $image['position'];
                    }

                    // se as imagens estiveram em ordens diferentes, necessário reenviar de novo
                    if ($arrayphotos[$imageUrl]['order'] != $position) {
                        $images[$count]['url'] = (string)$imageUrl;
                        $images[$count]['external'] = (string)$imageUrl;
                        $images[$count]['order'] = $position;
                        $count++;
                    }

                    if ((isset($image['removed']) && $image['removed'] == 1) || ((!$senddisable) && isset($image['disabled']) && $image['disabled'] == 1)) {
                        // do nothing
                    } else {
                        unset($arrayphotos[$imageUrl]);
                    }

                    continue;
                }

                /* 2) tenta busca pelo nome do arquivo
                $file = $this->getImageFileNameFromMagento($product->getMediaConfig()->getMediaUrl($image['file']));
                $finded = false;

                foreach ($arrayphotos as $photo) {
                   // achou no pluggto
                    if ($finded) {
                        unset($arrayphotos[array_search($photo['url'], $arrayphotos)]);
                        break;
                    }

                }

                if ($finded) {
                    continue;
                }
                */

                // 3) Não tem no PluggTo, tem que enviar
                // tenta buscar pelo nome do arquivo
                if ($senddisable || $image['disabled'] == 0) {
                    // deve ser adicionada
                    $images[$count]['url'] = (string)$imageUrl;
                    $images[$count]['external'] = (string)$imageUrl;
                    $images[$count]['order'] = (int)$image['position'];
                    $images[$count]['name'] = (string)$image['label'];
                    $images[$count]['title'] = (string)$image['label'];
                    $images[$count]['disabled'] = (bool)$image['disabled'];

                    if ($image['file'] == $product->getThumbnail()) {
                        $images[$count]['thumb'] = (bool)true;
                    } else {
                        $images[$count]['thumb'] = (bool)false;
                    }

                    $count++;
                }
            }
        }

        if (!isset($configs['products']['allow_remove_images']) || $configs['products']['allow_remove_images']) {
            // Imagens que não tem na loja, tem que excluir no PluggTo
            foreach ($arrayphotos as $odimage) {
                $images[$count]['url'] = $odimage['url'];
                $images[$count]['remove'] = true;
                $count++;
            }
        }

        return $images;
    }

    public function getMediaUrl($image = '')
    {
        $image = trim($image);
        $result = Mage::getBaseUrl('media') . 'xmlconnect';

        if ($image) {
            if (strpos($image, '/') !== 0) {
                $image = '/' . $image;
            }
            $result .= $image;
        }

        return $result;
    }

    // in: a product objecto
    // out:: images array

    public function getProducQtd($product, $old = false)
    {
        $stock = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product->getEntityId())->getData();

        if (isset($stock['qty'])) {
            $stock['quantity'] = (int)$stock['qty'];
        } else {
            $stock['quantity'] = null;
        }
        if (isset($stock['min_qty'])) {
            $stock['min_qty'] = (int)$stock['min_qty'];
        } else {
            $stock['min_qty'] = null;
        }
        return $stock;
    } // end getImages

    // get product attributes

    public function formatRawData($datas)
    {
        $raw = array();
        if (is_array($datas)) {
            foreach ($datas as $key => $value) {
                if (is_array($datas[$key])) {
                    if (is_array($value) && !empty($value)) {
                        //	$raw[$key] = $this->formatRawData($value);
                    } else {
                        $raw[$key] = $value;
                    }
                } elseif (!is_object($datas[$key])) {
                    $raw[$key] = $value;
                }
            }
        }

        return $raw;
    }

    public function unLinkAll()
    {
        $resource = Mage::getSingleton('core/resource');
        $writeConnection = $resource->getConnection('core_write');
        $query = "UPDATE " . $resource->getTableName('catalog_product_entity_varchar') . " SET value = '' where attribute_id = (SELECT attribute_id FROM " . $resource->getTableName('eav_attribute') . " where attribute_code = 'pluggto_id')";
        $writeConnection->query($query);
    }

    public function disconnectByPluggToId($pluggtoId) {}

    // get store attributes
    protected function _construct()
    {
        $this->_init("pluggto/product");
    }

    public function lockSave()
    {
        Mage::getSingleton('core/session')->setPluggToNotSave(1);
    }

    public function unlockSave()
    {
        Mage::getSingleton('core/session')->setPluggToNotSave();
    }

    public function getProductInPluggto($sku)
    {
        try {
            $body = array();
            $body['bysku'] = trim($sku);
            $result = Mage::getSingleton('pluggto/api')->load(1)->get('products', $body, 'field', true);

            if (is_array($result) && isset($result['Body']['result'][0]['Product'])) {
                $old = $result['Body']['result'][0]['Product'];
            } else {
                $old = false;
            }

            return $old;
        } catch (exception $e) {
            Mage::helper('pluggto')->WriteLogForModule('Error', print_r($e->getMessage(), 1));
        }
    }

    public function setProductStore($product)
    {
        $configs = $this->getConfig();

        if (isset($configs['products']['product_store_default'])) {
            $storeView = $configs['products']['product_store_default'];
        } else {
            $storeView = null;
        }

        if (!empty($storeView)) {
            $nproduct = Mage::getModel('catalog/product')->setStoreId($storeView);
            $nproduct->load($product->getEntityId());
        } else {
            $nproduct = $product;
        }

        return $nproduct;
    }
}