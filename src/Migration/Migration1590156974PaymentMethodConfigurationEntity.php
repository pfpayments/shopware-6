<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Class Migration1590156974PaymentMethodConfigurationEntity
 *
 * @package PostFinanceCheckoutPayment\Migration
 */
class Migration1590156974PaymentMethodConfigurationEntity extends MigrationStep {
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
		$connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `postfinancecheckout_payment_method_configuration` (
              `id` BINARY(16) NOT NULL,
              `data` JSON NOT NULL,
              `payment_method_configuration_id` INT UNSIGNED NOT NULL,
              `payment_method_id` BINARY(16) NOT NULL,
              `sort_order` TINYINT UNSIGNED NOT NULL,
              `space_id` INT UNSIGNED NOT NULL,
              `state` VARCHAR(255) NOT NULL,
              `created_at` DATETIME(3) NOT NULL,
              `updated_at` DATETIME(3) DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `payment_method_configuration_id_space_id_UNIQUE` (`payment_method_configuration_id`,`space_id`),
              KEY `fk.pfc_payment_method_configuration.payment_method_id` (`payment_method_id`),
              CONSTRAINT `fk.pfc_payment_method_configuration.payment_method_id` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_method` (`id`) ON DELETE CASCADE
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
