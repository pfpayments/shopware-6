/* global Shopware */

import './extension/sw-plugin';
import './extension/sw-settings-index';
import './page/postfinancecheckout-settings';
import './component/sw-postfinancecheckout-credentials';
import './component/sw-postfinancecheckout-options';
import './component/sw-postfinancecheckout-storefront-options';
import enGB from './snippet/en-GB.json';
import deDE from './snippet/de-DE.json';

const {Module} = Shopware;

Module.register('postfinancecheckout-settings', {
	type: 'plugin',
	name: 'PostFinanceCheckout',
	title: 'postfinancecheckout-settings.general.descriptionTextModule',
	description: 'postfinancecheckout-settings.general.descriptionTextModule',
	color: '#62ff80',
	icon: 'default-action-settings',

	snippets: {
		'de-DE': deDE,
		'en-GB': enGB
	},

	routes: {
		index: {
			component: 'postfinancecheckout-settings',
			path: 'index',
			meta: {
				parentPath: 'sw.settings.index'
			}
		}
	}

});
