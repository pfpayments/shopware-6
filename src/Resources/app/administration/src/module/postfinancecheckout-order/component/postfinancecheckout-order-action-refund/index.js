/* global Shopware */

import template from './index.html.twig';

const {Component, Mixin, Filter, Utils} = Shopware;

Component.register('postfinancecheckout-order-action-refund', {
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
			refundQuantity: 0,
			isLoading: true,
			currentLineItem: '',
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
			this.refundQuantity = 1;
		},

		refund() {
			this.isLoading = true;
			this.PostFinanceCheckoutRefundService.createRefund(
				this.transactionData.transactions[0].metaData.salesChannelId,
				this.transactionData.transactions[0].id,
				this.refundQuantity,
				this.$parent.$parent.currentLineItem
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
						case 'refundQuantityZero':
							errorMessage = this.$tc('postfinancecheckout-order.refundAction.refundCreateError.messageRefundQuantityIsZero');
						break;
						case 'refundExceedsQuantity':
							errorMessage = this.$tc('postfinancecheckout-order.refundAction.refundCreateError.messageRefundQuantityExceedsAvailableBalance');
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
