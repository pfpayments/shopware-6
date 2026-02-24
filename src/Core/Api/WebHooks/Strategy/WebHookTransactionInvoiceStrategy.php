<?php

namespace PostFinanceCheckoutPayment\Core\Api\WebHooks\Strategy;

use Shopware\Core\{
	Checkout\Cart\CartException,
	Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity,
	Framework\Context,
	System\StateMachine\Exception\IllegalTransitionException};
use Exception;
use Symfony\Component\{
	HttpFoundation\JsonResponse,
	HttpFoundation\Response,};
use PostFinanceCheckout\Sdk\{
	Model\RefundState,
	Model\Transaction,
	Model\TransactionInvoiceState,
	Model\TransactionState,
	Model\TransactionInvoice,};
use PostFinanceCheckoutPayment\Core\{
	Api\WebHooks\Service\WebHooksService,
	Api\WebHooks\Struct\WebHookRequest,
	Util\Payload\TransactionPayload};


/**
 * Handles the strategy for processing webhook requests related to manual tasks.
 *
 * This class extends the base webhook strategy class and is tailored specifically for handling
 * webhooks that deal with manual task updates. These tasks could involve manual interventions required
 * for certain operations within the system, which are triggered by external webhook events.
 *
 * @package PostFinanceCheckoutPayment\Core\Api\WebHooks\Strategy
 */
class WebHookTransactionInvoiceStrategy extends WebHookStrategyBase implements WebhookStrategyActionsInterface {

	/**
	 * @inheritDoc
	 */
	public function match(string $webhookEntityId): bool {
		return WebHooksService::TRANSACTION_INVOICE == $webhookEntityId;
	}

	/**
	 * Loads the relevant entity from the API based on the webhook request.
	 *
	 * This method utilizes the TransactionService to fetch entity details (e.g., transaction data)
	 * based on the space and entity ID provided in the webhook request.
	 *
	 * @param WebHookRequest $request request.
	 * @return object|\PostFinanceCheckout\Sdk\Model\TransactionInvoice
	 * @throws \PostFinanceCheckout\Sdk\ApiException ApiException.
	 * @throws \PostFinanceCheckout\Sdk\Http\ConnectionException ConnectionException.
	 * @throws \PostFinanceCheckout\Sdk\VersioningException VersioningException.
	 */
	public function getTransaction(WebHookRequest $request)
	{
		return $this->settings->getApiClient()
			->getTransactionInvoiceService()
			->read($request->getSpaceId(), $request->getEntityId());
	}

	/**
	 * @inheritDoc
	 */
	public function getOrderIdByTransaction($transactionInvoice): string|null
	{
		/** @var \PostFinanceCheckout\Sdk\Model\TransactionInvoice $transaction */
		return $transactionInvoice->getCompletion()
			->getLineItemVersion()
			->getTransaction()
			->getMetaData()[TransactionPayload::POSTFINANCECHECKOUT_METADATA_ORDER_ID];
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
			TransactionInvoiceState::DERECOGNIZED,
			TransactionInvoiceState::NOT_APPLICABLE,
			TransactionInvoiceState::PAID,
		];

		return in_array($request->getState(), $applicableStates);
	}

	/**
	 * Processes the incoming webhook request that pertains to manual tasks.
	 *
	 * This method activates the manual task service to handle updates based on the data provided
	 * in the webhook request. It could involve marking tasks as completed, updating their status, or
	 * initiating sub-processes required as part of the task resolution.
	 *
	 * @param WebHookRequest $request The webhook request object containing all necessary data.
	 * @return Response The method does not return a value but updates the state of manual tasks based on the webhook data.
	 * @throws Exception Throws an exception if there is a failure in processing the manual task updates.
	 */
	public function process(WebHookRequest $request): Response
	{
		return $this->updateTransactionInvoice($request, $this->getContext());
	}

	/**
	 * Processes the PostFinanceCheckout TransactionInvoice webhook request by updating transaction and order states based on the invoice state.
	 * This method handles the entire lifecycle of the invoice processing within the system, from fetching transaction data,
	 * locking operations for safety, updating transaction statuses based on invoice changes, and handling order delivery states.
	 *
	 * @param WebHookRequest $request The data received from the webhook.
	 * @param Context $context The context within which this operation is performed, encapsulating scope-specific information like permissions and current store details.
	 *
	 * @return Response Returns a JSON response indicating the status of the operation, whether it was successful or resulted in an error.
	 */
	public function updateTransactionInvoice(WebHookRequest $request, Context $context): Response
	{
		$status = Response::HTTP_UNPROCESSABLE_ENTITY;

		try {
			$transactionInvoice = $this->getTransaction($request);
			$orderId            = $this->getOrderIdByTransaction($transactionInvoice);
			if(!empty($orderId)) {
				$this->executeLocked($orderId, $context, function () use ($orderId, $transactionInvoice, $context, $request) {

					$orderTransactionId = $transactionInvoice->getCompletion()
						->getLineItemVersion()
						->getTransaction()
						->getMetaData()[TransactionPayload::POSTFINANCECHECKOUT_METADATA_ORDER_TRANSACTION_ID];
					$orderTransaction   = $this->getOrderTransaction($orderId, $context);
					$this->updatePriceIfAdditionalItemsExist($transactionInvoice, $orderTransaction, $context);

					if (!in_array(
						$orderTransaction->getStateMachineState()?->getTechnicalName(),
						$this->transactionFinalStates
					)) {
						switch ($request->getState()) {
							case TransactionInvoiceState::DERECOGNIZED:
								$this->orderTransactionStateHandler->cancel($orderTransactionId, $context);
								break;
							case TransactionInvoiceState::NOT_APPLICABLE:
							case TransactionInvoiceState::PAID:
								$this->orderTransactionStateHandler->paid($orderTransactionId, $context);
								$this->unholdDelivery($orderTransactionId, $context);
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

	/**
	 * Updates the order's total price if there are additional items added to the transaction invoice compared to the completion invoice.
	 * This method checks for discrepancies between the line items listed in the transaction invoice and its completion part,
	 * adjusting the order's total price to reflect any additional items added on the portal side.
	 *
	 * @param TransactionInvoice $transactionInvoice The transaction invoice object containing detailed line items and completion details.
	 * @param OrderTransactionEntity $orderTransaction The order transaction entity linked to the invoice, used for updating order details.
	 * @param Context $context The operational context providing settings and environment for the operation.
	 */
	private function updatePriceIfAdditionalItemsExist(
		TransactionInvoice $transactionInvoice,
		OrderTransactionEntity $orderTransaction,
		Context $context
	): void {
		$completionLineItems = $transactionInvoice->getCompletion()->getLineItems();
		$lineItems = $transactionInvoice->getLineItems();

		if (count($completionLineItems) !== count($lineItems)) {
			$this->transactionService->updateOrderTotalPriceByInvoiceTotal(
				$orderTransaction->getOrderId(),
				$transactionInvoice->getOutstandingAmount(),
				$context
			);
		}
	}
}
