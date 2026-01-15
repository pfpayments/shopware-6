/* global Shopware */

import template from './index.html.twig';

const {Component, Mixin, Filter, Utils} = Shopware;

Component.register('postfinancecheckout-order-action-refund-by-amount', {
	template,

	inject: ['PostFinanceCheckoutRefundService'],

	mixins: [
		Mixin.getByName('notification')
	],

	props: {
		transactionData: {
			type: Object,
			required: true
		},

		orderId: {
			type: String,
			required: true
		}
	},

	data() {
		return {
			isLoading: true,
			currency: this.transactionData.transactions[0].currency,
			refundAmount: 0,
			refundableAmount: 0,
		};
	},

	computed: {
		dateFilter() {
			return Filter.getByName('date');
		}
	},

	created() {
		this.createdComponent();
	},

	methods: {
		createdComponent() {
			this.isLoading = false;
			this.currency = this.transactionData.transactions[0].currency;
			this.refundAmount = Number(this.transactionData.transactions[0].amountIncludingTax);
			this.refundableAmount = Number(this.transactionData.transactions[0].amountIncludingTax);
		},

		refundByAmount() {
			this.isLoading = true;
			this.PostFinanceCheckoutRefundService.createRefundByAmount(
				this.transactionData.transactions[0].metaData.salesChannelId,
				this.transactionData.transactions[0].id,
				this.refundAmount
			).then(() => {
				this.createNotificationSuccess({
					title: this.$tc('postfinancecheckout-order.refundAction.successTitle'),
					message: this.$tc('postfinancecheckout-order.refundAction.successMessage')
				});
				this.isLoading = false;
				this.$emit('modal-close');
				this.$nextTick(() => {
					this.$router.replace(`${this.$route.path}?hash=${Utils.createId()}`);
				});
			}).catch((errorResponse) => {
				try {
					var errorTitle = errorResponse?.response?.data?.errors?.[0]?.title ?? this.$tc('postfinancecheckout-order.refundAction.refundCreateError.errorTitle')
					var errorMessage;
					switch(errorResponse.response.data) {
						case 'refundAmountZero':
							errorMessage = this.$tc('postfinancecheckout-order.refundAction.refundCreateError.messageRefundAmountIsZero');
						break;
						case 'refundExceedsAmount':
							errorMessage = this.$tc('postfinancecheckout-order.refundAction.refundCreateError.messageRefundAmountExceedsAvailableBalance');
						break;
						case 'methodDoesNotSupportRefund':
							errorMessage = this.$tc('postfinancecheckout-order.refundAction.refundCreateError.messagePaymentMethodDoesNotSupportRefund');
						break;
						default:
							errorMessage = errorResponse.response.data.errors[0].detail;
					}
					this.createNotificationError({
						title: errorTitle,
						message: errorMessage,
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
});
