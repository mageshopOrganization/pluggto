<?php

class Thirdlevel_Pluggto_Model_Line extends Mage_Core_Model_Abstract
{
    /**
     * [4.0.7 MageShop] Feature-detection da coluna is_cached_reject.
     * Multi-tenant: lojas que ainda nao rodaram o setup upgrade nao tem a
     * coluna. Sem essa checagem, qualquer UPDATE/SELECT explodiria com
     * "Unknown column". Cacheado por processo pra evitar DESCRIBE repetido.
     */
    protected static $__hasCachedRejectColumn = null;
    public function hasCachedRejectColumn()
    {
        if (self::$__hasCachedRejectColumn === null) {
            try {
                $resource = Mage::getSingleton('core/resource');
                $conn     = $resource->getConnection('core_read');
                $table    = $resource->getTableName('pluggto/line');
                $cols     = $conn->describeTable($table);
                self::$__hasCachedRejectColumn = array_key_exists('is_cached_reject', $cols);
            } catch (Exception $e) {
                self::$__hasCachedRejectColumn = false;
            }
        }
        return self::$__hasCachedRejectColumn;
    }

    protected function _construct()
    {
        $this->_init("pluggto/line");
    }

    /**
     * [4.0.6] Dedup automatica: ao salvar uma linha PENDENTE (status=0),
     * apaga outras linhas pendentes com a mesma chave logica + opt + what.
     * Mantem apenas o registro atual.
     *
     * Chave de dedup:
     *  - Notificacoes (GET, vindas do PluggTo) sao chaveadas por `pluggtoid`.
     *  - Exports (PUT/POST, Magento->PluggTo) de produto novo ainda nao tem
     *    `pluggtoid` (NULL), entao usa `storeid` (entity_id do produto Magento).
     *
     * Resolve o acumulo de pendentes repetidos do mesmo recurso — so o mais
     * recente reflete o estado atual. Atua apenas em pendentes (status=0);
     * linhas em outros status sao historico/auditoria.
     */
    protected function _beforeSave()
    {
        parent::_beforeSave();

        if ((int) $this->getStatus() !== 0) {
            return;
        }

        $opt  = $this->getOpt();
        $what = $this->getWhat();
        if (!$opt || !$what) {
            return;
        }

        $pluggtoid = $this->getPluggtoid();
        $storeid   = $this->getStoreid();

        $resource = Mage::getSingleton('core/resource');
        $conn     = $resource->getConnection('core_write');
        $table    = $resource->getTableName('pluggto/line');

        $where = [
            'opt = ?'    => $opt,
            'what = ?'   => $what,
            'status = ?' => 0,
        ];

        if (!empty($pluggtoid)) {
            $where['pluggtoid = ?'] = $pluggtoid;
        } elseif (!empty($storeid)) {
            $where['storeid = ?'] = $storeid;
        } else {
            return; // sem chave logica (pluggtoid e storeid vazios) — nao deduplica
        }

        if ($this->getId()) {
            $where['id != ?'] = $this->getId();
        }

        $conn->delete($table, $where);
    }

    /**
     * @param int        $id
     * @param array|null $prefetched  Resposta de um GET ja obtido (mesmo formato do
     *   doCall: Body/code/success). Quando informado, o GET de produto reutiliza esta
     *   resposta em vez de refazer a chamada HTTP; o restante do tratamento e identico.
     */
    public function processNotification($id, $prefetched = null)
    {
        $api = Mage::getSingleton('pluggto/api')->load(1);
        $data = $this->load($id);
        $error = false;

        switch ($data->getOpt()):
            case 'GET':
                if ($data->getReason() == 'deleted' && $data->getWhat() == 'products') {
                    $data->setResult('Deleted')->setCode('200')->setStatus(1)->save();
                    return;
                }

                if ($data->getWhat() == 'orders') {
                    $body = array('showExternal' => 'true');
                    $result = $api->get($data->getUrl(), $body, 'field', true);
                } else if ($data->getWhat() == 'products') {
                    // Reutiliza a resposta ja obtida (busca paralela do playline)
                    // quando disponivel; caso contrario, faz a chamada normalmente.
                    if ($prefetched !== null) {
                        $result = $prefetched;
                    } else {
                        $result = $api->get($data->getUrl(), null, null, true);
                    }
                }

                // not process when api is out or unreacheable
                if (!isset($result) || $result['code'] == 0 || $result['code'] == 100 || $result['Body'] == null) {
                    return;
                }

                if ($result['code'] == 200) {
                    if ($data->getWhat() == 'products') {
                        try {
                            Mage::getModel('pluggto/product')->saveProduct($result['Body']['Product']);
                        } catch (exception $e) {
                            $error = $e->getMessage();
                            Mage::helper('pluggto')->WriteLogForModule('Error', 'Error saving product: ' . print_r($e->getMessage(), 1));
                        }
                    } else if ($data->getWhat() == 'orders') {
                        try {
                            Mage::getSingleton('pluggto/order')->create($result['Body']['Order']);
                        } catch (exception $e) {
                            $error = ['message' => $e->getMessage(), 'line' => $e->getLine(), 'file' => $e->getFile(), 'trace' => $e->getTraceAsString()];
                        }
                    }
                }

                if ($result['code'] != 200 || $error) {
                    if ($error) {
                        $data->setResult(json_encode(print_r($error, 1)))->setCode($result['code'])->setStatus(2)->save();
                    } else {
                        $data->setResult(json_encode($result['Body']))->setCode($result['code'])->setStatus(2)->save();
                    }

                    // [4.0.6] Se 404 em GET de produto, marca todos os outros
                    // pendentes do mesmo pluggtoid como falha imediatamente.
                    // Eles falhariam igual e gerariam mais "not_found".
                    if ($result['code'] == 404 && $data->getWhat() == 'products') {
                        $this->_cascadeNotFound($data, $result['Body']);
                    }
                } else {
                    $data->setResult(json_encode($result['Body']))->setCode($result['code'])->setStatus(1)->save();
                }

                break;
            case 'POST':
                $body = $data->getBody();
                if (empty($body) &&  $data->getWhat() == 'products') {
                    $product = Mage::getModel('catalog/product')->load($data->getStoreid());

                    if ($product->getEntityId() != null) {
                        $old = Mage::getSingleton('pluggto/product')->getProductInPluggto($product->getSku());
                        if (isset($old['id']) && !empty($old['id'])) {
                            $data->setUrl('products/' . $old['id']);
                            $data->setOpt('PUT');
                        }

                        $body = Mage::getSingleton('pluggto/product')->formateToPluggto($product, $old);
                        $data->setBody(json_encode($body))->save();
                    } else {
                        $data->setResult(json_encode('Product not find in Store'))->setCode(500)->setStatus(2)->save();
                        return;
                    }
                }

                // check to see if the operation didn' changed
                if ($data->getOpt() == 'PUT') {
                    // If not found in Plugg.To, create
                    $result = $api->put($data->getUrl(), $data->getBody());
                } else {
                    // If not found in Plugg.To, create
                    $result = $api->post($data->getUrl(), $data->getBody());
                }

                // not process when api is out or unreacheable
                if ($result['code'] == 0 || $result['code'] == 100 || $result['Body'] == null) {
                    return;
                }

                if ($result['code'] == 201 || $result['code'] == 200) {
                    if ($data->getWhat() == 'orders') {
                        Mage::getSingleton('pluggto/order')->savePluggToid($result['Body']['Order']);
                    }
                }

                // if return sucess, save pluggtoattributes
                if ($result['code'] == 201 || $result['code'] == 200) {
                    $data->setResult(json_encode($result['Body']))->setCode($result['code'])->setStatus(1)->save();
                    break;
                } else {
                    // if authentication issue, mark to try again
                    if ($result['code'] == 500 && $result['Body'] == 'Authentication Fail, was not possible to authenticate to Plugg.To') {
                        $data->setResult(json_encode($result['Body']))->setCode($result['code'])->setStatus(0)->save();
                        // if return code 0 or empty, maybe a API is out or has a firewall
                    } else if (empty($result['code']) || $result['code'] == 0) {
                        $data->setResult('API NOT REACHED')->setCode($result['code'])->setStatus(0)->save();
                    } else {
                        $data->setResult(json_encode($result['Body']))->setCode($result['code'])->setStatus(2)->save();
                    }
                    break;
                }
            case 'PUT':
                $body = $data->getBody();
                if ($data->getWhat() == 'products') {
                    $product = Mage::getModel('catalog/product')->load($data->getStoreid());
                    $old = Mage::getSingleton('pluggto/product')->getProductInPluggto($product->getSku());
                    $body = Mage::getSingleton('pluggto/product')->formateToPluggto($product, $old);
                    $body = json_encode($body);
                    $data->setBody($body)->save();
                }

                $result = $api->put($data->getUrl(), $body);

                // not process when api is out or unreacheable
                if ($result['code'] == 0 || $result['code'] == 100 || $result['Body'] == null) {
                    return;
                }

                // sucess!
                if ($result['code'] == 200) {
                    if ($data->getWhat() == 'orders') {
                        Mage::getSingleton('pluggto/order')->savePluggToid($result['Body']['Order']);
                    }
                    // product with sku with mistake, try to find correct product
                } elseif ($result['code'] == 400 && $data->getWhat() == 'products') {
                    if (isset($result['Body']['details']) &&  $result['Body']['details'] == 'Changes not found in the document') {
                        // [4.0.7 MageShop] Marca a linha como rejeicao em cache. O
                        // syncPriceStock consulta essas linhas antes de enfileirar
                        // um novo PUT: se preco/estoque/special locais forem iguais
                        // aos do body ja rejeitado, pula. Se algo mudar, o enfileirar
                        // limpa a flag (libera pra clearQueue apagar).
                        // Feature-detection: so seta a flag se a coluna existe (loja
                        // com upgrade rodado). Senao, save antigo sem quebrar.
                        $data->setResult(json_encode($result['Body']))->setCode($result['code'])->setStatus(1);
                        $__wasCached = false;
                        if ($this->hasCachedRejectColumn()) {
                            $data->setIsCachedReject(1);
                            $__wasCached = true;
                        }
                        $data->save();
                        // [4.0.7] Auditoria (respeita perf_log): registra a linha que
                        // entrou em cache. A chave usada pelo syncPriceStock e storeid
                        // (entity_id do produto Magento); se estiver vazio, a marca nao
                        // sera usada pelo cache - logamos WARN.
                        if ($__wasCached) {
                            $__sid = trim((string) $data->getStoreid());
                            if ($__sid === '') {
                                Mage::helper('pluggto')->WriteLogForModule(
                                    'PERF',
                                    'cached_reject_warn: line_id=' . $data->getId()
                                    . ' storeid=<empty> - marca sem efeito (cache ignora linhas sem storeid)'
                                );
                            } else {
                                Mage::helper('pluggto')->WriteLogForModule(
                                    'PERF',
                                    'cached_reject: line_id=' . $data->getId()
                                    . ' storeid=' . $__sid
                                    . ' pluggtoid=' . $data->getPluggtoid()
                                    . ' opt=' . $data->getOpt()
                                    . ' url=' . $data->getUrl()
                                );
                            }
                        }
                        break;
                    } else {
                        $product = json_decode($data->getBody(), 1);
                        $old = Mage::getSingleton('pluggto/product')->getProductInPluggto($product['sku']);
                        // product was found, update the correct product

                        if (isset($old['id'])) {
                            $result = $api->put('skus/' . $old['sku'], $data->getBody());
                        }
                    }
                    /// product not found, try to find the correct product
                } elseif ($result['code'] == 404 && $data->getWhat() == 'products') {
                    $result = $api->post('products/', $body);

                    if ($result['code'] == 201) {
                        $data->setResult(json_encode($result['Body']))->setOpt('POST')->setCode($result['code'])->setStatus(1)->save();
                    } else {
                        $data->setResult(json_encode($result['Body']))->setOpt('POST')->setCode($result['code'])->setStatus(2)->save();
                    }

                    break;
                }

                if ($result['code'] != 200) {
                    if ($result['code'] == 500 && $result['Body'] == 'Authentication Fail, was not possible to authenticate to Plugg.To') {
                        $data->setResult(json_encode($result['Body']))->setCode($result['code'])->setStatus(0)->save();
                    } else {
                        $data->setResult(json_encode($result['Body']))->setCode($result['code'])->setStatus(2)->save();
                    }
                } else {
                    $data->setResult(json_encode($result['Body']))->setCode($result['code'])->setStatus(1)->save();
                }

                break;
            case 'DEL':
                $result = $api->del($data->getUrl());

                // not process when api is out or unreacheable
                if ($result['code'] == 0 || $result['code'] == 100 || $result['Body'] == null) {
                    return;
                }

                if ($result['code'] != 200) {
                    if ($result['code'] == 500 && $result['Body'] == 'Authentication Fail, was not possible to authenticate to Plugg.To') {
                        $data->setResult(json_encode($result['Body']))->setCode($result['code'])->setStatus(0)->save();
                    } else {
                        $data->setResult(json_encode($result['Body']))->setCode($result['code'])->setStatus(2)->save();
                    }
                } else {
                    $data->setResult(json_encode($result['Body']))->setCode($result['code'])->setStatus(1)->save();
                }

            break;
        endswitch;
    }

    public function playline($force = false, $id = null)
    {
        try {
            ini_set('max_execution_time', 0);
            ini_set('memory_limit', '256M');

            $resource = Mage::getSingleton('core/resource');
            $conn  = $resource->getConnection('core_read');
            $table = $resource->getTableName('pluggto/line');

            // [4.0.6] Anti-overflow: se a fila estiver com >100k pendentes,
            // pula o ciclo. Evita ficar girando em uma loja problematica e
            // travando o orquestrador. Operacao manual de limpeza precisa
            // intervir nesse cenario.
            $pending = (int) $conn->fetchOne(
                "SELECT COUNT(*) FROM {$table} WHERE status = 0"
            );
            if ($pending > 100000) {
                Mage::helper('pluggto')->WriteLogForModule(
                    'Warn',
                    "playline: queue overflow ({$pending} pending) - skipping cycle"
                );
                return array('processed' => 0, 'errors' => 0, 'skipped' => 'overflow', 'pending' => $pending);
            }

            $queue_quantity = (int) Mage::getStoreConfig('pluggto/configs/queues_quantity');
            if ($queue_quantity <= 0) {
                $queue_quantity = 100;
            }

            // [4.0.6] ASC = FIFO real (antes era DESC e itens antigos NUNCA
            //         eram processados — empilhavam pra sempre).
            // [4.0.6] Skip pendentes >14 dias: dado antigo nao agrega valor;
            //         provavelmente o produto mudou varias vezes desde entao.
            $cutoff = date('Y-m-d H:i:s', strtotime('-14 days'));
            $collection = $this->getCollection()
                ->addFieldToFilter('status', 0)
                ->addFieldToFilter('created', ['gteq' => $cutoff])
                ->setPageSize($queue_quantity)
                ->setOrder('id', 'ASC');

            if (Mage::getStoreConfig('pluggto/configs/multi_queues')) {
                $idArray = array();
                foreach ($collection as $data) {
                    $idArray[] = $data->getId();
                }
                if (!empty($idArray)) {
                    $collection->massUpdate(array('status' => 3), $idArray);
                }
            }

            $api = Mage::getSingleton('pluggto/api')->load(1);
            $start = time();
            $allowtime = (int) ini_get('max_input_time');
            $maximput = (int) ini_get('max_execution_time');

            if ($maximput == 0) {
                $maximput = PHP_INT_MAX;
            }
            $memory_limit = (int) ini_get('memory_limit');

            if ($allowtime < $maximput) {
                $allowtime = $maximput;
            }
            if (empty($allowtime)) {
                $allowtime = 30;
            } else {
                $allowtime = $allowtime - 10;
            }

            // check if line is running, break if has less than 60 secounds last line
            $lastimestamp = $api->getLine();
            if (!Mage::getStoreConfig('pluggto/configs/multi_queues')) {
                if (!empty($lastimestamp) && $force == false) {
                    $now = new DateTime('now');
                    $diff = $now->getTimestamp() - $lastimestamp;
                    if ($diff < 60) {
                        return array('processed' => 0, 'errors' => 0, 'skipped' => 'throttle_' . $diff . 's', 'pending' => $pending);
                    }
                }
            }

            // Busca paralela dos GET de produto, quando habilitada e fora do modo
            // multi_queues. Com a config zerada/vazia, mantem o fluxo serial abaixo.
            $parallel = (int) Mage::getStoreConfig('pluggto/mageshop/parallel_fetch');
            // [PERF] tempo de execucao (gated: mageshop/perf_log)
            Mage::helper('pluggto')->WriteLogForModule('PERF', sprintf(
                'playline: pendentes=%d parallel_fetch=%d', $pending, $parallel
            ));
            if ($parallel > 0 && !Mage::getStoreConfig('pluggto/configs/multi_queues')) {
                $ret = $this->_playlineParallel($collection, $parallel, $api, $start, $allowtime, $memory_limit);
                $ret['pending'] = $pending;
                $ret['mode'] = 'parallel';
                return $ret;
            }

            // [MageShop] Contadores pra auditoria via Cron.php._perfEnd().
            $processed = 0;
            $errors = 0;

            foreach ($collection as $data) {
                $api->setLine(time())->save();

                try {
                    $this->processNotification($data->getId());
                    $processed++;
                } catch (Exception $e) {
                    $errors++;
                    $data = $this->load($data->getId());
                    $data->setStatus(0);
                    $data->save();
                    Mage::helper('pluggto')->WriteLogForModule(
                        'Error',
                        'FAIL REQUEST: ' . $e->getMessage()
                    );
                }

                // [4.0.6 BUGFIX] $passou e int (segundos). strtotime() espera
                //               string de data e retornava false, fazendo
                //               este break NUNCA disparar.
                $passou = time() - $start;
                $mem = round(memory_get_usage() / 1048576, 2);

                if (!Mage::getStoreConfig('pluggto/configs/multi_queues')
                    && $passou > $allowtime) {
                    break;
                } elseif ($mem > $memory_limit) {
                    break;
                }
            }
            return array('processed' => $processed, 'errors' => $errors, 'pending' => $pending, 'mode' => 'serial');
        } catch (Exception $e) {
            Mage::helper('pluggto')->WriteLogForModule(
                'Error',
                'playline: ' . $e->getMessage()
            );
            return array('processed' => 0, 'errors' => 0, 'skipped' => 'exception');
        }
    }

    /**
     * Variante do playline com busca paralela (config pluggto/mageshop/parallel_fetch).
     *
     * Os GET de produto do lote sao buscados na Plugg.To em paralelo (curl_multi,
     * concorrencia limitada), e cada resposta e entao processada por
     * processNotification($id, $prefetched), que reaproveita integralmente a logica
     * de gravacao/status/cascade. As demais operacoes (PUT, POST, GET de pedidos e
     * GETs marcados como 'deleted') seguem o fluxo serial inalterado.
     *
     * A paralelizacao se restringe a GETs porque sao idempotentes; nenhuma operacao
     * de escrita (PUT/POST) e disparada concorrentemente.
     */
    protected function _playlineParallel($collection, $concurrency, $api, $start, $allowtime, $memory_limit)
    {
        // Produtos com operacao de escrita (PUT/POST/DEL) pendente no lote: seus
        // GETs seguem seriais para preservar a ordem em relacao a escrita.
        $writePids = array();
        foreach ($collection as $data) {
            if ($data->getOpt() != 'GET' && $data->getPluggtoid()) {
                $writePids[$data->getPluggtoid()] = true;
            }
        }

        // Separa o lote em 3 buckets:
        //  - $toFetch   : GET de produto (idempotente) -> curl_multi paralelo (doCallMultiGet).
        //  - $writeIds  : PUT/POST/DEL -> curl_multi paralelo (doCallMultiWrite, quando parallel_writes>1).
        //  - $serialIds : resto (GET de orders, GET deleted, e escritas quando parallel_writes<=1).
        $toFetch      = array(); // id => url
        $writeIds     = array(); // [id, ...]
        $serialIds    = array(); // [id, ...]
        foreach ($collection as $data) {
            $opt = strtoupper((string) $data->getOpt());
            if ($opt == 'GET'
                && $data->getWhat() == 'products'
                && $data->getReason() != 'deleted'
                && $data->getUrl()
                && !isset($writePids[$data->getPluggtoid()])
            ) {
                $toFetch[$data->getId()] = $data->getUrl();
            } elseif (in_array($opt, array('PUT', 'POST', 'DEL'), true)) {
                $writeIds[] = $data->getId();
            } else {
                $serialIds[] = $data->getId();
            }
        }

        // Busca paralela dos GET de produto (token reutilizado, retry interno).
        $fetched = array();
        $__fetchT = 0; // [PERF] tempo de execucao (gated: mageshop/perf_log)
        if (!empty($toFetch)) {
            $api->setLine(time())->save();
            $__t = microtime(true);
            $fetched = Mage::getModel('pluggto/call')
                ->doCallMultiGet($toFetch, $concurrency, 3);
            $__fetchT = microtime(true) - $__t;
        }
        $__saveT = microtime(true); // [PERF] (gated: mageshop/perf_log)

        // [MageShop] Contadores pra auditoria via Cron.php._perfEnd().
        $processed = 0;
        $errors = 0;
        $noResponse = 0;

        // Processa cada GET de produto com a resposta ja obtida.
        foreach ($toFetch as $id => $url) {
            $api->setLine(time())->save();
            if (!isset($fetched[$id])) {
                // Sem resposta para este id: mantem pendente para o proximo ciclo.
                $noResponse++;
                continue;
            }
            try {
                $this->processNotification($id, $fetched[$id]);
                $processed++;
            } catch (Exception $e) {
                $errors++;
                $this->load($id)->setStatus(0)->save();
                Mage::helper('pluggto')->WriteLogForModule(
                    'Error',
                    'FAIL REQUEST: ' . $e->getMessage()
                );
            }
            $passou = time() - $start;
            $mem = round(memory_get_usage() / 1048576, 2);
            if ($passou > $allowtime || $mem > $memory_limit) {
                break; // o restante fica pendente para o proximo ciclo
            }
        }

        // [PERF] tempo de execucao dos GETs paralelos (gated: mageshop/perf_log)
        Mage::helper('pluggto')->WriteLogForModule('PERF', sprintf(
            'playline parallel(conc=%d): gets=%d fetch=%.2fs save=%.2fs | writes=%d serial=%d',
            $concurrency, count($toFetch), $__fetchT, microtime(true) - $__saveT, count($writeIds), count($serialIds)
        ));

        // [4.0.7 MageShop] Escritas em paralelo, se habilitado (parallel_writes>1).
        // Trava por chave (pluggtoid/storeid) preserva ordem por recurso; ganho
        // grande quando o batch tem muitos PUTs de produtos DIFERENTES.
        $parallelWrites = (int) Mage::getStoreConfig('pluggto/mageshop/parallel_writes');
        $__writesRoutedParallel = 0;
        $__writesT = 0;
        if ($parallelWrites > 1 && !empty($writeIds)) {
            $__t = microtime(true);
            $__writesRoutedParallel = $this->_dispatchWritesInParallel(
                $writeIds, $api, $start, $allowtime, $memory_limit, $parallelWrites, $processed, $errors
            );
            $__writesT = microtime(true) - $__t;

            // Timeout / memory check antes de continuar pro loop serial residual.
            $passou = time() - $start;
            $mem = round(memory_get_usage() / 1048576, 2);
            if ($passou > $allowtime || $mem > $memory_limit) {
                Mage::helper('pluggto')->WriteLogForModule('PERF', sprintf(
                    'playline parallel writes: dispatched=%d duration=%.2fs (stop before serial residual)',
                    $__writesRoutedParallel, $__writesT
                ));
                return array(
                    'processed'      => $processed,
                    'errors'         => $errors,
                    'no_response'    => $noResponse,
                    'gets'           => count($toFetch),
                    'writes'         => count($writeIds),
                    'writes_parallel'=> $__writesRoutedParallel,
                    'serial'         => count($serialIds),
                );
            }
        } else {
            // parallel_writes desligado ou 1: escritas fluem pelo loop serial abaixo
            // junto com o resto. Mantem retrocompatibilidade completa.
            $serialIds = array_merge($serialIds, $writeIds);
            $writeIds  = array();
        }

        // Operacoes seriais restantes: GET de orders, GET deleted, e (se
        // parallel_writes<=1) tambem escritas — replicando comportamento antigo.
        foreach ($serialIds as $id) {
            $api->setLine(time())->save();
            try {
                $this->processNotification($id);
                $processed++;
            } catch (Exception $e) {
                $errors++;
                $this->load($id)->setStatus(0)->save();
                Mage::helper('pluggto')->WriteLogForModule(
                    'Error',
                    'FAIL REQUEST: ' . $e->getMessage()
                );
            }
            $passou = time() - $start;
            $mem = round(memory_get_usage() / 1048576, 2);
            if ($passou > $allowtime || $mem > $memory_limit) {
                break;
            }
        }

        // [PERF] resumo final quando escritas paralelas rodaram.
        if ($parallelWrites > 1) {
            Mage::helper('pluggto')->WriteLogForModule('PERF', sprintf(
                'playline parallel writes done: routed=%d duration=%.2fs (conc=%d)',
                $__writesRoutedParallel, $__writesT, $parallelWrites
            ));
        }

        return array(
            'processed'      => $processed,
            'errors'         => $errors,
            'no_response'    => $noResponse,
            'gets'           => count($toFetch),
            'writes'         => $__writesRoutedParallel,
            'serial'         => count($serialIds),
        );
    }

    /**
     * [4.0.7 MageShop] Dispara escritas (PUT/POST/DEL) em paralelo via curl_multi,
     * respeitando trava por chave (pluggtoid || storeid) — 2 escritas do mesmo
     * recurso NUNCA correm concorrentes entre si; grupos diferentes paralelizam
     * ate `$concurrency` simultaneas.
     *
     * Fase 1 (pre-processing, serial mas rapido — so DB):
     *   Pra cada linha, monta method/path/body. Replica a lgica pre-HTTP do
     *   processNotification (POST->PUT rewrite quando produto ja existe,
     *   formateToPluggto pra PUT de products, etc.).
     *
     * Fase 2 (dispatch, paralelo — HTTP):
     *   Buckets por chave. Cada onda dispatcha 1 linha por bucket via
     *   doCallMultiWrite. Ondas subsequentes drenam buckets com >1 item.
     *
     * Fase 3 (pos-processing, serial mas rapido — so DB):
     *   Pra cada resposta, _applyWriteResponse aplica status/code/cache/etc.
     *
     * @param array $writeIds     [lineId, ...] das linhas com opt PUT/POST/DEL
     * @param Thirdlevel_Pluggto_Model_Api $api
     * @param int   $start         time() do inicio do playline (pra timeout)
     * @param int   $allowtime     limite de tempo do playline
     * @param int   $memory_limit  limite de memoria em MB
     * @param int   $concurrency   max escritas simultaneas (parallel_writes)
     * @param int   $processed     por ref, incrementado a cada linha ok
     * @param int   $errors        por ref, incrementado em exception no pos-processing
     * @return int  qtd de linhas efetivamente dispatched (informativo)
     */
    /**
     * [4.0.7 MageShop] Batch GET dos "old products" da Plugg.To (endpoint
     * `products?bysku=<sku>`), em paralelo via curl_multi. Substitui as N chamadas
     * serial que o getProductInPluggto fazia dentro do pre-processing de escritas.
     *
     * Estritamente idempotente e safe: todo GET e independente. Erros isolados
     * caem em `false` (mesma semantica do getProductInPluggto que retorna false
     * quando resultado nao encontrado ou malformado) — downstream trata como
     * "produto novo" e envia body completo, exatamente como se a chamada serial
     * tivesse falhado.
     *
     * @param array $skusByLineId  [lineId => sku]
     * @param int   $concurrency   requests simultaneos
     * @return array               [lineId => oldProductArray|false]
     */
    protected function _prefetchOldProducts(array $skusByLineId, $concurrency)
    {
        if (empty($skusByLineId)) {
            return array();
        }

        // Monta paths com bysku=... (ja com ? no path — doCallMultiGet trata isso).
        $paths = array();
        foreach ($skusByLineId as $lineId => $sku) {
            $paths[$lineId] = 'products?bysku=' . rawurlencode(trim((string) $sku));
        }

        $results = Mage::getModel('pluggto/call')
            ->doCallMultiGet($paths, $concurrency, 3);

        // Unwrap Body.result[0].Product — mesmo formato do getProductInPluggto.
        // Qualquer falha (result ausente, malformado, http err) => false.
        $oldByLineId = array();
        foreach ($skusByLineId as $lineId => $_) {
            if (isset($results[$lineId]['Body']['result'][0]['Product'])) {
                $oldByLineId[$lineId] = $results[$lineId]['Body']['result'][0]['Product'];
            } else {
                $oldByLineId[$lineId] = false;
            }
        }
        return $oldByLineId;
    }

    protected function _dispatchWritesInParallel(array $writeIds, $api, $start, $allowtime, $memory_limit, $concurrency, &$processed, &$errors)
    {
        if (empty($writeIds)) {
            return 0;
        }

        // -------- FASE 1a: preload Line + catalog/product; coleta SKUs pra batch --------
        // Objetivo: descobrir, sem chamar HTTP ainda, quais linhas precisam de
        // "old" da Plugg.To (PUT products, POST products sem body). Depois o
        // fetch e feito em UMA leva de curl_multi (fase 1b), ao inves de N
        // chamadas seriais dentro do foreach de escrita.
        $preLoad       = array();  // lineId => ['data','origOpt','product'] apos filtro
        $skusToFetch   = array();  // lineId => sku (so das que precisam de old)
        $prefiltered   = 0;

        foreach ($writeIds as $lineId) {
            try {
                // [4.0.7 BUGFIX] NAO usar $this->load() aqui — em Magento 1, load()
                // modifica o proprio $this in place e retorna a MESMA referencia.
                // Se guardarmos $data no $preLoad, todas as entries acabam
                // apontando pra mesma instancia (a ultima carregada) — o que
                // colapsa TODOS os buckets em UM so e mata a paralelizacao.
                $data    = Mage::getModel('pluggto/line')->load($lineId);
                $origOpt = strtoupper((string) $data->getOpt());

                if (!in_array($origOpt, array('PUT', 'POST', 'DEL'), true)) {
                    continue;
                }

                if ($origOpt === 'DEL') {
                    $preLoad[$lineId] = array('data' => $data, 'origOpt' => 'DEL', 'product' => null);
                    continue;
                }

                if ($data->getWhat() === 'products') {
                    $currentBody = $data->getBody();

                    // POST products com body ja pronto: nao precisa de fetch.
                    if ($origOpt === 'POST' && !empty($currentBody)) {
                        $preLoad[$lineId] = array('data' => $data, 'origOpt' => 'POST', 'product' => null);
                        continue;
                    }

                    $product = Mage::getModel('catalog/product')->load($data->getStoreid());
                    if ($product->getEntityId() === null) {
                        // Produto local nao existe (mesma semantica do serial): erro imediato pra POST.
                        if ($origOpt === 'POST') {
                            $data->setResult(json_encode('Product not find in Store'))->setCode(500)->setStatus(2)->save();
                        }
                        $prefiltered++;
                        continue;
                    }

                    $preLoad[$lineId] = array(
                        'data'    => $data,
                        'origOpt' => $origOpt,
                        'product' => $product,
                    );
                    $sku = trim((string) $product->getSku());
                    if ($sku !== '') {
                        $skusToFetch[$lineId] = $sku;
                    }
                } else {
                    // PUT em orders etc — sem fetch de old.
                    $preLoad[$lineId] = array('data' => $data, 'origOpt' => $origOpt, 'product' => null);
                }
            } catch (Exception $e) {
                $errors++;
                $prefiltered++;
                try { Mage::getModel('pluggto/line')->load($lineId)->setStatus(0)->save(); } catch (Exception $_) { /* rollback */ }
                Mage::helper('pluggto')->WriteLogForModule(
                    'Error',
                    'FAIL WRITE preload (parallel) line_id=' . $lineId . ': ' . $e->getMessage()
                );
            }
        }

        // -------- FASE 1b: batch GET dos "old" em paralelo (mesma concurrency) --------
        $__preT = microtime(true);
        $oldByLineId = array();
        if (!empty($skusToFetch)) {
            $api->setLine(time())->save();
            $oldByLineId = $this->_prefetchOldProducts($skusToFetch, $concurrency);
        }
        $__prefetchDur = microtime(true) - $__preT;
        Mage::helper('pluggto')->WriteLogForModule('PERF', sprintf(
            'writes.prefetch: skus=%d fetched=%d duration=%.2fs conc=%d',
            count($skusToFetch), count($oldByLineId), $__prefetchDur, $concurrency
        ));

        // -------- FASE 1c: monta body/path/method e agrupa em buckets --------
        $buckets      = array();  // chave => [lineId, ...] em ordem FIFO
        $lineDataById = array();  // lineId => Line model
        $reqMetaById  = array();  // lineId => ['method','path','body','origOpt']

        foreach ($preLoad as $lineId => $pre) {
            try {
                $data    = $pre['data'];
                $origOpt = $pre['origOpt'];
                $product = $pre['product'];

                $method = $origOpt;
                $path   = (string) $data->getUrl();
                $body   = $data->getBody();

                if ($origOpt === 'POST' && $data->getWhat() === 'products' && empty($body)) {
                    // POST products sem body: usa $old (do batch fetch) pra decidir
                    // se produto ja existe -> reescreve pra PUT.
                    $old = isset($oldByLineId[$lineId]) ? $oldByLineId[$lineId] : false;
                    if (isset($old['id']) && !empty($old['id'])) {
                        $data->setUrl('products/' . $old['id']);
                        $data->setOpt('PUT');
                        $path   = 'products/' . $old['id'];
                        $method = 'PUT';
                    }
                    $body = json_encode(Mage::getSingleton('pluggto/product')->formateToPluggto($product, $old));
                    $data->setBody($body)->save();
                } elseif ($origOpt === 'PUT' && $data->getWhat() === 'products') {
                    // PUT products: monta body com $old do batch fetch.
                    $old  = isset($oldByLineId[$lineId]) ? $oldByLineId[$lineId] : false;
                    $body = json_encode(Mage::getSingleton('pluggto/product')->formateToPluggto($product, $old));
                    $data->setBody($body)->save();
                } elseif ($origOpt === 'DEL') {
                    $method = 'DELETE';
                    $body   = null;
                }

                // Chave do bucket: pluggtoid preferido; senao storeid; senao lineId.
                $key = trim((string) $data->getPluggtoid());
                if ($key === '') {
                    $sid = trim((string) $data->getStoreid());
                    $key = ($sid !== '') ? ('sid:' . $sid) : ('lid:' . $lineId);
                }

                if (!isset($buckets[$key])) {
                    $buckets[$key] = array();
                }
                $buckets[$key][] = $lineId;

                $lineDataById[$lineId] = $data;
                $reqMetaById[$lineId]  = array(
                    'method'  => $method,
                    'path'    => $path,
                    'body'    => $body,
                    'origOpt' => $origOpt,
                );
            } catch (Exception $e) {
                $errors++;
                try { $this->load($lineId)->setStatus(0)->save(); } catch (Exception $_) { /* rollback */ }
                Mage::helper('pluggto')->WriteLogForModule(
                    'Error',
                    'FAIL WRITE preprocess (parallel) line_id=' . $lineId . ': ' . $e->getMessage()
                );
            }
        }

        if (empty($buckets)) {
            return 0;
        }

        // Ordena FIFO dentro de cada bucket (id ASC = ordem de chegada).
        foreach ($buckets as $k => $ids) {
            sort($ids);
            $buckets[$k] = $ids;
        }

        // Log de auditoria (perf_log): resumo antes do dispatch.
        $__totalItems = 0;
        $__multiKey = 0;
        foreach ($buckets as $ids) {
            $__totalItems += count($ids);
            if (count($ids) > 1) { $__multiKey++; }
        }
        Mage::helper('pluggto')->WriteLogForModule('PERF', sprintf(
            'writes.plan: items=%d buckets=%d multi_key_buckets=%d prefiltered=%d conc=%d',
            $__totalItems, count($buckets), $__multiKey, $prefiltered, $concurrency
        ));

        // -------- FASE 2 + 3: ondas de dispatch --------
        $waveN  = 0;
        $dispatched = 0;

        while (!empty($buckets)) {
            $waveN++;
            $requestsById = array();
            $waveKeys     = array();

            foreach ($buckets as $key => $ids) {
                $lineId = $ids[0];
                $meta   = $reqMetaById[$lineId];
                $requestsById[$lineId] = array(
                    'method' => $meta['method'],
                    'path'   => $meta['path'],
                    'body'   => $meta['body'],
                );
                $waveKeys[$lineId] = $key;
            }

            if (empty($requestsById)) {
                break;
            }

            $__waveT = microtime(true);
            Mage::helper('pluggto')->WriteLogForModule('PERF', sprintf(
                'writes.wave.start: wave=%d items=%d buckets_remain=%d',
                $waveN, count($requestsById), count($buckets)
            ));

            // Dispatcha em paralelo.
            $results = Mage::getModel('pluggto/call')
                ->doCallMultiWrite($requestsById, $concurrency, 3);

            // Aplica cada resposta.
            $waveOk  = 0;
            $waveErr = 0;
            $waveApiOut = 0;
            foreach ($requestsById as $lineId => $_) {
                $data   = $lineDataById[$lineId];
                $meta   = $reqMetaById[$lineId];
                $result = isset($results[$lineId]) ? $results[$lineId] : null;

                try {
                    // API fora: nao conta como processed (linha continua pendente).
                    if (empty($result) || $result['code'] == 0 || $result['code'] == 100 || $result['Body'] === null) {
                        $waveApiOut++;
                        $this->_applyWriteResponse($data, $result, $meta['body'], $meta['origOpt'], $api);
                    } else {
                        $this->_applyWriteResponse($data, $result, $meta['body'], $meta['origOpt'], $api);
                        $processed++;
                        $waveOk++;
                    }
                    $dispatched++;
                } catch (Exception $e) {
                    $errors++;
                    $waveErr++;
                    try {
                        $data->setStatus(0)->save();
                    } catch (Exception $_) { /* ignora erro de save no rollback */ }
                    Mage::helper('pluggto')->WriteLogForModule('Error', 'FAIL WRITE (parallel): ' . $e->getMessage());
                }

                // Avanca o bucket - remove o item processado.
                $bucketKey = $waveKeys[$lineId];
                if (isset($buckets[$bucketKey])) {
                    array_shift($buckets[$bucketKey]);
                    if (empty($buckets[$bucketKey])) {
                        unset($buckets[$bucketKey]);
                    }
                }
            }

            Mage::helper('pluggto')->WriteLogForModule('PERF', sprintf(
                'writes.wave.end: wave=%d duration=%.2fs ok=%d err=%d api_out=%d',
                $waveN, microtime(true) - $__waveT, $waveOk, $waveErr, $waveApiOut
            ));

            // Refresh do timestamp do api pra evitar linha pausada.
            $api->setLine(time())->save();

            $passou = time() - $start;
            $mem = round(memory_get_usage() / 1048576, 2);
            if ($passou > $allowtime || $mem > $memory_limit) {
                Mage::helper('pluggto')->WriteLogForModule('PERF', sprintf(
                    'writes.abort: reason=%s elapsed=%ds mem=%sMB buckets_pending=%d',
                    ($passou > $allowtime ? 'timeout' : 'memory'),
                    $passou, $mem, count($buckets)
                ));
                break;
            }
        }

        return $dispatched;
    }

    /**
     * [4.0.7 MageShop] Aplica o result de uma escrita (PUT/POST/DEL) na linha da
     * fila. Replica FIELMENTE a logica pos-HTTP do processNotification para cada
     * opt, sem re-fazer o HTTP principal (esse ja foi feito paralelamente pelo
     * _dispatchWritesInParallel via doCallMultiWrite).
     *
     * Fallbacks HTTP secundarios (400 com produto existente re-PUT, 404 do PUT
     * vira POST) SEGUEM SERIAL - sao casos raros e nao vale a pena paraleliza-los.
     *
     * @param Thirdlevel_Pluggto_Model_Line $data       linha da fila (modelo)
     * @param array                          $result    resposta do curl_multi
     * @param string|null                    $body      body JSON enviado (usado nos fallbacks)
     * @param string                         $origOpt   'PUT' | 'POST' | 'DEL' (opt original)
     * @param Thirdlevel_Pluggto_Model_Api   $api       instancia para fallbacks
     */
    protected function _applyWriteResponse($data, $result, $body, $origOpt, $api)
    {
        // API fora do ar: linha permanece pendente (mesma semantica do serial).
        if (empty($result) || $result['code'] == 0 || $result['code'] == 100 || $result['Body'] == null) {
            return;
        }

        if ($origOpt === 'DEL') {
            if ($result['code'] != 200) {
                if ($result['code'] == 500 && $result['Body'] == 'Authentication Fail, was not possible to authenticate to Plugg.To') {
                    $data->setResult(json_encode($result['Body']))->setCode($result['code'])->setStatus(0)->save();
                } else {
                    $data->setResult(json_encode($result['Body']))->setCode($result['code'])->setStatus(2)->save();
                }
            } else {
                $data->setResult(json_encode($result['Body']))->setCode($result['code'])->setStatus(1)->save();
            }
            return;
        }

        if ($origOpt === 'POST') {
            // Sucesso (POST criou ou o pre-processing detectou existente e virou PUT).
            if ($result['code'] == 201 || $result['code'] == 200) {
                if ($data->getWhat() == 'orders') {
                    Mage::getSingleton('pluggto/order')->savePluggToid($result['Body']['Order']);
                }
                $data->setResult(json_encode($result['Body']))->setCode($result['code'])->setStatus(1)->save();
                return;
            }
            if ($result['code'] == 500 && $result['Body'] == 'Authentication Fail, was not possible to authenticate to Plugg.To') {
                $data->setResult(json_encode($result['Body']))->setCode($result['code'])->setStatus(0)->save();
            } elseif (empty($result['code']) || $result['code'] == 0) {
                $data->setResult('API NOT REACHED')->setCode($result['code'])->setStatus(0)->save();
            } else {
                $data->setResult(json_encode($result['Body']))->setCode($result['code'])->setStatus(2)->save();
            }
            return;
        }

        // origOpt === 'PUT' (default): replica switch case 'PUT' do processNotification.
        if ($result['code'] == 200) {
            if ($data->getWhat() == 'orders') {
                Mage::getSingleton('pluggto/order')->savePluggToid($result['Body']['Order']);
            }
        } elseif ($result['code'] == 400 && $data->getWhat() == 'products') {
            if (isset($result['Body']['details']) && $result['Body']['details'] == 'Changes not found in the document') {
                // [4.0.7 MageShop] Rejeicao silenciosa - marca cache. Ver [[project_pluggto_cached_reject]].
                $data->setResult(json_encode($result['Body']))->setCode($result['code'])->setStatus(1);
                $__wasCached = false;
                if ($this->hasCachedRejectColumn()) {
                    $data->setIsCachedReject(1);
                    $__wasCached = true;
                }
                $data->save();
                if ($__wasCached) {
                    $__sid = trim((string) $data->getStoreid());
                    if ($__sid === '') {
                        Mage::helper('pluggto')->WriteLogForModule(
                            'PERF',
                            'cached_reject_warn: line_id=' . $data->getId()
                            . ' storeid=<empty> - marca sem efeito (cache ignora linhas sem storeid)'
                        );
                    } else {
                        Mage::helper('pluggto')->WriteLogForModule(
                            'PERF',
                            'cached_reject: line_id=' . $data->getId()
                            . ' storeid=' . $__sid
                            . ' pluggtoid=' . $data->getPluggtoid()
                            . ' opt=' . $data->getOpt()
                            . ' url=' . $data->getUrl()
                        );
                    }
                }
                return;
            } else {
                // Fallback SERIAL: tenta refetch e re-PUT em skus/old.sku.
                $product = json_decode($body, 1);
                if (isset($product['sku'])) {
                    $old = Mage::getSingleton('pluggto/product')->getProductInPluggto($product['sku']);
                    if (isset($old['id'])) {
                        $result = $api->put('skus/' . $old['sku'], $body);
                    }
                }
            }
        } elseif ($result['code'] == 404 && $data->getWhat() == 'products') {
            // Fallback SERIAL: produto nao existe na Plugg.To, tenta POST.
            $result = $api->post('products/', $body);
            if ($result['code'] == 201) {
                $data->setResult(json_encode($result['Body']))->setOpt('POST')->setCode($result['code'])->setStatus(1)->save();
            } else {
                $data->setResult(json_encode($result['Body']))->setOpt('POST')->setCode($result['code'])->setStatus(2)->save();
            }
            return;
        }

        if ($result['code'] != 200) {
            if ($result['code'] == 500 && $result['Body'] == 'Authentication Fail, was not possible to authenticate to Plugg.To') {
                $data->setResult(json_encode($result['Body']))->setCode($result['code'])->setStatus(0)->save();
            } else {
                $data->setResult(json_encode($result['Body']))->setCode($result['code'])->setStatus(2)->save();
            }
        } else {
            $data->setResult(json_encode($result['Body']))->setCode($result['code'])->setStatus(1)->save();
        }
    }

    /**
     * [4.0.6] Quando um GET de produto retorna 404, marca como falha permanente
     * TODOS os outros pendentes do mesmo pluggtoid — eles vao falhar igual.
     * Evita gastar slot do cron processando o mesmo produto inexistente N vezes.
     */
    protected function _cascadeNotFound($data, $resultBody)
    {
        try {
            $others = $this->getCollection()
                ->addFieldToFilter('pluggtoid', $data->getPluggtoid())
                ->addFieldToFilter('status', 0)
                ->addFieldToFilter('id', ['neq' => $data->getId()]);

            foreach ($others as $other) {
                $other->setResult(json_encode($resultBody))
                      ->setCode(404)
                      ->setStatus(2)
                      ->save();
            }
        } catch (Exception $e) {
            Mage::helper('pluggto')->WriteLogForModule(
                'Error',
                '_cascadeNotFound: ' . $e->getMessage()
            );
        }
    }

    public function clearQueue()
    {
        $daystoclear = (int) Mage::getStoreConfig('pluggto/configs/clear_queue');
        if ($daystoclear <= 0) {
            return;
        }

        // [4.0.6] Limpa SUCESSOS (status=1) E FALHAS (status=2).
        //         Antes so apagava sucesso, e as falhas acumulavam pra sempre.
        // [4.0.6] LIMIT pra nao locktrar tabela em deletes massivos.
        $sdate = date('Y-m-d H:i:s', strtotime("-{$daystoclear} days"));
        $resource = Mage::getSingleton('core/resource');
        $writeConnection = $resource->getConnection('core_write');
        $tableName = $resource->getTableName('pluggto/line');

        // [4.0.7 MageShop] Preserva linhas com is_cached_reject=1: elas sao a
        // memoria de rejeicoes silenciosas usada pelo syncPriceStock pra nao
        // re-enfileirar. Sao liberadas (is_cached_reject=0) automaticamente
        // pelo _releaseRejectedCache quando preco/estoque muda.
        // Feature-detection: lojas sem a coluna caem no SQL antigo (multi-tenant).
        if ($this->hasCachedRejectColumn()) {
            $writeConnection->query(
                "DELETE FROM {$tableName} WHERE status IN (1, 2) AND is_cached_reject = 0 AND created < ? LIMIT 5000",
                [$sdate]
            );
        } else {
            $writeConnection->query(
                "DELETE FROM {$tableName} WHERE status IN (1, 2) AND created < ? LIMIT 5000",
                [$sdate]
            );
        }
    }

    public function checkCron()
    {
        $storeConfiguration = Mage::getStoreConfig('pluggto/configuration/client_id');

        if (empty($storeConfiguration)) {
            return;
        }

        $api =  Mage::getSingleton('pluggto/api')->load(1);
        $lastimestamp = $api->getLine();

        if (empty($lastimestamp)) {
            Mage::getSingleton('core/session')->addError('PluggTo queue is not running, check if cron is configured correctly');
            return;
        }

        $now = new DateTime('now');
        $diff =  $now->getTimestamp() - $lastimestamp;

        if ($diff > 300) {
            Mage::getSingleton('core/session')->addNotice(sprintf('Last time that PluggTo Queue run was more than %s minutes ago.', round($diff / 60)));
        } elseif ($diff > 3600) {
            Mage::getSingleton('core/session')->addError('Last time that PluggTo Queue run was more than 1 hour ago, check if cron is configured correctly');
        }
    }

    public function cleanStackForMultiQueue()
    {
        // [4.0.6 BUGFIX] $sdate antes era "YYYY-MM-DD 00:00:00" (sempre meia-noite
        //                do dia atual). Filtro original "criado > meia-noite" pegava
        //                praticamente tudo. Correcao: timestamp exato de 1h atras.
        $sdate = date('Y-m-d H:i:s', strtotime('-1 hour'));

        $resource = Mage::getSingleton('core/resource');
        $writeConnection = $resource->getConnection('core_write');
        $tableName = $resource->getTableName('pluggto/line');
        $writeConnection->query(
            "UPDATE {$tableName} SET status = 0 WHERE status = 3 AND created > ?",
            [$sdate]
        );
    }
}