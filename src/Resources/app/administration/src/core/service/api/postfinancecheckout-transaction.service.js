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
	 * @param {String} salesChannelId
	 * @param {int} transactionId
	 * @return {Promise<{blob: Blob, filename: String}>}
	 */
	getInvoiceDocument(salesChannelId, transactionId) {
		return this.fetchDocument(
			`${Shopware.Context.api.apiPath}/_action/${this.getApiBasePath()}/transaction/get-invoice-document/${salesChannelId}/${transactionId}`,
			'invoice.pdf'
		);
	}

	/**
	 * Download Packing slip
	 *
	 * @param {String} salesChannelId
	 * @param {int} transactionId
	 * @return {Promise<{blob: Blob, filename: String}>}
	 */
	getPackingSlip(salesChannelId, transactionId) {
		return this.fetchDocument(
			`${Shopware.Context.api.apiPath}/_action/${this.getApiBasePath()}/transaction/get-packing-slip/${salesChannelId}/${transactionId}`,
			'packing-slip.pdf'
		);
	}

	/**
	 * Fetch a document as a blob through an authenticated request.
	 *
	 * The endpoints sit behind the admin API token, so the URL must not be handed to
	 * window.open() - the browser would send an unauthenticated request and get a 401.
	 *
	 * @param {String} apiRoute
	 * @param {String} fallbackFilename
	 * @return {Promise<{blob: Blob, filename: String}>}
	 */
	fetchDocument(apiRoute, fallbackFilename) {
		return this.httpClient.get(
			apiRoute,
			{
				headers: this.getBasicHeaders(),
				responseType: 'blob'
			}
		).then((response) => {
			return {
				blob: response.data,
				filename: this.parseFilename(response.headers['content-disposition'], fallbackFilename)
			};
		});
	}

	/**
	 * Read the filename out of a Content-Disposition header.
	 *
	 * @param {String} contentDisposition
	 * @param {String} fallbackFilename
	 * @return {String}
	 */
	parseFilename(contentDisposition, fallbackFilename) {
		if (!contentDisposition) {
			return fallbackFilename;
		}

		// The RFC 5987 form takes precedence, it carries the encoded original title.
		const encoded = contentDisposition.match(/filename\*=UTF-8''([^;]+)/i);
		if (encoded) {
			try {
				return decodeURIComponent(encoded[1]);
			} catch (e) {
				// malformed encoding - fall through to the plain form
			}
		}

		const plain = contentDisposition.match(/filename="?([^";]+)"?/i);

		return plain ? plain[1] : fallbackFilename;
	}
}

export default PostFinanceCheckoutTransactionService;