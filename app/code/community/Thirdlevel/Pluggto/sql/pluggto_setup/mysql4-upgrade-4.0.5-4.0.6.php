<?php
/**
 * Pluggto upgrade 4.0.5 -> 4.0.6
 *
 * Adds composite indexes on thirdlevel_pluggto_line to make the queue
 * SELECT (status + id) and the cleanup DELETE (status + created) fast
 * regardless of table size. Without these, the playline cron does a
 * full table scan every minute, which on tenants with multi-million-row
 * queues turns each cron cycle into 5+ minutes.
 */
try {
    $installer = $this;
    $installer->startSetup();

    $table = $installer->getTable('pluggto/line');
    $conn  = $installer->getConnection();

    if ($conn->isTableExists($table)) {
        $existingIndexes = array_keys($conn->getIndexList($table));

        // Used by playline:  WHERE status = 0 ORDER BY id ASC LIMIT N
        if (!in_array('IDX_STATUS_ID', $existingIndexes)
            && !in_array('idx_status_id', $existingIndexes)
        ) {
            $conn->addIndex($table, 'idx_status_id', ['status', 'id']);
        }

        // Used by clearQueue:  WHERE status IN (1,2) AND created < ?
        if (!in_array('IDX_STATUS_CREATED', $existingIndexes)
            && !in_array('idx_status_created', $existingIndexes)
        ) {
            $conn->addIndex($table, 'idx_status_created', ['status', 'created']);
        }
    }

    $installer->endSetup();
} catch (Exception $e) {
    Mage::log('Pluggto upgrade 4.0.5 -> 4.0.6 failed: ' . $e->getMessage());
}
