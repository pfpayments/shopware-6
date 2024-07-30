<?php declare(strict_types=1);

namespace WeArePlanetPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Class Migration1590646356OrderEntity
 *
 * @package WeArePlanetPayment\Migration
 */
class Migration1590646356OrderEntity extends MigrationStep {
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
			$connection->executeStatement('ALTER TABLE `order` ADD COLUMN `weareplanet_lock` DATETIME DEFAULT NULL;');
		}catch (\Exception $exception){
			echo $exception->getMessage();
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
