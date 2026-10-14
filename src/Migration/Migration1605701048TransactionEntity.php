<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Class Migration1605701048TransactionEntity
 *
 * @package PostFinanceCheckoutPayment\Migration
 */
class Migration1605701048TransactionEntity extends MigrationStep
{

    /**
     * get creation timestamp
     *
     * @return int
     */
    public function getCreationTimestamp(): int
    {
        return 1605701048;
    }

	/**
	 * update non-destructive changes
	 *
	 * The single-column foreign keys on `order_id` and `order_transaction_id` are no longer declared
	 * by Migration1590156974, because they are invalid DDL on MySQL 8.4. They are dropped
	 * conditionally here so that this migration keeps working both on an installation that still has
	 * them and on a fresh one that never created them. Without that, the failing DROP would be
	 * swallowed by the catch below and the composite foreign keys would never be created.
	 *
	 * @param \Doctrine\DBAL\Connection $connection
	 */
    public function update(Connection $connection): void
    {

        try {
            $connection->executeStatement('
                ALTER TABLE `postfinancecheckout_transaction_tmp`
                    ADD `order_version_id` binary(16) NOT NULL AFTER `transaction_id`;
            ');

            $connection->executeStatement('
                UPDATE `postfinancecheckout_transaction_tmp` t1
                    INNER JOIN `order` t2
                        ON t1.order_id = t2.id
                    SET t1.order_version_id = t2.version_id;
            ');

            $this->dropForeignKeyIfExists($connection, 'postfinancecheckout_transaction_tmp', 'fk.pfc_transaction.order_id');
            $this->dropForeignKeyIfExists($connection, 'postfinancecheckout_transaction_tmp', 'fk.pfc_transaction.order_transaction_id');
            $this->dropForeignKeyIfExists($connection, 'postfinancecheckout_transaction_tmp', 'fk.pfc_transaction.payment_method_id');
            $this->dropForeignKeyIfExists($connection, 'postfinancecheckout_transaction_tmp', 'fk.pfc_transaction.sales_channel_id');

            $connection->executeStatement('
                ALTER TABLE `postfinancecheckout_transaction_tmp`
                    ADD CONSTRAINT `fk.pfc_transaction_order_id` FOREIGN KEY (`order_id`, `order_version_id`)
                        REFERENCES `order` (`id`, `version_id`) ON DELETE CASCADE ON UPDATE CASCADE,
                    ADD CONSTRAINT `fk.pfc_transaction_payment_method_id` FOREIGN KEY (`payment_method_id`)
                        REFERENCES `payment_method` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
                    ADD CONSTRAINT `fk.pfc_transaction_sales_channel_id` FOREIGN KEY (`sales_channel_id`)
                        REFERENCES `sales_channel` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT;
            ');
        } catch (\Exception $exception) {
            // column probably exists
        }
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
