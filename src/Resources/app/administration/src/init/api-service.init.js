/* global Shopware */

import PostFinanceCheckoutConfigurationService from '../core/service/api/postfinancecheckout-configuration.service';
import PostFinanceCheckoutRefundService from '../core/service/api/postfinancecheckout-refund.service';
import PostFinanceCheckoutTransactionService from '../core/service/api/postfinancecheckout-transaction.service';
import PostFinanceCheckoutTransactionCompletionService
	from '../core/service/api/postfinancecheckout-transaction-completion.service';
import PostFinanceCheckoutTransactionVoidService
	from '../core/service/api/postfinancecheckout-transaction-void.service';


const {Application} = Shopware;

// noinspection JSUnresolvedFunction
Application.addServiceProvider('PostFinanceCheckoutConfigurationService', (container) => {
	const initContainer = Application.getContainer('init');
	return new PostFinanceCheckoutConfigurationService(initContainer.httpClient, container.loginService);
});

// noinspection JSUnresolvedFunction
Application.addServiceProvider('PostFinanceCheckoutRefundService', (container) => {
	const initContainer = Application.getContainer('init');
	return new PostFinanceCheckoutRefundService(initContainer.httpClient, container.loginService);
});

// noinspection JSUnresolvedFunction
Application.addServiceProvider('PostFinanceCheckoutTransactionService', (container) => {
	const initContainer = Application.getContainer('init');
	return new PostFinanceCheckoutTransactionService(initContainer.httpClient, container.loginService);
});

// noinspection JSUnresolvedFunction
Application.addServiceProvider('PostFinanceCheckoutTransactionCompletionService', (container) => {
	const initContainer = Application.getContainer('init');
	return new PostFinanceCheckoutTransactionCompletionService(initContainer.httpClient, container.loginService);
});

// noinspection JSUnresolvedFunction
Application.addServiceProvider('PostFinanceCheckoutTransactionVoidService', (container) => {
	const initContainer = Application.getContainer('init');
	return new PostFinanceCheckoutTransactionVoidService(initContainer.httpClient, container.loginService);
});