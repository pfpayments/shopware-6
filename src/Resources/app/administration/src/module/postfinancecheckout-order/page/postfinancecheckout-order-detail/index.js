/* global Shopware */

import '../../component/postfinancecheckout-order-action-completion';
import '../../component/postfinancecheckout-order-action-refund';
import '../../component/postfinancecheckout-order-action-refund-partial';
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
			itemRefundableQuantity: 0,
			isLoading: true,
			orderId: '',
			currency: '',
			modalType: '',
			refundAmount: 0.00,
			refundableAmount: 0.00,
			itemRefundedAmount: 0.00,
			itemRefundedQuantity: 0,
			itemRefundableAmount: 0.00,
			currentLineItem: '',
			refundLineItemQuantity: [],
			refundLineItemAmount: [],
			selectedItems: []
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
			    // It must be set in order to have correctly working checkbox mechanism
				{
					property: 'id',
					rawData: true,
					visible: false,
					primary: true
				},
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
							    if (reduction.quantityReduction > 0) {
                                    if (this.refundLineItemQuantity[reduction.lineItemUniqueId] === undefined) {
                                        this.refundLineItemQuantity[reduction.lineItemUniqueId] = reduction.quantityReduction;
                                    } else {
                                        this.refundLineItemQuantity[reduction.lineItemUniqueId] += reduction.quantityReduction;
                                    }
							    }
                                if (reduction.unitPriceReduction > 0) {
                                    if (this.refundLineItemAmount[reduction.lineItemUniqueId] === undefined) {
                                        this.refundLineItemAmount[reduction.lineItemUniqueId] = reduction.unitPriceReduction;
                                    } else {
                                        this.refundLineItemAmount[reduction.lineItemUniqueId] += reduction.unitPriceReduction;
                                    }
                                }
							});

						});

						PostFinanceCheckoutTransaction.transactions[0].lineItems.forEach((lineItem) => {
							if (!lineItem.id) {
								lineItem.id = lineItem.uniqueId;
                            }

                            lineItem.itemRefundedAmount = parseFloat(this.refundLineItemAmount[lineItem.uniqueId] || 0) * parseInt(lineItem.quantity);
                            lineItem.amountIncludingTax = parseFloat(lineItem.amountIncludingTax) || 0;

                            lineItem.itemRefundedQuantity = parseInt(this.refundLineItemQuantity[lineItem.uniqueId]) || 0;
                            lineItem.refundableAmount = parseFloat(
                              (lineItem.amountIncludingTax - lineItem.itemRefundedAmount).toFixed(2)
                            );

							lineItem.amountIncludingTax = Utils.format.currency(
								lineItem.amountIncludingTax,
								this.currency
							);

							lineItem.taxAmount = Utils.format.currency(
								lineItem.taxAmount,
								this.currency
							);

							totalAmountTemp = parseFloat(parseFloat(totalAmountTemp) + parseFloat(lineItem.unitPriceIncludingTax * lineItem.quantity));

							lineItem.refundableQuantity = parseInt(
								parseInt(lineItem.quantity) - parseInt(this.refundLineItemQuantity[lineItem.uniqueId] || 0)
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
			this.refundLineItemQuantity = [];
			this.refundLineItemAmount = [];
			this.isLoading = true;
		},

		spawnModal(modalType, lineItemId, refundableQuantity, itemRefundableAmount) {
			this.modalType = modalType;
			this.currentLineItem = lineItemId;
			this.itemRefundableQuantity = refundableQuantity;
            this.itemRefundableAmount = !isNaN(itemRefundableAmount) ? Math.round(itemRefundableAmount * 100) / 100 : 0;
		},

		closeModal() {
			this.modalType = '';
		},

		lineItemRefund(lineItemId, itemQuantity) {
			this.isLoading = true;
			this.PostFinanceCheckoutRefundService.createRefund(
				this.transactionData.transactions[0].metaData.salesChannelId,
				this.transactionData.transactions[0].id,
				itemQuantity,
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
					var errorTitle = errorResponse?.response?.data?.errors?.[0]?.title ?? this.$tc('postfinancecheckout-order.refundAction.refundCreateError.errorTitle')
					var errorMessage;
					switch(errorResponse.response.data) {
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
						message: errorResponse.response.data,
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
		},
		isSelectable(item) {
			return item.refundableQuantity > 0 && item.refundableAmount > 0 && item.itemRefundedAmount == 0 && item.itemRefundedQuantity == 0;
		},
		onSelectionChanged(selection) {
			this.selectedItems = Object.values(selection);
		},
        onPerformBulkAction() {
            if (this.selectedItems.length) {
                // Set isLoading to true to show the loader
                this.isLoading = true;

                // Force the DOM to update before proceeding with the asynchronous operations
                this.$nextTick(() => {
                    const refundPromises = this.selectedItems.map((item) => {
                        return this.lineItemRefundBulk(item.uniqueId, item.quantity); // Simulated refund action with delay
                    });

                    // Wait for all refund promises to complete
                    Promise.all(refundPromises)
                        .then(() => {
                            // Once all promises are resolved, hide the loader and close the modal
                            this.isLoading = false;
                            this.$emit('modal-close');
                            this.$nextTick(() => {
                                this.$router.replace(`${this.$route.path}?hash=${Utils.createId()}`);
                            });
                        })
                        .catch((error) => {
							if (error?.response?.data === 'methodDoesNotSupportRefund') {
								this.isLoading = false;
								return;
							}
                            // Handle any errors during the refund process
                            this.createNotificationError({
                                title: 'Error',
                                message: 'Something went wrong with the refunds',
                                autoClose: false
                            });
                            this.isLoading = false; // Ensure the loader is hidden even on error
                        });
                });
            }
        },
        lineItemRefundBulk(lineItemId, itemQuantity) {
            return new Promise((resolve, reject) => {
                this.PostFinanceCheckoutRefundService.createRefund(
                    this.transactionData.transactions[0].metaData.salesChannelId,
                    this.transactionData.transactions[0].id,
                    itemQuantity,
                    lineItemId
                )
                .then(() => {
                    this.createNotificationSuccess({
                        title: this.$tc('postfinancecheckout-order.refundAction.successTitle'),
                        message: this.$tc('postfinancecheckout-order.refundAction.successMessage')
                    });
                    resolve();
                })
                .catch((errorResponse) => {
                    try {
                        var errorTitle = errorResponse?.response?.data?.errors?.[0]?.title ?? this.$tc('postfinancecheckout-order.refundAction.refundCreateError.errorTitle')
						var errorMessage;
						switch(errorResponse.response.data) {
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
                            message: errorResponse.response.data,
                            autoClose: false
                        });
                    } finally {
                        reject(errorResponse);
                    }
                });
            });
        },
	}
});
