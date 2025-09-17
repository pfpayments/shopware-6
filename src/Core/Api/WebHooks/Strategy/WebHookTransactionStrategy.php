<?php

namespace PostFinanceCheckoutPayment\Core\Api\WebHooks\Strategy;

use Symfony\Component\HttpFoundation\{
	JsonResponse,
	Response,};
use Shopware\Core\{
	Checkout\Cart\CartException,
	Framework\Context,
	System\StateMachine\Exception\IllegalTransitionException};
use PostFinanceCheckout\Sdk\Model\{
	RefundState,
	Transaction,
	TransactionInvoiceState,
	TransactionState,
	TransactionInvoice,
    Token};
use PostFinanceCheckoutPayment\Core\{
	Api\WebHooks\Service\WebHooksService,
	Api\WebHooks\Struct\WebHookRequest,
	Util\Payload\TransactionPayload};

/**
 * Class WebHookTransactionStrategy
 *
 * This class provides the implementation for processing transaction webhooks.
 * It includes methods for handling specific actions that need to be taken when
 * transaction-related webhook notifications are received, such as updating order
 * statuses, recording transaction logs, or triggering further business logic.
 *
 * @package PostFinanceCheckoutPayment\Core\Api\WebHooks\Strategy
 */
class WebHookTransactionStrategy extends WebHookStrategyBase implements WebhookStrategyActionsInterface {

	/**
	 * @inheritDoc
	 */
	public function match(string $webhookEntityId): bool {
		return WebHooksService::TRANSACTION == $webhookEntityId;
	}

	/**
	 * Loads the relevant entity from the API based on the webhook request.
	 *
	 * This method utilizes the TransactionService to fetch entity details (e.g., transaction data)
	 * based on the space and entity ID provided in the webhook request.
	 *
	 * @param WebHookRequest $request request.
	 * @return object|\PostFinanceCheckout\Sdk\Model\Transaction
	 * @throws \PostFinanceCheckout\Sdk\ApiException ApiException.
	 * @throws \PostFinanceCheckout\Sdk\Http\ConnectionException ConnectionException.
	 * @throws \PostFinanceCheckout\Sdk\VersioningException VersioningException.
	 */
	public function getTransaction(WebHookRequest $request): Transaction {
		return $this->settings->getApiClient()
			->getTransactionService()
			->read($request->getSpaceId(), $request->getEntityId());
	}

	/**
	 * @inheritDoc
	 */
	public function getOrderIdByTransaction(Transaction $transaction): string
	{
		/** @var \PostFinanceCheckout\Sdk\Model\Transaction $transaction */
		return $transaction->getMetaData()[TransactionPayload::POSTFINANCECHECKOUT_METADATA_ORDER_ID];
	}

	/**
	 * Check if the request state is applicable.
	 *
	 * This method checks if the state of the transaction from a webhook request
	 * is one of the predefined applicable states.
	 *
	 * @param WebHookRequest $request The webhook request containing the transaction state.
	 * @return bool Returns true if the state is applicable, false otherwise.
	 */
	public function isRequestStateApplicable(WebHookRequest $request): bool
	{
		$applicableStates = [
			TransactionState::FAILED,
			TransactionState::DECLINE,
			TransactionState::VOIDED,
			TransactionState::FULFILL,
			TransactionState::AUTHORIZED,
		];

		return in_array($request->getState(), $applicableStates);
	}

	/**
	 * Process the webhook request.
	 *
	 * @param WebHookRequest $request The webhook request object.
	 * @return Response.
	 */
	public function process(WebHookRequest $request): Response
	{
		return $this->processTransaction($request, $this->getContext());
	}

	/**
	 * Handles the processing of webhook callbacks related to PostFinanceCheckout transactions.
	 * This method updates or handles transaction states based on the webhook data received.
	 *
	 * @param WebHookRequest $request The data received from the webhook, encapsulating the transaction details.
	 * @param Context $context The operational context providing settings and environment for transaction processing.
	 * @return Response Returns a JSON response indicating the result of the transaction update operation.
	 */
	private function processTransaction(WebHookRequest $request, Context $context): Response
	{
		$status = Response::HTTP_UNPROCESSABLE_ENTITY;

		try {
			/** @var \Shopware\Core\Checkout\Order\OrderEntity $order */
			$transaction = $this->getTransaction($request);
            $token = $transaction->getToken();
			$orderId = $transaction->getMetaData()[TransactionPayload::POSTFINANCECHECKOUT_METADATA_ORDER_ID];
			if (!empty($orderId) && !$transaction->getParent()) {
				$this->executeLocked($orderId, $context, function () use ($orderId, $transaction, $context, $request, $token) {
					$this->transactionService->upsert($transaction, $context);
					$orderTransactionId = $transaction->getMetaData()[TransactionPayload::POSTFINANCECHECKOUT_METADATA_ORDER_TRANSACTION_ID];
					$orderTransaction   = $this->getOrderTransaction($orderId, $context);
					$this->logger->info("OrderId: {orderId} Current state: {state}", [
						'orderId' => $orderId,
						'state' => $orderTransaction->getStateMachineState()?->getTechnicalName(),
					]);

					if (!in_array(
						$orderTransaction->getStateMachineState()?->getTechnicalName(),
						$this->transactionFinalStates
					)) {
						switch ($request->getState()) {
							case TransactionState::FAILED:
								$this->orderTransactionStateHandler->fail($orderTransactionId, $context);
								$this->unholdAndCancelDelivery($orderId, $context);
								break;
							case TransactionState::DECLINE:
							case TransactionState::VOIDED:
								$this->orderTransactionStateHandler->cancel($orderTransactionId, $context);
								$this->unholdAndCancelDelivery($orderId, $context);
								break;
							case TransactionState::FULFILL:
								$this->unholdDelivery($orderId, $context);
								break;
							case TransactionState::AUTHORIZED:
                                if ($token instanceof Token) {
                                    // Update orderTransaction with the authorized token:
                                    $data = [
                                        'id' => $orderTransactionId,
                                        'customFields' => [
                                            TransactionPayload::ORDER_TRANSACTION_CUSTOM_FIELDS_POSTFINANCECHECKOUT_TOKEN => $token->getId(),
                                        ],
                                    ];
                                    $this->container->get('order_transaction.repository')->update([$data], $context);
                                }
								$this->orderTransactionStateHandler->process($orderTransactionId, $context);
								$this->sendEmail($transaction, $context, $orderId);
								break;
							default:
								break;
						}
					}

				});
			}
			$status = Response::HTTP_OK;
		} catch (CartException $exception) {
			$status = Response::HTTP_OK;
			$this->logRequest($exception, $request, 'info');
		} catch (IllegalTransitionException $exception) {
			$status = Response::HTTP_OK;
			$this->logRequest($exception, $request, 'info');
		} catch (\Exception $exception) {
			$this->logRequest($exception, $request, 'critical');
		}

		return new JsonResponse(['data' => $request->jsonSerialize()], $status);
	}
}
