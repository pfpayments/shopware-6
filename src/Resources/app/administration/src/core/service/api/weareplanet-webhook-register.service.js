/* global Shopware */

const ApiService = Shopware.Classes.ApiService;

/**
 * @class WeArePlanetPayment\Core\Api\WebHooks\Controller\WebHookController
 */
class WeArePlanetWebHookRegisterService extends ApiService {

	/**
	 * WeArePlanetWebHookRegisterService
	 *
	 * @param httpClient
	 * @param loginService
	 * @param apiEndpoint
	 */
	constructor(httpClient, loginService, apiEndpoint = 'weareplanet') {
		super(httpClient, loginService, apiEndpoint);
	}

	/**
	 * Register a webhook
	 *
	 * @param {String|null} salesChannelId
	 * @return {*}
	 */
	registerWebHook(salesChannelId) {

		const headers = this.getBasicHeaders();
		const apiRoute = `${Shopware.Context.api.apiPath}/_action/${this.getApiBasePath()}/webHook/register/${salesChannelId}`;

		return this.httpClient.post(
			apiRoute,
			{},
			{
				headers: headers
			}
		).then((response) => {
			return ApiService.handleResponse(response);
		});
	}
}

export default WeArePlanetWebHookRegisterService;
