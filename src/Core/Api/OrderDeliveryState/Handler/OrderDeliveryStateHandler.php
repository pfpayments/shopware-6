<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Api\OrderDeliveryState\Handler;

use Shopware\Core\{
	Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryDefinition,
	Framework\Context,
	System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions,
	System\StateMachine\StateMachineRegistry,
	System\StateMachine\Transition};

/**
 * Class OrderDeliveryStateHandler
 *
 * @package PostFinanceCheckoutPayment\Core\Api\OrderDeliveryState\Handler
 */
class OrderDeliveryStateHandler {

	public const STATE_HOLD    = 'hold';
	public const ACTION_HOLD   = 'hold';
	public const ACTION_UNHOLD = 'unhold';

	/**
	 * @var \Shopware\Core\System\StateMachine\StateMachineRegistry
	 */
	private $stateMachineRegistry;

	/**
	 * OrderDeliveryStateHandler constructor.
	 *
	 * @param \Shopware\Core\System\StateMachine\StateMachineRegistry $stateMachineRegistry
	 */
	public function __construct(StateMachineRegistry $stateMachineRegistry)
	{
		$this->stateMachineRegistry = $stateMachineRegistry;
	}

	/**
	 * @param string                           $entityId
	 * @param \Shopware\Core\Framework\Context $context
	 */
	public function hold(string $entityId, Context $context): void
	{
		$this->stateMachineRegistry->transition(
			new Transition(
				OrderDeliveryDefinition::ENTITY_NAME,
				$entityId,
				self::ACTION_HOLD,
				'stateId'
			),
			$context
		);
	}

	/**
	 * @param string                           $entityId
	 * @param \Shopware\Core\Framework\Context $context
	 */
	public function unhold(string $entityId, Context $context): void
	{
		$this->stateMachineRegistry->transition(
			new Transition(
				OrderDeliveryDefinition::ENTITY_NAME,
				$entityId,
				self::ACTION_UNHOLD,
				'stateId'
			),
			$context
		);
	}

	/**
	 * @param string                           $entityId
	 * @param \Shopware\Core\Framework\Context $context
	 */
	public function cancel(string $entityId, Context $context): void
	{
		$this->stateMachineRegistry->transition(
			new Transition(
				OrderDeliveryDefinition::ENTITY_NAME,
				$entityId,
				StateMachineTransitionActions::ACTION_CANCEL,
				'stateId'
			),
			$context
		);
	}
}