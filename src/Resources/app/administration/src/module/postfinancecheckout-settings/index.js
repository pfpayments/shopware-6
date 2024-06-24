/* global Shopware */

import './acl';
import './page/postfinancecheckout-settings';
import './component/sw-postfinancecheckout-credentials';
import './component/sw-postfinancecheckout-options';
import './component/sw-postfinancecheckout-settings-icon';
import './component/sw-postfinancecheckout-storefront-options';
import './component/sw-postfinancecheckout-advanced-options';

import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';
import frFR from './snippet/fr-FR.json';
import itIT from './snippet/it-IT.json';

const {Module} = Shopware;

Module.register('postfinancecheckout-settings', {
	type: 'plugin',
	name: 'PostFinanceCheckout',
	title: 'postfinancecheckout-settings.general.descriptionTextModule',
	description: 'postfinancecheckout-settings.general.descriptionTextModule',
	color: '#28d8ff',
	icon: 'default-action-settings',
	version: '1.0.1',
	targetVersion: '1.0.1',

    snippets: {
        'de-DE': deDE,
        'en-GB': enGB,
        'fr-FR': frFR,
        'it-IT': itIT,
    },

	routes: {
		index: {
			component: 'postfinancecheckout-settings',
			path: 'index',
			meta: {
				parentPath: 'sw.settings.index',
				privilege: 'postfinancecheckout.viewer'
			},
			props: {
                default: (route) => {
                    return {
                        hash: route.params.hash,
                    };
                },
            },
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
