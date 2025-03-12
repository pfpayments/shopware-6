<?php

namespace PostFinanceCheckoutPayment\Core\Api\WebHooks\Strategy;

use Doctrine\DBAL\Connection;
use Psr\{
	Container\ContainerInterface,
	Log\LoggerInterface,};
use Shopware\Core\{
	Checkout\Cart\CartException,
	Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity,
	Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity,
	Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler,
	Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates,
	Checkout\Order\OrderEntity,
	Checkout\Order\SalesChannel\OrderService,
	Framework\Context,
	Framework\DataAbstractionLayer\Search\Criteria,
	Framework\DataAbstractionLayer\Search\Sorting\FieldSorting,
	System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions,};
use Symfony\Component\{
	HttpFoundation\ParameterBag};
use PostFinanceCheckout\Sdk\{
	Model\RefundState,
	Model\Transaction,
	Model\TransactionInvoiceState,
	Model\TransactionState,
	Model\TransactionInvoice,
	ApiException,
	Http\ConnectionException,
	VersioningException,};
use PostFinanceCheckoutPayment\Core\{
	Api\OrderDeliveryState\Handler\OrderDeliveryStateHandler,
	Api\PaymentMethodConfiguration\Service\PaymentMethodConfigurationService,
	Api\Refund\Service\RefundService,
	Api\Transaction\Service\OrderMailService,
	Api\Transaction\Service\TransactionService,
	Api\WebHooks\Struct\WebHookRequest,
	Settings\Service\SettingsService,
	Settings\Struct\Settings,};

/**
 * Abstract class WebHookStrategyBase
 *
 * Serves as a base class for all webhook strategy implementations. It provides common methods needed to process webhook requests,
 * such as loading entity data from the API, retrieving order details, and more.
 * Note: Not all standard methods are applicable in all derived strategy classes. In some strategies, certain operations
 * may intentionally be left unimplemented to reflect that they are not relevant to the specific type of webhook being handled.
 *
 * @package PostFinanceCheckoutPayment\Core\Api\WebHooks\Strategy
 */
abstract class WebHookStrategyBase implements WebHookStrategyInterface {

	/**
	 * @var LoggerInterface
	 */
	protected $logger;

	/**
	 * @var Context
	 */
	private $context;

	/**
	 * @var string
	 */
	private $salesChannelId;

	/**
	 * @var OrderService
	 */
	protected $orderService;

	/**
	 * @var Settings
	 */
	protected $settings;

	/**
	 * @var ContainerInterface
	 */
	protected $container;

	/**
	 * @var Connection
	 */
	protected $connection;

	/**
	 * @var OrderMailService
	 */
	protected $orderMailService;

	/**
	 * @var OrderTransactionStateHandler
	 */
	protected $orderTransactionStateHandler;

	/**
	 * @var PaymentMethodConfigurationService
	 */
	protected $paymentMethodConfigurationService;

	/**
	 * @var SettingsService
	 */
	protected $settingsService;

	/**
	 * @var RefundService
	 */
	protected $refundService;

	/**
	 * @var TransactionService
	 */
	protected $transactionService;

	/**
	 * @var OrderEntity
	 */
	protected $orderEntity;

	/**
	 * Transaction Final States
	 *
	 * @var array
	 */
	public $transactionFinalStates = [
		OrderTransactionStates::STATE_CANCELLED,
		OrderTransactionStates::STATE_PAID,
		OrderTransactionStates::STATE_REFUNDED,
	];

	public $postfinancecheckoutTransactionSuccessStates = [
		TransactionState::AUTHORIZED,
		TransactionState::COMPLETED,
		TransactionState::FULFILL,
	];

	/**
	 * WebHookStrategyBase constructor.
	 *
	 * @param Connection $connection
	 * @param OrderTransactionStateHandler $orderTransactionStateHandler
	 * @param OrderService $orderService
	 * @param PaymentMethodConfigurationService $paymentMethodConfigurationService
	 * @param RefundService $refundService
	 * @param OrderMailService $orderMailService
	 * @param TransactionService $transactionService
	 * @param SettingsService $settingsService
	 * @param ContainerInterface $container
	 * @param LoggerInterface $postfinancecheckoutPaymentLogger
	 * @see "$postfinancecheckoutPaymentLogger", please read the documentation "How to Autowire Logger Channels"
	 */
	public function __construct(
		Connection $connection,
		OrderTransactionStateHandler $orderTransactionStateHandler,
		OrderService $orderService,
		PaymentMethodConfigurationService $paymentMethodConfigurationService,
		RefundService $refundService,
		OrderMailService $orderMailService,
		TransactionService $transactionService,
		SettingsService $settingsService,
		ContainerInterface $container,
		LoggerInterface $postfinancecheckoutPaymentLogger
	) {
		$this->connection                        = $connection;
		$this->orderTransactionStateHandler      = $orderTransactionStateHandler;
		$this->paymentMethodConfigurationService = $paymentMethodConfigurationService;
		$this->refundService                     = $refundService;
		$this->orderMailService                  = $orderMailService;
		$this->transactionService                = $transactionService;
		$this->settingsService                   = $settingsService;
		$this->orderService                      = $orderService;
		$this->container                         = $container;
		$this->logger                            = $postfinancecheckoutPaymentLogger;
	}

	/**
	 * Sets the context for the current operation.
	 *
	 * This method assigns a new context to be used in subsequent operations within this instance.
	 * Passing a null value clears the current context.
	 *
	 * @param Context|null $context The new context to set, or null to clear the existing context.
	 * @return self Returns this instance to allow for method chaining.
	 */
	public function setContext(?Context $context): self
	{
		$this->context = $context;
		return $this;
	}

	/**
	 * Get the current context.
	 *
	 * This method returns the context that has been set for this instance, which may be used in various operations.
	 * If no context has been set, it returns null.
	 *
	 * @return Context|null The current context if set; otherwise, null.
	 */
	public function getContext(): ?Context
	{
		return $this->context;
	}

	/**
	 * Sets the sales channel ID for this instance.
	 *
	 * This method updates the sales channel ID. This ID is used in various operations that are specific to a sales channel.
	 *
	 * @param string|null $salesChannelId The sales channel ID to be set.
	 * @return $this Provides a fluent interface by returning itself.
	 */
	public function setSalesChannelId(?string $salesChannelId): self
	{
		$this->salesChannelId = $salesChannelId;
		return $this;
	}

	/**
	 * Retrieves the current sales channel ID.
	 *
	 * This method returns the sales channel ID that has been set for this instance. If no ID has been set, it returns null.
	 *
	 * @return string|null The current sales channel ID if set; otherwise, null.
	 */
	public function getSalesChannelId(): ?string
	{
		return $this->salesChannelId;
	}

	/**
	 * Updates the settings based on the current sales channel.
	 *
	 * This method fetches and applies the settings specific to the sales channel ID currently set for this instance.
	 * @return $this Provides a fluent interface by returning itself.
	 */
	public function setCurrentSettingsBySalesChannel(): self
	{
		$this->settings = $this->getSettingsBySalesChannel($this->getSalesChannelId());
		return $this;
	}

	/**
	 * Get settings for a specific sales channel.
	 *
	 * This method accesses settings from the settings service using the provided sales channel ID. It returns configuration settings
	 * that are specific to the given sales channel.
	 *
	 * @param string|null $salesChannelId The ID of the sales channel for which settings are being requested. If null, it may default to system-wide settings or no settings.
	 * @return Settings The settings object containing configuration details for the specified sales channel.
	 */
	protected function getSettingsBySalesChannel(?string $salesChannelId): Settings
	{
		return $this->settingsService->getSettings($salesChannelId);
	}

	/**
	 * Get an order entity based on the order ID.
	 *
	 * This method fetches an order entity from the database using the provided order ID and context. If the order entity has not
	 * been fetched before, it performs a database query to retrieve it and caches it for future use.
	 *
	 * @param string $orderId The unique identifier of the order.
	 * @param Context $context The context of the current operation, including scope and permissions.
	 * @return OrderEntity The order entity associated with the provided ID.
	 * @throws CartException If the order cannot be found.
	 */
	protected function getOrderEntity(string $orderId, Context $context): OrderEntity
	{
		if (is_null($this->orderEntity)) {
			$criteria = (new Criteria([$orderId]))
				->addAssociations(['deliveries', 'transactions']);
			$criteria->getAssociation('transactions')
				->addSorting(new FieldSorting('createdAt', FieldSorting::ASCENDING));

			try {
				$this->orderEntity = $this->container
					->get('order.repository')
					->search($criteria, $context)
					->first();
				if (is_null($this->orderEntity)) {
					throw CartException::orderNotFound($orderId);
				}
			} catch (\Exception $e) {
				throw CartException::orderNotFound($orderId);
			}
		}

		return $this->orderEntity;
	}

	/**
	 * Get the last transaction associated with an order.
	 *
	 * This method accesses the last transaction of an order based on the provided order ID and context.
	 *
	 * @param string $orderId The unique identifier of the order.
	 * @param Context $context The context of the current operation, including scope and permissions.
	 * @return OrderTransactionEntity The last transaction entity of the specified order.
	 */
	protected function getOrderTransaction(String $orderId, Context $context): OrderTransactionEntity
	{
		return $this->getOrderEntity($orderId, $context)->getTransactions()->last();
	}

	/**
	 * Unholds the delivery of an order.
	 *
	 * This method changes the state of an order's last delivery from 'held' to 'released', allowing further processing like shipping.
	 *
	 * @param string $orderId The unique identifier of the order.
	 * @param Context $context The context of the current operation, including scope and permissions.
	 */
	protected function unholdDelivery(string $orderId, Context $context): void
	{
		try {
			$order = $this->getOrderEntity($orderId, $context);
			/** @var OrderDeliveryEntity $orderDelivery */
			$orderDelivery = $order->getDeliveries()->last();
			if ($orderDelivery->getStateMachineState()?->getTechnicalName() !== OrderDeliveryStateHandler::STATE_HOLD){
				return;
			}
			/** @var OrderDeliveryStateHandler $orderDeliveryStateHandler */
			$orderDeliveryStateHandler = $this->container->get(OrderDeliveryStateHandler::class);
			$orderDeliveryStateHandler->unhold($orderDelivery->getId(), $context);
		} catch (\Exception $exception) {
			$this->logger->info($exception->getMessage(), $exception->getTrace());
		}
	}

	/**
	 * Releases any holds and cancels the delivery of an order. If the order's delivery is not on hold, this method does nothing.
	 * Any exceptions encountered during the process are logged for debugging.
	 *
	 * @param string $orderId The ID of the order to process.
	 * @param Context $context Shopware execution context for the current operation.
	 */
	protected function unholdAndCancelDelivery(string $orderId, Context $context): void
	{
		$order = $this->getOrderEntity($orderId, $context);
		try {
			$this->orderService->orderStateTransition(
				$order->getId(),
				StateMachineTransitionActions::ACTION_CANCEL,
				new ParameterBag(),
				$context
			);
		} catch (\Exception $exception) {
			$this->logger->info($exception->getMessage(), $exception->getTrace());
		}

		try {

			$orderDeliveryStateHandler = $this->container->get(OrderDeliveryStateHandler::class);
			/** @var OrderDeliveryEntity $orderDelivery */
			$orderDelivery = $order->getDeliveries()->last();
			if ($orderDelivery->getStateMachineState()?->getTechnicalName() !== OrderDeliveryStateHandler::STATE_HOLD){
				return;
			}
			$orderDeliveryId = $orderDelivery->getId();
			$orderDeliveryStateHandler->unhold($orderDeliveryId, $context);
			$orderDeliveryStateHandler->cancel($orderDeliveryId, $context);
		} catch (\Exception $exception) {
			$this->logger->info($exception->getMessage(), $exception->getTrace());
		}
	}

	/**
	 * Executes a locked operation on an order.
	 *
	 * This method ensures that the operation on the order is executed in a locked context, preventing other processes from interfering.
	 * It locks the order, performs the operation, and then commits or rolls back the transaction based on the success of the operation.
	 *
	 * @param string $orderId The unique identifier of the order.
	 * @param Context $context The context of the current operation, including scope and permissions.
	 * @param callable $operation The operation to execute on the order.
	 * @return mixed The result of the operation.
	 * @throws Exception If the operation fails.
	 */
	protected function executeLocked(string $orderId, Context $context, callable $operation)
	{
		try {

			$data = [
				'id'                         => $orderId,
				'postfinancecheckout_lock' => date('Y-m-d H:i:s'),
			];

			$order = $this->container->get('order.repository')->search(new Criteria([$orderId]), $context)->first();

			if(empty($order)){
				throw CartException::orderNotFound($orderId);
			}

			$this->container->get('order.repository')->upsert([$data], $context);

			$result = $operation();

			return $result;
		} catch (\Exception $exception) {
			throw $exception;
		}
	}

	/**
	 * Sends an email based on the transaction state.
	 *
	 * This method checks if the transaction state matches any of the successful states and sends an email if enabled in the settings.
	 *
	 * @param Transaction $transaction The transaction object containing the state and metadata.
	 * @param Context $context The context of the current operation, including scope and permissions.
	 * @param string $orderId The unique identifier of the order associated with the transaction.
	 */
	protected function sendEmail(Transaction $transaction, Context $context, string $orderId): void
	{
		$salesChannelId = $this->getSalesChannelId();
		$this->settings = $this->getSettingsBySalesChannel($salesChannelId);
		if ($this->settings->isEmailEnabled()
			&& in_array($transaction->getState(), $this->postfinancecheckoutTransactionSuccessStates)) {
			$this->orderMailService->send($orderId, $context);
		}
	}

	/**
	 * Logs a message with dynamic retrieval of the class and method names from where it is called, and allows specifying the log level.
	 *
	 * This method captures the class and method that called it using a backtrace, which automates the process
	 * of logging without needing to manually specify the source of the log entry. It enhances error tracking
	 * and informational logging by providing precise source identification for a variety of log levels.
	 *
	 * @param \Throwable $exception The exception to log, providing the error details.
	 * @param WebHookRequest $request The HTTP request context, used for additional logging data.
	 * @param string $logLevel The level of the log entry ('info', 'critical', etc.), controlling how the log is processed.
	 */
	protected function logRequest(\Throwable $exception, WebHookRequest $request, string $logLevel = 'info'): void
	{
		$class = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['class'];
		$function = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'];
		$message = $class . ' : ' . $function . ' : ' . $exception->getMessage();

		switch ($logLevel) {
			case 'critical':
				$this->logger->critical($message, $request->jsonSerialize());
				break;
			case 'debug':
				$this->logger->debug($message, $request->jsonSerialize());
				break;
			case 'info':
			default:
				$this->logger->info($message, $request->jsonSerialize());
				break;
		}
	}
}
