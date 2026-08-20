<?php

class Thirdlevel_Pluggto_Model_Cron
{
    /**
     * [MageShop] Id curto por processo PHP. Todas as linhas de log emitidas
     * dentro do mesmo processo (mesmo cron cycle) compartilham o mesmo id -
     * permite correlacionar START/END de sub-tarefas na hora de investigar.
     * Estatico + null-coalesce: gerado uma vez por processo.
     */
    protected static $__cronId = null;
    protected function _cronId()
    {
        if (self::$__cronId === null) {
            self::$__cronId = substr(md5(microtime(true) . getmypid() . mt_rand()), 0, 6);
        }
        return self::$__cronId;
    }

    /**
     * [MageShop] Store code atual (ex.: "default"). Fica em cache estatico pra
     * evitar chamar Mage::app()->getStore() a cada log line. Fallback "?" se
     * algo estranho acontecer (garante que nunca explode dentro de log).
     */
    protected static $__storeCode = null;
    protected function _storeCode()
    {
        if (self::$__storeCode === null) {
            try {
                self::$__storeCode = Mage::app()->getStore()->getCode();
            } catch (Exception $e) {
                self::$__storeCode = '?';
            }
            if (empty(self::$__storeCode)) {
                self::$__storeCode = '?';
            }
        }
        return self::$__storeCode;
    }

    /**
     * [MageShop] Prefixo padrao das linhas: "[cron_id=XXX store=YYY]".
     */
    protected function _logPrefix()
    {
        return '[cron_id=' . $this->_cronId() . ' store=' . $this->_storeCode() . ']';
    }

    /**
     * [MageShop] Marca inicio de uma sub-tarefa e retorna o token que _perfEnd
     * usa pra calcular duracao. Se o processo for morto (timeout do orquestrador,
     * kill do PHP-FPM), o START fica no log SEM END correspondente - visualmente
     * obvio qual sub-tarefa estava rodando quando morreu.
     * Gated: WriteLogForModule('PERF', ...) so grava se mageshop/perf_log=1.
     */
    protected function _perfStart($name, $context = array())
    {
        $ctx = $this->_fmtContext($context);
        Mage::helper('pluggto')->WriteLogForModule(
            'PERF',
            $this->_logPrefix() . ' START ' . $name . ($ctx ? ' ' . $ctx : '')
        );
        return array('t0' => microtime(true), 'name' => $name);
    }

    /**
     * [MageShop] Finaliza uma sub-tarefa. context pode conter contadores extras
     * (ex.: processed, errors, pending, mode) - vira sufixo "chave=valor".
     */
    protected function _perfEnd($token, $context = array())
    {
        $dur = round(microtime(true) - $token['t0'], 2);
        $ctx = $this->_fmtContext($context);
        Mage::helper('pluggto')->WriteLogForModule(
            'PERF',
            $this->_logPrefix() . ' END   ' . $token['name']
            . ' duration=' . $dur . 's'
            . ($ctx ? ' ' . $ctx : '')
        );
    }

    /**
     * [MageShop] Serializa contexto ["k"=>v, ...] pra "k=v k2=v2". Vazio => ''.
     * Escapa espaco em valores string (raro) trocando por '_' pra manter grep facil.
     */
    protected function _fmtContext($context)
    {
        if (empty($context) || !is_array($context)) {
            return '';
        }
        $parts = array();
        foreach ($context as $k => $v) {
            if (is_bool($v)) {
                $v = $v ? '1' : '0';
            } elseif ($v === null) {
                $v = 'null';
            } elseif (is_string($v) && strpos($v, ' ') !== false) {
                $v = str_replace(' ', '_', $v);
            }
            $parts[] = $k . '=' . $v;
        }
        return implode(' ', $parts);
    }

    public function runQueueCycle()
    {
        $__cycle = $this->_perfStart('runQueueCycle');
        try {
            $multiEnabled = Mage::getStoreConfig('pluggto/configs/multi_queues');
            $qty = (int) Mage::getStoreConfig('pluggto/configs/multi_queues_quantity');
            if ($qty <= 0) $qty = 1;

            $iterations = $multiEnabled ? $qty : 1;

            for ($i = 0; $i < $iterations; $i++) {
                $this->safeWorkerLikeProcess(); // runBulkExport + playline
                if ($i + 1 < $iterations) {
                    sleep(3); // mesmo espaçamento dos scripts originais
                }
            }

            // limpeza de transações travadas (como no script raiz)
            if ($multiEnabled) {
                Mage::getModel('pluggto/line')->cleanStackForMultiQueue();
            }

            // limpa transações antigas da fila
            $__t = $this->_perfStart('clearQueue');
            Mage::getModel('pluggto/line')->clearQueue();
            $this->_perfEnd($__t);

            $this->_perfEnd($__cycle, array('iterations' => $iterations, 'multi' => (bool) $multiEnabled));
        } catch (Exception $e) {
            $this->_perfEnd($__cycle, array('status' => 'error'));
            Mage::helper('pluggto')->WriteLogForModule('Error', $this->_logPrefix() . ' runQueueCycle: ' . $e->getMessage());
        }
    }

    /**
     * Um "worker" equivalente ao pluggto_process.php:
     * runBulkExport() + playline(). Protegido por lock.
     */
    protected function safeWorkerLikeProcess()
    {
        $lockName = 'pluggto_worker.lock';
        $ttl = 300; // 5 min — ajuste conforme seu intervalo de execução

        if (!$this->acquireLock($lockName, $ttl)) {
            // Log audita a colisao pra saber que o cron esta encavalando.
            Mage::helper('pluggto')->WriteLogForModule(
                'PERF',
                $this->_logPrefix() . ' SKIP  worker lock=' . $lockName . ' motivo=locked'
            );
            return; // já existe worker recente; evita sobreposição
        }

        try {
            $__b = $this->_perfStart('runBulkExport');
            Mage::getSingleton('pluggto/bulkexport')->runBulkExport();
            $this->_perfEnd($__b);

            $__p = $this->_perfStart('playline');
            $ret = Mage::getModel('pluggto/line')->playline();
            // Line::playline() agora retorna array com contadores (retrocompativel:
            // callers que ignoram o retorno continuam funcionando).
            $ctx = is_array($ret) ? $ret : array('processed' => 0, 'errors' => 0);
            $this->_perfEnd($__p, $ctx);
        } catch (Exception $e) {
            Mage::helper('pluggto')->WriteLogForModule('Error', $this->_logPrefix() . ' worker: ' . $e->getMessage());
        } finally {
            $this->releaseLock($lockName);
        }
    }

    /**
     * Substitui pluggto_orders_update.php
     */
    public function ordersUpdate()
    {
        $lockName = 'pluggto_orders_update.lock';
        $ttl = 300; // evite rodar 2 ao mesmo tempo

        if (!$this->acquireLock($lockName, $ttl)) {
            Mage::helper('pluggto')->WriteLogForModule(
                'PERF',
                $this->_logPrefix() . ' SKIP  ordersUpdate lock=' . $lockName . ' motivo=locked'
            );
            return;
        }

        $__t = $this->_perfStart('ordersUpdate');
        try {
            Mage::getModel('pluggto/export')->updateOrders();
            $this->_perfEnd($__t);
        } catch (Exception $e) {
            $this->_perfEnd($__t, array('status' => 'error'));
            Mage::helper('pluggto')->WriteLogForModule('Error', $this->_logPrefix() . ' ordersUpdate: ' . $e->getMessage());
        } finally {
            $this->releaseLock($lockName);
        }
    }

    /**
     * Substitui pluggto_sync_stock_price.php
     */
    public function syncStockPrice()
    {
        $lockName = 'pluggto_sync_stock_price.lock';
        $ttl = 300;

        if (!$this->acquireLock($lockName, $ttl)) {
            Mage::helper('pluggto')->WriteLogForModule(
                'PERF',
                $this->_logPrefix() . ' SKIP  syncStockPrice lock=' . $lockName . ' motivo=locked'
            );
            return;
        }

        $__t = $this->_perfStart('syncStockPrice');
        try {
            Mage::getSingleton('pluggto/product')->syncPriceStock();
            $this->_perfEnd($__t);
        } catch (Exception $e) {
            $this->_perfEnd($__t, array('status' => 'error'));
            Mage::helper('pluggto')->WriteLogForModule('Error', $this->_logPrefix() . ' syncStockPrice: ' . $e->getMessage());
        } finally {
            $this->releaseLock($lockName);
        }
    }

    /* ====================== Helpers de Lock ====================== */

    protected function getLockDir()
    {
        $locksDir = Mage::getBaseDir('var') . DS . 'locks';
        if(!is_dir($locksDir)) {
            @mkdir($locksDir, 0777, true);
        }

        $dir = Mage::getBaseDir('locks');
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        return $dir;
    }

    protected function lockPath($name)
    {
        return $this->getLockDir() . DS . $name;
    }

    /**
     * Cria um lock com TTL:
     * - Se existir e mtime < TTL, não roda (retorna false).
     * - Caso contrário, cria/renova e retorna true.
     */
    protected function acquireLock($name, $ttlSeconds = 300)
    {
        $path = $this->lockPath($name);

        if (file_exists($path)) {
            $age = time() - (int) @filemtime($path);
            if ($age < $ttlSeconds) {
                return false;
            }
        }

        @touch($path);
        return true;
    }

    protected function releaseLock($name)
    {
        $path = $this->lockPath($name);
        if (file_exists($path)) {
            @unlink($path);
        }
    }
}
