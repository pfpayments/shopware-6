/* global Shopware */

import '../../component/postfinancecheckout-order-action-completion';
import '../../component/postfinancecheckout-order-action-refund';
import '../../component/postfinancecheckout-order-action-void';
import template from './index.html.twig';
import './index.scss';

const {Component, Mixin, Filter, Context, Utils} = Shopware;
const Criteria = Shopware.Data.Criteria;

Component.register('postfinancecheckout-order-detail', {
	template,

	inject: [
		'PostFinanceCheckoutTransactionService',
		'repositoryFactory'
	],

	mixins: [
		Mixin.getByName('notification')
	],

	data() {
		return {
			transactionData: {
				transactions: [],
				refunds: []
			},
			transaction: {},
			lineItems: [],
			currency: '',
			modalType: '',
			refundAmount: 0,
			refundableAmount: 0,
			isLoading: true,
			orderId: ''
		};
	},

	metaInfo() {
		return {
			title: this.$tc('postfinancecheckout-order.header')
		};
	},


	computed: {
		dateFilter() {
			return Filter.getByName('date');
		},

		relatedResourceColumns() {
			return [
				{
					property: 'paymentConnectorConfiguration.name',
					label: this.$tc('postfinancecheckout-order.transactionHistory.types.payment_method'),
					rawData: true
				},
				{
					property: 'state',
					label: this.$tc('postfinancecheckout-order.transactionHistory.types.state'),
					rawData: true
				},
				{
					property: 'currency',
					label: this.$tc('postfinancecheckout-order.transactionHistory.types.currency'),
					rawData: true
				},
				{
					property: 'authorized_amount',
					label: this.$tc('postfinancecheckout-order.transactionHistory.types.authorized_amount'),
					rawData: true
				},
				{
					property: 'id',
					label: this.$tc('postfinancecheckout-order.transactionHistory.types.transaction'),
					rawData: true
				},
				{
					property: 'customerId',
					label: this.$tc('postfinancecheckout-order.transactionHistory.types.customer'),
					rawData: true
				}
			];
		},

		lineItemColumns() {
			return [
				{
					property: 'uniqueId',
					label: this.$tc('postfinancecheckout-order.lineItem.types.uniqueId'),
					rawData: true,
					visible: false,
					primary: true
				},
				{
					property: 'name',
					label: this.$tc('postfinancecheckout-order.lineItem.types.name'),
					rawData: true
				},
				{
					property: 'quantity',
					label: this.$tc('postfinancecheckout-order.lineItem.types.quantity'),
					rawData: true
				},
				{
					property: 'amountIncludingTax',
					label: this.$tc('postfinancecheckout-order.lineItem.types.amountIncludingTax'),
					rawData: true
				},
				{
					property: 'type',
					label: this.$tc('postfinancecheckout-order.lineItem.types.type'),
					rawData: true
				},
				{
					property: 'taxAmount',
					label: this.$tc('postfinancecheckout-order.lineItem.types.taxAmount'),
					rawData: true
				}
			];
		},

		refundColumns() {
			return [
				{
					property: 'id',
					label: this.$tc('postfinancecheckout-order.refund.types.id'),
					rawData: true,
					visible: true,
					primary: true
				},
				{
					property: 'amount',
					label: this.$tc('postfinancecheckout-order.refund.types.amount'),
					rawData: true
				},
				{
					property: 'state',
					label: this.$tc('postfinancecheckout-order.refund.types.state'),
					rawData: true
				},
				{
					property: 'createdOn',
					label: this.$tc('postfinancecheckout-order.refund.types.createdOn'),
					rawData: true
				}
			];
		}
	},

	watch: {
		'$route'() {
			this.resetDataAttributes();
			this.createdComponent();
		}
	},

	created() {
		this.createdComponent();
	},

	methods: {
		createdComponent() {
			this.orderId = this.$route.params.id;
			const orderRepository = this.repositoryFactory.create('order');
			const orderCriteria = new Criteria(1, 1);
			orderCriteria.addAssociation('transactions');

			orderRepository.get(this.orderId, Context.api, orderCriteria).then((order) => {
				this.order = order;
				this.isLoading = false;
				const postfinancecheckoutTransactionId = order.transactions[0].customFields.postfinancecheckout_transaction_id;
				this.PostFinanceCheckoutTransactionService.getTransactionData(order.salesChannelId, postfinancecheckoutTransactionId)
					.then((PostFinanceCheckoutTransaction) => {
						this.currency = PostFinanceCheckoutTransaction.transactions[0].currency;
						PostFinanceCheckoutTransaction.transactions[0].authorized_amount = Utils.format.currency(
							PostFinanceCheckoutTransaction.transactions[0].authorizationAmount,
							this.currency
						);
						PostFinanceCheckoutTransaction.transactions[0].lineItems.forEach((lineItem) => {
							lineItem.amountIncludingTax = Utils.format.currency(
								lineItem.amountIncludingTax,
								this.currency
							);
							lineItem.taxAmount = Utils.format.currency(
								lineItem.taxAmount,
								this.currency
							);
						});
						PostFinanceCheckoutTransaction.refunds.forEach((refund) => {
							refund.amount = Utils.format.currency(
								refund.amount,
								this.currency
							);
						});
						this.lineItems = PostFinanceCheckoutTransaction.transactions[0].lineItems;
						this.transactionData = PostFinanceCheckoutTransaction;
						this.refundAmount = Number(this.transactionData.transactions[0].amountIncludingTax);
						this.refundableAmount = Number(this.transactionData.transactions[0].amountIncludingTax);
						this.transaction = this.transactionData.transactions[0];
					}).catch((errorResponse) => {
					try {
						this.createNotificationError({
							title: this.$tc('postfinancecheckout-order.paymentDetails.error.title'),
							message: errorResponse.message,
							autoClose: false
						});
					} catch (e) {
						this.createNotificationError({
							title: this.$tc('postfinancecheckout-order.paymentDetails.error.title'),
							message: errorResponse.message,
							autoClose: false
						});
					} finally {
						this.isLoading = false;
					}
				});
			});
		},
		downloadPackingSlip() {
			window.open(
				this.PostFinanceCheckoutTransactionService.getPackingSlip(
					Shopware.Context.api,
					this.transaction.metaData.salesChannelId,
					this.transaction.id
				),
				'_blank'
			);
		},

		downloadInvoice() {
			window.open(
				this.PostFinanceCheckoutTransactionService.getInvoiceDocument(
					Shopware.Context.api,
					this.transaction.metaData.salesChannelId,
					this.transaction.id
				),
				'_blank'
			);
		},

		resetDataAttributes() {
			this.transactionData = {
				transactions: [],
				refunds: []
			};
			this.lineItems = [];
			this.isLoading = true;
		},

		spawnModal(modalType) {
			this.modalType = modalType;
		},

		closeModal() {
			this.modalType = '';
		}
	}
});
