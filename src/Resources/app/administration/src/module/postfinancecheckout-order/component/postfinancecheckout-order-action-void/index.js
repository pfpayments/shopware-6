/* global Shopware */

import template from './index.html.twig';

const {Component, Mixin, Filter, Utils} = Shopware;

Component.register('postfinancecheckout-order-action-void', {
	template,

	inject: ['PostFinanceCheckoutTransactionVoidService'],

	mixins: [
		Mixin.getByName('notification')
	],

	props: {
		transactionData: {
			type: Object,
			required: true
		}
	},

	data() {
		return {
			isLoading: true,
			isVoid: false
		};
	},

	computed: {
		dateFilter() {
			return Filter.getByName('date');
		},
		lineItemColumns() {
			return [
				{
					property: 'uniqueId',
					label: this.$tc('postfinancecheckout-order.refund.types.uniqueId'),
					rawData: false,
					allowResize: true,
					primary: true,
					width: 'auto'
				},
				{
					property: 'name',
					label: this.$tc('postfinancecheckout-order.refund.types.name'),
					rawData: true,
					allowResize: true,
					sortable: true,
					width: 'auto'
				},
				{
					property: 'quantity',
					label: this.$tc('postfinancecheckout-order.refund.types.quantity'),
					rawData: true,
					allowResize: true,
					width: 'auto'
				},
				{
					property: 'amountIncludingTax',
					label: this.$tc('postfinancecheckout-order.refund.types.amountIncludingTax'),
					rawData: true,
					allowResize: true,
					inlineEdit: 'string',
					width: 'auto'
				},
				{
					property: 'type',
					label: this.$tc('postfinancecheckout-order.refund.types.type'),
					rawData: true,
					allowResize: true,
					sortable: true,
					width: 'auto'
				},
				{
					property: 'taxAmount',
					label: this.$tc('postfinancecheckout-order.refund.types.taxAmount'),
					rawData: true,
					allowResize: true,
					width: 'auto'
				}
			];
		}
	},

	created() {
		this.createdComponent();
	},

	methods: {
		createdComponent() {
			this.isLoading = false;
			this.currency = this.transactionData.transactions[0].currency;
			this.refundableAmount = this.transactionData.transactions[0].amountIncludingTax;
			this.refundAmount = this.transactionData.transactions[0].amountIncludingTax;
		},

		voidPayment() {
			if (this.isVoid) {
				this.isLoading = true;
				this.PostFinanceCheckoutTransactionVoidService.createTransactionVoid(
					this.transactionData.transactions[0].metaData.salesChannelId,
					this.transactionData.transactions[0].id
				).then(() => {
					this.createNotificationSuccess({
						title: this.$tc('postfinancecheckout-order.voidAction.successTitle'),
						message: this.$tc('postfinancecheckout-order.voidAction.successMessage')
					});
					this.isLoading = false;
					this.$emit('modal-close');
					this.$nextTick(() => {
						this.$router.replace(`${this.$route.path}?hash=${Utils.createId()}`);
					});
				}).catch((errorResponse) => {
					try {
						this.createNotificationError({
							title: errorResponse.response.data.errors[0].title,
							message: errorResponse.response.data.errors[0].detail,
							autoClose: false
						});
					} catch (e) {
						this.createNotificationError({
							title: errorResponse.title,
							message: errorResponse.message,
							autoClose: false
						});
					} finally {
						this.isLoading = false;
						this.$emit('modal-close');
						this.$nextTick(() => {
							this.$router.replace(`${this.$route.path}?hash=${Utils.createId()}`);
						});
					}
				});
			}
		}
	}
});
