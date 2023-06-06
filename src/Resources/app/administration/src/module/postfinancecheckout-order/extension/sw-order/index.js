/* global Shopware */

import template from './sw-order.html.twig';
import './sw-order.scss';

const {Component, Context} = Shopware;
const Criteria = Shopware.Data.Criteria;

const postfinancecheckoutFormattedHandlerIdentifier = 'handler_postfinancecheckoutpayment_postfinancecheckoutpaymenthandler';

Component.override('sw-order-detail', {
	template,

	data() {
		return {
			isPostFinanceCheckoutPayment: false
		};
	},

	computed: {
		isEditable() {
			return !this.isPostFinanceCheckoutPayment || this.$route.name !== 'postfinancecheckout.order.detail';
		},
		showTabs() {
			return true;
		}
	},

	watch: {
		orderId: {
			deep: true,
			handler() {
				if (!this.orderId) {
					this.setIsPostFinanceCheckoutPayment(null);
					return;
				}

				const orderRepository = this.repositoryFactory.create('order');
				const orderCriteria = new Criteria(1, 1);
				orderCriteria.addAssociation('transactions');

				orderRepository.get(this.orderId, Context.api, orderCriteria).then((order) => {
					if (
						(order.amountTotal <= 0) ||
						(order.transactions.length <= 0) ||
						!order.transactions[0].paymentMethodId
					) {
						this.setIsPostFinanceCheckoutPayment(null);
						return;
					}

					const paymentMethodId = order.transactions[0].paymentMethodId;
					if (paymentMethodId !== undefined && paymentMethodId !== null) {
						this.setIsPostFinanceCheckoutPayment(paymentMethodId);
					}
				});
			},
			immediate: true
		}
	},

	methods: {
		setIsPostFinanceCheckoutPayment(paymentMethodId) {
			if (!paymentMethodId) {
				return;
			}
			const paymentMethodRepository = this.repositoryFactory.create('payment_method');
			paymentMethodRepository.get(paymentMethodId, Context.api).then(
				(paymentMethod) => {
					this.isPostFinanceCheckoutPayment = (paymentMethod.formattedHandlerIdentifier === postfinancecheckoutFormattedHandlerIdentifier);
				}
			);
		}
	}
});
