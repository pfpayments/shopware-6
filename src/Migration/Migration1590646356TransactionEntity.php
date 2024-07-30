<?php declare(strict_types=1);

namespace WeArePlanetPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Class Migration1590646356TransactionEntity
 *
 * @package WeArePlanetPayment\Migration
 */
class Migration1590646356TransactionEntity extends MigrationStep {

	/**
	 * get creation timestamp
	 *
	 * @return int
	 */
	public function getCreationTimestamp(): int
	{
		return 1590646356;
	}

	/**
	 * update non-destructive changes
	 *
	 * @param \Doctrine\DBAL\Connection $connection
	 */
	public function update(Connection $connection): void
	{
		try {
			$connection->executeStatement('ALTER TABLE `weareplanet_transaction` ADD COLUMN `confirmation_email_sent` TINYINT(1) NOT NULL DEFAULT 0 AFTER `id`;');
		}catch (\Exception $exception){
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
