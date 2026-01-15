<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

/**
 * Class Migration1766067106TransactionEntity
 *
 * @package PostFinanceCheckoutPayment\Migration
 */
class Migration1766067106TransactionEntity extends MigrationStep
{

    /**
     * get creation timestamp
     *
     * @return int
     */
    public function getCreationTimestamp(): int
    {
        return 1766067106;
    }

    /**
     * update non-destructive changes
     * 
     * @param \Doctrine\DBAL\Connection $connection
     */
    public function update(Connection $connection): void
    {
        $oldTableName = 'postfinancecheckout_transaction';
        $tempTableName = 'postfinancecheckout_transaction_tmp';
        $realTableName = 'postfinancecheckout_transaction_data';
        $logger = new Logger('postfinancecheckout_migration');
        $logger->pushHandler(new StreamHandler(dirname(__DIR__, 5) . '/var/log/postfinancecheckout-migration.log'));
        $logger->info(
            'Migration start', [
                'old_table_exists' => $this->tableExists($connection, $oldTableName),
                'temp_table_exists' => $this->tableExists($connection, $tempTableName),
                'real_table_exists' => $this->tableExists($connection, $realTableName),
            ]
        );

        if ($this->tableExists($connection, $tempTableName)) {
            // If _temp table exists, it means that this is a fresh installation.
            $logger->info('Fresh installation detected.');
            $connection->executeStatement(
                sprintf('RENAME TABLE `%s` TO `%s`', $tempTableName, $realTableName)
            );
            $logger->info('Fresh installation finished.');
        } else {
            // If _temp does not exist, it means that this could be a version upgrade.
            $logger->info('Possible plugin upgrade detected.');
            if ($this->tableExists($connection, $oldTableName) && !$this->isOldPluginTable($connection, $oldTableName)) {
                $logger->info('Old postfinancecheckout_transaction table detected.');
                // If postfinancecheckout_transaction already exists and does not belong to old plugin, 
                // it means that this is indeed a version update.
                $this->syncTransactionTable($connection, $oldTableName);
                $logger->info('Old postfinancecheckout_transaction table sync finished.');
                $this->syncRefundTable($connection, $oldTableName);
                $logger->info('Old postfinancecheckout_refund table sync finished.');
                $connection->executeStatement(
                    sprintf('RENAME TABLE `%s` TO `%s`', $oldTableName, $realTableName)
                );
                $logger->info('Old postfinancecheckout_transaction table renaming completed.');
            }
            $logger->info('Possible plugin upgrade finished.');
            // If postfinancecheckout_transaction exists and it does belong to old plugin, 
            // it means we must run it in parallel.
        }
        $logger->info('Migration finished.');
        return;
    }

    /**
     * Check if table exists.
     * 
     * @param \Doctrine\DBAL\Connection $connection
     * @param string $table
     * 
     * @return bool
     */
    public function tableExists(Connection $connection, string $table): bool {
        $result = $connection->fetchOne('SHOW TABLES LIKE :table', ['table' => $table]);
        return $result !== false && $result !== null;
    }

    /**
     * Check if table belongs to old plugin.
     * 
     * @param \Doctrine\DBAL\Connection $connection
     * @param string $table
     * 
     * @return bool
     */
    public function isOldPluginTable(Connection $connection, string $table): bool {
        $oldTableExclusiveColumns = [
            'finalized_at' => 'datetime',
            'refunded_at' => 'datetime',
            'initial_transaction_mode' => 'varchar',
            'manual_capture' => 'tinyint',
            'partial_refunded_at' => 'datetime',
            'refunded_amount' => 'double',
            'amount_to_refund' => 'double',
        ];
        $resultColumns = $connection->fetchAllAssociative(
            'SELECT LOWER(COLUMN_NAME) AS column_name, LOWER(DATA_TYPE) AS data_type
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = :table',
            ['table' => $table]
        );
        $dbColumns = [];
        foreach($resultColumns as $column) {
            $dbColumns[$column['column_name']] = $column['data_type'];
        }

        $oldPluginTable = true;
        foreach($oldTableExclusiveColumns as $columnName => $columnType) {
            if(!isset($dbColumns[$columnName])) {
                $oldPluginTable = false;
                break;
            }
            if ($dbColumns[$columnName] !== $columnType) {
                $oldPluginTable = false;
                break;
            }
        }
        return $oldPluginTable;
    }

    /**
     * Synchronizes the transaction table with the current/latest version.
     * 
     * @param \Doctrine\DBAL\Connection $connection
     * @param string $table
     */
    private function syncTransactionTable(Connection $connection, string $table): void {
        $this->addColumnIfMissing($connection, $table, 'confirmation_email_sent', "TINYINT(1) NOT NULL DEFAULT 0 AFTER `id`");
        $this->addColumnIfMissing($connection, $table, 'erp_merchant_id', "VARCHAR(255) DEFAULT NULL AFTER `confirmation_email_sent`");
        $this->addColumnIfMissing($connection, $table, 'data', "LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)) AFTER `erp_merchant_id`");
        $this->addColumnIfMissing($connection, $table, 'payment_method_id', "BINARY(16) NOT NULL");
        $this->addColumnIfMissing($connection, $table, 'order_id', "BINARY(16) NOT NULL");
        $this->addColumnIfMissing($connection, $table, 'order_transaction_id', "BINARY(16) NOT NULL");
        $this->addColumnIfMissing($connection, $table, 'space_id', "INT(10) UNSIGNED NOT NULL");
        $this->addColumnIfMissing($connection, $table, 'state', "VARCHAR(255) NOT NULL");
        $this->addColumnIfMissing($connection, $table, 'sales_channel_id', "BINARY(16) NOT NULL");
        $this->addColumnIfMissing($connection, $table, 'transaction_id', "INT(10) UNSIGNED NOT NULL");
        $this->addColumnIfMissing($connection, $table, 'order_version_id', "BINARY(16) NOT NULL AFTER `transaction_id`");

        $this->addColumnIfMissing($connection, $table, 'created_at', "DATETIME(3) NOT NULL");
        $this->addColumnIfMissing($connection, $table, 'updated_at', "DATETIME(3) DEFAULT NULL");

        $this->ensureIndexBySql($connection, $table, 'fk.pfc_transaction.order_id', "KEY `fk.pfc_transaction.order_id` (`order_id`)");
        $this->ensureIndexBySql($connection, $table, 'fk.pfc_transaction.order_transaction_id', "KEY `fk.pfc_transaction.order_transaction_id` (`order_transaction_id`)");
        $this->ensureIndexBySql($connection, $table, 'fk.pfc_transaction.payment_method_id', "KEY `fk.pfc_transaction.payment_method_id` (`payment_method_id`)");
        $this->ensureIndexBySql($connection, $table, 'fk.pfc_transaction.sales_channel_id', "KEY `fk.pfc_transaction.sales_channel_id` (`sales_channel_id`)");
        $this->ensureIndexBySql($connection, $table, 'fk.pfc_transaction', "KEY `fk.pfc_transaction` (`order_id`,`order_version_id`)");

        $this->ensureForeignKey(
            $connection,
            $table,
            'fk.pfc_transaction_order_id',
            ['order_id', 'order_version_id'],
            'order',
            ['id', 'version_id'],
            'CASCADE',
            'CASCADE'
        );
        $this->ensureForeignKey(
            $connection,
            $table,
            'fk.pfc_transaction_payment_method_id',
            ['payment_method_id'],
            'payment_method',
            ['id'],
            'RESTRICT',
            'CASCADE'
        );
        $this->ensureForeignKey(
            $connection,
            $table,
            'fk.pfc_transaction_sales_channel_id',
            ['sales_channel_id'],
            'sales_channel',
            ['id'],
            'RESTRICT',
            'CASCADE'
        );
    }

    /**
     * Synchronizes the parts of the refund table related to transactions with the current/latest version.
     * 
     * @param \Doctrine\DBAL\Connection $connection
     * @param string $table
     */
    private function syncRefundTable(Connection $connection, string $table): void {
        $refundTable = 'postfinancecheckout_refund';
        $this->ensureIndexBySql($connection, $refundTable, 'fk.pfc_refund.transaction_id', "KEY `fk.pfc_refund.transaction_id` (`transaction_id`)");
        $this->ensureForeignKey(
            $connection,
            $refundTable,
            'fk.pfc_refund.transaction_id',
            ['transaction_id'],
            $table,
            ['transaction_id'],
            'CASCADE',
            null
        );
    }

    /**
     * Adds column to the table if it's missing.
     * 
     * @param \Doctrine\DBAL\Connection $connection
     * @param string $table
     * @param string $column
     * @param string $sqlFragment
     */
    private function addColumnIfMissing(Connection $connection, string $table, string $column, string $sqlFragment): void {
        if ($this->columnExists($connection, $table, $column)) {
            return;
        }
        $connection->executeStatement(
            sprintf("ALTER TABLE `%s` ADD COLUMN `%s` %s", $table, $column, $sqlFragment)
        );
    }

    /**
     * Adds index to the table if it's missing.
     * 
     * @param \Doctrine\DBAL\Connection $connection
     * @param string $table
     * @param string $indexName
     * @param string $sqlFragment
     */
    private function ensureIndexBySql(Connection $connection, string $table, string $indexName, string $sqlFragment): void {
        if ($this->indexExists($connection, $table, $indexName)) {
            return;
        }
        $connection->executeStatement(
            sprintf("ALTER TABLE `%s` ADD %s", $table, $sqlFragment)
        );
    }

    /**
     * Adds foreign key constraint to the table if it's missing.
     * 
     * @param \Doctrine\DBAL\Connection $connection
     * @param string $table
     * @param string $constraintName
     * @param string $columns
     * @param string $refTable
     * @param string $refColumns
     * @param string|null $onDelete
     * @param string|null $onUpdate
     */
    private function ensureForeignKey(
        Connection $connection,
        string $table,
        string $constraintName,
        array $columns,
        string $refTable,
        array $refColumns,
        ?string $onDelete,
        ?string $onUpdate
    ): void {
        if ($this->foreignKeyExists($connection, $table, $constraintName)) {
            return;
        }
        $columnsList = '`' . implode('`,`', $columns) . '`';
        $refColumnsList = '`' . implode('`,`', $refColumns) . '`';
        $connection->executeStatement(
            sprintf(
                "ALTER TABLE `%s`
                ADD CONSTRAINT `%s` FOREIGN KEY (%s)
                REFERENCES `%s` (%s)%s%s",
                $table,
                $constraintName,
                $columnsList,
                $refTable,
                $refColumnsList,
                $onDelete ? " ON DELETE {$onDelete}" : "",
                $onUpdate ? " ON UPDATE {$onUpdate}" : ""
            )
        );
    }

    /**
     * Check if foreign key constraint exists.
     * 
     * @param \Doctrine\DBAL\Connection $connection
     * @param string $table
     * @param string $constraintName
     * 
     * @return bool
     */
    private function foreignKeyExists(Connection $connection, string $table, $constraintName): bool {
        $result = $connection->fetchOne(
            "SELECT 1 FROM information_schema.referential_constraints
                WHERE constraint_schema = DATABASE()
                AND table_name = ?
                AND constraint_name = ?
                LIMIT 1",
            [$table,$constraintName]
        );
        return $result !== false && $result !== null;
    }

    /**
     * update destructive changes
     * 
     * @param \Doctrine\DBAL\Connection $connection
     */
    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}