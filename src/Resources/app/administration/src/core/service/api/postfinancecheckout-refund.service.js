/* global Shopware */

const ApiService = Shopware.Classes.ApiService;

/**
 * @class PostFinanceCheckoutPayment\Core\Api\Transaction\Controller\RefundController
 */
class PostFinanceCheckoutRefundService extends ApiService {

	/**
	 * PostFinanceCheckoutRefundService constructor
	 *
	 * @param httpClient
	 * @param loginService
	 * @param apiEndpoint
	 */
	constructor(httpClient, loginService, apiEndpoint = 'postfinancecheckout') {
		super(httpClient, loginService, apiEndpoint);
	}

	/**
	 * Refund a transaction
	 *
	 * @param {String} salesChannelId
	 * @param {int} transactionId
	 * @param {int} quantity
	 * @param {int} lineItemId
	 * @return {*}
	 */
	createRefund(salesChannelId, transactionId, quantity, lineItemId) {

		const headers = this.getBasicHeaders();
		const apiRoute = `${Shopware.Context.api.apiPath}/_action/${this.getApiBasePath()}/refund/create-refund/`;

		return this.httpClient.post(
			apiRoute,
			{
				salesChannelId: salesChannelId,
				transactionId: transactionId,
				quantity: quantity,
				lineItemId: lineItemId
			},
			{
				headers: headers
			}
		).then((response) => {
			return ApiService.handleResponse(response);
		});
	}

	/**
	 * Refund a transaction
	 *
	 * @param {String} salesChannelId
	 * @param {int} transactionId
	 * @param {float} refundableAmount
	 * @return {*}
	 */
	createRefundByAmount(salesChannelId, transactionId, refundableAmount) {

		const headers = this.getBasicHeaders();
		const apiRoute = `${Shopware.Context.api.apiPath}/_action/${this.getApiBasePath()}/refund/create-refund-by-amount/`;

		return this.httpClient.post(
			apiRoute,
			{
				salesChannelId: salesChannelId,
				transactionId: transactionId,
				refundableAmount: refundableAmount
			},
			{
				headers: headers
			}
		).then((response) => {
			return ApiService.handleResponse(response);
		});
	}
}

export default PostFinanceCheckoutRefundService;