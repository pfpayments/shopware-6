/* global Shopware */

import template from './index.html.twig';
import constants from '../../page/weareplanet-settings/configuration-constants'

const {Component, Mixin} = Shopware;

Component.register('sw-weareplanet-credentials', {
    template: template,

    name: 'WeArePlanetCredentials',

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
            required: true
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
        },
        isShowcase: {
            type: Boolean,
            required: true
        }
    },

    data() {
        return {
            ...constants
        };
    },

    computed: {},

    methods: {

        checkTextFieldInheritance(value) {
            if (typeof value !== 'string') {
                return true;
            }

            return value.length <= 0;
        },

        checkNumberFieldInheritance(value) {
            if (typeof value !== 'number') {
                return true;
            }

            return value.length <= 0;
        },

        checkBoolFieldInheritance(value) {
            return typeof value !== 'boolean';
        },

        // Emits the 'check-api-connection-event' with the current API connection parameters.
        // Used to trigger API connection testing from this component.
        emitCheckApiConnectionEvent() {
            const apiConnectionParams = {
                spaceId: this.actualConfigData[constants.CONFIG_SPACE_ID],
                userId: this.actualConfigData[constants.CONFIG_USER_ID],
                applicationKey: this.actualConfigData[constants.CONFIG_APPLICATION_KEY]
            };

            this.$emit('check-api-connection-event', apiConnectionParams);
        }
    }
});
