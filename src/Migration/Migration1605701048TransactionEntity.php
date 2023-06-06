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
	 * @param \Doctrine\DBAL\Connection $connection
	 */
    public function update(Connection $connection): void
    {

        try {
            $connection->executeStatement('
                ALTER TABLE `postfinancecheckout_transaction`
                    ADD `order_version_id` binary(16) NOT NULL AFTER `transaction_id`;
            ');

            $connection->executeStatement('
                UPDATE `postfinancecheckout_transaction` t1
                    INNER JOIN `order` t2
                        ON t1.order_id = t2.id
                    SET t1.order_version_id = t2.version_id;
            ');

            $connection->executeStatement('
                ALTER TABLE `postfinancecheckout_transaction`
                    DROP FOREIGN KEY `fk.pfc_transaction.order_id`,
                    DROP FOREIGN KEY `fk.pfc_transaction.order_transaction_id`,
                    DROP FOREIGN KEY `fk.pfc_transaction.payment_method_id`,
                    DROP FOREIGN KEY `fk.pfc_transaction.sales_channel_id`;
            ');

            $connection->executeStatement('
                ALTER TABLE `postfinancecheckout_transaction`
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
