/* global Shopware */

import './extension/sw-order';
import './page/weareplanet-order-detail';

import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';
import frFR from './snippet/fr-FR.json';
import itIT from './snippet/it-IT.json';

const {Module} = Shopware;

Module.register('weareplanet-order', {
	type: 'plugin',
	name: 'WeArePlanet',
	title: 'weareplanet-order.general.title',
	description: 'weareplanet-order.general.descriptionTextModule',
	version: '1.0.0',
	targetVersion: '1.0.0',
	color: '#2b52ff',

	snippets: {
		'de-DE': deDE,
		'en-GB': enGB,
		'fr-FR': frFR,
		'it-IT': itIT
	},

	routeMiddleware(next, currentRoute) {
		if (currentRoute.name === 'sw.order.detail') {
			currentRoute.children.push({
				component: 'weareplanet-order-detail',
				name: 'weareplanet.order.detail',
				isChildren: true,
				path: '/sw/order/weareplanet/detail/:id'
			});
		}
		next(currentRoute);
	}
});
