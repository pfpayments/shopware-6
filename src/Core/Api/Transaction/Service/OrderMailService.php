<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Api\Transaction\Service;

use Doctrine\DBAL\Connection;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\{
	Checkout\Cart\Event\CheckoutOrderPlacedEvent,
	Checkout\Cart\CartException,
	Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates,
	Checkout\Order\OrderEntity,
	Checkout\Order\OrderStates,
	Content\Flow\Aggregate\FlowSequence\FlowSequenceCollection,
	Content\Flow\Aggregate\FlowSequence\FlowSequenceEntity,
	Content\Flow\FlowCollection,
	Content\Mail\Service\SendMailTemplate,
	Content\Mail\Service\SendMailTemplateParams,
	Framework\Context,
	Framework\DataAbstractionLayer\Search\Criteria,
	Framework\DataAbstractionLayer\Search\Filter\EqualsFilter,
	Framework\DataAbstractionLayer\Search\Sorting\FieldSorting,
	Framework\Uuid\Uuid,
	System\User\UserCollection};
use Symfony\Component\Mime\Address;
use PostFinanceCheckoutPayment\Core\{
	Api\Transaction\Entity\TransactionEntity,
	Api\Transaction\Entity\TransactionEntityDefinition,
	Settings\Service\SettingsService};

/**
 * Class OrderMailService
 *
 * @package PostFinanceCheckoutPayment\Core\Api\Transaction\Service
 */
class OrderMailService {

	public const EMAIL_ORIGIN_IS_POSTFINANCECHECKOUT = "isPostFinanceCheckoutPayment";
	/**
	 * @var \Psr\Container\ContainerInterface
	 */
	protected $container;

	/**
	 * @var \Psr\Log\LoggerInterface
	 */
	protected $logger;

	/**
	 * @var \Shopware\Core\Content\Mail\Service\SendMailTemplate
	 */
	protected $sendMailTemplate;

	/**
	 * @var \PostFinanceCheckoutPayment\Core\Settings\Service\SettingsService
	 */
	protected $settingsService;

	/**
	 * @var \Doctrine\DBAL\Connection
	 */
	protected $connection;

	/**
	 * MailService constructor.
	 *
	 * @param \Psr\Container\ContainerInterface                                   $container
	 * @param \Shopware\Core\Content\Mail\Service\SendMailTemplate                $sendMailTemplate
	 * @param \PostFinanceCheckoutPayment\Core\Settings\Service\SettingsService $settingsService
	 * @param \Doctrine\DBAL\Connection                                           $connection
	 */
	public function __construct(ContainerInterface $container, SendMailTemplate $sendMailTemplate, SettingsService $settingsService, Connection $connection)
	{
		$this->container = $container;
		$this->sendMailTemplate = $sendMailTemplate;
		$this->settingsService = $settingsService;
		$this->connection = $connection;
	}

	/**
	 * @param \Psr\Log\LoggerInterface $logger
	 *
	 * @internal
	 * @required
	 *
	 */
	public function setLogger(LoggerInterface $logger): void
	{
		$this->logger = $logger;
	}

	/**
	 * @param string                           $orderId
	 * @param \Shopware\Core\Framework\Context $context
	 */
	public function send(string $orderId, Context $context): void
	{
		try {

			$transactionEntity = $this->getTransactionEntityByOrderId($orderId, $context);
			if (is_null($transactionEntity) || $transactionEntity->isConfirmationEmailSent()) {
				return;
			}

			$order = $this->getOrder($orderId, $context);
			if (is_null($order->getOrderCustomer())) {
				return;
			}

			if (!$this->settingsService->getSettings($order->getSalesChannelId())->isEmailEnabled()) {
				return;
			}

			if ($this->isCancelledOrFailed($order)) {
				$this->logger->info('Order confirmation mail not sent: order or payment is cancelled or failed.', [
					'orderId' => $orderId,
				]);
				return;
			}

			if (!$this->claimConfirmationEmail($orderId)) {
				return;
			}

			$templateData = [
				'order'                                     => $order,
				'orderId'                                   => $order->getId(),
				'eventName'                                 => CheckoutOrderPlacedEvent::EVENT_NAME,
				self::EMAIL_ORIGIN_IS_POSTFINANCECHECKOUT => true,
			];

			foreach ($this->getOrderPlacedMailActions($order, $context) as $actionConfig) {
				$this->sendMail($order, $actionConfig, $templateData, $context);
			}

		} catch (\Exception $exception) {
			$this->logger->critical($exception->getMessage(), ['orderId' => $orderId]);
		}
	}

	/**
	 * @param \Shopware\Core\Checkout\Order\OrderEntity $order
	 *
	 * @return bool
	 */
	protected function isCancelledOrFailed(OrderEntity $order): bool
	{
		if ($order->getStateMachineState()?->getTechnicalName() === OrderStates::STATE_CANCELLED) {
			return true;
		}

		return in_array(
			$order->getTransactions()?->last()?->getStateMachineState()?->getTechnicalName(),
			[OrderTransactionStates::STATE_CANCELLED, OrderTransactionStates::STATE_FAILED, OrderTransactionStates::STATE_REFUNDED],
			true
		);
	}

	/**
	 * Get transaction entity by orderId
	 *
	 * @param string                           $orderId
	 * @param \Shopware\Core\Framework\Context $context
	 *
	 * @return \PostFinanceCheckoutPayment\Core\Api\Transaction\Entity\TransactionEntity|null
	 */
	protected function getTransactionEntityByOrderId(string $orderId, Context $context): ?TransactionEntity
	{
		return $this->container->get(TransactionEntityDefinition::ENTITY_NAME . '.repository')
				    ->search(new Criteria([$orderId]), $context)
				    ->get($orderId);
	}

	/**
	 * @param string                           $orderId
	 * @param \Shopware\Core\Framework\Context $context
	 *
	 * @return \Shopware\Core\Checkout\Order\OrderEntity
	 */
	protected function getOrder(string $orderId, Context $context): OrderEntity
	{
		$orderCriteria = (new Criteria([$orderId]))->addAssociations([
			'addresses',
			'addresses.country',
			'billingAddress',
			'billingAddress.country',
			'currency',
			'deliveries',
			'deliveries.shippingCosts',
			'deliveries.shippingMethod',
			'deliveries.shippingOrderAddress',
			'deliveries.shippingOrderAddress.country',
			'documents',
			'language',
			'language.locale',
			'lineItems',
			'lineItems.downloads',
			'orderCustomer',
			'orderCustomer.customer',
			'orderCustomer.salutation',
			'salesChannel',
			'stateMachineState',
			'tags',
			'transactions',
			'transactions.paymentMethod',
			'transactions.stateMachineState'
		]);

		$orderCriteria->getAssociation('transactions')->addSorting(new FieldSorting('createdAt'));
		$orderCriteria->getAssociation('lineItems')->addSorting(new FieldSorting('position'));

		/** @var OrderEntity|null $order */
		$order = $this->container->get('order.repository')->search($orderCriteria, $context)->first();
		if (is_null($order)) {
			throw CartException::orderNotFound($orderId);
		}
		return $order;
	}

	/**
	 * Returns the config of every "Send email" action that Shopware's Flow Builder would run
	 * for this order on "checkout.order.placed".
	 *
	 * @param \Shopware\Core\Checkout\Order\OrderEntity $order
	 * @param \Shopware\Core\Framework\Context          $context
	 *
	 * @return array
	 */
	protected function getOrderPlacedMailActions(OrderEntity $order, Context $context): array
	{
		$orderRuleIds = $order->getRuleIds() ?? [];
		$actions = [];

		foreach ($this->getOrderPlacedFlows($context) as $flow) {
			$sequences = $flow->getSequences();
			if (!$sequences instanceof FlowSequenceCollection) {
				continue;
			}

			foreach ($sequences as $sequence) {
				if ($sequence->getActionName() !== 'action.mail.send') {
					continue;
				}

				$config = $sequence->getConfig();
				if (empty($config['mailTemplateId'])) {
					continue;
				}

				if (!$this->isSequenceReached($sequence, $sequences, $orderRuleIds)) {
					continue;
				}

				$actions[] = $config;
			}
		}

		return $actions;
	}

	/**
	 * @param \Shopware\Core\Framework\Context $context
	 *
	 * @return \Shopware\Core\Content\Flow\FlowCollection
	 */
	protected function getOrderPlacedFlows(Context $context): FlowCollection
	{
		$criteria = (new Criteria())
			->addFilter(new EqualsFilter('eventName', CheckoutOrderPlacedEvent::EVENT_NAME))
			->addFilter(new EqualsFilter('active', true))
			->addFilter(new EqualsFilter('invalid', false))
			->addSorting(new FieldSorting('priority', FieldSorting::DESCENDING));

		$criteria->getAssociation('sequences')
			->addSorting(new FieldSorting('displayGroup'))
			->addSorting(new FieldSorting('position'));

		$flows = $this->container->get('flow.repository')
			->search($criteria, $context)
			->getEntities();

		return $flows;
	}

	/**
	 * Walks up the sequence tree: each condition node above the action must lead into the
	 * branch (true/false) the action sits in.
	 *
	 * @param \Shopware\Core\Content\Flow\Aggregate\FlowSequence\FlowSequenceEntity     $sequence
	 * @param \Shopware\Core\Content\Flow\Aggregate\FlowSequence\FlowSequenceCollection $flowSequences
	 * @param array                                                                     $orderRuleIds
	 *
	 * @return bool
	 */
	protected function isSequenceReached(FlowSequenceEntity $sequence, FlowSequenceCollection $flowSequences, array $orderRuleIds): bool
	{
		$current = $sequence;
		$visited = [];

		while (($parentId = $current->getParentId()) !== null) {
			if (isset($visited[$parentId])) {
				return false;
			}
			$visited[$parentId] = true;

			$parent = $flowSequences->get($parentId);
			if (!$parent instanceof FlowSequenceEntity) {
				return false;
			}

			$isConditionNode = empty($parent->getActionName());
			if ($isConditionNode) {
				$ruleId = $parent->getRuleId();
				if (empty($ruleId)) {
					return false;
				}

				$ruleMatches = in_array($ruleId, $orderRuleIds, true);
				if ($ruleMatches !== $current->isTrueCase()) {
					return false;
				}
			}

			$current = $parent;
		}

		return true;
	}

	/**
	 * @param \Shopware\Core\Checkout\Order\OrderEntity $order
	 * @param array                                     $actionConfig
	 * @param array                                     $templateData
	 * @param \Shopware\Core\Framework\Context          $context
	 */
	protected function sendMail(OrderEntity $order, array $actionConfig, array $templateData, Context $context): void
	{
		$mailTemplateId = (string) $actionConfig['mailTemplateId'];

		if (empty($actionConfig['recipient']) || !is_array($actionConfig['recipient'])) {
			$this->logger->warning('Skipping order confirmation mail: flow action has no recipient configured.', [
				'orderId'        => $order->getId(),
				'mailTemplateId' => $mailTemplateId,
			]);
			return;
		}

		$recipients = $this->getRecipients($actionConfig['recipient'], $order, $context);
		if (empty($recipients)) {
			return;
		}

		try {
			$this->sendMailTemplate->send(new SendMailTemplateParams(
				mailTemplateId: $mailTemplateId,
				languageId: $order->getLanguageId(),
				recipients: $recipients,
				data: $templateData,
				salesChannelId: $order->getSalesChannelId(),
			), $context);
		} catch (\Throwable $exception) {
			$this->logger->error('Order confirmation mail could not be sent and will not be resent: ' . $exception->getMessage(), [
				'orderId'        => $order->getId(),
				'mailTemplateId' => $mailTemplateId,
			]);
		}
	}

	/**
	 * Resolves recipients the same way Shopware's SendMailAction does.
	 *
	 * @param array                                     $recipientConfig
	 * @param \Shopware\Core\Checkout\Order\OrderEntity $order
	 * @param \Shopware\Core\Framework\Context          $context
	 *
	 * @return array
	 */
	protected function getRecipients(array $recipientConfig, OrderEntity $order, Context $context): array
	{
		$recipients = [];

		switch ($recipientConfig['type'] ?? null) {
			case 'custom':
				foreach ((array) ($recipientConfig['data'] ?? []) as $email => $name) {
					$recipients[(string) $email] = (string) $name;
				}
				break;

			case 'admin':
				$criteria = (new Criteria())->addFilter(new EqualsFilter('admin', true));
				/** @var UserCollection $admins */
				$admins = $this->container->get('user.repository')->search($criteria, $context)->getEntities();
				foreach ($admins as $admin) {
					$recipients[$admin->getEmail()] = trim($admin->getFirstName() . ' ' . $admin->getLastName());
				}
				break;

			default:
				$orderCustomer = $order->getOrderCustomer();
				$recipients[$orderCustomer->getEmail()] = trim($orderCustomer->getFirstName() . ' ' . $orderCustomer->getLastName());
		}

		$addresses = [];
		foreach ($recipients as $email => $name) {
			if (trim($email) === '') {
				continue;
			}
			try {
				$addresses[] = new Address(trim($email), $name);
			} catch (\Throwable $exception) {
				$this->logger->warning('Skipping invalid order confirmation mail recipient: ' . $exception->getMessage(), [
					'orderId' => $order->getId(),
				]);
			}
		}

		return $addresses;
	}

	/**
	 * Atomically marks the confirmation mail of the order as sent.
	 *
	 * @param string $orderId
	 *
	 * @return bool
	 */
	protected function claimConfirmationEmail(string $orderId): bool
	{
		$affectedRows = $this->connection->executeStatement(
			'UPDATE `' . TransactionEntityDefinition::ENTITY_NAME . '`
				SET `confirmation_email_sent` = 1
				WHERE `id` = :id AND `confirmation_email_sent` = 0',
			['id' => Uuid::fromHexToBytes($orderId)]
		);

		return (int) $affectedRows === 1;
	}
}
