<?php

namespace PostFinanceCheckoutPayment\Core\Api\WebHooks\Strategy;

use PostFinanceCheckoutPayment\Core\Api\WebHooks\Struct\WebHookRequest;
use PostFinanceCheckout\Sdk\{
	Model\Refund,
	Model\RefundState,
	Model\Transaction,
	Model\TransactionInvoiceState,
	Model\TransactionState,
	Model\TransactionInvoice,
	ApiException,
	Http\ConnectionException,
	VersioningException,};

/**
 * Class Entity
 * Defines a strategy interface for processing webhook requests.
 *
 * @package PostFinanceCheckoutPayment\Core\Api\WebHooks\Strategy
 */
interface WebhookStrategyActionsInterface {

	/**
	 * Check if the request state is applicable.
	 *
	 * This method checks if the state of the transaction from a webhook request
	 * is one of the predefined applicable states.
	 *
	 * @param WebHookRequest $request The webhook request containing the transaction state.
	 * @return bool Returns true if the state is applicable, false otherwise.
	 */
	public function isRequestStateApplicable(WebHookRequest $request): bool;

	/**
	 * Loads the relevant entity from the API based on the webhook request.
	 *
	 * This method utilizes the TransactionService to fetch entity details
	 * based on the space and entity ID provided in the webhook request.
	 *
	 * @param WebHookRequest $request request.
	 * @return object|Transaction
	 * @throws ApiException ApiException.
	 * @throws ConnectionException ConnectionException.
	 * @throws VersioningException VersioningException.
	 */
	public function getTransaction(WebHookRequest $request);

	/**
	 * Get the order ID associated with a transaction.
	 *
	 * This method abstracts the retrieval of an order ID based on a provided metadata transaction object.
	 * Implementing classes must define the specific logic to extract the order ID from the transaction.
	 * The transaction object can be of type Transaction, TransactionInvoiceState, or Refund.
	 *
	 * @param Transaction|TransactionInvoiceState|Refund|mixed $transaction The transaction object from which the order ID should be extracted.
	 * @return string The order ID as a string.
	 */
	public function getOrderIdByTransaction(Transaction $transaction): string;
}
