<?php

namespace PostFinanceCheckoutPayment\Core\Api\WebHooks\Strategy;

use Exception;
use Symfony\Component\HttpFoundation\{
	JsonResponse,
	Response,};
use Shopware\Core\{
	Checkout\Cart\CartException,
	Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates,
	Framework\Context,
	System\StateMachine\Exception\IllegalTransitionException};
use PostFinanceCheckout\Sdk\{
	Model\Refund,
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
class WebHookRefundStrategy extends WebHookStrategyBase implements WebhookStrategyActionsInterface {

	/**
	 * @inheritDoc
	 */
	public function match(string $webhookEntityId): bool
	{
		return WebHooksService::REFUND == $webhookEntityId;
	}

	/**
	 * Loads the relevant entity from the API based on the webhook request.
	 *
	 * This method utilizes the TransactionService to fetch entity details (e.g., transaction data)
	 * based on the space and entity ID provided in the webhook request.
	 *
	 * @param WebHookRequest $request request.
	 * @return object|\PostFinanceCheckout\Sdk\Model\Refund
	 * @throws \PostFinanceCheckout\Sdk\ApiException ApiException.
	 * @throws \PostFinanceCheckout\Sdk\Http\ConnectionException ConnectionException.
	 * @throws \PostFinanceCheckout\Sdk\VersioningException VersioningException.
	 */
	public function getTransaction(WebHookRequest $request)
	{
		return $this->settings->getApiClient()
			->getRefundService()
			->read($request->getSpaceId(), $request->getEntityId());
	}

	/**
	 * @inheritDoc
	 */
	public function getOrderIdByTransaction($transaction): string|null
	{
		/** @var \PostFinanceCheckout\Sdk\Model\Refund $transaction */
		return $transaction->getTransaction()
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
			RefundState::SUCCESSFUL,
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
		return $this->updateRefund($request, $this->getContext());
	}

	/**
	 * Processes the refund callback for a PostFinanceCheckout transaction, updating the associated order transaction state based on the refund status.
	 * This method handles different refund scenarios, including full and partial refunds, and adjusts the order transaction state accordingly.
	 * It ensures transactional integrity by locking the order record during updates to prevent concurrent modifications.
	 * Logs the outcome of the operation and any exceptions encountered.
	 *
	 * @param WebHookRequest $request The webhook request data encapsulating the refund details.
	 * @param Context $context Shopware execution context, providing scope for operations like database access.
	 *
	 * @return Response Returns a JSON response indicating the outcome of the refund processing.
	 */
	public function updateRefund(WebHookRequest $request, Context $context): Response
	{
		$status = Response::HTTP_UNPROCESSABLE_ENTITY;

		try {
			$refund  = $this->getTransaction($request);
			$orderId = $this->getOrderIdByTransaction($refund);

			if(!empty($orderId)) {
				$this->executeLocked($orderId, $context, function () use ($orderId, $refund, $context, $request) {
					if ($request->getListenerEntityTechnicalName() == WebHookRequest::REFUND && $request->getState() == RefundState::SUCCESSFUL) {
						$this->refundService->upsert($refund, $context);
						$orderTransactionId = $refund->getTransaction()->getMetaData()[TransactionPayload::POSTFINANCECHECKOUT_METADATA_ORDER_TRANSACTION_ID];
						$orderTransaction   = $this->getOrderTransaction($orderId, $context);

						$transactionByOrderTransactionId = $this->transactionService->getByOrderTransactionId($orderTransactionId, $context);
						$totalRefundedAmount  = $this->getTotalRefundedAmount($transactionByOrderTransactionId->getTransactionId(), $context);
						$leftToRefund = floatval($orderTransaction->getAmount()->getTotalPrice()) - $totalRefundedAmount;
						if ($leftToRefund > 0) {
							$this->orderTransactionStateHandler->refundPartially($orderTransactionId, $context);
						} elseif ($leftToRefund === floatval(0)) { // This trick is used, because it's float type and 0 is int
							$this->orderTransactionStateHandler->refund($orderTransactionId, $context);
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
	 * Calculates the total amount refunded for a specific transaction by summing up all refunds associated with it.
	 * This method queries the database for all refund records related to the transaction and aggregates their amounts.
	 * It ensures accurate financial calculations that are crucial for adjusting transaction states and reporting.
	 *
	 * @param int $transactionId The unique identifier of the transaction for which to calculate the total refunded amount.
	 * @param Context $context Shopware execution context, providing scope for operations like database access.
	 *
	 * @return float The total amount refunded for the specified transaction, converted to a float to ensure precision in calculations.
	 */
	private function getTotalRefundedAmount(int $transactionId, Context $context): float
	{
		$amount = 0;
		$refunds = $this->transactionService->getRefundEntityCollectionByTransactionId($transactionId, $context);
		foreach ($refunds as $refund) {
			$amount += floatval($refund->getData()['amount'] ?? 0);
		}

		return (float) (string) $amount;
	}
}
