<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Class Migration1684240994TransactionEntity
 *
 * @package PostFinanceCheckoutPayment\Migration
 */
class Migration1684240994TransactionEntity extends MigrationStep {
	
	/**
	 * get creation timestamp
	 *
	 * @return int
	 */
	public function getCreationTimestamp(): int
	{
		return 1684240994;
	}
	
	/**
	 * update non-destructive changes
	 *
	 * @param \Doctrine\DBAL\Connection $connection
	 */
	public function update(Connection $connection): void
	{
		try {
			$connection->executeStatement('ALTER TABLE `postfinancecheckout_transaction` ADD COLUMN `erp_merchant_id` VARCHAR(255) DEFAULT NULL AFTER `confirmation_email_sent`;');
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
