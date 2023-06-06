<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Api\OrderDeliveryState\Service;

use Psr\Container\ContainerInterface;
use Shopware\Core\{
	Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryStates,
	Framework\Context,
	Framework\DataAbstractionLayer\Search\Criteria,
	Framework\DataAbstractionLayer\Search\Filter\EqualsFilter,
	Framework\Uuid\Uuid};
use PostFinanceCheckoutPayment\Core\{
	Api\OrderDeliveryState\Handler\OrderDeliveryStateHandler,
	Util\LocaleCodeProvider};

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
	 * @var \PostFinanceCheckoutPayment\Core\Util\LocaleCodeProvider
	 */
	protected $localeCodeProvider;

	/**
	 * @var \Shopware\Core\System\StateMachine\StateMachineDefinition
	 */
	protected $stateMachineRepository;

	/**
	 * @var \Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionDefinition
	 */
	protected $stateMachineTransitionRepository;

	/**
	 * @var \Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateDefinition
	 */
	protected $stateMachineStateRepository;

	/**
	 * OrderDeliveryStateHandler constructor.
	 *
	 * @param \Psr\Container\ContainerInterface $container
	 */
	public function __construct(ContainerInterface $container)
	{
		$this->container                        = $container;
		$this->localeCodeProvider               = $this->container->get(LocaleCodeProvider::class);
		$this->stateMachineRepository           = $this->container->get('state_machine.repository');
		$this->stateMachineStateRepository      = $this->container->get('state_machine_state.repository');
		$this->stateMachineTransitionRepository = $this->container->get('state_machine_transition.repository');
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
	 *
	 * @return \Shopware\Core\System\StateMachine\StateMachineEntity
	 */
	protected function getStateMachineEntity(Context $context): string
	{
		$stateMachineCriteria = (new Criteria())
			->addFilter(new EqualsFilter('technicalName', OrderDeliveryStates::STATE_MACHINE));
		return $this->stateMachineRepository->search($stateMachineCriteria, $context)->first()->getId();
	}

	/**
	 * @param string                           $stateMachineId
	 * @param \Shopware\Core\Framework\Context $context
	 *
	 * @return string
	 */
	protected function getHoldStateId(string $stateMachineId, Context $context): string
	{
		$holdStateMachineStateCriteria = (new Criteria())
			->addFilter(
				new EqualsFilter('technicalName', OrderDeliveryStateHandler::STATE_HOLD),
				new EqualsFilter('stateMachineId', $stateMachineId)
			);

		$holdStateMachineStateEntity = $this->stateMachineStateRepository->search($holdStateMachineStateCriteria, $context)->first();

		$holdStateId = is_null($holdStateMachineStateEntity) ? Uuid::randomHex() : $holdStateMachineStateEntity->getId();

		if (is_null($holdStateMachineStateEntity)) {
			$translations = $this->localeCodeProvider->getAvailableTranslations('postfinancecheckout.deliveryState.hold', 'Hold', $context);
			$data         = [
				'id'             => $holdStateId,
				'technicalName'  => OrderDeliveryStateHandler::STATE_HOLD,
				'stateMachineId' => $stateMachineId,
				'translations'   => $translations,
			];
			$this->stateMachineStateRepository->upsert([$data], $context);
		}

		return $holdStateId;
	}

	/**
	 * @param string                           $stateMachineId
	 * @param \Shopware\Core\Framework\Context $context
	 *
	 * @return string
	 */
	protected function getOpenStateId(string $stateMachineId, Context $context): string
	{
		$stateMachineStateCriteria = (new Criteria())
			->addFilter(
				new EqualsFilter('technicalName', OrderDeliveryStates::STATE_OPEN),
				new EqualsFilter('stateMachineId', $stateMachineId)
			);

		return $this->stateMachineStateRepository->search($stateMachineStateCriteria, $context)->first()->getId();
	}

	/**
	 * @param string                           $stateMachineId
	 * @param string                           $openStateId
	 * @param string                           $holdStateId
	 * @param \Shopware\Core\Framework\Context $context
	 */
	protected function upsertHoldTransition(string $stateMachineId, string $openStateId, string $holdStateId, Context $context): void
	{
		$translations = $this->localeCodeProvider->getAvailableTranslations('postfinancecheckout.deliveryState.hold','Hold', $context);

		$this->upsertTransition(OrderDeliveryStateHandler::ACTION_HOLD, $stateMachineId, $openStateId, $holdStateId, $translations, $context);
	}

	/**
	 * @param string                           $actionName
	 * @param string                           $stateMachineId
	 * @param string                           $fromStateId
	 * @param string                           $toStateId
	 * @param array                            $translations
	 * @param \Shopware\Core\Framework\Context $context
	 */
	protected function upsertTransition(string $actionName, string $stateMachineId, string $fromStateId, string $toStateId, array $translations, Context $context): void
	{
		$criteria = (new Criteria())
			->addFilter(
				new EqualsFilter('actionName', $actionName),
				new EqualsFilter('stateMachineId', $stateMachineId),
				new EqualsFilter('fromStateId', $fromStateId),
				new EqualsFilter('toStateId', $toStateId)
			);

		$stateMachineTransitionEntity = $this->stateMachineTransitionRepository->search($criteria, $context)->first();
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
			$this->stateMachineTransitionRepository->upsert([$data], $context);
		}

	}

	/**
	 * @param string                           $stateMachineId
	 * @param string                           $openStateId
	 * @param string                           $holdStateId
	 * @param \Shopware\Core\Framework\Context $context
	 */
	protected function upsertUnholdTransition(string $stateMachineId, string $holdStateId, string $openStateId, Context $context): void
	{
		$translations = $this->localeCodeProvider->getAvailableTranslations('postfinancecheckout.deliveryState.unhold','Unhold',$context);

		$this->upsertTransition(OrderDeliveryStateHandler::ACTION_UNHOLD, $stateMachineId, $holdStateId, $openStateId, $translations, $context);
	}
}