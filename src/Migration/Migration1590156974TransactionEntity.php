<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Class Migration1590156974TransactionEntity
 *
 * @package PostFinanceCheckoutPayment\Migration
 */
class Migration1590156974TransactionEntity extends MigrationStep {

	/**
	 * get creation timestamp
	 *
	 * @return int
	 */
	public function getCreationTimestamp(): int
	{
		return 1590156974;
	}

	/**
	 * update non-destructive changes
	 *
	 * @param \Doctrine\DBAL\Connection $connection
	 * @throws \Doctrine\DBAL\DBALException
	 */
	public function update(Connection $connection): void
	{
		// `order` and `order_transaction` have a composite primary key (`id`, `version_id`), and since
		// MySQL 8.4 a foreign key must reference a complete unique/primary key. The single-column
		// foreign keys this migration used to create are therefore invalid DDL on MySQL 8.4 and made
		// a fresh installation abort here with error 6125.
		//
		// They are not declared any more. Both are produced later with the correct composite form, so
		// the end result is unchanged: `order_id` by Migration1605701048, `order_transaction_id` by
		// Migration1788457529. Nothing else about this statement changes, and an installation that
		// already ran this migration is not affected, because a migration never re-runs.
		$connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `postfinancecheckout_transaction_tmp` (
              `id` BINARY(16) NOT NULL,
              `data` JSON NOT NULL,
              `payment_method_id` BINARY(16) NOT NULL,
              `order_id` BINARY(16) NOT NULL,
              `order_transaction_id` BINARY(16) NOT NULL,
              `space_id` INT UNSIGNED NOT NULL,
              `state` VARCHAR(255) NOT NULL,
              `sales_channel_id` BINARY(16) NOT NULL,
              `transaction_id` INT UNSIGNED NOT NULL,
              `created_at` DATETIME(3) NOT NULL,
              `updated_at` DATETIME(3) DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `transaction_id_UNIQUE` (`transaction_id`),
              KEY `fk.pfc_transaction.order_id` (`order_id`),
              KEY `fk.pfc_transaction.order_transaction_id` (`order_transaction_id`),
              KEY `fk.pfc_transaction.payment_method_id` (`payment_method_id`),
              KEY `fk.pfc_transaction.sales_channel_id` (`sales_channel_id`),
              CONSTRAINT `fk.pfc_transaction.payment_method_id` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_method` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk.pfc_transaction.sales_channel_id` FOREIGN KEY (`sales_channel_id`) REFERENCES `sales_channel` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
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
