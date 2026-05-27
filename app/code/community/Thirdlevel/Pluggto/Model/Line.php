<?php

class Thirdlevel_Pluggto_Model_Line extends Mage_Core_Model_Abstract
{
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
                        $data->setResult(json_encode($result['Body']))->setCode($result['code'])->setStatus(1)->save();
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
                return;
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
                        return;
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
                $this->_playlineParallel($collection, $parallel, $api, $start, $allowtime, $memory_limit);
                return;
            }

            foreach ($collection as $data) {
                $api->setLine(time())->save();

                try {
                    $this->processNotification($data->getId());
                } catch (Exception $e) {
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
            return;
        } catch (Exception $e) {
            Mage::helper('pluggto')->WriteLogForModule(
                'Error',
                'playline: ' . $e->getMessage()
            );
            return;
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

        // Separa o lote: GET de produto vai para a busca paralela; o resto, serial.
        $toFetch   = array(); // id => url
        $serialIds = array();
        foreach ($collection as $data) {
            if ($data->getOpt() == 'GET'
                && $data->getWhat() == 'products'
                && $data->getReason() != 'deleted'
                && $data->getUrl()
                && !isset($writePids[$data->getPluggtoid()])
            ) {
                $toFetch[$data->getId()] = $data->getUrl();
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

        // Processa cada GET de produto com a resposta ja obtida.
        foreach ($toFetch as $id => $url) {
            $api->setLine(time())->save();
            if (!isset($fetched[$id])) {
                // Sem resposta para este id: mantem pendente para o proximo ciclo.
                continue;
            }
            try {
                $this->processNotification($id, $fetched[$id]);
            } catch (Exception $e) {
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

        // [PERF] tempo de execucao (gated: mageshop/perf_log)
        Mage::helper('pluggto')->WriteLogForModule('PERF', sprintf(
            'playline parallel(conc=%d): gets=%d fetch=%.2fs save=%.2fs | serial=%d',
            $concurrency, count($toFetch), $__fetchT, microtime(true) - $__saveT, count($serialIds)
        ));

        // Operacoes restantes (PUT, POST, GET de pedidos, deleted) seguem seriais.
        foreach ($serialIds as $id) {
            $api->setLine(time())->save();
            try {
                $this->processNotification($id);
            } catch (Exception $e) {
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

        $writeConnection->query(
            "DELETE FROM {$tableName} WHERE status IN (1, 2) AND created < ? LIMIT 5000",
            [$sdate]
        );
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