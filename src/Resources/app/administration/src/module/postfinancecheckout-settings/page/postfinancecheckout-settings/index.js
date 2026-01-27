/* global Shopware */

import template from './index.html.twig';
import constants from './configuration-constants';

const { Component, Mixin } = Shopware;

Component.register('postfinancecheckout-settings', {

    template: template,

    inject: [
        'acl',
        'PostFinanceCheckoutConfigurationService',
        'repositoryFactory'
    ],

    mixins: [
        Mixin.getByName('notification'),
        Mixin.getByName('sw-inline-snippet')
    ],

    data() {
        return {

            config: {},

            isLoading: false,
            isTesting: false,

            isSaveSuccessful: false,

            applicationKeyFilled: false,
            applicationKeyErrorState: false,

            spaceIdFilled: false,
            spaceIdErrorState: false,

            userIdFilled: false,
            userIdErrorState: false,

            isSetDefaultPaymentSuccessful: false,
            isSettingDefaultPaymentMethods: false,

            configIntegrationDefaultValue: 'payment_page',
            configEmailEnabledDefaultValue: true,
            configLineItemConsistencyEnabledDefaultValue: true,
            configStorefrontInvoiceDownloadEnabledEnabledDefaultValue: true,
            configStorefrontWebhooksUpdateEnabledDefaultValue: true,
            configStorefrontPaymentsUpdateEnabledDefaultValue: true,

            ...constants
        };
    },

    props: {
        isLoading: {
            type: Boolean,
            required: true
        }
    },

    metaInfo() {
        return {
            title: this.$createTitle()
        };
    },

    watch: {
        config: {
            handler(configData) {
                const defaultConfig = (this.$refs.configComponent.allConfigs || {}).null || {};
                const salesChannelId = this.$refs.configComponent.selectedSalesChannelId;
                if (salesChannelId === null) {

                    this.applicationKeyFilled = !!this.config[this.CONFIG_APPLICATION_KEY];
                    this.spaceIdFilled = !!this.config[this.CONFIG_SPACE_ID];
                    this.userIdFilled = !!this.config[this.CONFIG_USER_ID];

                    if (!(this.CONFIG_INTEGRATION in this.config)) {
                        this.config[this.CONFIG_INTEGRATION] = this.configIntegrationDefaultValue;
                    }

                    if (!(this.CONFIG_EMAIL_ENABLED in this.config)) {
                        this.config[this.CONFIG_EMAIL_ENABLED] = this.configEmailEnabledDefaultValue;
                    }

                    if (!(this.CONFIG_LINE_ITEM_CONSISTENCY_ENABLED in this.config)) {
                        this.config[this.CONFIG_LINE_ITEM_CONSISTENCY_ENABLED] = this.configLineItemConsistencyEnabledDefaultValue;
                    }

                    if (!(this.CONFIG_STOREFRONT_INVOICE_DOWNLOAD_ENABLED in this.config)) {
                        this.config[this.CONFIG_STOREFRONT_INVOICE_DOWNLOAD_ENABLED] = this.configStorefrontInvoiceDownloadEnabledEnabledDefaultValue;
                    }

                    if (!(this.CONFIG_STOREFRONT_WEBHOOKS_UPDATE_ENABLED in this.config)) {
                        this.config[this.CONFIG_STOREFRONT_WEBHOOKS_UPDATE_ENABLED] = this.configStorefrontWebhooksUpdateEnabledDefaultValue;
                    }

                    if (!(this.CONFIG_STOREFRONT_PAYMENTS_UPDATE_ENABLED in this.config)) {
                        this.config[this.CONFIG_STOREFRONT_PAYMENTS_UPDATE_ENABLED] = this.configStorefrontPaymentsUpdateEnabledDefaultValue;
                    }

                } else {

                    this.applicationKeyFilled = !!this.config[this.CONFIG_APPLICATION_KEY] || !!defaultConfig[this.CONFIG_APPLICATION_KEY];
                    this.spaceIdFilled = !!this.config[this.CONFIG_SPACE_ID] || !!defaultConfig[this.CONFIG_SPACE_ID];
                    this.userIdFilled = !!this.config[this.CONFIG_USER_ID] || !!defaultConfig[this.CONFIG_USER_ID];


                    if (!(this.CONFIG_INTEGRATION in this.config) || !(this.CONFIG_INTEGRATION in defaultConfig)) {
                        this.config[this.CONFIG_INTEGRATION] = this.configIntegrationDefaultValue;
                    }

                    if (!(this.CONFIG_EMAIL_ENABLED in this.config) || !(this.CONFIG_EMAIL_ENABLED in defaultConfig)) {
                        this.config[this.CONFIG_EMAIL_ENABLED] = this.configEmailEnabledDefaultValue;
                    }

                    if (!(this.CONFIG_LINE_ITEM_CONSISTENCY_ENABLED in this.config) || !(this.CONFIG_LINE_ITEM_CONSISTENCY_ENABLED in defaultConfig)) {
                        this.config[this.CONFIG_LINE_ITEM_CONSISTENCY_ENABLED] = this.configLineItemConsistencyEnabledDefaultValue;
                    }

                    if (!(this.CONFIG_STOREFRONT_INVOICE_DOWNLOAD_ENABLED in this.config) || !(this.CONFIG_STOREFRONT_INVOICE_DOWNLOAD_ENABLED in defaultConfig)) {
                        this.config[this.CONFIG_STOREFRONT_INVOICE_DOWNLOAD_ENABLED] = this.configStorefrontInvoiceDownloadEnabledEnabledDefaultValue;
                    }

                    if (!(this.CONFIG_STOREFRONT_WEBHOOKS_UPDATE_ENABLED in this.config) || !(this.CONFIG_STOREFRONT_WEBHOOKS_UPDATE_ENABLED in defaultConfig)) {
                        this.config[this.CONFIG_STOREFRONT_WEBHOOKS_UPDATE_ENABLED] = this.configStorefrontWebhooksUpdateEnabledDefaultValue;
                    }

                    if (!(this.CONFIG_STOREFRONT_PAYMENTS_UPDATE_ENABLED in this.config) || !(this.CONFIG_STOREFRONT_PAYMENTS_UPDATE_ENABLED in defaultConfig)) {
                        this.config[this.CONFIG_STOREFRONT_PAYMENTS_UPDATE_ENABLED] = this.configStorefrontPaymentsUpdateEnabledDefaultValue;
                    }
                }

                this.$emit('salesChannelChanged');
                this.$emit('update:value', configData);
            },
            deep: true
        }
    },

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

        getInheritValue(key) {
            if (this.selectedSalesChannelId == null) {
                return this.actualConfigData[key];
            } else {
                return this.allConfigs['null'][key];
            }
        },

        async onSave() {
            if (!(this.spaceIdFilled && this.userIdFilled && this.applicationKeyFilled)) {
                this.setErrorStates();
                return;
            }

            this.isLoading = true;
            const validationError = await this.validateHeadlessIntegration();

            if (validationError === 'HEADLESS') {
                this.createNotificationError({
                    title: this.$tc('postfinancecheckout-settings.settingForm.titleError'),
                    message: this.$tc('postfinancecheckout-settings.settingForm.messageHeadlessIntegrationError')
                });
                this.isLoading = false;
                return;
            } else if (validationError === 'GLOBAL') {
                this.createNotificationError({
                    title: this.$tc('postfinancecheckout-settings.settingForm.titleError'),
                    message: this.$tc('postfinancecheckout-settings.settingForm.messageGlobalIframeError')
                });
                this.isLoading = false;
                return;
            }

            this.save();
        },

        async validateHeadlessIntegration() {
            const salesChannelId = this.$refs.configComponent.selectedSalesChannelId;
            const currentIntegration = this.config[this.CONFIG_INTEGRATION];

            // If integration is 'payment_page', it is always valid.
            if (currentIntegration === 'payment_page') {
                return null;
            }

            const salesChannelRepo = this.repositoryFactory.create('sales_channel');

            try {
                if (salesChannelId) {
                    // Specific Sales Channel Check
                    const salesChannel = await salesChannelRepo.get(salesChannelId, Shopware.Context.api);

                    const currentTypeId = salesChannel.typeId.replace(/-/g, '');

                    // REST-2: Inverted Logic
                    // We only allow 'iframe' integration if the Sales Channel is of type 'Storefront'.
                    // Any other type (Headless, Product Export, Custom, etc.) does not support Iframe injection.
                    const isStorefront = currentTypeId === constants.STOREFRONT_SALES_CHANNEL_TYPE_ID;
                    if (!isStorefront) {
                        return 'HEADLESS';
                    }
                } else {
                    // Global Scope ("All Sales Channels") Check
                    // We must check if there is ANY Sales Channel that is NOT Storefront.
                    // If so, we cannot allow "Iframe" globally, as it would break those channels.
                    const criteria = new Shopware.Data.Criteria();
                    criteria.addFilter(
                        Shopware.Data.Criteria.not(
                            'AND',
                            [Shopware.Data.Criteria.equals('typeId', constants.STOREFRONT_SALES_CHANNEL_TYPE_ID)]
                        )
                    );
                    criteria.setLimit(1); // We only need to know if at least one exists

                    const result = await salesChannelRepo.search(criteria, Shopware.Context.api);

                    if (result.total > 0) {
                        return 'GLOBAL';
                    }
                }

                return null;
            } catch (e) {
                console.error(e);
                return null;
            }
        },

        save() {
            this.isLoading = true;

            this.$refs.configComponent.save().then((res) => {
                if (res) {
                    this.config = res;
                }
                this.registerWebHooks();
                this.synchronizePaymentMethodConfiguration();
                this.installOrderDeliveryStates();
            }).catch((e) => {
                console.error('Error:', e);
                this.isLoading = false;
            });
        },

        registerWebHooks() {
            if (this.config[this.CONFIG_STOREFRONT_WEBHOOKS_UPDATE_ENABLED] === false) {
                return false;
            }

            this.PostFinanceCheckoutConfigurationService.registerWebHooks(this.$refs.configComponent.selectedSalesChannelId)
                .then(() => {
                    this.createNotificationSuccess({
                        title: this.$tc('postfinancecheckout-settings.settingForm.titleSuccess'),
                        message: this.$tc('postfinancecheckout-settings.settingForm.messageWebHookUpdated')
                    });
                }).catch((e) => {
                    this.createNotificationError({
                        title: this.$tc('postfinancecheckout-settings.settingForm.titleError'),
                        message: this.$tc('postfinancecheckout-settings.settingForm.messageWebHookError')
                    });
                    this.isLoading = false;
                    console.error('Error:', e);
                });
        },

        synchronizePaymentMethodConfiguration() {
            if (this.config[this.CONFIG_STOREFRONT_PAYMENTS_UPDATE_ENABLED] === false) {
                return false;
            }

            this.PostFinanceCheckoutConfigurationService.synchronizePaymentMethodConfiguration(this.$refs.configComponent.selectedSalesChannelId)
                .then(() => {
                    this.createNotificationSuccess({
                        title: this.$tc('postfinancecheckout-settings.settingForm.titleSuccess'),
                        message: this.$tc('postfinancecheckout-settings.settingForm.messagePaymentMethodConfigurationUpdated')
                    });
                    this.isLoading = false;
                }).catch((e) => {
                    this.createNotificationError({
                        title: this.$tc('postfinancecheckout-settings.settingForm.titleError'),
                        message: this.$tc('postfinancecheckout-settings.settingForm.messagePaymentMethodConfigurationError')
                    });
                    this.isLoading = false;
                    console.error('Error:', e);
                });
        },

        installOrderDeliveryStates() {
            this.PostFinanceCheckoutConfigurationService.installOrderDeliveryStates()
                .then(() => {
                    this.createNotificationSuccess({
                        title: this.$tc('postfinancecheckout-settings.settingForm.titleSuccess'),
                        message: this.$tc('postfinancecheckout-settings.settingForm.messageOrderDeliveryStateUpdated')
                    });
                    this.isLoading = false;
                }).catch(() => {
                    this.createNotificationError({
                        title: this.$tc('postfinancecheckout-settings.settingForm.titleError'),
                        message: this.$tc('postfinancecheckout-settings.settingForm.messageOrderDeliveryStateError')
                    });
                    this.isLoading = false;
                });
        },

        onSetPaymentMethodDefault() {
            this.isSettingDefaultPaymentMethods = true;
            this.PostFinanceCheckoutConfigurationService.setPostFinanceCheckoutAsSalesChannelPaymentDefault(
                this.$refs.configComponent.selectedSalesChannelId
            ).then(() => {
                this.isSettingDefaultPaymentMethods = false;
                this.isSetDefaultPaymentSuccessful = true;
                this.createNotificationSuccess({
                    title: this.$tc('postfinancecheckout-settings.settingForm.titleSuccess'),
                    message: this.$tc('postfinancecheckout-settings.salesChannelCard.messageDefaultPaymentUpdated')
                });
            });
        },

        setErrorStates() {
            const messageNotBlankErrorState = {
                code: 1,
                detail: this.$tc('postfinancecheckout-settings.messageNotBlank')
            };

            if (!this.spaceIdFilled) {
                this.spaceIdErrorState = messageNotBlankErrorState;
            }

            if (!this.userIdFilled) {
                this.userIdErrorState = messageNotBlankErrorState;
            }

            if (!this.applicationKeyFilled) {
                this.applicationKeyErrorState = messageNotBlankErrorState;
            }
        },

        // Handles the 'check-api-connection-event'.
        // Uses the provided apiConnectionData to perform API connection checks.
        onCheckApiConnection(apiConnectionData) {
            const { spaceId, userId, applicationKey } = apiConnectionData;
            this.isTesting = true;

            this.PostFinanceCheckoutConfigurationService.checkApiConnection(spaceId, userId, applicationKey)
                .then((res) => {
                    if (res.result === 200) {
                        this.createNotificationSuccess({
                            title: this.$tc('postfinancecheckout-settings.settingForm.credentials.alert.title'),
                            message: this.$tc('postfinancecheckout-settings.settingForm.credentials.alert.successMessage')
                        });
                    } else {
                        this.createNotificationError({
                            title: this.$tc('postfinancecheckout-settings.settingForm.credentials.alert.title'),
                            message: this.$tc('postfinancecheckout-settings.settingForm.credentials.alert.errorMessage')
                        });
                    }
                    this.isTesting = false;
                }).catch(() => {
                    this.createNotificationError({
                        title: this.$tc('postfinancecheckout-settings.settingForm.credentials.alert.title'),
                        message: this.$tc('postfinancecheckout-settings.settingForm.credentials.alert.errorMessage')
                    });
                    this.isTesting = false;
                });
        }
    }
});
