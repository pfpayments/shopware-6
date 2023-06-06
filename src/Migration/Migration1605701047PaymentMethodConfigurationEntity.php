<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Class Migration1605701047PaymentMethodConfigurationEntity
 *
 * @package PostFinanceCheckoutPayment\Migration
 */
class Migration1605701047PaymentMethodConfigurationEntity extends MigrationStep {
	/**
	 * get creation timestamp
	 *
	 * @return int
	 */
	public function getCreationTimestamp(): int
	{
		return 1605701047;
	}

	/**
	 * update non-destructive changes
	 *
	 * @param \Doctrine\DBAL\Connection $connection
	 * @throws \Doctrine\DBAL\DBALException
	 */
	public function update(Connection $connection): void
	{
		$connection->executeStatement('ALTER TABLE `postfinancecheckout_payment_method_configuration` CHANGE `payment_method_configuration_id` `payment_method_configuration_id` bigint unsigned NOT NULL AFTER `data`;');
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
