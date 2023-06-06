/* global Shopware */

const ApiService = Shopware.Classes.ApiService;

/**
 * @class PostFinanceCheckoutPayment\Core\Api\Transaction\Controller\TransactionController
 */
class PostFinanceCheckoutTransactionService extends ApiService {

	/**
	 * PostFinanceCheckoutTransactionService constructor
	 *
	 * @param httpClient
	 * @param loginService
	 * @param apiEndpoint
	 */
	constructor(httpClient, loginService, apiEndpoint = 'postfinancecheckout') {
		super(httpClient, loginService, apiEndpoint);
	}

	/**
	 * Get transaction data
	 *
	 * @param {String} salesChannelId
	 * @param {int} transactionId
	 * @return {*}
	 */
	getTransactionData(salesChannelId, transactionId) {

		const headers = this.getBasicHeaders();
		const apiRoute = `${Shopware.Context.api.apiPath}/_action/${this.getApiBasePath()}/transaction/get-transaction-data/`;

		return this.httpClient.post(
			apiRoute,
			{
				salesChannelId: salesChannelId,
				transactionId: transactionId
			},
			{
				headers: headers
			}
		).then((response) => {
			return ApiService.handleResponse(response);
		});
	}

	/**
	 * Download Invoice Document
	 *
	 * @param context
	 * @param salesChannelId
	 * @param transactionId
	 * @return {string}
	 */
	getInvoiceDocument(salesChannelId, transactionId) {
		return `${Shopware.Context.api.apiPath}/_action/${this.getApiBasePath()}/transaction/get-invoice-document/${salesChannelId}/${transactionId}`;
	}

	/**
	 * Download Packing slip
	 *
	 * @param salesChannelId
	 * @param transactionId
	 * @return {string}
	 */
	getPackingSlip(salesChannelId, transactionId) {
		return `${Shopware.Context.api.apiPath}/_action/${this.getApiBasePath()}/transaction/get-packing-slip/${salesChannelId}/${transactionId}`;
	}
}

export default PostFinanceCheckoutTransactionService;