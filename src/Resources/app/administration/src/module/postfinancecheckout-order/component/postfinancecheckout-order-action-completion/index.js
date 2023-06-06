/* global Shopware */

import template from './index.html.twig';

const {Component, Mixin, Filter, Utils} = Shopware;

Component.register('postfinancecheckout-order-action-completion', {

	template: template,

	inject: ['PostFinanceCheckoutTransactionCompletionService'],

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
			isCompletion: false
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
		},

		completion() {
			if (this.isCompletion) {
				this.isLoading = true;
				this.PostFinanceCheckoutTransactionCompletionService.createTransactionCompletion(
					this.transactionData.transactions[0].metaData.salesChannelId,
					this.transactionData.transactions[0].id
				).then(() => {
					this.createNotificationSuccess({
						title: this.$tc('postfinancecheckout-order.captureAction.successTitle'),
						message: this.$tc('postfinancecheckout-order.captureAction.successMessage')
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
