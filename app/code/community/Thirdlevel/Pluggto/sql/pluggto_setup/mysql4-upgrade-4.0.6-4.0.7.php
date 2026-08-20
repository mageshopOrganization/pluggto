<?php
/**
 * Pluggto upgrade 4.0.6 -> 4.0.7
 *
 * Adiciona a coluna `is_cached_reject` em thirdlevel_pluggto_line.
 * Serve como memoria persistente das linhas cujo PUT retornou
 * "not_updated / Changes not found in the document" - usada pelo
 * syncPriceStock pra nao re-enfileirar produtos que a Plugg.To ja
 * rejeitou (ex.: preco abaixo do competitive_price_min).
 *
 * O clearQueue passa a preservar linhas com is_cached_reject=1 -
 * senao a memoria se apaga e o loop volta.
 *
 * Indice adicional em (is_cached_reject, pluggtoid) pra o bulk load
 * do cache no inicio do syncPriceStock ser um lookup rapido.
 */
try {
    $installer = $this;
    $installer->startSetup();

    $table = $installer->getTable('pluggto/line');
    $conn  = $installer->getConnection();

    if ($conn->isTableExists($table)) {
        $columns = $conn->describeTable($table);

        if (!array_key_exists('is_cached_reject', $columns)) {
            $conn->addColumn($table, 'is_cached_reject', array(
                'type'     => Varien_Db_Ddl_Table::TYPE_SMALLINT,
                'nullable' => false,
                'default'  => 0,
                'comment'  => 'Marca linhas cujo PUT foi silenciosamente rejeitado pela Plugg.To (not_updated). clearQueue preserva estas.',
            ));
        }

        $existingIndexes = array_keys($conn->getIndexList($table));

        if (!in_array('IDX_CACHED_REJECT_PLUGGTOID', $existingIndexes)
            && !in_array('idx_cached_reject_pluggtoid', $existingIndexes)
        ) {
            $conn->addIndex($table, 'idx_cached_reject_pluggtoid', array('is_cached_reject', 'pluggtoid'));
        }
    }

    $installer->endSetup();
} catch (Exception $e) {
    Mage::log('Pluggto upgrade 4.0.6 -> 4.0.7 failed: ' . $e->getMessage());
}
