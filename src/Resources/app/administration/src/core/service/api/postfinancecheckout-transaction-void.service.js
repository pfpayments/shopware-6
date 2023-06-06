/* global Shopware */

const ApiService = Shopware.Classes.ApiService;

/**
 * @class PostFinanceCheckoutPayment\Core\Api\Transaction\Controller\TransactionVoidController
 */
class PostFinanceCheckoutTransactionVoidService extends ApiService {

	/**
	 * PostFinanceCheckoutTransactionVoidService constructor
	 *
	 * @param httpClient
	 * @param loginService
	 * @param apiEndpoint
	 */
	constructor(httpClient, loginService, apiEndpoint = 'postfinancecheckout') {
		super(httpClient, loginService, apiEndpoint);
	}

	/**
	 * Void a transaction
	 *
	 * @param {String} salesChannelId
	 * @param {int} transactionId
	 * @return {*}
	 */
	createTransactionVoid(salesChannelId, transactionId) {

		const headers = this.getBasicHeaders();
		const apiRoute = `${Shopware.Context.api.apiPath}/_action/${this.getApiBasePath()}/transaction-void/create-transaction-void/`;

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
}

export default PostFinanceCheckoutTransactionVoidService;