/* global Shopware */

import './extension/sw-order';
import './page/postfinancecheckout-order-detail';

import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

const {Module} = Shopware;

Module.register('postfinancecheckout-order', {
	type: 'plugin',
	name: 'PostFinanceCheckout',
	title: 'postfinancecheckout-order.general.title',
	description: 'postfinancecheckout-order.general.descriptionTextModule',
	version: '1.0.0',
	targetVersion: '1.0.0',
	color: '#2b52ff',

	snippets: {
		'de-DE': deDE,
		'en-GB': enGB
	},

	routeMiddleware(next, currentRoute) {
		if (currentRoute.name === 'sw.order.detail') {
			currentRoute.children.push({
				component: 'postfinancecheckout-order-detail',
				name: 'postfinancecheckout.order.detail',
				isChildren: true,
				path: '/sw/order/postfinancecheckout/detail/:id'
			});
		}
		next(currentRoute);
	}
});
