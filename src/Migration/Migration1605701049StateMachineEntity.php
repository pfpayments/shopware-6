<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateDefinition;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionDefinition;
use Shopware\Core\System\StateMachine\StateMachineDefinition;

/**
 * Class Migration1605701049StateMachineEntity
 *
 * @package PostFinanceCheckoutPayment\Migration
 */
class Migration1605701049StateMachineEntity extends MigrationStep
{

    /**
     * get creation timestamp
     *
     * @return int
     */
    public function getCreationTimestamp(): int
    {
        return 1605701049;
    }

	/**
	 * update non-destructive changes
	 *
	 * @param \Doctrine\DBAL\Connection $connection
	 */
    public function update(Connection $connection): void
    {
        try {
        	// Enable mark transaction as paid when it's on status reminded
			$table = StateMachineDefinition::ENTITY_NAME;
			$stateMachineId =  $connection->fetchColumn(
				"SELECT id FROM `$table` WHERE `technical_name` = :technical_name",
				[
					'technical_name' => 'order_transaction.state',
				]
			);

			$table = StateMachineStateDefinition::ENTITY_NAME;
			$remindedStateId =  $connection->fetchColumn(
				"SELECT id FROM `$table` WHERE `technical_name` = :technical_name AND `state_machine_id` = :state_machine_id",
				[
					'technical_name' => 'reminded',
					'state_machine_id' => $stateMachineId,
				]
			);

			$paidStateId =  $connection->fetchColumn(
				"SELECT id FROM `$table` WHERE `technical_name` = :technical_name AND `state_machine_id` = :state_machine_id",
				[
					'technical_name' => 'paid',
					'state_machine_id' => $stateMachineId,
				]
			);

			$id = Uuid::randomBytes();
			$connection->insert(StateMachineTransitionDefinition::ENTITY_NAME,
				[
					'id' => $id,
					'action_name' => 'paid',
					'state_machine_id' => $stateMachineId,
					'from_state_id' => $remindedStateId,
					'to_state_id' => $paidStateId,
					'created_at' => date('Y-m-d H:i:s')
				]
			);
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
