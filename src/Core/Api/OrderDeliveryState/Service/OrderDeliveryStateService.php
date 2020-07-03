<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Api\OrderDeliveryState\Service;

use Psr\Container\ContainerInterface;
use Shopware\Core\{
	Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryStates,
	Framework\Context,
	Framework\DataAbstractionLayer\Search\Criteria,
	Framework\DataAbstractionLayer\Search\Filter\EqualsFilter,
	Framework\Uuid\Uuid};
use PostFinanceCheckoutPayment\Core\Api\OrderDeliveryState\Handler\OrderDeliveryStateHandler;

/**
 * Class OrderDeliveryStateService
 *
 * @package PostFinanceCheckoutPayment\Core\Api\OrderDeliveryState\Service
 */
class OrderDeliveryStateService {

	/**
	 * @var \Psr\Container\ContainerInterface
	 */
	protected $container;

	/**
	 * OrderDeliveryStateHandler constructor.
	 *
	 * @param \Psr\Container\ContainerInterface $container
	 */
	public function __construct(ContainerInterface $container)
	{
		$this->container = $container;
	}

	/**
	 * @param \Shopware\Core\Framework\Context $context
	 */
	public function install(Context $context): void
	{
		$stateMachineId = $this->getStateMachineEntity($context);
		$holdStateId    = $this->getHoldStateId($stateMachineId, $context);
		$openStateId    = $this->getOpenStateId($stateMachineId, $context);

		$this->upsertHoldTransition($stateMachineId, $openStateId, $holdStateId, $context);
		$this->upsertUnholdTransition($stateMachineId, $holdStateId, $openStateId, $context);

	}

	/**
	 * @param \Shopware\Core\Framework\Context $context
	 * @return \Shopware\Core\System\StateMachine\StateMachineEntity
	 */
	protected function getStateMachineEntity(Context $context): string
	{
		$stateMachineCriteria = (new Criteria())
			->addFilter(new EqualsFilter('technicalName', OrderDeliveryStates::STATE_MACHINE));
		$stateMachineEntity   = $this->container->get('state_machine.repository')->search($stateMachineCriteria, $context)->first();
		return $stateMachineEntity->getId();
	}

	/**
	 * @param string                           $stateMachineId
	 * @param \Shopware\Core\Framework\Context $context
	 * @return string
	 */
	protected function getHoldStateId(string $stateMachineId, Context $context): string
	{
		$stateMachineStateRepository = $this->container->get('state_machine_state.repository');

		$holdStateMachineStateCriteria = (new Criteria())
			->addFilter(
				new EqualsFilter('technicalName', OrderDeliveryStateHandler::STATE_HOLD),
				new EqualsFilter('stateMachineId', $stateMachineId)
			);

		$holdStateMachineStateEntity = $stateMachineStateRepository->search($holdStateMachineStateCriteria, $context)->first();

		$holdStateId = is_null($holdStateMachineStateEntity) ? Uuid::randomHex() : $holdStateMachineStateEntity->getId();

		if (is_null($holdStateMachineStateEntity)) {
			$data = [
				'id'             => $holdStateId,
				'technicalName'  => OrderDeliveryStateHandler::STATE_HOLD,
				'stateMachineId' => $stateMachineId,
				'translations'   => [
					'en-GB' => [
						'name' => 'Hold',
					],
					'de-DE' => [
						'name' => 'Halten',
					],
				],
			];
			$stateMachineStateRepository->upsert([$data], $context);
		}

		return $holdStateId;
	}

	/**
	 * @param string                           $stateMachineId
	 * @param \Shopware\Core\Framework\Context $context
	 * @return string
	 */
	protected function getOpenStateId(string $stateMachineId, Context $context): string
	{
		$stateMachineStateRepository = $this->container->get('state_machine_state.repository');

		$stateMachineStateCriteria = (new Criteria())
			->addFilter(
				new EqualsFilter('technicalName', OrderDeliveryStates::STATE_OPEN),
				new EqualsFilter('stateMachineId', $stateMachineId)
			);

		$stateMachineStateEntity = $stateMachineStateRepository->search($stateMachineStateCriteria, $context)->first();
		return $stateMachineStateEntity->getId();
	}

	/**
	 * @param string                           $stateMachineId
	 * @param string                           $openStateId
	 * @param string                           $holdStateId
	 * @param \Shopware\Core\Framework\Context $context
	 */
	protected function upsertHoldTransition(string $stateMachineId, string $openStateId, string $holdStateId, Context $context)
	{
		$translations = [
			'en-GB' => [
				'name' => 'Hold',
			],
			'de-DE' => [
				'name' => 'Halten',
			],
		];

		$this->upsertTransition(OrderDeliveryStateHandler::ACTION_HOLD, $stateMachineId, $openStateId, $holdStateId, $translations, $context);
	}

	protected function upsertTransition(string $actionName, string $stateMachineId, string $fromStateId, string $toStateId, array $translations, Context $context): void
	{
		$stateMachineTransitionRepository = $this->container->get('state_machine_transition.repository');
		$criteria                         = (new Criteria())
			->addFilter(
				new EqualsFilter('actionName', $actionName),
				new EqualsFilter('stateMachineId', $stateMachineId),
				new EqualsFilter('fromStateId', $fromStateId),
				new EqualsFilter('toStateId', $toStateId)
			);

		$stateMachineTransitionEntity = $stateMachineTransitionRepository->search($criteria, $context)->first();
		$transitionId                 = is_null($stateMachineTransitionEntity) ? Uuid::randomHex() : $stateMachineTransitionEntity->getId();

		if (is_null($stateMachineTransitionEntity)) {
			$data = [
				'id'             => $transitionId,
				'actionName'     => $actionName,
				'stateMachineId' => $stateMachineId,
				'fromStateId'    => $fromStateId,
				'toStateId'      => $toStateId,
				'translations'   => $translations,
			];
			$stateMachineTransitionRepository->upsert([$data], $context);
		}

	}

	/**
	 * @param string                           $stateMachineId
	 * @param string                           $openStateId
	 * @param string                           $holdStateId
	 * @param \Shopware\Core\Framework\Context $context
	 */
	protected function upsertUnholdTransition(string $stateMachineId, string $holdStateId, string $openStateId, Context $context)
	{
		$translations = [
			'en-GB' => [
				'name' => 'Unhold',
			],
			'de-DE' => [
				'name' => 'Aufheben',
			],
		];

		$this->upsertTransition(OrderDeliveryStateHandler::ACTION_UNHOLD, $stateMachineId, $holdStateId, $openStateId, $translations, $context);
	}
}