/* global Shopware */

import template from './index.html.twig';
import constants from '../../page/postfinancecheckout-settings/configuration-constants'

const {Component, Mixin} = Shopware;

Component.register('sw-postfinancecheckout-credentials', {
    template,

    name: 'PostFinanceCheckoutCredentials',

    inject: [
        'acl'
    ],

    mixins: [
        Mixin.getByName('notification')
    ],

    props: {
        actualConfigData: {
            type: Object,
            required: true
        },
        allConfigs: {
            type: Object,
            required: true
        },

        selectedSalesChannelId: {
            type: [String, null],
            required: false,
            default: null
        },
        spaceIdFilled: {
            type: Boolean,
            required: true
        },
        spaceIdErrorState: {
            required: true
        },
        userIdFilled: {
            type: Boolean,
            required: true
        },
        userIdErrorState: {
            required: true
        },
        applicationKeyFilled: {
            type: Boolean,
            required: true
        },
        applicationKeyErrorState: {
            required: true
        },
        isLoading: {
            type: Boolean,
            required: true
        },
        isTesting: {
            type: Boolean,
            required: false
        }
    },

    data() {
        return {
            ...constants
        };
    },


    computed: {
        currentConfig() {
            if (this.selectedSalesChannelId && this.allConfigs[this.selectedSalesChannelId]) {
                return this.allConfigs[this.selectedSalesChannelId];
            }
            return this.allConfigs['null'] || {};
        }
    },

    methods: {

		checkTextFieldInheritance(value) {
		    return !value || value.length <= 0;
		},

		checkNumberFieldInheritance(value) {
		    return value == null || value === '';
		},

		checkBoolFieldInheritance(value) {
		    return typeof value !== 'boolean';
		},

        // Emits the 'check-api-connection-event' with the current API connection parameters.
        // Used to trigger API connection testing from this component.
        emitCheckApiConnectionEvent() {
            const apiConnectionParams = {
                spaceId: this.currentConfig[constants.CONFIG_SPACE_ID],
                userId: this.currentConfig[constants.CONFIG_USER_ID],
                applicationKey: this.currentConfig[constants.CONFIG_APPLICATION_KEY]
            };

            this.$emit('check-api-connection-event', apiConnectionParams);
        },

        getInheritedValue(key) {
            return this.allConfigs['null']?.[key] ?? null;
        }
    }
});
