<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Api\Transaction\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\{
	Checkout\Cart\Event\CheckoutOrderPlacedEvent,
	Checkout\Cart\Exception\OrderNotFoundException,
	Checkout\Order\OrderEntity,
	Content\MailTemplate\MailTemplateEntity,
	Content\Mail\Service\AbstractMailService,
	Framework\Context,
	Framework\DataAbstractionLayer\Search\Criteria,
	Framework\DataAbstractionLayer\Search\Filter\EqualsFilter,
	Framework\DataAbstractionLayer\Search\Filter\NotFilter,
	Framework\DataAbstractionLayer\Search\Filter\OrFilter,
	Framework\Event\EventAction\EventActionCollection};
use PostFinanceCheckoutPayment\Core\{
	Api\Transaction\Entity\TransactionEntity,
	Api\Transaction\Entity\TransactionEntityDefinition};

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
	 * @var \Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface
	 */
	protected $mailTemplateRepository;

	/**
	 * @var \Shopware\Core\Content\Mail\Service\AbstractMailService
	 */
	protected $mailService;

	/**
	 * MailService constructor.
	 *
	 * @param \Psr\Container\ContainerInterface                                	$container
	 * @param \Shopware\Core\Content\Mail\Service\AbstractMailService 			$mailService
	 */
	public function __construct(ContainerInterface $container, AbstractMailService $mailService)
	{
		$this->container   = $container;
		$this->mailService = $mailService;
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
			if ($transactionEntity->isConfirmationEmailSent()) {
				return;
			}

			$order = $this->getOrder($orderId, $context);
			if (is_null($order->getOrderCustomer())) {
				return;
			}

			$languageIdChain[]      = $order->getLanguageId();
			$contextLanguageIdChain = $context->getLanguageIdChain();
			foreach ($contextLanguageIdChain as $languageId) {
				$contextLanguageIdChain[] = $languageId;
			}
			array_unique($languageIdChain);

			$context->assign(['languageIdChain' => $languageIdChain,]);

			$templateData = [
				'order'                                     => $order,
				self::EMAIL_ORIGIN_IS_POSTFINANCECHECKOUT => true,
			];

			$data = $this->getData($order, $context);

			foreach ($data as $datum){
				$this->mailService->send($datum, $context, $templateData);
			}
			$this->markTransactionEntityConfirmationEmailAsSent($orderId, $context);


		} catch (\Exception $exception) {
			$this->logger->critical($exception->getMessage());
		}
	}

	/**
	 * Get transaction entity by orderId
	 *
	 * @param string                           $orderId
	 * @param \Shopware\Core\Framework\Context $context
	 *
	 * @return \PostFinanceCheckoutPayment\Core\Api\Transaction\Entity\TransactionEntity
	 */
	protected function getTransactionEntityByOrderId(string $orderId, Context $context): TransactionEntity
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
			'currency',
			'deliveries',
			'deliveries.shippingCosts',
			'deliveries.shippingMethod',
			'deliveries.shippingOrderAddress',
			'deliveries.shippingOrderAddress.country',
			'documents',
			'language',
			'lineItems',
			'orderCustomer',
			'orderCustomer.customer',
			'orderCustomer.salutation',
			'salesChannel',
			'stateMachineState',
			'tags',
			'transactions',
			'transactions.paymentMethod',
		]);

		/** @var OrderEntity|null $order */
		$order = $this->container->get('order.repository')->search($orderCriteria, $context)->first();
		if (is_null($order)) {
			throw new OrderNotFoundException($orderId);
		}
		return $order;
	}


	/**
	 * @param \Shopware\Core\Checkout\Order\OrderEntity $order
	 * @param \Shopware\Core\Framework\Context          $context
	 *
	 * @return array
	 */
	protected function getData(OrderEntity $order, Context $context): array
	{
		$data = [];

		/**
		 * @var
		 */
		/** @var \Shopware\Core\Framework\Event\EventAction\EventActionCollection $eventActionEntities */
		$eventActionEntities = $this->getBusinessEvents($order, $context);
		$customerRecipient   = [
			$order->getOrderCustomer()->getEmail() => $order->getOrderCustomer()->getFirstName() . ' ' . $order->getOrderCustomer()->getLastName(),
		];

		foreach ($eventActionEntities as $eventActionEntity) {

			$eventConfig    = $eventActionEntity->getConfig();
			$mailTemplateId = $eventConfig['mail_template_id'];
			$recipients     = !empty($eventConfig['recipients']) ? $eventConfig['recipients'] : $customerRecipient;
			$mailTemplate   = $this->getMailTemplateById($context, $mailTemplateId);

			$data[] = [
				'recipients'     => $recipients,
				'senderName'     => $mailTemplate->getTranslation('senderName'),
				'salesChannelId' => $order->getSalesChannelId(),
				'templateId'     => $mailTemplateId,
				'customFields'   => $mailTemplate->getCustomFields(),
				'contentHtml'    => $mailTemplate->getTranslation('contentHtml'),
				'contentPlain'   => $mailTemplate->getTranslation('contentPlain'),
				'subject'        => $mailTemplate->getTranslation('subject'),
			];
		}

		return $data;
	}

	protected function getBusinessEvents(OrderEntity $order, Context $context): EventActionCollection
	{
		$criteria = (new Criteria())
			->addAssociations([
				'rules',
				'salesChannels',
			])
			->addFilter(new EqualsFilter('eventName', CheckoutOrderPlacedEvent::EVENT_NAME))
			->addFilter(new EqualsFilter('active', true))
			->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [new EqualsFilter('config.mail_template_id', null)]))
			->addFilter(new OrFilter([
				new EqualsFilter('salesChannels.id', $order->getSalesChannelId()),
				new EqualsFilter('salesChannels.id', null),
			]));


		/** @var EventActionCollection $events */
		$events = $this->container->get('event_action.repository')
								  ->search($criteria, $context)
								  ->getEntities();
		return $events;
	}

	/**
	 * @param \Shopware\Core\Framework\Context $context
	 * @param string                           $id
	 *
	 * @return \Shopware\Core\Content\MailTemplate\MailTemplateEntity
	 */
	protected function getMailTemplateById(Context $context, string $id): MailTemplateEntity
	{
		$criteria = (new Criteria([$id]))->addAssociations(['media', 'media.media', 'salesChannels', 'mailTemplateType']);

		$mailTemplateEntity = $this->container->get('mail_template.repository')->search($criteria, $context)->first();

		return $mailTemplateEntity;
	}

	/**
	 * @param string                           $orderId
	 * @param \Shopware\Core\Framework\Context $context
	 */
	protected function markTransactionEntityConfirmationEmailAsSent(string $orderId, Context $context)
	{
		$this->container->get(TransactionEntityDefinition::ENTITY_NAME . '.repository')->upsert([['id' => $orderId, 'confirmationEmailSent' => true]], $context);
	}
}