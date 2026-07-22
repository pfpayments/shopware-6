/* global Shopware */

import template from './index.html.twig';
import constants from '../../page/postfinancecheckout-settings/configuration-constants'

const {Component, Mixin, Context} = Shopware;
const Criteria = Shopware.Data.Criteria;

Component.register('sw-postfinancecheckout-options', {
	template: template,

	name: 'PostFinanceCheckoutOptions',

	inject: [
		'repositoryFactory'
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
			required: true
		},
		isLoading: {
			type: Boolean,
			required: true
		}
	},

	data() {
		return {
			...constants,
			productCustomFields: []
		};
	},

	created() {
		this.loadProductCustomFields();
	},

	computed: {
		customFieldRepository() {
			return this.repositoryFactory.create('custom_field');
		},

		productCustomFieldOptions() {
			const session = Shopware.Store && Shopware.Store.get ? Shopware.Store.get('session') : null;
			const locale = (session && session.currentLocale) || 'en-GB';

			return this.productCustomFields.map((customField) => {
				const configLabel = customField.config && customField.config.label
					? (customField.config.label[locale] || customField.config.label['en-GB'])
					: null;

				return {
					value: customField.name,
					label: configLabel && configLabel !== customField.name
						? `${configLabel} (${customField.name})`
						: customField.name
				};
			});
		},

		integrationOptions() {
			return [
				{
					id: 'payment_page',
					name: this.$tc('postfinancecheckout-settings.settingForm.options.integration.options.payment_page')
				},
				{
					id: 'iframe',
					name: this.$tc('postfinancecheckout-settings.settingForm.options.integration.options.iframe')
				}
			];
		}
	},

	methods: {
		loadProductCustomFields() {
			const criteria = new Criteria(1, 500);
			criteria.addFilter(Criteria.equals('customFieldSet.relations.entityName', 'product'));
			criteria.addSorting(Criteria.sort('name', 'ASC'));

			this.customFieldRepository.search(criteria, Context.api).then((result) => {
				this.productCustomFields = result;
			});
		},

		checkMultiSelectFieldInheritance(value) {
			return !Array.isArray(value) || value.length <= 0;
		},

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
		}
	}
});
