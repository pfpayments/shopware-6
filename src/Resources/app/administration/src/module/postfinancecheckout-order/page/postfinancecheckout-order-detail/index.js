/* global Shopware */

import '../../component/postfinancecheckout-order-action-completion';
import '../../component/postfinancecheckout-order-action-refund';
import '../../component/postfinancecheckout-order-action-refund-by-amount';
import '../../component/postfinancecheckout-order-action-void';
import template from './index.html.twig';
import './index.scss';

const {Component, Mixin, Filter, Context, Utils} = Shopware;
const Criteria = Shopware.Data.Criteria;

Component.register('postfinancecheckout-order-detail', {
	template,

	inject: [
		'PostFinanceCheckoutTransactionService',
		'PostFinanceCheckoutRefundService',
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
			refundableQuantity: 0,
			isLoading: true,
			orderId: '',
			currency: '',
			modalType: '',
			refundAmount: 0,
			refundableAmount: 0,
			currentLineItem: '',
			refundLineItem: []
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
					property: 'paymentMethodName',
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
				},
				{
					property: 'refundableQuantity',
					rawData: true,
					visible: false,
				},
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
			orderCriteria.getAssociation('transactions').addSorting(Criteria.sort('createdAt', 'DESC'));

			orderRepository.get(this.orderId, Context.api, orderCriteria).then((order) => {
				this.order = order;
				this.isLoading = false;
				var totalAmountTemp = 0;
				var refundsAmountTemp = 0;
				const postfinancecheckoutTransactionId = order.transactions[0].customFields.postfinancecheckout_transaction_id;
				this.PostFinanceCheckoutTransactionService.getTransactionData(order.salesChannelId, postfinancecheckoutTransactionId)
					.then((PostFinanceCheckoutTransaction) => {
						this.currency = PostFinanceCheckoutTransaction.transactions[0].currency;

						PostFinanceCheckoutTransaction.transactions[0].authorized_amount = Utils.format.currency(
							PostFinanceCheckoutTransaction.transactions[0].authorizationAmount,
							this.currency
						);

						PostFinanceCheckoutTransaction.refunds.forEach((refund) => {
							refundsAmountTemp = parseFloat(parseFloat(refundsAmountTemp) + parseFloat(refund.amount));
							refund.amount = Utils.format.currency(
								refund.amount,
								this.currency
							);

							refund.reductions.forEach((reduction) => {
								if (this.refundLineItem[reduction.lineItemUniqueId] === undefined) {
									this.refundLineItem[reduction.lineItemUniqueId] = reduction.quantityReduction;
								} else {
									this.refundLineItem[reduction.lineItemUniqueId] += reduction.quantityReduction;
								}
							});
						});

						PostFinanceCheckoutTransaction.transactions[0].lineItems.forEach((lineItem) => {
							lineItem.amountIncludingTax = Utils.format.currency(
								lineItem.amountIncludingTax,
								this.currency
							);

							lineItem.taxAmount = Utils.format.currency(
								lineItem.taxAmount,
								this.currency
							);

							totalAmountTemp = parseFloat(lineItem.unitPriceIncludingTax * lineItem.quantity);

							lineItem.refundableQuantity = parseInt(
								parseInt(lineItem.quantity) - parseInt(this.refundLineItem[lineItem.uniqueId] || 0)
							);
						});

						this.lineItems = PostFinanceCheckoutTransaction.transactions[0].lineItems;
						this.transactionData = PostFinanceCheckoutTransaction;
						this.transaction = this.transactionData.transactions[0];
						this.refundAmount = Number(this.transactionData.transactions[0].amountIncludingTax);
						this.refundableAmount = parseFloat(parseFloat(totalAmountTemp) - parseFloat(refundsAmountTemp));
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
					this.transaction.metaData.salesChannelId,
					this.transaction.id
				),
				'_blank'
			);
		},

		downloadInvoice() {
			window.open(
				this.PostFinanceCheckoutTransactionService.getInvoiceDocument(
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
			this.refundLineItem = [];
			this.isLoading = true;
		},

		spawnModal(modalType, lineItemId, refundableQuantity) {
			this.modalType = modalType;
			this.currentLineItem = lineItemId;
			this.refundableQuantity = refundableQuantity;
		},

		closeModal() {
			this.modalType = '';
		},

		lineItemRefund(lineItemId) {
			this.isLoading = true;
			this.PostFinanceCheckoutRefundService.createRefund(
				this.transactionData.transactions[0].metaData.salesChannelId,
				this.transactionData.transactions[0].id,
				0,
				lineItemId
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
});
