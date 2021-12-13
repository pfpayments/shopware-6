/* global Shopware */

import './acl';
import './page/postfinancecheckout-settings';
import './component/sw-postfinancecheckout-credentials';
import './component/sw-postfinancecheckout-options';
import './component/sw-postfinancecheckout-settings-icon';
import './component/sw-postfinancecheckout-storefront-options';
import './component/sw-postfinancecheckout-advanced-options';

const {Module} = Shopware;

Module.register('postfinancecheckout-settings', {
	type: 'plugin',
	name: 'PostFinanceCheckout',
	title: 'postfinancecheckout-settings.general.descriptionTextModule',
	description: 'postfinancecheckout-settings.general.descriptionTextModule',
	color: '#28d8ff',
	icon: 'default-action-settings',
	version: '1.0.0',
	targetVersion: '1.0.0',

	routes: {
		index: {
			component: 'postfinancecheckout-settings',
			path: 'index',
			meta: {
				parentPath: 'sw.settings.index',
				privilege: 'postfinancecheckout.viewer'
			}
		}
	},

	settingsItem: {
		group: 'plugins',
		to: 'postfinancecheckout.settings.index',
		iconComponent: 'sw-postfinancecheckout-settings-icon',
		backgroundEnabled: true,
		privilege: 'postfinancecheckout.viewer'
	}

});
