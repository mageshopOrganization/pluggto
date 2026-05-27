<?php

class Thirdlevel_Pluggto_Model_Cron
{
    public function runQueueCycle()
    {
        try {
            $__cycle = microtime(true); // [PERF] tempo de execucao (gated: mageshop/perf_log)
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
            $__t = microtime(true); // [PERF] tempo de execucao (gated: mageshop/perf_log)
            Mage::getModel('pluggto/line')->clearQueue();
            Mage::helper('pluggto')->WriteLogForModule('PERF', 'clearQueue: ' . round(microtime(true) - $__t, 2) . 's');

            Mage::helper('pluggto')->WriteLogForModule('PERF', 'runQueueCycle TOTAL: ' . round(microtime(true) - $__cycle, 2) . 's');
        } catch (Exception $e) {
            Mage::helper('pluggto')->WriteLogForModule('Error', 'runQueueCycle: ' . $e->getMessage());
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
            return; // já existe worker recente; evita sobreposição
        }

        try {
            $__t = microtime(true); // [PERF] tempo de execucao (gated: mageshop/perf_log)
            Mage::getSingleton('pluggto/bulkexport')->runBulkExport();
            Mage::helper('pluggto')->WriteLogForModule('PERF', 'runBulkExport: ' . round(microtime(true) - $__t, 2) . 's');

            $__t = microtime(true);
            Mage::getModel('pluggto/line')->playline();
            Mage::helper('pluggto')->WriteLogForModule('PERF', 'playline: ' . round(microtime(true) - $__t, 2) . 's');
        } catch (Exception $e) {
            Mage::helper('pluggto')->WriteLogForModule('Error', 'worker: ' . $e->getMessage());
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
            return;
        }

        try {
            $__t = microtime(true); // [PERF] tempo de execucao (gated: mageshop/perf_log)
            Mage::getModel('pluggto/export')->updateOrders();
            Mage::helper('pluggto')->WriteLogForModule('PERF', 'updateOrders: ' . round(microtime(true) - $__t, 2) . 's');
        } catch (Exception $e) {
            Mage::helper('pluggto')->WriteLogForModule('Error', 'ordersUpdate: ' . $e->getMessage());
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
            return;
        }

        try {
            $__t = microtime(true); // [PERF] tempo de execucao (gated: mageshop/perf_log)
            Mage::getSingleton('pluggto/product')->syncPriceStock();
            Mage::helper('pluggto')->WriteLogForModule('PERF', 'syncPriceStock: ' . round(microtime(true) - $__t, 2) . 's');
        } catch (Exception $e) {
            Mage::helper('pluggto')->WriteLogForModule('Error', 'syncStockPrice: ' . $e->getMessage());
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