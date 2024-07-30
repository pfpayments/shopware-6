/* global Shopware */

import WeArePlanetConfigurationService from '../core/service/api/weareplanet-configuration.service';
import WeArePlanetRefundService from '../core/service/api/weareplanet-refund.service';
import WeArePlanetTransactionService from '../core/service/api/weareplanet-transaction.service';
import WeArePlanetTransactionCompletionService
	from '../core/service/api/weareplanet-transaction-completion.service';
import WeArePlanetTransactionVoidService
	from '../core/service/api/weareplanet-transaction-void.service';


const {Application} = Shopware;

// noinspection JSUnresolvedFunction
Application.addServiceProvider('WeArePlanetConfigurationService', (container) => {
	const initContainer = Application.getContainer('init');
	return new WeArePlanetConfigurationService(initContainer.httpClient, container.loginService);
});

// noinspection JSUnresolvedFunction
Application.addServiceProvider('WeArePlanetRefundService', (container) => {
	const initContainer = Application.getContainer('init');
	return new WeArePlanetRefundService(initContainer.httpClient, container.loginService);
});

// noinspection JSUnresolvedFunction
Application.addServiceProvider('WeArePlanetTransactionService', (container) => {
	const initContainer = Application.getContainer('init');
	return new WeArePlanetTransactionService(initContainer.httpClient, container.loginService);
});

// noinspection JSUnresolvedFunction
Application.addServiceProvider('WeArePlanetTransactionCompletionService', (container) => {
	const initContainer = Application.getContainer('init');
	return new WeArePlanetTransactionCompletionService(initContainer.httpClient, container.loginService);
});

// noinspection JSUnresolvedFunction
Application.addServiceProvider('WeArePlanetTransactionVoidService', (container) => {
	const initContainer = Application.getContainer('init');
	return new WeArePlanetTransactionVoidService(initContainer.httpClient, container.loginService);
});