/* global Shopware */

import template from './sw-order.html.twig';
import './sw-order.scss';

const {Component, Context} = Shopware;
const Criteria = Shopware.Data.Criteria;

const weareplanetFormattedHandlerIdentifier = 'handler_weareplanetpayment_weareplanetpaymenthandler';

Component.override('sw-order-detail', {
	template,

	data() {
		return {
			isWeArePlanetPayment: false
		};
	},

	computed: {
		isEditable() {
			return !this.isWeArePlanetPayment || this.$route.name !== 'weareplanet.order.detail';
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
					this.setIsWeArePlanetPayment(null);
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
						this.setIsWeArePlanetPayment(null);
						return;
					}

					const paymentMethodId = order.transactions[0].paymentMethodId;
					if (paymentMethodId !== undefined && paymentMethodId !== null) {
						this.setIsWeArePlanetPayment(paymentMethodId);
					}
				});
			},
			immediate: true
		}
	},

	methods: {
		setIsWeArePlanetPayment(paymentMethodId) {
			if (!paymentMethodId) {
				return;
			}
			const paymentMethodRepository = this.repositoryFactory.create('payment_method');
			paymentMethodRepository.get(paymentMethodId, Context.api).then(
				(paymentMethod) => {
					this.isWeArePlanetPayment = (paymentMethod.formattedHandlerIdentifier === weareplanetFormattedHandlerIdentifier);
				}
			);
		}
	}
});
