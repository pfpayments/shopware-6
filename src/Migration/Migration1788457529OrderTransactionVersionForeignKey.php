<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Class Migration1788457529OrderTransactionVersionForeignKey
 *
 * Brings `postfinancecheckout_transaction_data` to its final shape with regard to the
 * `order_transaction` reference, and repairs the version columns of existing rows.
 *
 * Why this is a separate migration
 * --------------------------------
 * Since MySQL 8.4 a foreign key has to reference a complete unique/primary key. `order_transaction`
 * has a composite primary key (`id`, `version_id`), so the reference has to be composite as well.
 * Migration1590156974 could not simply be corrected for that, because an edited migration never
 * re-runs on an installation that already executed it. That migration was therefore only stripped of
 * the two invalid foreign keys (the minimum needed to make a fresh install possible on MySQL 8.4),
 * and the actual convergence happens here instead - in one place, for every installation:
 *
 *   - fresh install                    -> adds the column and the composite foreign key
 *   - update from plugin < 6.2.0       -> same, after Migration1766067106 renamed the table
 *   - update from plugin 6.2.0 - 6.2.6 -> same; these never re-run the edited migrations
 *   - MySQL 8.0 -> 8.4 database upgrade without a plugin update -> picked up on the next update
 *
 * The step is idempotent and skippable: when the column and the composite foreign key are already in
 * place it returns after two information_schema look-ups.
 *
 * Why the `order_transaction_id` reference was missing in the first place
 * ----------------------------------------------------------------------
 * Migration1605701048 dropped `fk.pfc_transaction.order_transaction_id` and never recreated it, so
 * the column has been unconstrained since 2020. Rows that reference an `order_transaction` which no
 * longer exists could therefore accumulate, and those rows have to be removed before the foreign key
 * can be validated.
 *
 * @package PostFinanceCheckoutPayment\Migration
 */
class Migration1788457529OrderTransactionVersionForeignKey extends MigrationStep
{
    private const TABLE = 'postfinancecheckout_transaction_data';

    private const COLUMN = 'order_transaction_version_id';

    private const FOREIGN_KEY = 'fk.pfc_transaction_order_transaction_id';

    /**
     * Index created by Migration1590156974 that only covers `order_transaction_id`. It becomes a
     * redundant prefix of the composite index once the foreign key is in place.
     */
    private const LEGACY_INDEX = 'fk.pfc_transaction.order_transaction_id';

    /**
     * Rows are touched in batches so that a large table does not end up in one long running
     * statement that could hit a lock or query timeout.
     */
    private const BATCH_SIZE = 5000;

    /**
     * get creation timestamp
     *
     * @return int
     */
    public function getCreationTimestamp(): int
    {
        return 1788457529;
    }

    /**
     * update non-destructive changes
     *
     * @param \Doctrine\DBAL\Connection $connection
     */
    public function update(Connection $connection): void
    {
        $logger = $this->createLogger();

        // Shops that still run the legacy plugin in parallel never get this table, because
        // Migration1766067106 leaves their `postfinancecheckout_transaction` table alone.
        if (!$this->tableExists($connection, self::TABLE)) {
            $this->log($logger, 'Table not present, nothing to migrate.');
            return;
        }

        if (
            $this->columnExists($connection, self::TABLE, self::COLUMN)
            && $this->compositeOrderTransactionForeignKeyExists($connection)
        ) {
            $this->log($logger, 'Schema already converged, skipping.');
            return;
        }

        $liveVersion = Uuid::fromHexToBytes(Defaults::LIVE_VERSION);

        $this->addVersionColumn($connection, $logger);

        // Both version columns must point at the live version. Migration1605701048 backfilled
        // `order_version_id` by joining on `id` alone, so on a versioned order it could have picked
        // any version - with ON DELETE CASCADE such a row disappears as soon as that version is
        // discarded. The same mistake is avoided here by writing the live version directly.
        $this->backfillVersionColumn($connection, $logger, 'order_version_id', 'order', 'order_id', $liveVersion);
        $this->backfillVersionColumn($connection, $logger, self::COLUMN, 'order_transaction', 'order_transaction_id', $liveVersion);

        $this->deleteRowsWithoutOrderTransaction($connection, $logger, $liveVersion);

        // A single-column foreign key could only have survived here on a shop that somehow never ran
        // Migration1605701048 to the end. It has to go before the composite one is added.
        $this->dropForeignKeyIfExists($connection, self::TABLE, 'fk.pfc_transaction.order_transaction_id');

        $this->addCompositeIndex($connection, $logger);
        $this->addCompositeForeignKey($connection, $logger);

        $this->log($logger, 'Migration finished.');
    }

    /**
     * update destructive changes
     *
     * @param \Doctrine\DBAL\Connection $connection
     */
    public function updateDestructive(Connection $connection): void
    {
        // Once the composite index exists, the single-column index from Migration1590156974 is a
        // redundant prefix of it. Dropping it is not required for correctness, so it stays out of
        // the non-destructive step.
        if (!$this->tableExists($connection, self::TABLE)) {
            return;
        }

        if (!$this->indexExists($connection, self::TABLE, self::FOREIGN_KEY)) {
            return;
        }

        $this->dropIndexIfExists($connection, self::TABLE, self::LEGACY_INDEX);
    }

    /**
     * Adds the version column at the end of the table.
     *
     * No AFTER clause is used on purpose: it would force a full table copy, while appending the
     * column can be done as an instant, metadata-only change. Existing rows get the implicit
     * all-zero default and are corrected by the backfill.
     *
     * @param \Doctrine\DBAL\Connection $connection
     * @param \Monolog\Logger|null $logger
     */
    private function addVersionColumn(Connection $connection, ?Logger $logger): void
    {
        if ($this->columnExists($connection, self::TABLE, self::COLUMN)) {
            return;
        }

        $sql = sprintf(
            'ALTER TABLE `%s` ADD COLUMN `%s` BINARY(16) NOT NULL',
            self::TABLE,
            self::COLUMN
        );

        try {
            $connection->executeStatement($sql . ', ALGORITHM=INSTANT');
        } catch (DBALException) {
            $connection->executeStatement($sql);
        }

        $this->log($logger, sprintf('Column `%s` added.', self::COLUMN));
    }

    /**
     * Points a version column at the live version wherever the referenced live row exists.
     *
     * @param \Doctrine\DBAL\Connection $connection
     * @param \Monolog\Logger|null $logger
     * @param string $column version column on this plugin's table
     * @param string $referencedTable versioned Shopware table
     * @param string $referenceColumn column on this plugin's table holding the referenced id
     * @param string $liveVersion live version id as bytes
     */
    private function backfillVersionColumn(
        Connection $connection,
        ?Logger    $logger,
        string     $column,
        string     $referencedTable,
        string     $referenceColumn,
        string     $liveVersion
    ): void {
        if (!$this->columnExists($connection, self::TABLE, $column)) {
            return;
        }

        $sql = sprintf(
            'UPDATE `%1$s`
                SET `%2$s` = :liveVersion
                WHERE `%2$s` <> :liveVersion
                    AND EXISTS (
                        SELECT 1 FROM `%3$s` ref
                        WHERE ref.`id` = `%1$s`.`%4$s`
                            AND ref.`version_id` = :liveVersion
                    )
                LIMIT %5$d',
            self::TABLE,
            $column,
            $referencedTable,
            $referenceColumn,
            self::BATCH_SIZE
        );

        $total = 0;
        do {
            $affected = (int) $connection->executeStatement($sql, ['liveVersion' => $liveVersion]);
            $total += $affected;
        } while ($affected === self::BATCH_SIZE);

        if ($total > 0) {
            $this->log($logger, sprintf('Backfilled `%s` on %d row(s).', $column, $total));
        }
    }

    /**
     * Removes rows whose `order_transaction_id` has no live `order_transaction`.
     *
     * The column was unconstrained for years, so such rows can exist and would make the foreign key
     * fail with errno 1452. They are unusable anyway - there is no order transaction left to attach
     * them to - and once the foreign key is in place they would be cascade-deleted.
     *
     * Their refunds go with them through `fk.pfc_refund.transaction_id`, which is ON DELETE CASCADE.
     * That is intended: a refund row whose transaction has no order transaction cannot be used
     * either. The number of deleted rows and a sample of their `transaction_id`s are logged so the
     * deletion can be traced afterwards.
     *
     * @param \Doctrine\DBAL\Connection $connection
     * @param \Monolog\Logger|null $logger
     * @param string $liveVersion live version id as bytes
     */
    private function deleteRowsWithoutOrderTransaction(Connection $connection, ?Logger $logger, string $liveVersion): void
    {
        $condition = sprintf(
            'NOT EXISTS (
                SELECT 1 FROM `order_transaction` ot
                WHERE ot.`id` = `%1$s`.`order_transaction_id`
                    AND ot.`version_id` = :liveVersion
            )',
            self::TABLE
        );

        $sample = $connection->fetchFirstColumn(
            sprintf('SELECT `transaction_id` FROM `%s` WHERE %s LIMIT 20', self::TABLE, $condition),
            ['liveVersion' => $liveVersion]
        );

        if ($sample === []) {
            return;
        }

        $sql = sprintf('DELETE FROM `%s` WHERE %s LIMIT %d', self::TABLE, $condition, self::BATCH_SIZE);

        $total = 0;
        do {
            $affected = (int) $connection->executeStatement($sql, ['liveVersion' => $liveVersion]);
            $total += $affected;
        } while ($affected === self::BATCH_SIZE);

        $this->log(
            $logger,
            sprintf('Deleted %d row(s) without a live order transaction.', $total),
            ['sample_transaction_ids' => $sample]
        );
    }

    /**
     * @param \Doctrine\DBAL\Connection $connection
     * @param \Monolog\Logger|null $logger
     */
    private function addCompositeIndex(Connection $connection, ?Logger $logger): void
    {
        if ($this->indexExists($connection, self::TABLE, self::FOREIGN_KEY)) {
            return;
        }

        $connection->executeStatement(sprintf(
            'ALTER TABLE `%s` ADD KEY `%s` (`order_transaction_id`, `%s`)',
            self::TABLE,
            self::FOREIGN_KEY,
            self::COLUMN
        ));

        $this->log($logger, sprintf('Index `%s` added.', self::FOREIGN_KEY));
    }

    /**
     * Adds the composite foreign key.
     *
     * A failure here is logged instead of thrown. The column and the backfill are what the entity
     * definition needs in order to keep persisting transactions, and those are already done at this
     * point - aborting the plugin update over a missing constraint would do more harm than the
     * missing constraint itself.
     *
     * @param \Doctrine\DBAL\Connection $connection
     * @param \Monolog\Logger|null $logger
     */
    private function addCompositeForeignKey(Connection $connection, ?Logger $logger): void
    {
        if ($this->compositeOrderTransactionForeignKeyExists($connection)) {
            return;
        }

        try {
            $connection->executeStatement(sprintf(
                'ALTER TABLE `%s`
                    ADD CONSTRAINT `%s` FOREIGN KEY (`order_transaction_id`, `%s`)
                        REFERENCES `order_transaction` (`id`, `version_id`)
                        ON DELETE CASCADE ON UPDATE CASCADE',
                self::TABLE,
                self::FOREIGN_KEY,
                self::COLUMN
            ));

            $this->log($logger, sprintf('Foreign key `%s` added.', self::FOREIGN_KEY));
        } catch (\Throwable $exception) {
            $this->log(
                $logger,
                sprintf(
                    'Could not add foreign key `%s`: %s. The column is in place, so the plugin keeps working. '
                    . 'Re-run this migration after cleaning up the table to add the constraint.',
                    self::FOREIGN_KEY,
                    $exception->getMessage()
                ),
                [],
                true
            );
        }
    }

    /**
     * Checks whether a foreign key on this table references `order_transaction` through exactly
     * (`order_transaction_id`, `order_transaction_version_id`), whatever it is called.
     *
     * The name is deliberately ignored: installations that ran an earlier attempt at this fix may
     * carry the constraint under a dot-style name, and recreating it would be pointless work.
     *
     * @param \Doctrine\DBAL\Connection $connection
     *
     * @return bool
     */
    private function compositeOrderTransactionForeignKeyExists(Connection $connection): bool
    {
        $rows = $connection->fetchAllAssociative(
            "SELECT CONSTRAINT_NAME, COLUMN_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE CONSTRAINT_SCHEMA = DATABASE()
                    AND TABLE_NAME = :table
                    AND REFERENCED_TABLE_NAME = 'order_transaction'
                ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION",
            ['table' => self::TABLE]
        );

        $columnsByConstraint = [];
        foreach ($rows as $row) {
            $columnsByConstraint[$row['CONSTRAINT_NAME']][] = strtolower((string) $row['COLUMN_NAME']);
        }

        foreach ($columnsByConstraint as $columns) {
            if ($columns === ['order_transaction_id', self::COLUMN]) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param \Doctrine\DBAL\Connection $connection
     * @param string $table
     *
     * @return bool
     */
    private function tableExists(Connection $connection, string $table): bool
    {
        return (bool) $connection->fetchOne('SHOW TABLES LIKE :table', ['table' => $table]);
    }

    /**
     * Logs into the same file as Migration1766067106.
     *
     * Logging must never be the reason a plugin update fails, so a handler that cannot be opened
     * simply disables logging for this run.
     *
     * @return \Monolog\Logger|null
     */
    private function createLogger(): ?Logger
    {
        try {
            $logger = new Logger('postfinancecheckout_migration');
            $logger->pushHandler(new StreamHandler(dirname(__DIR__, 5) . '/var/log/postfinancecheckout-migration.log'));

            return $logger;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param \Monolog\Logger|null $logger
     * @param string $message
     * @param array $context
     * @param bool $critical
     */
    private function log(?Logger $logger, string $message, array $context = [], bool $critical = false): void
    {
        if ($logger === null) {
            return;
        }

        $message = self::class . ' : ' . $message;

        if ($critical) {
            $logger->critical($message, $context);
            return;
        }

        $logger->info($message, $context);
    }
}
