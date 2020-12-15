<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Api\Transaction\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\{
	Checkout\Cart\Exception\OrderNotFoundException,
	Checkout\Order\OrderEntity,
	Content\MailTemplate\MailTemplateEntity,
	Content\MailTemplate\MailTemplateTypes,
	Content\MailTemplate\Service\MailServiceInterface,
	Framework\Context,
	Framework\DataAbstractionLayer\Search\Criteria,
	Framework\DataAbstractionLayer\Search\Filter\EqualsFilter,
	Framework\Validation\DataBag\DataBag,
	System\SalesChannel\SalesChannelEntity};
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
	 * @var \Shopware\Core\Content\MailTemplate\Service\MailServiceInterface
	 */
	protected $mailService;

	/**
	 * MailService constructor.
	 *
	 * @param \Psr\Container\ContainerInterface                                $container
	 * @param \Shopware\Core\Content\MailTemplate\Service\MailServiceInterface $mailService
	 */
	public function __construct(ContainerInterface $container, MailServiceInterface $mailService)
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
	 * @param string                           $technicalName
	 */
	public function send(string $orderId, Context $context, string $technicalName = MailTemplateTypes::MAILTYPE_ORDER_CONFIRM): void
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

			$data = $this->getData($order, $context, $technicalName);

			$this->mailService->send($data->all(), $context, $templateData);
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
	 * @return mixed
	 */
	protected function getSalesChannel(OrderEntity $order, Context $context): SalesChannelEntity
	{
		$languageId           = $order->getLanguageId();
		$salesChannelCriteria = new Criteria([$order->getSalesChannel()->getId()]);
		$salesChannelCriteria->getAssociation('domains')
							 ->addFilter(
								 new EqualsFilter('languageId', $languageId)
							 );
		return $this->container->get('sales_channel.repository')->search($salesChannelCriteria, $context)->first();
	}


	/**
	 * @param \Shopware\Core\Checkout\Order\OrderEntity $order
	 * @param \Shopware\Core\Framework\Context          $context
	 * @param string                                    $technicalName
	 *
	 * @return \Shopware\Core\Framework\Validation\DataBag\DataBag
	 */
	protected function getData(OrderEntity $order, Context $context, string $technicalName): DataBag
	{
		$mailTemplate = $this->getMailTemplate($order, $context, $technicalName, true);
		$data         = new DataBag();
		$data->add([
			'recipients'     => [$order->getOrderCustomer()->getEmail() => $order->getOrderCustomer()->getFirstName() . ' ' . $order->getOrderCustomer()->getLastName(),],
			'senderName'     => $mailTemplate->getTranslation('senderName'),
			'salesChannelId' => $order->getSalesChannelId(),
			'templateId'     => $mailTemplate->getId(),
			'customFields'   => $mailTemplate->getCustomFields(),
			'contentHtml'    => $mailTemplate->getTranslation('contentHtml'),
			'contentPlain'   => $mailTemplate->getTranslation('contentPlain'),
			'subject'        => $mailTemplate->getTranslation('subject'),
		]);

		return $data;
	}

	/**
	 * @param \Shopware\Core\Checkout\Order\OrderEntity $order
	 * @param \Shopware\Core\Framework\Context          $context
	 * @param string                                    $technicalName
	 * @param bool                                      $filterBySalesChannelId
	 *
	 * @return \Shopware\Core\Content\MailTemplate\MailTemplateEntity
	 */
	protected function getMailTemplate(OrderEntity $order, Context $context, string $technicalName, bool $filterBySalesChannelId = true): MailTemplateEntity
	{
		$criteria = (new Criteria())->addFilter(new EqualsFilter('mailTemplateType.technicalName', $technicalName))
									->addAssociation('media.media')
									->setLimit(1);

		if ($filterBySalesChannelId && !empty($order->getSalesChannelId())) {
			$criteria->addFilter(
				new EqualsFilter('mail_template.salesChannels.salesChannel.id', $order->getSalesChannelId())
			);
		}

		$mailTemplateEntity = $this->container->get('mail_template.repository')->search($criteria, $context)->first();
		if (empty($mailTemplateEntity) && $filterBySalesChannelId) {
			return $this->getMailTemplate($order, $context, $technicalName, false);
		}
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