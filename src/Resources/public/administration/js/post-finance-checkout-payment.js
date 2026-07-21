(()=>{var I=`{% block sw_order_detail_content_tabs_general %}
    {% parent %}

{# sw-tabs-item will dissappear. See: https://github.com/shopware/shopware/blob/trunk/UPGRADE-6.7.md#sw-tabs-is-removed #}
<sw-tabs-item v-if="isPostFinanceCheckoutPayment"
			  :route="{ name: 'postfinancecheckout.order.detail', params: { id: $route.params.id } }"
			  :title="$tc('postfinancecheckout-order.header')">
	{{ $tc('postfinancecheckout-order.header') }}
</sw-tabs-item>
{% endblock %}

{% block sw_order_detail_actions_slot_smart_bar_actions %}
<template v-if="isEditable">
	{% parent %}
</template>
{% endblock %}
`;var{Component:ie,Context:C}=Shopware,oe=Shopware.Data.Criteria,se="handler_postfinancecheckoutpayment_postfinancecheckoutpaymenthandler";ie.override("sw-order-detail",{template:I,data(){return{isPostFinanceCheckoutPayment:!1}},computed:{isEditable(){return!this.isPostFinanceCheckoutPayment||this.$route.name!=="postfinancecheckout.order.detail"},showTabs(){return!0}},watch:{orderId:{deep:!0,handler(){if(!this.orderId){this.setIsPostFinanceCheckoutPayment(null);return}let e=this.repositoryFactory.create("order"),t=new oe(1,1);t.addAssociation("transactions"),e.get(this.orderId,C.api,t).then(n=>{if(n.amountTotal<=0||n.transactions.length<=0||!n.transactions[0].paymentMethodId){this.setIsPostFinanceCheckoutPayment(null);return}let a=n.transactions[0].paymentMethodId;a!=null&&this.setIsPostFinanceCheckoutPayment(a)})},immediate:!0}},methods:{setIsPostFinanceCheckoutPayment(e){if(!e)return;this.repositoryFactory.create("payment_method").get(e,C.api).then(n=>{this.isPostFinanceCheckoutPayment=n.formattedHandlerIdentifier===se})}}});var y=`{% block postfinancecheckout_order_action_completion %}
<sw-modal variant="small"
		  :title="$tc(\`postfinancecheckout-order.modal.title.capture\`)"
		  @modal-close="$emit('modal-close')">

	{% block postfinancecheckout_order_action_completion_amount %}
		<mt-checkbox
				:label="$tc('postfinancecheckout-order.captureAction.button.text')"
				v-model:checked="isCompletion">
        </mt-checkbox>
	{% endblock %}

	{% block postfinancecheckout_order_action_completion_confirm_button %}
	<template #modal-footer>
		<mt-button variant="primary"
				   @click="completion">
			{{ $tc('postfinancecheckout-order.refundAction.confirmButton.text') }}
		</mt-button>
	</template>
	{% endblock %}

	<mt-loader v-if="isLoading"></mt-loader>
</sw-modal>
{% endblock %}
`;var{Component:ce,Mixin:le,Filter:de,Utils:E}=Shopware;ce.register("postfinancecheckout-order-action-completion",{template:y,inject:["PostFinanceCheckoutTransactionCompletionService"],mixins:[le.getByName("notification")],props:{transactionData:{type:Object,required:!0}},data(){return{isLoading:!0,isCompletion:!1}},computed:{dateFilter(){return de.getByName("date")}},created(){this.createdComponent()},methods:{createdComponent(){this.isLoading=!1},completion(){this.isCompletion&&(this.isLoading=!0,this.PostFinanceCheckoutTransactionCompletionService.createTransactionCompletion(this.transactionData.transactions[0].metaData.salesChannelId,this.transactionData.transactions[0].id).then(()=>{this.createNotificationSuccess({title:this.$tc("postfinancecheckout-order.captureAction.successTitle"),message:this.$tc("postfinancecheckout-order.captureAction.successMessage")}),this.isLoading=!1,this.$emit("modal-close"),this.$nextTick(()=>{this.$router.replace(`${this.$route.path}?hash=${E.createId()}`)})}).catch(e=>{try{this.createNotificationError({title:e.response.data.errors[0].title,message:e.response.data.errors[0].detail,autoClose:!1})}catch{this.createNotificationError({title:e.title,message:e.message,autoClose:!1})}finally{this.isLoading=!1,this.$emit("modal-close"),this.$nextTick(()=>{this.$router.replace(`${this.$route.path}?hash=${E.createId()}`)})}}))}}});var v=`{% block postfinancecheckout_order_action_refund %}
<sw-modal variant="small"
		  :title="$tc(\`postfinancecheckout-order.modal.title.refund\`)"
		  @modal-close="$emit('modal-close')">

	{% block postfinancecheckout_order_action_refund_amount %}

		<mt-number-field
			:max="this.$parent.$parent.itemRefundableQuantity"
			:min="0"
			 v-model="refundQuantity"
			number-type="int"
			 :label="$tc('postfinancecheckout-order.refund.refundQuantity.label')">
		</mt-number-field>

		<div>
			{{ $tc('postfinancecheckout-order.refundAction.maxAvailableItemsToRefund') }}:
			<b>{{ this.$parent.$parent.itemRefundableQuantity }}</b>
		</div>
	{% endblock %}

	{% block postfinancecheckout_order_action_refund_confirm_button %}
	<template #modal-footer>
		<mt-button variant="primary" @click="refund()">
			{{ $tc('postfinancecheckout-order.refundAction.confirmButton.text') }}
		</mt-button>
	</template>
	{% endblock %}

	<mt-loader v-if="isLoading"></mt-loader>
</sw-modal>
{% endblock %}
`;var{Component:pe,Mixin:he,Filter:me,Utils:S}=Shopware;pe.register("postfinancecheckout-order-action-refund",{template:v,inject:["PostFinanceCheckoutRefundService"],mixins:[he.getByName("notification")],props:{transactionData:{type:Object,required:!0},orderId:{type:String,required:!0}},data(){return{refundQuantity:0,isLoading:!0,currentLineItem:""}},computed:{dateFilter(){return me.getByName("date")}},created(){this.createdComponent()},methods:{createdComponent(){this.isLoading=!1,this.refundQuantity=1},refund(){this.isLoading=!0,this.PostFinanceCheckoutRefundService.createRefund(this.transactionData.transactions[0].metaData.salesChannelId,this.transactionData.transactions[0].id,this.refundQuantity,this.$parent.$parent.currentLineItem).then(()=>{this.createNotificationSuccess({title:this.$tc("postfinancecheckout-order.refundAction.successTitle"),message:this.$tc("postfinancecheckout-order.refundAction.successMessage")}),this.isLoading=!1,this.$emit("modal-close"),this.$nextTick(()=>{this.$router.replace(`${this.$route.path}?hash=${S.createId()}`)})}).catch(e=>{try{var t=e?.response?.data?.errors?.[0]?.title??this.$tc("postfinancecheckout-order.refundAction.refundCreateError.errorTitle"),n;switch(e.response.data){case"refundQuantityZero":n=this.$tc("postfinancecheckout-order.refundAction.refundCreateError.messageRefundQuantityIsZero");break;case"refundExceedsQuantity":n=this.$tc("postfinancecheckout-order.refundAction.refundCreateError.messageRefundQuantityExceedsAvailableBalance");break;case"methodDoesNotSupportRefund":n=this.$tc("postfinancecheckout-order.refundAction.refundCreateError.messagePaymentMethodDoesNotSupportRefund");break;default:n=e.response.data.errors[0].detail}this.createNotificationError({title:t,message:n,autoClose:!1})}catch{this.createNotificationError({title:e.title,message:e.message,autoClose:!1})}finally{this.isLoading=!1,this.$emit("modal-close"),this.$nextTick(()=>{this.$router.replace(`${this.$route.path}?hash=${S.createId()}`)})}})}}});var w=`{% block postfinancecheckout_order_action_refund_partial %}
<sw-modal variant="small"
		  :title="$tc(\`postfinancecheckout-order.modal.title.refund\`)"
		  @modal-close="$emit('modal-close')">

	{% block postfinancecheckout_order_action_refund_amount_partial %}
		<mt-number-field
		 :max="this.$parent.$parent.itemRefundableAmount"
		 :min="0.00"
		 v-model="refundAmount"
		 :label="$tc('postfinancecheckout-order.refund.refundAmount.label')"
		 :suffix="currency">
		</mt-number-field>

		<div>
			{{ $tc('postfinancecheckout-order.refundAction.maxAvailableAmountToRefund') }}:
			<b>{{ this.$parent.$parent.itemRefundableAmount }}</b>
		</div>
	{% endblock %}

	{% block postfinancecheckout_order_action_refund_confirm_button_partial %}
	<template #modal-footer>
		<mt-button variant="primary" @click="createPartialRefund(this.$parent.$parent.currentLineItem)">
			{{ $tc('postfinancecheckout-order.refundAction.confirmButton.text') }}
		</mt-button>
	</template>
	{% endblock %}

	<mt-loader v-if="isLoading"></mt-loader>
</sw-modal>
{% endblock %}
`;var{Component:ge,Mixin:ke,Filter:be,Utils:F}=Shopware;ge.register("postfinancecheckout-order-action-refund-partial",{template:w,inject:["PostFinanceCheckoutRefundService"],mixins:[ke.getByName("notification")],props:{transactionData:{type:Object,required:!0},orderId:{type:String,required:!0}},data(){return{isLoading:!0,currency:this.transactionData.transactions[0].currency,refundAmount:0}},computed:{dateFilter(){return be.getByName("date")}},created(){this.createdComponent()},methods:{createdComponent(){this.isLoading=!1,this.currency=this.transactionData.transactions[0].currency,this.refundAmount||(this.refundAmount=this.$parent.$parent.itemRefundableAmount)},createPartialRefund(e){this.isLoading=!0,this.PostFinanceCheckoutRefundService.createPartialRefund(this.transactionData.transactions[0].metaData.salesChannelId,this.transactionData.transactions[0].id,this.refundAmount,e).then(()=>{this.createNotificationSuccess({title:this.$tc("postfinancecheckout-order.refundAction.successTitle"),message:this.$tc("postfinancecheckout-order.refundAction.successMessage")}),this.isLoading=!1,this.$emit("modal-close"),this.$nextTick(()=>{this.$router.replace(`${this.$route.path}?hash=${F.createId()}`)})}).catch(t=>{try{var n=t?.response?.data?.errors?.[0]?.title??this.$tc("postfinancecheckout-order.refundAction.refundCreateError.errorTitle"),a;t.response.data==="methodDoesNotSupportRefund"?a=this.$tc("postfinancecheckout-order.refundAction.refundCreateError.messagePaymentMethodDoesNotSupportRefund"):a=t.response.data.errors[0].detail,this.createNotificationError({title:n,message:a,autoClose:!1})}catch{this.createNotificationError({title:t.title,message:t.message,autoClose:!1})}finally{this.isLoading=!1,this.$emit("modal-close"),this.$nextTick(()=>{this.$router.replace(`${this.$route.path}?hash=${F.createId()}`)})}})}},watch:{refundAmount(e){e!==null&&(this.refundAmount=Math.round(e*100)/100)}}});var A=`{% block postfinancecheckout_order_action_refund_by_amount %}
<sw-modal variant="small"
		  :title="$tc(\`postfinancecheckout-order.modal.title.refund\`)"
		  @modal-close="$emit('modal-close')">

	{% block postfinancecheckout_order_action_refund_amount_by_amount %}
		<mt-number-field
		 :max="refundableAmount"
		 :min="0"
		 v-model="refundAmount"
		 :label="$tc('postfinancecheckout-order.refund.refundAmount.label')"
		 :suffix="currency">
		</mt-number-field>
	{% endblock %}

	{% block postfinancecheckout_order_action_refund_confirm_button_by_amount %}
	<template #modal-footer>
		<mt-button variant="primary" @click="refundByAmount()">
			{{ $tc('postfinancecheckout-order.refundAction.confirmButton.text') }}
		</mt-button>
	</template>
	{% endblock %}

	<mt-loader v-if="isLoading"></mt-loader>
</sw-modal>
{% endblock %}
`;var{Component:Ie,Mixin:Ce,Filter:ye,Utils:T}=Shopware;Ie.register("postfinancecheckout-order-action-refund-by-amount",{template:A,inject:["PostFinanceCheckoutRefundService"],mixins:[Ce.getByName("notification")],props:{transactionData:{type:Object,required:!0},orderId:{type:String,required:!0}},data(){return{isLoading:!0,currency:this.transactionData.transactions[0].currency,refundAmount:0,refundableAmount:0}},computed:{dateFilter(){return ye.getByName("date")}},created(){this.createdComponent()},methods:{createdComponent(){this.isLoading=!1,this.currency=this.transactionData.transactions[0].currency,this.refundAmount=Number(this.transactionData.transactions[0].amountIncludingTax),this.refundableAmount=Number(this.transactionData.transactions[0].amountIncludingTax)},refundByAmount(){this.isLoading=!0,this.PostFinanceCheckoutRefundService.createRefundByAmount(this.transactionData.transactions[0].metaData.salesChannelId,this.transactionData.transactions[0].id,this.refundAmount).then(()=>{this.createNotificationSuccess({title:this.$tc("postfinancecheckout-order.refundAction.successTitle"),message:this.$tc("postfinancecheckout-order.refundAction.successMessage")}),this.isLoading=!1,this.$emit("modal-close"),this.$nextTick(()=>{this.$router.replace(`${this.$route.path}?hash=${T.createId()}`)})}).catch(e=>{try{var t=e?.response?.data?.errors?.[0]?.title??this.$tc("postfinancecheckout-order.refundAction.refundCreateError.errorTitle"),n;switch(e.response.data){case"refundAmountZero":n=this.$tc("postfinancecheckout-order.refundAction.refundCreateError.messageRefundAmountIsZero");break;case"refundExceedsAmount":n=this.$tc("postfinancecheckout-order.refundAction.refundCreateError.messageRefundAmountExceedsAvailableBalance");break;case"methodDoesNotSupportRefund":n=this.$tc("postfinancecheckout-order.refundAction.refundCreateError.messagePaymentMethodDoesNotSupportRefund");break;default:n=e.response.data.errors[0].detail}this.createNotificationError({title:t,message:n,autoClose:!1})}catch{this.createNotificationError({title:e.title,message:e.message,autoClose:!1})}finally{this.isLoading=!1,this.$emit("modal-close"),this.$nextTick(()=>{this.$router.replace(`${this.$route.path}?hash=${T.createId()}`)})}})}}});var P=`{% block postfinancecheckout_order_action_void %}
<sw-modal variant="small"
		  :title="$tc(\`postfinancecheckout-order.modal.title.void\`)"
		  @modal-close="$emit('modal-close')">

	{% block postfinancecheckout_order_action_void_amount %}
        {# Review if this v-model:checked="isVoid" needs to change to checked #}
		<mt-checkbox
				:label="$tc('postfinancecheckout-order.voidAction.confirm.message')"
				v-model:checked="isVoid">
        </mt-checkbox>
	{% endblock %}

	{% block postfinancecheckout_order_action_void_confirm_button %}
	<template #modal-footer>
		<mt-button variant="primary"
				   @click="voidPayment">
			{{ $tc('postfinancecheckout-order.refundAction.confirmButton.text') }}
		</mt-button>
	</template>
	{% endblock %}

	<mt-loader v-if="isLoading"></mt-loader>
</sw-modal>
{% endblock %}
`;var{Component:ve,Mixin:Se,Filter:we,Utils:N}=Shopware;ve.register("postfinancecheckout-order-action-void",{template:P,inject:["PostFinanceCheckoutTransactionVoidService"],mixins:[Se.getByName("notification")],props:{transactionData:{type:Object,required:!0}},data(){return{isLoading:!0,isVoid:!1}},computed:{dateFilter(){return we.getByName("date")},lineItemColumns(){return[{property:"uniqueId",label:this.$tc("postfinancecheckout-order.refund.types.uniqueId"),rawData:!1,allowResize:!0,primary:!0,width:"auto"},{property:"name",label:this.$tc("postfinancecheckout-order.refund.types.name"),rawData:!0,allowResize:!0,sortable:!0,width:"auto"},{property:"quantity",label:this.$tc("postfinancecheckout-order.refund.types.quantity"),rawData:!0,allowResize:!0,width:"auto"},{property:"amountIncludingTax",label:this.$tc("postfinancecheckout-order.refund.types.amountIncludingTax"),rawData:!0,allowResize:!0,inlineEdit:"string",width:"auto"},{property:"type",label:this.$tc("postfinancecheckout-order.refund.types.type"),rawData:!0,allowResize:!0,sortable:!0,width:"auto"},{property:"taxAmount",label:this.$tc("postfinancecheckout-order.refund.types.taxAmount"),rawData:!0,allowResize:!0,width:"auto"}]}},created(){this.createdComponent()},methods:{createdComponent(){this.isLoading=!1,this.currency=this.transactionData.transactions[0].currency,this.refundableAmount=this.transactionData.transactions[0].amountIncludingTax,this.refundAmount=this.transactionData.transactions[0].amountIncludingTax},voidPayment(){this.isVoid&&(this.isLoading=!0,this.PostFinanceCheckoutTransactionVoidService.createTransactionVoid(this.transactionData.transactions[0].metaData.salesChannelId,this.transactionData.transactions[0].id).then(()=>{this.createNotificationSuccess({title:this.$tc("postfinancecheckout-order.voidAction.successTitle"),message:this.$tc("postfinancecheckout-order.voidAction.successMessage")}),this.isLoading=!1,this.$emit("modal-close"),this.$nextTick(()=>{this.$router.replace(`${this.$route.path}?hash=${N.createId()}`)})}).catch(e=>{try{this.createNotificationError({title:e.response.data.errors[0].title,message:e.response.data.errors[0].detail,autoClose:!1})}catch{this.createNotificationError({title:e.title,message:e.message,autoClose:!1})}finally{this.isLoading=!1,this.$emit("modal-close"),this.$nextTick(()=>{this.$router.replace(`${this.$route.path}?hash=${N.createId()}`)})}}))}}});var D=`{% block postfinancecheckout_order_detail %}
<div class="postfinancecheckout-order-detail">
	<div v-if="!isLoading">
		<mt-card :title="$tc('postfinancecheckout-order.paymentDetails.cardTitle')">
			<template #grid>
				{% block postfinancecheckout_order_actions_section %}
				<mt-card-section secondary slim>
					{% block postfinancecheckout_order_transaction_refunds_action_button %}
						<mt-button
								variant="primary"
								size="small"
								:disabled="transaction.state != 'FULFILL' || refundableAmount <= 0"
								@click="spawnModal('refundByAmount')">
							{{ $tc('postfinancecheckout-order.buttons.label.refund') }}
						</mt-button>
					{% endblock %}
					{% block postfinancecheckout_order_transaction_completion_action_button %}
					<mt-button
							variant="primary"
							size="small"
							:disabled="transaction.state != 'AUTHORIZED' || isLoading"
							@click="spawnModal('completion')">
						{{ $tc('postfinancecheckout-order.buttons.label.completion') }}
					</mt-button>
					{% endblock %}
					{% block postfinancecheckout_order_transaction_void_action_button %}
					<mt-button
							variant="primary"
							size="small"
							:disabled="transaction.state != 'AUTHORIZED' || isLoading"
							@click="spawnModal('void')">
						{{ $tc('postfinancecheckout-order.buttons.label.void') }}
					</mt-button>
					{% endblock %}
					{% block postfinancecheckout_order_transaction_download_invoice_action_button %}
					<mt-button
							variant="primary"
							size="small"
							:disabled="transaction.state != 'FULFILL'"
							@click="downloadInvoice()">
						{{ $tc('postfinancecheckout-order.buttons.label.download-invoice') }}
					</mt-button>
					{% endblock %}
					{% block postfinancecheckout_order_transaction_download_packing_slip_action_button %}
					<mt-button
							variant="primary"
							size="small"
							:disabled="transaction.state != 'FULFILL'"
							@click="downloadPackingSlip()">
						{{ $tc('postfinancecheckout-order.buttons.label.download-packing-slip') }}
					</mt-button>
					{% endblock %}
				</mt-card-section>
				{% endblock %}
			</template>
		</mt-card>
		{% block postfinancecheckout_order_transaction_history_card %}
		<mt-card :title="$tc('postfinancecheckout-order.transactionHistory.cardTitle')">
			<template #grid>

				{% block postfinancecheckout_order_transaction_history_grid %}
				<sw-data-grid :dataSource="transactionData.transactions"
							  :columns="relatedResourceColumns"
							  :showActions="true"
							  :showSelection="false">

					<template #actions="{ item }">
						<sw-context-menu-item v-if="item.customerId">{{ $tc('postfinancecheckout-order.transactionHistory.customerId') }}: {{ item.customerId }}</sw-context-menu-item>
						<sw-context-menu-item v-if="item.customerName">{{ $tc('postfinancecheckout-order.transactionHistory.customerName') }}: {{ item.customerName }}</sw-context-menu-item>
						<sw-context-menu-item v-if="item.creditCardHolder">{{ $tc('postfinancecheckout-order.transactionHistory.creditCardHolder') }}: {{ item.creditCardHolder }}</sw-context-menu-item>
						<sw-context-menu-item v-if="item.paymentMethodName">{{ $tc('postfinancecheckout-order.transactionHistory.paymentMethod') }}: {{ item.paymentMethodName }}</sw-context-menu-item>
						<sw-context-menu-item v-if="item.brandName">{{ $tc('postfinancecheckout-order.transactionHistory.paymentMethodBrand') }}: {{ item.brandName }}</sw-context-menu-item>
						<sw-context-menu-item v-if="item.pseudoCardNumber">{{ $tc('postfinancecheckout-order.transactionHistory.PseudoCreditCardNumber') }}: {{ item.pseudoCardNumber }}</sw-context-menu-item>
						<sw-context-menu-item v-if="item.pseudoCardNumber && item.cardExpireMonth && item.cardExpireYear">{{ $tc('postfinancecheckout-order.transactionHistory.CardExpire') }}: {{ item.cardExpireMonth }} / {{ item.cardExpireYear }}</sw-context-menu-item>
						<sw-context-menu-item v-if="item.payId">PayID: {{ item.payId }}</sw-context-menu-item>
					</template>
				</sw-data-grid>
				{% endblock %}
			</template>

		</mt-card>
		{% endblock %}
		{% block postfinancecheckout_order_transaction_line_items_card %}
        <mt-card :title="$tc('postfinancecheckout-order.lineItem.cardTitle')">
            <template #grid>

                {% block postfinancecheckout_order_transaction_line_items_grid %}
                    <sw-data-grid
                            :dataSource="lineItems"
                            :columns="lineItemColumns"
                            :showActions="true"
                            :showSelection="true"
                            :local-mode="false"
                            :is-record-selectable="isSelectable"
                            @selection-change="onSelectionChanged"
                    >
                    {% block postfinancecheckout_order_transaction_line_items_grid_grid_actions %}
                        <template #actions="{ item }">
                            <sw-context-menu-item
                                    :disabled="transaction.state != 'FULFILL' || item.refundableQuantity != item.quantity || item.refundableAmount == 0 || item.itemRefundedAmount > 0 || item.itemRefundedQuantity > 0"
                                    @click="lineItemRefund(item.uniqueId, item.quantity)">
                                {{ $tc('postfinancecheckout-order.buttons.label.refund-whole-line-item') }}
                            </sw-context-menu-item>

                            <sw-context-menu-item
                                    :disabled="transaction.state != 'FULFILL' || item.refundableQuantity == 0 || item.refundableAmount == 0 || item.itemRefundedAmount > 0"
                                    @click="spawnModal('refund', item.uniqueId, item.refundableQuantity)">
                                {{ $tc('postfinancecheckout-order.buttons.label.refund-line-item-by-quantity') }}
                            </sw-context-menu-item>

                            <sw-context-menu-item
                                    :disabled="transaction.state != 'FULFILL' || item.refundableQuantity == 0 || item.refundableAmount == 0 || item.itemRefundedQuantity > 0"
                                    @click="spawnModal('partialRefund', item.uniqueId, item.refundableQuantity, item.refundableAmount)">
                                {{ $tc('postfinancecheckout-order.buttons.label.refund-line-item-parial') }}
                            </sw-context-menu-item>
                        </template>
                    {% endblock %}
                    {% block postfinancecheckout_order_transaction_line_items_grid_bulk_actions %}
                        <template #bulk>
                            <a
                                    class="link link-danger"
                                    role="link"
                                    tabindex="0"
                                    :disabled="selectedItems.length === 0"
                                    @click="onPerformBulkAction">
                                {{ $tc('postfinancecheckout-order.buttons.label.refund-line-item-selected') }}
                            </a>
                        </template>
                    {% endblock %}

                    </sw-data-grid>
                {% endblock %}
            </template>
        </mt-card>
		{% endblock %}
		{% block postfinancecheckout_order_transaction_refunds_card %}
		<mt-card :title="$tc('postfinancecheckout-order.refund.cardTitle')" v-if="transactionData.refunds.length > 0">
			<template #grid>

				{% block postfinancecheckout_order_transaction_refunds_grid %}
				<sw-data-grid
						:dataSource="transactionData.refunds"
						:columns="refundColumns"
						:showActions="false"
						:showSelection="false">
				</sw-data-grid>
				{% endblock %}
			</template>

		</mt-card>
		{% endblock %}
		{% block postfinancecheckout_order_actions_modal_refund_partial %}
			<postfinancecheckout-order-action-refund-partial
					v-if="modalType === 'partialRefund'"
					:orderId="orderId"
					:transactionData="transactionData"
					:lineItems="lineItems"
					@modal-close="closeModal">
			</postfinancecheckout-order-action-refund-partial>
		{% endblock %}
		{% block postfinancecheckout_order_actions_modal_refund %}
		<postfinancecheckout-order-action-refund
				v-if="modalType === 'refund'"
				:orderId="orderId"
				:transactionData="transactionData"
				:lineItems="lineItems"
				@modal-close="closeModal">
		</postfinancecheckout-order-action-refund>
		{% endblock %}
		{% block postfinancecheckout_order_actions_modal_refund_by_amount %}
			<postfinancecheckout-order-action-refund-by-amount
					v-if="modalType === 'refundByAmount'"
					:orderId="orderId"
					:transactionData="transactionData"
					:lineItems="lineItems"
					@modal-close="closeModal">
			</postfinancecheckout-order-action-refund-by-amount>
		{% endblock %}
		{% block postfinancecheckout_order_actions_modal_completion%}
		<postfinancecheckout-order-action-completion
				v-if="modalType === 'completion'"
				:orderId="orderId"
				:transactionData="transactionData"
				:lineItems="lineItems"
				@modal-close="closeModal">
		</postfinancecheckout-order-action-completion>
		{% endblock %}
		{% block postfinancecheckout_order_actions_modal_void %}
		<postfinancecheckout-order-action-void
				v-if="modalType === 'void'"
				:orderId="orderId"
				:transactionData="transactionData"
				:lineItems="lineItems"
				@modal-close="closeModal">
		</postfinancecheckout-order-action-void>
		{% endblock %}
	</div>
	<mt-loader v-if="isLoading"></mt-loader>
</div>
{% endblock %}
`;var{Component:Ae,Mixin:Te,Filter:Pe,Context:Ne,Utils:p}=Shopware,O=Shopware.Data.Criteria;Ae.register("postfinancecheckout-order-detail",{template:D,inject:["PostFinanceCheckoutTransactionService","PostFinanceCheckoutRefundService","repositoryFactory"],mixins:[Te.getByName("notification")],data(){return{transactionData:{transactions:[],refunds:[]},transaction:{},lineItems:[],refundableQuantity:0,itemRefundableQuantity:0,isLoading:!0,orderId:"",currency:"",modalType:"",refundAmount:0,refundableAmount:0,itemRefundedAmount:0,itemRefundedQuantity:0,itemRefundableAmount:0,currentLineItem:"",refundLineItemQuantity:[],refundLineItemAmount:[],selectedItems:[]}},metaInfo(){return{title:this.$tc("postfinancecheckout-order.header")}},computed:{dateFilter(){return Pe.getByName("date")},relatedResourceColumns(){return[{property:"paymentMethodName",label:this.$tc("postfinancecheckout-order.transactionHistory.types.payment_method"),rawData:!0},{property:"state",label:this.$tc("postfinancecheckout-order.transactionHistory.types.state"),rawData:!0},{property:"currency",label:this.$tc("postfinancecheckout-order.transactionHistory.types.currency"),rawData:!0},{property:"authorized_amount",label:this.$tc("postfinancecheckout-order.transactionHistory.types.authorized_amount"),rawData:!0},{property:"id",label:this.$tc("postfinancecheckout-order.transactionHistory.types.transaction"),rawData:!0},{property:"customerId",label:this.$tc("postfinancecheckout-order.transactionHistory.types.customer"),rawData:!0}]},lineItemColumns(){return[{property:"id",rawData:!0,visible:!1,primary:!0},{property:"uniqueId",label:this.$tc("postfinancecheckout-order.lineItem.types.uniqueId"),rawData:!0,visible:!1,primary:!0},{property:"name",label:this.$tc("postfinancecheckout-order.lineItem.types.name"),rawData:!0},{property:"quantity",label:this.$tc("postfinancecheckout-order.lineItem.types.quantity"),rawData:!0},{property:"amountIncludingTax",label:this.$tc("postfinancecheckout-order.lineItem.types.amountIncludingTax"),rawData:!0},{property:"type",label:this.$tc("postfinancecheckout-order.lineItem.types.type"),rawData:!0},{property:"taxAmount",label:this.$tc("postfinancecheckout-order.lineItem.types.taxAmount"),rawData:!0},{property:"refundableQuantity",rawData:!0,visible:!1}]},refundColumns(){return[{property:"id",label:this.$tc("postfinancecheckout-order.refund.types.id"),rawData:!0,visible:!0,primary:!0},{property:"amount",label:this.$tc("postfinancecheckout-order.refund.types.amount"),rawData:!0},{property:"state",label:this.$tc("postfinancecheckout-order.refund.types.state"),rawData:!0},{property:"createdOn",label:this.$tc("postfinancecheckout-order.refund.types.createdOn"),rawData:!0}]}},watch:{$route(){this.resetDataAttributes(),this.createdComponent()}},created(){this.createdComponent()},methods:{createdComponent(){this.orderId=this.$route.params.id;let e=this.repositoryFactory.create("order"),t=new O(1,1);t.addAssociation("transactions"),t.getAssociation("transactions").addSorting(O.sort("createdAt","DESC")),e.get(this.orderId,Ne.api,t).then(n=>{this.order=n,this.isLoading=!1;var a=0,i=0;let r=n.transactions[0].customFields.postfinancecheckout_transaction_id;this.PostFinanceCheckoutTransactionService.getTransactionData(n.salesChannelId,r).then(s=>{this.currency=s.transactions[0].currency,s.transactions[0].authorized_amount=p.format.currency(s.transactions[0].authorizationAmount,this.currency),s.refunds.forEach(o=>{i=parseFloat(parseFloat(i)+parseFloat(o.amount)),o.amount=p.format.currency(o.amount,this.currency),o.reductions.forEach(l=>{l.quantityReduction>0&&(this.refundLineItemQuantity[l.lineItemUniqueId]===void 0?this.refundLineItemQuantity[l.lineItemUniqueId]=l.quantityReduction:this.refundLineItemQuantity[l.lineItemUniqueId]+=l.quantityReduction),l.unitPriceReduction>0&&(this.refundLineItemAmount[l.lineItemUniqueId]===void 0?this.refundLineItemAmount[l.lineItemUniqueId]=l.unitPriceReduction:this.refundLineItemAmount[l.lineItemUniqueId]+=l.unitPriceReduction)})}),s.transactions[0].lineItems.forEach(o=>{o.id||(o.id=o.uniqueId),o.itemRefundedAmount=parseFloat(this.refundLineItemAmount[o.uniqueId]||0)*parseInt(o.quantity),o.amountIncludingTax=parseFloat(o.amountIncludingTax)||0,o.itemRefundedQuantity=parseInt(this.refundLineItemQuantity[o.uniqueId])||0,o.refundableAmount=parseFloat((o.amountIncludingTax-o.itemRefundedAmount).toFixed(2)),o.amountIncludingTax=p.format.currency(o.amountIncludingTax,this.currency),o.taxAmount=p.format.currency(o.taxAmount,this.currency),a=parseFloat(parseFloat(a)+parseFloat(o.unitPriceIncludingTax*o.quantity)),o.refundableQuantity=parseInt(parseInt(o.quantity)-parseInt(this.refundLineItemQuantity[o.uniqueId]||0))}),this.lineItems=s.transactions[0].lineItems,this.transactionData=s,this.transaction=this.transactionData.transactions[0],this.refundAmount=Number(this.transactionData.transactions[0].amountIncludingTax),this.refundableAmount=parseFloat(parseFloat(a)-parseFloat(i))}).catch(s=>{try{this.createNotificationError({title:this.$tc("postfinancecheckout-order.paymentDetails.error.title"),message:s.message,autoClose:!1})}catch{this.createNotificationError({title:this.$tc("postfinancecheckout-order.paymentDetails.error.title"),message:s.message,autoClose:!1})}finally{this.isLoading=!1}})})},downloadPackingSlip(){window.open(this.PostFinanceCheckoutTransactionService.getPackingSlip(this.transaction.metaData.salesChannelId,this.transaction.id),"_blank")},downloadInvoice(){window.open(this.PostFinanceCheckoutTransactionService.getInvoiceDocument(this.transaction.metaData.salesChannelId,this.transaction.id),"_blank")},resetDataAttributes(){this.transactionData={transactions:[],refunds:[]},this.lineItems=[],this.refundLineItemQuantity=[],this.refundLineItemAmount=[],this.isLoading=!0},spawnModal(e,t,n,a){this.modalType=e,this.currentLineItem=t,this.itemRefundableQuantity=n,this.itemRefundableAmount=isNaN(a)?0:Math.round(a*100)/100},closeModal(){this.modalType=""},lineItemRefund(e,t){this.isLoading=!0,this.PostFinanceCheckoutRefundService.createRefund(this.transactionData.transactions[0].metaData.salesChannelId,this.transactionData.transactions[0].id,t,e).then(()=>{this.createNotificationSuccess({title:this.$tc("postfinancecheckout-order.refundAction.successTitle"),message:this.$tc("postfinancecheckout-order.refundAction.successMessage")}),this.isLoading=!1,this.$emit("modal-close"),this.$nextTick(()=>{this.$router.replace(`${this.$route.path}?hash=${p.createId()}`)})}).catch(n=>{try{var a=n?.response?.data?.errors?.[0]?.title??this.$tc("postfinancecheckout-order.refundAction.refundCreateError.errorTitle"),i;n.response.data==="methodDoesNotSupportRefund"?i=this.$tc("postfinancecheckout-order.refundAction.refundCreateError.messagePaymentMethodDoesNotSupportRefund"):i=n.response.data.errors[0].detail,this.createNotificationError({title:a,message:i,autoClose:!1})}catch{this.createNotificationError({title:n.title,message:n.response.data,autoClose:!1})}finally{this.isLoading=!1,this.$emit("modal-close"),this.$nextTick(()=>{this.$router.replace(`${this.$route.path}?hash=${p.createId()}`)})}})},isSelectable(e){return e.refundableQuantity>0&&e.refundableAmount>0&&e.itemRefundedAmount==0&&e.itemRefundedQuantity==0},onSelectionChanged(e){this.selectedItems=Object.values(e)},onPerformBulkAction(){this.selectedItems.length&&(this.isLoading=!0,this.$nextTick(()=>{let e=this.selectedItems.map(t=>this.lineItemRefundBulk(t.uniqueId,t.quantity));Promise.all(e).then(()=>{this.isLoading=!1,this.$emit("modal-close"),this.$nextTick(()=>{this.$router.replace(`${this.$route.path}?hash=${p.createId()}`)})}).catch(t=>{if(t?.response?.data==="methodDoesNotSupportRefund"){this.isLoading=!1;return}this.createNotificationError({title:"Error",message:"Something went wrong with the refunds",autoClose:!1}),this.isLoading=!1})}))},lineItemRefundBulk(e,t){return new Promise((n,a)=>{this.PostFinanceCheckoutRefundService.createRefund(this.transactionData.transactions[0].metaData.salesChannelId,this.transactionData.transactions[0].id,t,e).then(()=>{this.createNotificationSuccess({title:this.$tc("postfinancecheckout-order.refundAction.successTitle"),message:this.$tc("postfinancecheckout-order.refundAction.successMessage")}),n()}).catch(i=>{try{var r=i?.response?.data?.errors?.[0]?.title??this.$tc("postfinancecheckout-order.refundAction.refundCreateError.errorTitle"),s;i.response.data==="methodDoesNotSupportRefund"?s=this.$tc("postfinancecheckout-order.refundAction.refundCreateError.messagePaymentMethodDoesNotSupportRefund"):s=i.response.data.errors[0].detail,this.createNotificationError({title:r,message:s,autoClose:!1})}catch{this.createNotificationError({title:i.title,message:i.response.data,autoClose:!1})}finally{a(i)}})})}}});var x={"postfinancecheckout-order":{buttons:{label:{completion:"Abschluss","download-invoice":"Rechnung herunterladen","download-packing-slip":"Packzettel herunterladen",refund:"Eine neue R\xFCckerstattung erstellen",void:"Genehmigung annullieren","refund-whole-line-item":"Gesamte Werbebuchung erstatten","refund-line-item-by-quantity":"R\xFCckerstattung nach Menge","refund-line-item-selected":"R\xFCckerstattung ausw\xE4hlen","refund-line-item-parial":"Teilweise R\xFCckerstattung"}},captureAction:{button:{text:"Zahlung erfassen"},currentAmount:"Betrag",isFinal:"Dies ist die endg\xFCltige Verbuchung",maxAmount:"Maximaler Betrag",successMessage:"Ihre Verbuchung war erfolgreich",successTitle:"Erfolg"},general:{title:"Bestellungen"},header:"PostFinanceCheckout Payment",lineItem:{cardTitle:"Einzelposten",types:{amountIncludingTax:"Betrag",name:"Name",quantity:"Anzahl",taxAmount:"Steuern",type:"Typ",uniqueId:"Eindeutige ID"}},modal:{title:{capture:"Erfassen",refund:"Neue Gutschrift",void:"Autorisierung aufheben"}},paymentDetails:{cardTitle:"Zahlung",error:{title:"Fehler beim Abrufen von Zahlungsdetails von PostFinanceCheckout"}},refund:{cardTitle:"Gutschriften",refundAmount:{label:"Gutschriftsbetrag"},refundQuantity:{label:"Refund Menge"},types:{amount:"Betrag",createdOn:"Erstellt am",id:"ID",state:"Staat"}},refundAction:{confirmButton:{text:"Ausf\xFChren"},refundAmount:{label:"Betrag",placeholder:"Einen Betrag eingeben"},successMessage:"Ihre R\xFCckerstattung war erfolgreich",successTitle:"Erfolg",maxAvailableItemsToRefund:"Maximal Verf\xFCgbare Artikel zum Erstatten",maxAvailableAmountToRefund:"Maximal verf\xFCgbarer Erstattungsbetrag",refundCreateError:{errorTitle:"Fehler beim Erstellen der R\xFCckerstattung.",messageRefundAmountExceedsAvailableBalance:"Der R\xFCckerstattungsbetrag \xFCbersteigt das verf\xFCgbare Guthaben.",messageRefundAmountIsZero:"Der R\xFCckerstattungsbetrag muss gr\xF6\xDFer als 0 sein.",messageRefundQuantityExceedsAvailableBalance:"R\xFCckerstattung nach Menge \xFCberschreitet die maximal verf\xFCgbare Anzahl an Artikeln zur R\xFCckerstattung.",messageRefundQuantityIsZero:"R\xFCckerstattung nach Menge muss gr\xF6\xDFer als 0 sein.",messagePaymentMethodDoesNotSupportRefund:"Die Zahlungsmethode unterst\xFCtzt keine Online-R\xFCckerstattungen."}},transactionHistory:{cardTitle:"Einzelheiten",types:{authorized_amount:"Autorisierter Betrag",currency:"W\xE4hrung",customer:"Kunde",payment_method:"Zahlungsweise",state:"Staat",transaction:"Transaktion"},customerId:"Customer ID",customerName:"Customer Name",creditCardHolder:"Kreditkarteninhaber",paymentMethod:"Zahlungsart",paymentMethodBrand:"Marke der Zahlungsmethode",PseudoCreditCardNumber:"Pseudo-Kreditkartennummer",CardExpire:"Karte verf\xE4llt"},voidAction:{confirm:{button:{cancel:"Nein",confirm:"Autorisierung aufheben"},message:"Wollen Sie diese Zahlung wirklich stornieren?"},successMessage:"Die Zahlung wurde erfolgreich annulliert",successTitle:"Erfolg"}}};var $={"postfinancecheckout-order":{buttons:{label:{completion:"Complete","download-invoice":"Download Invoice","download-packing-slip":"Download Packing Slip",refund:"Create a new refund",void:"Cancel authorization","refund-whole-line-item":"Refund whole line item","refund-line-item-by-quantity":"Refund by quantity","refund-line-item-selected":"Refund selected","refund-line-item-parial":"Partial refund"}},captureAction:{button:{text:"Capture payment"},currentAmount:"Amount",isFinal:"This is final capture",maxAmount:"Maximum amount",successMessage:"Your capture was successful.",successTitle:"Success"},general:{title:"Orders"},header:"PostFinanceCheckout Payment",lineItem:{cardTitle:"Line Items",types:{amountIncludingTax:"Amount",name:"Name",quantity:"Quantity",taxAmount:"Taxes",type:"Type",uniqueId:"Unique ID"}},modal:{title:{capture:"Capture",refund:"New refund",void:"Cancel authorization"}},paymentDetails:{cardTitle:"Payment",error:{title:"Error fetching payment details from PostFinanceCheckout"}},refund:{cardTitle:"Refunds",refundAmount:{label:"Refund Amount"},refundQuantity:{label:"Refund Quantity"},types:{amount:"Amount",createdOn:"Created On",id:"ID",state:"State"}},refundAction:{confirmButton:{text:"Execute"},refundAmount:{label:"Amount",placeholder:"Enter a amount"},successMessage:"Your refund was successful.",successTitle:"Success",maxAvailableItemsToRefund:"Maximum available items to refund",maxAvailableAmountToRefund:"Maximum available amount to refund",refundCreateError:{errorTitle:"Error while creating the refund.",messageRefundAmountExceedsAvailableBalance:"Refund amount exceeds available balance.",messageRefundAmountIsZero:"Refund amount must be greater than 0.",messageRefundQuantityExceedsAvailableBalance:"Refund by quantity exceeds maximum available items to refund.",messageRefundQuantityIsZero:"Refund by quantity must be greater than 0.",messagePaymentMethodDoesNotSupportRefund:"Payment method does not support online refunds."}},transactionHistory:{cardTitle:"Details",types:{authorized_amount:"Authorized Amount",currency:"Currency",customer:"Customer",payment_method:"Payment Method",state:"State",transaction:"Transaction"},customerId:"Customer ID",customerName:"Customer Name",creditCardHolder:"Credit Card Holder",paymentMethod:"Payment Method",paymentMethodBrand:"Payment Method Brand",PseudoCreditCardNumber:"Pseudo Credit Card Number",CardExpire:"Card Expire"},voidAction:{confirm:{button:{cancel:"No",confirm:"Cancel authorization"},message:"Do you really want to cancel this payment?"},successMessage:"The payment was successfully voided.",successTitle:"Success"}}};var R={"postfinancecheckout-order":{buttons:{label:{completion:"Termin\xE9e","download-invoice":"T\xE9l\xE9charger la facture","download-packing-slip":"T\xE9l\xE9charger le bordereau d'exp\xE9dition",refund:"Cr\xE9er un nouveau remboursement",void:"Annulez l'autorisation","refund-whole-line-item":"Remboursement de la ligne enti\xE8re","refund-line-item-by-quantity":"Remboursement par quantit\xE9","refund-line-item-selected":"Rembourser s\xE9lectionn\xE9s","refund-line-item-parial":"Remboursement partiel"}},captureAction:{button:{text:"Capture du paiement"},currentAmount:"Montant",isFinal:"C'est la capture finale",maxAmount:"Montant maximal",successMessage:"Votre capture a \xE9t\xE9 r\xE9ussie.",successTitle:"Succ\xE8s"},general:{title:"Commandes"},header:"PostFinanceCheckout Paiement",lineItem:{cardTitle:"Articles de ligne",types:{amountIncludingTax:"Montant",name:"Nom",quantity:"Quantit\xE9",taxAmount:"Taxes",type:"Type",uniqueId:"ID unique"}},modal:{title:{capture:"Capture",refund:"Nouveau remboursement",void:"Annulez l'autorisation"}},paymentDetails:{cardTitle:"Paiement",error:{title:"Erreur dans la r\xE9cup\xE9ration des d\xE9tails du paiement \xE0 partir de PostFinanceCheckout"}},refund:{cardTitle:"Remboursements",refundAmount:{label:"Montant du remboursement"},refundQuantity:{label:"Quantit\xE9 \xE0 rembourser"},types:{amount:"Montant",createdOn:"Cr\xE9\xE9 le",id:"ID",state:"\xC9tat"}},refundAction:{confirmButton:{text:"Ex\xE9cutez"},refundAmount:{label:"Montant",placeholder:"Entrez un montant"},successMessage:"Votre remboursement a \xE9t\xE9 effectu\xE9 avec succ\xE8s.",successTitle:"Succ\xE8s",maxAvailableItemsToRefund:"Nombre maximum d'articles disponibles pour le remboursement",maxAvailableAmountToRefund:"Montant maximal disponible pour le remboursement",refundCreateError:{errorTitle:"Erreur lors de la cr\xE9ation du remboursement.",messageRefundAmountExceedsAvailableBalance:"Le montant du remboursement d\xE9passe le solde disponible.",messageRefundAmountIsZero:"Le montant du remboursement doit \xEAtre sup\xE9rieur \xE0 0.",messageRefundQuantityExceedsAvailableBalance:"Le remboursement par quantit\xE9 d\xE9passe le nombre maximal d\u2019articles remboursables.",messageRefundQuantityIsZero:"Le remboursement par quantit\xE9 doit \xEAtre sup\xE9rieur \xE0 0.",messagePaymentMethodDoesNotSupportRefund:"Le mode de paiement ne prend pas en charge les remboursements en ligne."}},transactionHistory:{cardTitle:"D\xE9tails",types:{authorized_amount:"Montant autoris\xE9",currency:"Monnaie",customer:"Client",payment_method:"Mode de paiement",state:"\xC9tat",transaction:"Transaction"},customerId:"Customer ID",customerName:"Customer Name",creditCardHolder:"Titulaire de la carte de cr\xE9dit",paymentMethod:"Mode de paiement",paymentMethodBrand:"Marque du mode de paiement",PseudoCreditCardNumber:"Pseudo num\xE9ro de carte de cr\xE9dit",CardExpire:"La carte expire"},voidAction:{confirm:{button:{cancel:"Non",confirm:"Annulez l'autorisation"},message:"Voulez-vous vraiment annuler ce paiement?"},successMessage:"Le paiement a \xE9t\xE9 annul\xE9 avec succ\xE8s.",successTitle:"Succ\xE8s"}}};var L={"postfinancecheckout-order":{buttons:{label:{completion:"Completato","download-invoice":"Scarica fattura","download-packing-slip":"Scarica distinta di imballaggio",refund:"Crea un nuovo rimborso",void:"Annulla autorizzazione","refund-whole-line-item":"Rimborso intera riga","refund-line-item-by-quantity":"Rimborso per quantit\xE0","refund-line-item-selected":"Rimborso selezionati","refund-line-item-parial":"Rimborso parziale"}},captureAction:{button:{text:"Cattura pagamento"},currentAmount:"Importo",isFinal:"Questa \xE8 la cattura finale",maxAmount:"Importo massimo",successMessage:"La tua cattura ha avuto successo.",successTitle:"Successo"},general:{title:"Ordini"},header:"Pagamento PostFinanceCheckout",lineItem:{cardTitle:"Articoli di linea",types:{amountIncludingTax:"Importo",name:"Nome",quantity:"Quantit\xE0",taxAmount:"Tasse",type:"Tipo",uniqueId:"ID unico"}},modal:{title:{capture:"Cattura",refund:"Nuovo rimborso",void:"Annulla autorizzazione"}},paymentDetails:{cardTitle:"Pagamento",error:{title:"Errore nel recupero dei dettagli del pagamento da PostFinanceCheckout"}},refund:{cardTitle:"Rimborsi",refundAmount:{label:"Importo del rimborso"},refundQuantity:{label:"Quantit\xE0 di rimborso"},types:{amount:"Importo",createdOn:"Creato il",id:"ID",state:"Stato"}},refundAction:{confirmButton:{text:"Esegui"},refundAmount:{label:"Importo",placeholder:"Inserisci un importo"},successMessage:"Il tuo rimborso \xE8 andato a buon fine.",successTitle:"Successo",maxAvailableItemsToRefund:"Numero massimo di articoli disponibili da rimborsare",maxAvailableAmountToRefund:"Importo massimo disponibile per il rimborso",refundCreateError:{errorTitle:"Errore durante la creazione del rimborso.",messageRefundAmountExceedsAvailableBalance:"LL'importo del rimborso supera il saldo disponibile.",messageRefundAmountIsZero:"L'importo del rimborso deve essere superiore a 0.",messageRefundQuantityExceedsAvailableBalance:"Il rimborso per quantit\xE0 supera il numero massimo di articoli rimborsabili.",messageRefundQuantityIsZero:"Il rimborso per quantit\xE0 deve essere maggiore di 0.",messagePaymentMethodDoesNotSupportRefund:"Il metodo di pagamento non supporta i rimborsi online."}},transactionHistory:{cardTitle:"Dettagli",types:{authorized_amount:"Importo autorizzato",currency:"Valuta",customer:"Cliente",payment_method:"Metodo di pagamento",state:"Stato",transaction:"Transazione"},customerId:"Customer ID",customerName:"Customer Name",creditCardHolder:"Proprietario della carta di credito",paymentMethod:"Metodo di pagamento",paymentMethodBrand:"Metodo di pagamento Marca",PseudoCreditCardNumber:"Numero di carta di credito pseudo",CardExpire:"La carta scade"},voidAction:{confirm:{button:{cancel:"No",confirm:"Annulla autorizzazione"},message:"Vuoi davvero annullare questo pagamento?"},successMessage:"Il pagamento \xE8 stato annullato con successo.",successTitle:"Successo"}}};var{Module:Re}=Shopware;Re.register("postfinancecheckout-order",{type:"plugin",name:"PostFinanceCheckout",title:"postfinancecheckout-order.general.title",description:"postfinancecheckout-order.general.descriptionTextModule",version:"1.0.1",targetVersion:"1.0.1",color:"#2b52ff",snippets:{"de-DE":x,"en-GB":$,"fr-FR":R,"it-IT":L},routeMiddleware(e,t){t.name==="sw.order.detail"&&t.children.push({component:"postfinancecheckout-order-detail",name:"postfinancecheckout.order.detail",isChildren:!0,path:"/sw/order/postfinancecheckout/detail/:id"}),e(t)}});Shopware.Service("privileges").addPrivilegeMappingEntry({category:"permissions",parent:"postfinancecheckout",key:"postfinancecheckout",roles:{viewer:{privileges:["sales_channel:read","sales_channel_payment_method:read","system_config:read"],dependencies:[]},editor:{privileges:["sales_channel:update","sales_channel_payment_method:create","sales_channel_payment_method:update","system_config:update","system_config:create","system_config:delete"],dependencies:["postfinancecheckout.viewer"]}}});Shopware.Service("privileges").addPrivilegeMappingEntry({category:"permissions",parent:null,key:"sales_channel",roles:{viewer:{privileges:["sales_channel_payment_method:read"]},editor:{privileges:["payment_method:update"]},creator:{privileges:["payment_method:create","shipping_method:create","delivery_time:create"]},deleter:{privileges:["payment_method:delete"]}}});var M=`{% block postfinancecheckout_settings %}
    <sw-page class="postfinancecheckout-settings">

        {% block postfinancecheckout_settings_header %}
            <template #smart-bar-header>
                <h2>
                    {{ $tc('sw-settings.index.title') }}
                    <mt-icon name="small-arrow-medium-right" size="16px"></mt-icon>
                    {{ $tc('postfinancecheckout-settings.header') }}
                </h2>
            </template>
        {% endblock %}

        {% block postfinancecheckout_settings_actions %}
            <template #smart-bar-actions>
                {% block postfinancecheckout_settings_actions_save %}
                    <mt-button
                            v-model:value="isSaveSuccessful"
                            class="sw-settings-login-registration__save-action"
                            variant="primary"
                            :isLoading="isLoading"
                            :disabled="isLoading"
                            @click="onSave">
                        {{ $tc('postfinancecheckout-settings.settingForm.save') }}
                    </mt-button>
                {% endblock %}
            </template>
        {% endblock %}

        {% block postfinancecheckout_settings_content %}
            <template #content>

                {% block postfinancecheckout_settings_content_card %}
                    <mt-card-view>

                        {% block postfinancecheckout_settings_content_card_channel_config %}
                            <sw-sales-channel-config v-model:value="config"
                                                        v-model:selectedSalesChannelId="selectedSalesChannelId"
                                                        ref="configComponent"
                                                        :domain="CONFIG_DOMAIN">

                                {% block postfinancecheckout_settings_content_card_channel_config_sales_channel %}
                                    <template #select="{ onInput, selectedSalesChannelId, salesChannel }">

                                        {% block postfinancecheckout_settings_content_card_channel_config_sales_channel_card %}
                                            <mt-card title="Sales Channel Switch">

                                                {% block postfinancecheckout_settings_content_card_channel_config_sales_channel_card_title %}
                                                <sw-sales-channel-switch
                                                                ref="channelSwitch"
                                                                @change-sales-channel-id="onSalesChannelSwitchChange($event, onInput)">
                                                </sw-sales-channel-switch>
                                                {% endblock %}
                                                {% block postfinancecheckout_settings_content_card_channel_config_sales_channel_card_footer %}
                                                    <template #footer>

                                                        {% block postfinancecheckout_settings_content_card_channel_config_sales_channel_card_footer_container %}
                                                            <sw-container columns="2fr 1fr" gap="0px 30px">

                                                                {% block postfinancecheckout_settings_content_card_channel_config_sales_channel_card_footer_container_text %}
                                                                    <p>{{ $tc('postfinancecheckout-settings.salesChannelCard.button.description') }}</p>
                                                                {% endblock %}

                                                                {% block postfinancecheckout_settings_content_card_channel_config_sales_channel_card_footer_container_button %}
                                                                    <sw-button
                                                                            variant="primary"
                                                                            v-model:value="isSetDefaultPaymentSuccessful"
                                                                            :isLoading="isSettingDefaultPaymentMethods"
                                                                            @click="onSetPaymentMethodDefault">
                                                                        {{ $tc('postfinancecheckout-settings.salesChannelCard.button.label') }}
                                                                    </sw-button>
                                                                {% endblock %}
                                                            </sw-container>
                                                        {% endblock %}
                                                    </template>
                                                {% endblock %}
                                            </mt-card>
                                        {% endblock %}
                                    </template>
                                {% endblock %}

                                {% block postfinancecheckout_settings_content_card_channel_config_cards %}
                                    <template #content="{ actualConfigData, allConfigs, selectedSalesChannelId }">
                                        <div v-if="actualConfigData">

                                            <sw-postfinancecheckout-credentials
                                                    :actualConfigData="actualConfigData"
                                                    :allConfigs="allConfigs"
                                                    :selectedSalesChannelId="selectedSalesChannelId"
                                                    :spaceIdErrorState="spaceIdErrorState"
                                                    :userIdErrorState="userIdErrorState"
                                                    :applicationKeyErrorState="applicationKeyErrorState"
                                                    :spaceIdFilled="spaceIdFilled"
                                                    :userIdFilled="userIdFilled"
                                                    :applicationKeyFilled="applicationKeyFilled"
                                                    :isLoading="isLoading"
                                                    :isTesting="isTesting"
                                                    @check-api-connection-event="onCheckApiConnection"
                                            ></sw-postfinancecheckout-credentials>

                                            <sw-postfinancecheckout-options
                                                    :actualConfigData="actualConfigData"
                                                    :allConfigs="allConfigs"
                                                    :isLoading="isLoading"
                                                    :selectedSalesChannelId="selectedSalesChannelId"
                                            >
                                            </sw-postfinancecheckout-options>

                                            <sw-postfinancecheckout-storefront-options
                                                    :actualConfigData="actualConfigData"
                                                    :allConfigs="allConfigs"
                                                    :isLoading="isLoading"
                                                    :selectedSalesChannelId="selectedSalesChannelId"
                                            >
                                            </sw-postfinancecheckout-storefront-options>

                                            <sw-postfinancecheckout-advanced-options
                                                    :actualConfigData="actualConfigData"
                                                    :allConfigs="allConfigs"
                                                    :isLoading="isLoading"
                                                    :selectedSalesChannelId="selectedSalesChannelId"
                                            >
                                            </sw-postfinancecheckout-advanced-options>


                                        </div>
                                    </template>
                                {% endblock %}

                            </sw-sales-channel-config>
                        {% endblock %}

                        {% block postfinancecheckout_settings_content_card_loading %}
                            <mt-loader v-if="isLoading"></mt-loader>
                        {% endblock %}
                    </mt-card-view>
                {% endblock %}

            </template>
        {% endblock %}
    </sw-page>
{% endblock %}
`;var d="PostFinanceCheckoutPayment.config",Me=d+".applicationKey",Be=d+".emailEnabled",Ge=d+".integration",qe=d+".lineItemConsistencyEnabled",ze=d+".spaceId",Ve=d+".spaceViewId",Ue=d+".storefrontInvoiceDownloadEnabled",He=d+".userId",Ke=d+".storefrontWebhooksUpdateEnabled",We=d+".storefrontPaymentsUpdateEnabled",Qe=d+".keepFailedPaymentsOrderOpen",Ye="8a243080f92e4c719546314b577cf82b",c={CONFIG_DOMAIN:d,CONFIG_APPLICATION_KEY:Me,CONFIG_EMAIL_ENABLED:Be,CONFIG_INTEGRATION:Ge,CONFIG_LINE_ITEM_CONSISTENCY_ENABLED:qe,CONFIG_SPACE_ID:ze,CONFIG_SPACE_VIEW_ID:Ve,CONFIG_STOREFRONT_INVOICE_DOWNLOAD_ENABLED:Ue,CONFIG_USER_ID:He,CONFIG_STOREFRONT_WEBHOOKS_UPDATE_ENABLED:Ke,CONFIG_STOREFRONT_PAYMENTS_UPDATE_ENABLED:We,CONFIG_KEEP_FAILED_PAYMENTS_ORDER_OPEN:Qe,STOREFRONT_SALES_CHANNEL_TYPE_ID:Ye};var{Component:Ze,Mixin:B}=Shopware;Ze.register("postfinancecheckout-settings",{template:M,inject:["acl","PostFinanceCheckoutConfigurationService","repositoryFactory"],mixins:[B.getByName("notification"),B.getByName("sw-inline-snippet")],data(){return{config:{},isLoading:!1,isTesting:!1,isSaveSuccessful:!1,applicationKeyFilled:!1,applicationKeyErrorState:!1,spaceIdFilled:!1,spaceIdErrorState:!1,userIdFilled:!1,userIdErrorState:!1,isSetDefaultPaymentSuccessful:!1,isSettingDefaultPaymentMethods:!1,selectedSalesChannelId:null,configIntegrationDefaultValue:"payment_page",configEmailEnabledDefaultValue:!0,configLineItemConsistencyEnabledDefaultValue:!0,configStorefrontInvoiceDownloadEnabledEnabledDefaultValue:!0,configStorefrontWebhooksUpdateEnabledDefaultValue:!0,configStorefrontPaymentsUpdateEnabledDefaultValue:!0,configKeepFailedPaymentsOrderOpenDefaultValue:!1,...c}},props:{isLoading:{type:Boolean,required:!0}},metaInfo(){return{title:this.$createTitle()}},watch:{config:{handler(e){let t=(this.$refs.configComponent.allConfigs||{}).null||{};this.selectedSalesChannelId===null?(this.applicationKeyFilled=!!this.config[this.CONFIG_APPLICATION_KEY],this.spaceIdFilled=!!this.config[this.CONFIG_SPACE_ID],this.userIdFilled=!!this.config[this.CONFIG_USER_ID],this.CONFIG_INTEGRATION in this.config||(this.config[this.CONFIG_INTEGRATION]=this.configIntegrationDefaultValue),this.CONFIG_EMAIL_ENABLED in this.config||(this.config[this.CONFIG_EMAIL_ENABLED]=this.configEmailEnabledDefaultValue),this.CONFIG_LINE_ITEM_CONSISTENCY_ENABLED in this.config||(this.config[this.CONFIG_LINE_ITEM_CONSISTENCY_ENABLED]=this.configLineItemConsistencyEnabledDefaultValue),this.CONFIG_STOREFRONT_INVOICE_DOWNLOAD_ENABLED in this.config||(this.config[this.CONFIG_STOREFRONT_INVOICE_DOWNLOAD_ENABLED]=this.configStorefrontInvoiceDownloadEnabledEnabledDefaultValue),this.CONFIG_STOREFRONT_WEBHOOKS_UPDATE_ENABLED in this.config||(this.config[this.CONFIG_STOREFRONT_WEBHOOKS_UPDATE_ENABLED]=this.configStorefrontWebhooksUpdateEnabledDefaultValue),this.CONFIG_STOREFRONT_PAYMENTS_UPDATE_ENABLED in this.config||(this.config[this.CONFIG_STOREFRONT_PAYMENTS_UPDATE_ENABLED]=this.configStorefrontPaymentsUpdateEnabledDefaultValue),this.CONFIG_KEEP_FAILED_PAYMENTS_ORDER_OPEN in this.config||(this.config[this.CONFIG_KEEP_FAILED_PAYMENTS_ORDER_OPEN]=this.configKeepFailedPaymentsOrderOpenDefaultValue)):(this.applicationKeyFilled=!!this.config[this.CONFIG_APPLICATION_KEY]||!!t[this.CONFIG_APPLICATION_KEY],this.spaceIdFilled=!!this.config[this.CONFIG_SPACE_ID]||!!t[this.CONFIG_SPACE_ID],this.userIdFilled=!!this.config[this.CONFIG_USER_ID]||!!t[this.CONFIG_USER_ID],(!(this.CONFIG_INTEGRATION in this.config)||!(this.CONFIG_INTEGRATION in t))&&(this.config[this.CONFIG_INTEGRATION]=this.configIntegrationDefaultValue),(!(this.CONFIG_EMAIL_ENABLED in this.config)||!(this.CONFIG_EMAIL_ENABLED in t))&&(this.config[this.CONFIG_EMAIL_ENABLED]=this.configEmailEnabledDefaultValue),(!(this.CONFIG_LINE_ITEM_CONSISTENCY_ENABLED in this.config)||!(this.CONFIG_LINE_ITEM_CONSISTENCY_ENABLED in t))&&(this.config[this.CONFIG_LINE_ITEM_CONSISTENCY_ENABLED]=this.configLineItemConsistencyEnabledDefaultValue),(!(this.CONFIG_STOREFRONT_INVOICE_DOWNLOAD_ENABLED in this.config)||!(this.CONFIG_STOREFRONT_INVOICE_DOWNLOAD_ENABLED in t))&&(this.config[this.CONFIG_STOREFRONT_INVOICE_DOWNLOAD_ENABLED]=this.configStorefrontInvoiceDownloadEnabledEnabledDefaultValue),(!(this.CONFIG_STOREFRONT_WEBHOOKS_UPDATE_ENABLED in this.config)||!(this.CONFIG_STOREFRONT_WEBHOOKS_UPDATE_ENABLED in t))&&(this.config[this.CONFIG_STOREFRONT_WEBHOOKS_UPDATE_ENABLED]=this.configStorefrontWebhooksUpdateEnabledDefaultValue),(!(this.CONFIG_STOREFRONT_PAYMENTS_UPDATE_ENABLED in this.config)||!(this.CONFIG_STOREFRONT_PAYMENTS_UPDATE_ENABLED in t))&&(this.config[this.CONFIG_STOREFRONT_PAYMENTS_UPDATE_ENABLED]=this.configStorefrontPaymentsUpdateEnabledDefaultValue),(!(this.CONFIG_KEEP_FAILED_PAYMENTS_ORDER_OPEN in this.config)||!(this.CONFIG_KEEP_FAILED_PAYMENTS_ORDER_OPEN in t))&&(this.config[this.CONFIG_KEEP_FAILED_PAYMENTS_ORDER_OPEN]=this.configKeepFailedPaymentsOrderOpenDefaultValue)),this.$emit("salesChannelChanged"),this.$emit("update:value",e)},deep:!0},selectedSalesChannelId:{handler(e){this.$nextTick(()=>{this.$refs.channelSwitch&&(this.$refs.channelSwitch.salesChannelId=e||"")})}}},methods:{checkTextFieldInheritance(e){return typeof e!="string"?!0:e.length<=0},checkNumberFieldInheritance(e){return typeof e!="number"?!0:e.length<=0},checkBoolFieldInheritance(e){return typeof e!="boolean"},getInheritValue(e){return this.selectedSalesChannelId==null?this.actualConfigData[e]:this.allConfigs.null[e]},async onSave(){if(!(this.spaceIdFilled&&this.userIdFilled&&this.applicationKeyFilled)){this.setErrorStates();return}this.isLoading=!0;let e=await this.validateHeadlessIntegration();if(e==="HEADLESS"){this.createNotificationError({title:this.$tc("postfinancecheckout-settings.settingForm.titleError"),message:this.$tc("postfinancecheckout-settings.settingForm.messageHeadlessIntegrationError")}),this.isLoading=!1;return}else if(e==="GLOBAL"){this.createNotificationError({title:this.$tc("postfinancecheckout-settings.settingForm.titleError"),message:this.$tc("postfinancecheckout-settings.settingForm.messageGlobalIframeError")}),this.isLoading=!1;return}this.save()},async validateHeadlessIntegration(){let e=this.selectedSalesChannelId;if(this.config[this.CONFIG_INTEGRATION]==="payment_page")return null;let n=this.repositoryFactory.create("sales_channel");try{if(e){if(!((await n.get(e,Shopware.Context.api)).typeId.replace(/-/g,"")===c.STOREFRONT_SALES_CHANNEL_TYPE_ID))return"HEADLESS"}else{let a=new Shopware.Data.Criteria;if(a.addFilter(Shopware.Data.Criteria.not("AND",[Shopware.Data.Criteria.equals("typeId",c.STOREFRONT_SALES_CHANNEL_TYPE_ID)])),a.setLimit(1),(await n.search(a,Shopware.Context.api)).total>0)return"GLOBAL"}return null}catch(a){return console.error(a),null}},save(){this.isLoading=!0,this.$refs.configComponent.save().then(e=>{e&&(this.config=e),this.registerWebHooks(),this.synchronizePaymentMethodConfiguration(),this.installOrderDeliveryStates()}).catch(e=>{console.error("Error:",e),this.isLoading=!1})},registerWebHooks(){if(this.config[this.CONFIG_STOREFRONT_WEBHOOKS_UPDATE_ENABLED]===!1)return!1;this.PostFinanceCheckoutConfigurationService.registerWebHooks(this.selectedSalesChannelId).then(()=>{this.createNotificationSuccess({title:this.$tc("postfinancecheckout-settings.settingForm.titleSuccess"),message:this.$tc("postfinancecheckout-settings.settingForm.messageWebHookUpdated")})}).catch(e=>{this.createNotificationError({title:this.$tc("postfinancecheckout-settings.settingForm.titleError"),message:this.$tc("postfinancecheckout-settings.settingForm.messageWebHookError")}),this.isLoading=!1,console.error("Error:",e)})},synchronizePaymentMethodConfiguration(){if(this.config[this.CONFIG_STOREFRONT_PAYMENTS_UPDATE_ENABLED]===!1)return!1;this.PostFinanceCheckoutConfigurationService.synchronizePaymentMethodConfiguration(this.selectedSalesChannelId).then(()=>{this.createNotificationSuccess({title:this.$tc("postfinancecheckout-settings.settingForm.titleSuccess"),message:this.$tc("postfinancecheckout-settings.settingForm.messagePaymentMethodConfigurationUpdated")}),this.isLoading=!1}).catch(e=>{this.createNotificationError({title:this.$tc("postfinancecheckout-settings.settingForm.titleError"),message:this.$tc("postfinancecheckout-settings.settingForm.messagePaymentMethodConfigurationError")}),this.isLoading=!1,console.error("Error:",e)})},installOrderDeliveryStates(){this.PostFinanceCheckoutConfigurationService.installOrderDeliveryStates().then(()=>{this.createNotificationSuccess({title:this.$tc("postfinancecheckout-settings.settingForm.titleSuccess"),message:this.$tc("postfinancecheckout-settings.settingForm.messageOrderDeliveryStateUpdated")}),this.isLoading=!1}).catch(()=>{this.createNotificationError({title:this.$tc("postfinancecheckout-settings.settingForm.titleError"),message:this.$tc("postfinancecheckout-settings.settingForm.messageOrderDeliveryStateError")}),this.isLoading=!1})},onSetPaymentMethodDefault(){this.isSettingDefaultPaymentMethods=!0,this.PostFinanceCheckoutConfigurationService.setPostFinanceCheckoutAsSalesChannelPaymentDefault(this.selectedSalesChannelId).then(()=>{this.isSettingDefaultPaymentMethods=!1,this.isSetDefaultPaymentSuccessful=!0,this.createNotificationSuccess({title:this.$tc("postfinancecheckout-settings.settingForm.titleSuccess"),message:this.$tc("postfinancecheckout-settings.salesChannelCard.messageDefaultPaymentUpdated")})})},setErrorStates(){let e={code:1,detail:this.$tc("postfinancecheckout-settings.messageNotBlank")};this.spaceIdFilled||(this.spaceIdErrorState=e),this.userIdFilled||(this.userIdErrorState=e),this.applicationKeyFilled||(this.applicationKeyErrorState=e)},onCheckApiConnection(e){let{spaceId:t,userId:n,applicationKey:a}=e;this.isTesting=!0,this.PostFinanceCheckoutConfigurationService.checkApiConnection(t,n,a).then(i=>{i.result===200?this.createNotificationSuccess({title:this.$tc("postfinancecheckout-settings.settingForm.credentials.alert.title"),message:this.$tc("postfinancecheckout-settings.settingForm.credentials.alert.successMessage")}):this.createNotificationError({title:this.$tc("postfinancecheckout-settings.settingForm.credentials.alert.title"),message:this.$tc("postfinancecheckout-settings.settingForm.credentials.alert.errorMessage")}),this.isTesting=!1}).catch(()=>{this.createNotificationError({title:this.$tc("postfinancecheckout-settings.settingForm.credentials.alert.title"),message:this.$tc("postfinancecheckout-settings.settingForm.credentials.alert.errorMessage")}),this.isTesting=!1})},onSalesChannelSwitchChange(e,t){this.selectedSalesChannelId=e,typeof t=="function"&&t(e)}}});var G=`{% block postfinancecheckout_settings_content_card_channel_config_credentials %}
	<mt-card
			class="mt-card"
			:title="$tc('postfinancecheckout-settings.settingForm.credentials.cardTitle')"
			v-if="actualConfigData"
	>

		{% block postfinancecheckout_settings_content_card_channel_config_credentials_card_container %}
			<sw-container>

				{% block postfinancecheckout_settings_content_card_channel_config_credentials_card_container_settings %}
					<div v-if="actualConfigData" class="postfinancecheckout-settings-credentials-fields">

						{% block postfinancecheckout_settings_content_card_channel_config_credentials_card_container_settings_space_id %}
							<sw-inherit-wrapper
									v-model:value="actualConfigData[CONFIG_SPACE_ID]"
									:inheritedValue="getInheritedValue(CONFIG_SPACE_ID)"
									@update:value="onSwitchInput">
								<template #content="props">
									<mt-number-field
											:name="CONFIG_SPACE_ID"
											:required="true"
											:mapInheritance="props"
											:label="$tc('postfinancecheckout-settings.settingForm.credentials.spaceId.label')"
											:helpText="$tc('postfinancecheckout-settings.settingForm.credentials.spaceId.tooltipText')"
											:disabled="!acl.can('postfinancecheckout.editor')"
											:model-value="props.currentValue"
											:error="spaceIdErrorState"
											@update:model-value="props.updateCurrentValue">
									</mt-number-field>
								</template>
							</sw-inherit-wrapper>
						{% endblock %}

						{% block postfinancecheckout_settings_content_card_channel_config_credentials_card_container_settings_user_id %}
							<sw-inherit-wrapper
									v-model:value="actualConfigData[CONFIG_USER_ID]"
									:inheritedValue="getInheritedValue(CONFIG_USER_ID)"
									:customInheritationCheckFunction="checkNumberFieldInheritance">
								<template #content="props">
									<mt-number-field
											:name="CONFIG_USER_ID"
											:required="true"
											:mapInheritance="props"
											:label="$tc('postfinancecheckout-settings.settingForm.credentials.userId.label')"
											:helpText="$tc('postfinancecheckout-settings.settingForm.credentials.userId.tooltipText')"
											:disabled="!acl.can('postfinancecheckout.editor')"
											:model-value="props.currentValue"
											:error="userIdErrorState"
											@update:model-value="props.updateCurrentValue">
									</mt-number-field>
								</template>
							</sw-inherit-wrapper>
						{% endblock %}

						{% block postfinancecheckout_settings_content_card_channel_config_credentials_card_container_settings_application_key %}
							<sw-inherit-wrapper
									v-model:value="actualConfigData[CONFIG_APPLICATION_KEY]"
									:inheritedValue="getInheritedValue(CONFIG_APPLICATION_KEY)"
									:customInheritationCheckFunction="checkTextFieldInheritance">
								<template #content="props">
									<mt-password-field
											:name="CONFIG_APPLICATION_KEY"
											:required="true"
											:passwordToggleAble="true"
											:mapInheritance="props"
											:label="$tc('postfinancecheckout-settings.settingForm.credentials.applicationKey.label')"
											:helpText="$tc('postfinancecheckout-settings.settingForm.credentials.applicationKey.tooltipText')"
											:disabled="!acl.can('postfinancecheckout.editor')"
											:model-value="props.currentValue"
											:error="applicationKeyErrorState"
											@update:model-value="props.updateCurrentValue">
									</mt-password-field>
								</template>
							</sw-inherit-wrapper>
						{% endblock %}
					</div>
				{% endblock %}

				{% verbatim %}
				<sw-container columns="1fr 1fr" gap="0px 30px">
					<mt-button
							variant="primary"
							:isLoading="isTesting"
							@click="emitCheckApiConnectionEvent">
						{{ $tc('postfinancecheckout-settings.settingForm.credentials.button.label') }}
					</mt-button>
				</sw-container>
				{% endverbatim %}

			</sw-container>
		{% endblock %}
	</mt-card>

{% endblock %}
`;var{Component:Je,Mixin:Xe}=Shopware;Je.register("sw-postfinancecheckout-credentials",{template:G,name:"PostFinanceCheckoutCredentials",inject:["acl"],mixins:[Xe.getByName("notification")],props:{actualConfigData:{type:Object,required:!0},allConfigs:{type:Object,required:!0},selectedSalesChannelId:{type:[String,null],required:!1,default:null},spaceIdFilled:{type:Boolean,required:!0},spaceIdErrorState:{required:!0},userIdFilled:{type:Boolean,required:!0},userIdErrorState:{required:!0},applicationKeyFilled:{type:Boolean,required:!0},applicationKeyErrorState:{required:!0},isLoading:{type:Boolean,required:!0},isTesting:{type:Boolean,required:!1}},data(){return{...c}},computed:{currentConfig(){return this.selectedSalesChannelId&&this.allConfigs[this.selectedSalesChannelId]?this.allConfigs[this.selectedSalesChannelId]:this.allConfigs.null||{}}},methods:{checkTextFieldInheritance(e){return!e||e.length<=0},checkNumberFieldInheritance(e){return e==null||e===""},checkBoolFieldInheritance(e){return typeof e!="boolean"},emitCheckApiConnectionEvent(){let e={spaceId:this.currentConfig[c.CONFIG_SPACE_ID],userId:this.currentConfig[c.CONFIG_USER_ID],applicationKey:this.currentConfig[c.CONFIG_APPLICATION_KEY]};this.$emit("check-api-connection-event",e)},getInheritedValue(e){return this.allConfigs.null?.[e]??null}}});var q=`{% block postfinancecheckout_settings_content_card_channel_config_options %}
	<mt-card class="mt-card"
			 :title="$tc('postfinancecheckout-settings.settingForm.options.cardTitle')">

		{% block postfinancecheckout_settings_content_card_channel_config_credentials_card_container %}
			<sw-container>

				{% block postfinancecheckout_settings_content_card_channel_config_credentials_card_container_settings %}
					<div v-if="actualConfigData" class="postfinancecheckout-settings-options-fields">

						{% block postfinancecheckout_settings_content_card_channel_config_credentials_card_container_settings_space_view_id %}
							<sw-inherit-wrapper
									v-model:value="actualConfigData[CONFIG_SPACE_VIEW_ID]"
									:inheritedValue="selectedSalesChannelId === null ? null : allConfigs['null'][CONFIG_SPACE_VIEW_ID]"
									:customInheritationCheckFunction="checkNumberFieldInheritance">
								<template #content="props">
									<mt-number-field
											:name="CONFIG_SPACE_VIEW_ID"
											:mapInheritance="props"
											:label="$tc('postfinancecheckout-settings.settingForm.options.spaceViewId.label')"
											:helpText="$tc('postfinancecheckout-settings.settingForm.options.spaceViewId.tooltipText')"
											:disabled="props.isInherited"
											:model-value="props.currentValue"
											@update:model-value="props.updateCurrentValue">
									</mt-number-field>
								</template>
							</sw-inherit-wrapper>
						{% endblock %}

						{% block postfinancecheckout_settings_content_card_channel_config_credentials_card_container_settings_integration %}
							<sw-inherit-wrapper
									v-model:value="actualConfigData[CONFIG_INTEGRATION]"
									:inheritedValue="selectedSalesChannelId === null ? null : allConfigs['null'][CONFIG_INTEGRATION]"
									:customInheritationCheckFunction="checkTextFieldInheritance">
								<template #content="props">
									<sw-single-select
											:name="CONFIG_INTEGRATION"
											labelProperty="name"
											valueProperty="id"
											:options="integrationOptions"
											:mapInheritance="props"
											:label="$tc('postfinancecheckout-settings.settingForm.options.integration.label')"
											:helpText="$tc('postfinancecheckout-settings.settingForm.options.integration.tooltipText')"
											:disabled="props.isInherited"
											:value="props.currentValue"
											@update:value="props.updateCurrentValue">
									</sw-single-select>
								</template>
							</sw-inherit-wrapper>
						{% endblock %}

						{% block postfinancecheckout_settings_content_card_channel_config_credentials_card_container_settings_line_item_consistency_enabled %}
							<sw-inherit-wrapper
									v-model:value="actualConfigData[CONFIG_LINE_ITEM_CONSISTENCY_ENABLED]"
									:inheritedValue="selectedSalesChannelId == null ? null : allConfigs['null'][CONFIG_LINE_ITEM_CONSISTENCY_ENABLED]"
									:customInheritationCheckFunction="checkBoolFieldInheritance">
								<template #content="props">
									<sw-switch-field
											:name="CONFIG_LINE_ITEM_CONSISTENCY_ENABLED"
											bordered
											:mapInheritance="props"
											:label="$tc('postfinancecheckout-settings.settingForm.options.lineItemConsistencyEnabled.label')"
											:helpText="$tc('postfinancecheckout-settings.settingForm.options.lineItemConsistencyEnabled.tooltipText')"
											:disabled="props.isInherited"
											:value="props.currentValue"
											@update:value="props.updateCurrentValue">
									</sw-switch-field>
								</template>
							</sw-inherit-wrapper>
						{% endblock %}

						{% block postfinancecheckout_settings_content_card_channel_config_credentials_card_container_settings_email_enabled %}
							<sw-inherit-wrapper
									v-model:value="actualConfigData[CONFIG_EMAIL_ENABLED]"
									:inheritedValue="selectedSalesChannelId == null ? null : allConfigs['null'][CONFIG_EMAIL_ENABLED]"
									:customInheritationCheckFunction="checkBoolFieldInheritance">
								<template #content="props">
									<sw-switch-field
											:name="CONFIG_EMAIL_ENABLED"
											bordered
											:mapInheritance="props"
											:label="$tc('postfinancecheckout-settings.settingForm.options.emailEnabled.label')"
											:helpText="$tc('postfinancecheckout-settings.settingForm.options.emailEnabled.tooltipText')"
											:disabled="props.isInherited"
											:value="props.currentValue"
											@update:value="props.updateCurrentValue">
									</sw-switch-field>
								</template>
							</sw-inherit-wrapper>
						{% endblock %}

						{% block postfinancecheckout_settings_content_card_channel_config_credentials_card_container_settings_order_close_enabled %}
							<sw-inherit-wrapper
									v-model:value="actualConfigData[CONFIG_KEEP_FAILED_PAYMENTS_ORDER_OPEN]"
									:inheritedValue="selectedSalesChannelId == null ? null : allConfigs['null'][CONFIG_KEEP_FAILED_PAYMENTS_ORDER_OPEN]"
									:customInheritationCheckFunction="checkBoolFieldInheritance">
								<template #content="props">
									<sw-switch-field
											:name="CONFIG_KEEP_FAILED_PAYMENTS_ORDER_OPEN"
											bordered
											:mapInheritance="props"
											:label="$tc('postfinancecheckout-settings.settingForm.options.orderCloseEnabled.label')"
											:helpText="$tc('postfinancecheckout-settings.settingForm.options.orderCloseEnabled.tooltipText')"
											:disabled="props.isInherited"
											:value="props.currentValue"
											@update:value="props.updateCurrentValue">
									</sw-switch-field>
								</template>
							</sw-inherit-wrapper>
						{% endblock %}
					</div>
				{% endblock %}
			</sw-container>
		{% endblock %}
	</mt-card>

{% endblock %}
`;var{Component:tt,Mixin:nt}=Shopware;tt.register("sw-postfinancecheckout-options",{template:q,name:"PostFinanceCheckoutOptions",mixins:[nt.getByName("notification")],props:{actualConfigData:{type:Object,required:!0},allConfigs:{type:Object,required:!0},selectedSalesChannelId:{required:!0},isLoading:{type:Boolean,required:!0}},data(){return{...c}},computed:{integrationOptions(){return[{id:"payment_page",name:this.$tc("postfinancecheckout-settings.settingForm.options.integration.options.payment_page")},{id:"iframe",name:this.$tc("postfinancecheckout-settings.settingForm.options.integration.options.iframe")}]}},methods:{checkTextFieldInheritance(e){return typeof e!="string"?!0:e.length<=0},checkNumberFieldInheritance(e){return typeof e!="number"?!0:e.length<=0},checkBoolFieldInheritance(e){return typeof e!="boolean"}}});var z=`{% block postfinancecheckout_settings_icon %}
    <span class="mt-icon icon--postfinancecheckout-multicolor mt-icon--multicolor" style="width: 16px; height: 16px;">
        <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" contentScriptType="text/ecmascript" width="1000px" zoomAndPan="magnify" contentStyleType="text/css" height="1000px" id="svg2" viewBox="0 0 52.529 52.529" preserveAspectRatio="xMidYMid meet" version="1.0">
    <defs id="defs6">
        <clipPath id="clipPath18">
            <path id="path20" d="M0 0h1486.77v420H0z"/>
        </clipPath>
    </defs>
    <g id="layer2" transform="translate(0 .004)">
        <path fill="#fcd205" stroke-width=".066" d="M0 52.525h52.529V-.004H0z" id="path22"/>
        <path fill="#fff" stroke-width=".125" d="M25.456 38.682l1.466-5.891H5.487l-1.468 5.891h21.437" id="path24"/>
        <path fill="#ed1c24" stroke-width=".11" d="M40.818 21.304s1.755-8.045 1.78-8.12H31.445c0 .05-2.306 10.676-2.306 10.727h2.858c.024 0 1.754-8.02 1.754-8.02h5.413s-1.704 8.044-1.754 8.095h8.245l-1.077 4.962H36.38c0 .05-1.653 7.569-1.653 7.569h-6.19c0 .024-.552 2.706-.576 2.731h9.121c0-.025 1.63-7.594 1.63-7.594h8.196c0-.025 2.155-10.3 2.155-10.35h-8.246" id="path26"/>
        <path fill="#231f20" stroke-width=".092" d="M21.249 35.443l1.163-5.396h4.486l.54-2.646h-4.465l.686-3.312h4.713l.581-2.646h-7.786l-2.949 14z" id="path1640"/>
        <path fill="#231f20" stroke-width=".092" d="M10.104 21.303l-2.973 14h3.015l.921-4.272h1.32c4.21 0 6.557-2.061 6.557-5.499 0-2.562-1.823-4.229-4.944-4.229zm2.472 2.564h1.257c1.383 0 2.051.562 2.051 1.875 0 1.687-1.067 2.728-3.035 2.728h-1.257z" id="path1638"/>
    </g>
</svg>

    </span>
{% endblock %}
`;var{Component:it}=Shopware;it.register("sw-postfinancecheckout-settings-icon",{template:z});var V=`<mt-card class="mt-card"
		 :title="$tc('postfinancecheckout-settings.settingForm.storefrontOptions.cardTitle')">
	<sw-container>
		<div v-if="actualConfigData" class="postfinancecheckout-settings-storefront-options-fields">
			<sw-inherit-wrapper
					v-model:value="actualConfigData[CONFIG_STOREFRONT_INVOICE_DOWNLOAD_ENABLED]"
					:inheritedValue="selectedSalesChannelId == null ? null : allConfigs['null'][CONFIG_STOREFRONT_INVOICE_DOWNLOAD_ENABLED]"
					:customInheritationCheckFunction="checkBoolFieldInheritance">
				<template #content="props">
					<sw-switch-field
							:name="CONFIG_STOREFRONT_INVOICE_DOWNLOAD_ENABLED"
							bordered
							:mapInheritance="props"
							:label="$tc('postfinancecheckout-settings.settingForm.storefrontOptions.invoiceDownloadEnabled.label')"
							:helpText="$tc('postfinancecheckout-settings.settingForm.storefrontOptions.invoiceDownloadEnabled.tooltipText')"
							:disabled="props.isInherited"
							:value="props.currentValue"
							@update:value="props.updateCurrentValue">
					</sw-switch-field>
				</template>
			</sw-inherit-wrapper>
		</div>
	</sw-container>
</mt-card>

`;var{Component:st,Mixin:rt}=Shopware;st.register("sw-postfinancecheckout-storefront-options",{template:V,name:"PostFinanceCheckoutStorefrontOptions",mixins:[rt.getByName("notification")],props:{actualConfigData:{type:Object,required:!0},allConfigs:{type:Object,required:!0},selectedSalesChannelId:{required:!0},isLoading:{type:Boolean,required:!0}},data(){return{...c}},methods:{checkTextFieldInheritance(e){return typeof e!="string"?!0:e.length<=0},checkNumberFieldInheritance(e){return typeof e!="number"?!0:e.length<=0},checkBoolFieldInheritance(e){return typeof e!="boolean"}}});var U=`<mt-card class="mt-card"
		 :title="$tc('postfinancecheckout-settings.settingForm.advancedOptions.cardTitle')">
	<sw-container>
		<div v-if="actualConfigData" class="postfinancecheckout-settings-advanced-options-fields">
			<sw-inherit-wrapper
					v-model:value="actualConfigData[CONFIG_STOREFRONT_WEBHOOKS_UPDATE_ENABLED]"
					:inheritedValue="selectedSalesChannelId == null ? null : allConfigs['null'][CONFIG_STOREFRONT_WEBHOOKS_UPDATE_ENABLED]"
					:customInheritationCheckFunction="checkBoolFieldInheritance">
				<template #content="props">
					<sw-switch-field
							:name="CONFIG_STOREFRONT_WEBHOOKS_UPDATE_ENABLED"
							bordered
							:mapInheritance="props"
							:label="$tc('postfinancecheckout-settings.settingForm.advancedOptions.webhooksUpdateEnabled.label')"
							:helpText="$tc('postfinancecheckout-settings.settingForm.advancedOptions.webhooksUpdateEnabled.tooltipText')"
							:disabled="props.isInherited"
							:value="props.currentValue"
							@update:value="props.updateCurrentValue">
					</sw-switch-field>
				</template>
			</sw-inherit-wrapper>

			<sw-inherit-wrapper
					v-model:value="actualConfigData[CONFIG_STOREFRONT_PAYMENTS_UPDATE_ENABLED]"
					:inheritedValue="selectedSalesChannelId == null ? null : allConfigs['null'][CONFIG_STOREFRONT_PAYMENTS_UPDATE_ENABLED]"
					:customInheritationCheckFunction="checkBoolFieldInheritance">
				<template #content="props">
					<sw-switch-field
							:name="CONFIG_STOREFRONT_PAYMENTS_UPDATE_ENABLED"
							bordered
							:mapInheritance="props"
							:label="$tc('postfinancecheckout-settings.settingForm.advancedOptions.paymentsUpdateEnabled.label')"
							:helpText="$tc('postfinancecheckout-settings.settingForm.advancedOptions.paymentsUpdateEnabled.tooltipText')"
							:disabled="props.isInherited"
							:value="props.currentValue"
							@update:value="props.updateCurrentValue">
					</sw-switch-field>
				</template>
			</sw-inherit-wrapper>
		</div>
	</sw-container>
</mt-card>

`;var{Component:lt,Mixin:dt}=Shopware;lt.register("sw-postfinancecheckout-advanced-options",{template:U,name:"PostFinanceCheckoutAdvancedOptions",inject:["acl"],mixins:[dt.getByName("notification")],props:{actualConfigData:{type:Object,required:!0},allConfigs:{type:Object,required:!0},selectedSalesChannelId:{required:!0},isLoading:{type:Boolean,required:!0}},data(){return{...c}},methods:{checkTextFieldInheritance(e){return typeof e!="string"?!0:e.length<=0},checkNumberFieldInheritance(e){return typeof e!="number"?!0:e.length<=0},checkBoolFieldInheritance(e){return typeof e!="boolean"}}});var H={"sw-privileges":{permissions:{parents:{postfinancecheckout:"PostFinanceCheckout plugin"},postfinancecheckout:{label:"PostFinanceCheckout berechtigungen"}}},"postfinancecheckout-settings":{general:{descriptionTextModule:"PostFinanceCheckout-Einstellungen",mainMenuItemGeneral:"PostFinanceCheckout"},header:"PostFinanceCheckout",messageNotBlank:"Dieser Wert sollte nicht leer sein.",salesChannelCard:{button:{description:"Klicken Sie auf diese Schaltfl\xE4che, um PostFinanceCheckout als Standard-Zahlungsabwickler im ausgew\xE4hlten Vertriebskanal festzulegen",label:"PostFinanceCheckout als Standard-Zahlungsabwickler festlegen"},messageDefaultPaymentError:"PostFinanceCheckout als Standard-Zahlungsabwickler konnte nicht festgelegt werden..",messageDefaultPaymentUpdated:"PostFinanceCheckout als Standard-Zahlungsabwickler wurde festgelegt."},settingForm:{credentials:{applicationKey:{label:"Application Key",tooltipText:"Der Anwendungsschl\xFCssel wird verwendet, um dieses Plugin mit der API PostFinanceCheckout zu authentifizieren."},cardTitle:"Anmeldedaten",spaceId:{label:"Space ID",tooltipText:"Die Space ID wird verwendet, um dieses Plugin mit der API PostFinanceCheckout zu authentifizieren."},userId:{label:"User ID",tooltipText:"Die Benutzer-ID wird verwendet, um dieses Plugin mit der PostFinanceCheckout-API zu authentifizieren."},button:{description:"Klicken Sie auf diese Schaltfl\xE4che, um die PostFinanceCheckout API zu testen",label:"API Verbindung testen"},alert:{title:"API-Test",successMessage:"Die Verbindung wurde erfolgreich getestet.",errorMessage:"Die Verbindung ist fehlgeschlagen. Versuchen Sie es erneut."}},messageSaveSuccess:"PostFinanceCheckout-Einstellungen wurden gespeichert.",messageOrderDeliveryStateError:"PostFinanceCheckout OrderDeliveryState konnte nicht gespeichert werden.",messageOrderDeliveryStateUpdated:"PostFinanceCheckout OrderDeliveryState wurde aktualisiert.",messagePaymentMethodConfigurationError:"PostFinanceCheckout PaymentMethodConfiguration konnte nicht gespeichert werden. Bitte \xFCberpr\xFCfen Sie Ihre Anmeldedaten.",messagePaymentMethodConfigurationUpdated:"PostFinanceCheckout PaymentMethodConfiguration wurde registriert.",messageWebHookError:"PostFinanceCheckout WebHook konnte nicht gespeichert werden. Bitte \xFCberpr\xFCfen Sie Ihre Zugangsdaten.",messageWebHookUpdated:"PostFinanceCheckout WebHook wurde aktualisiert.",options:{cardTitle:"Optionen",emailEnabled:{label:"Auftragsbest\xE4tigung per E-Mail senden",tooltipText:"Wenn diese Einstellung aktiviert ist, erhalten Ihre Kunden eine E-Mail von Ihrem Gesch\xE4ft, wenn die Zahlung ihrer Bestellung autorisiert ist."},orderCloseEnabled:{label:"Bestellung bei fehlgeschlagener Zahlung offen halten",tooltipText:"Wenn diese Einstellung aktiviert ist, bleibt die Bestellung auch bei fehlgeschlagenen Zahlungen offen."},integration:{label:"Integration",options:{iframe:"Iframe",payment_page:"Payment Page"},tooltipText:"Integration"},lineItemConsistencyEnabled:{label:"Konsistenz der Einzelposten",tooltipText:"Wenn diese Option aktiviert ist, stimmen die Summen der Einzelposten in PostFinanceCheckoutPayment immer mit der Shopware-Bestellsumme \xFCberein."},spaceViewId:{label:"Space View ID",tooltipText:"Space View ID"}},save:"Speichern",storefrontOptions:{cardTitle:"Storefront-Optionen",invoiceDownloadEnabled:{label:"Rechnung Download",tooltipText:"Wenn diese Einstellung aktiviert ist, k\xF6nnen Ihre Kunden Auftragsrechnungen von PostFinanceCheckout herunterladen."}},advancedOptions:{cardTitle:"Erweiterte-Optionen",webhooksUpdateEnabled:{label:"Webhooks-Update",tooltipText:"Wenn diese Einstellung aktiviert ist, wird das Webhook-Update ausgel\xF6st, wenn Sie die Einstellungen speichern"},paymentsUpdateEnabled:{label:"Payments-Update",tooltipText:"Wenn diese Einstellung aktiviert ist, wird die Aktualisierung der Zahlungsmethoden ausgel\xF6st, wenn Sie die Einstellungen speichern"}},titleError:"Fehler",titleSuccess:"Erfolg"}}};var K={"sw-privileges":{permissions:{parents:{postfinancecheckout:"PostFinanceCheckout plugin"},postfinancecheckout:{label:"PostFinanceCheckout permissions"}}},"postfinancecheckout-settings":{general:{descriptionTextModule:"PostFinanceCheckout settings",mainMenuItemGeneral:"PostFinanceCheckout"},header:"PostFinanceCheckout",messageNotBlank:"This value should not be blank.",salesChannelCard:{button:{description:"Click this button to set PostFinanceCheckout as default payment handler in the selected SalesChannel",label:"Set PostFinanceCheckout as default payment handler"},messageDefaultPaymentError:"PostFinanceCheckout as default payment could not be set.",messageDefaultPaymentUpdated:"PostFinanceCheckout as default payment has been set."},settingForm:{credentials:{applicationKey:{label:"Application Key",tooltipText:"The Application Key is used to authenticate this plugin with the PostFinanceCheckout API."},cardTitle:"Credentials",spaceId:{label:"Space ID",tooltipText:"The space ID is used to authenticate this plugin with the PostFinanceCheckout API."},userId:{label:"User ID",tooltipText:"The user ID is used to authenticate this plugin with the PostFinanceCheckout API."},button:{description:"Click this button to test the PostFinanceCheckout API",label:"API connection test"},alert:{title:"API Test",successMessage:"The connection was successfully tested.",errorMessage:"The connection was failed. Try it again."}},messageSaveSuccess:"PostFinanceCheckout settings have been saved.",messageOrderDeliveryStateError:"PostFinanceCheckout OrderDeliveryState could not be saved.",messageOrderDeliveryStateUpdated:"PostFinanceCheckout OrderDeliveryState has been updated.",messagePaymentMethodConfigurationError:"PostFinanceCheckout PaymentMethodConfiguration could not be saved. Please check your credentials.",messagePaymentMethodConfigurationUpdated:"PostFinanceCheckout PaymentMethodConfiguration has been registered.",messageWebHookError:"PostFinanceCheckout WebHook could not be saved. Please check your credentials.",messageWebHookUpdated:"PostFinanceCheckout WebHook has been updated.",messageHeadlessIntegrationError:"Iframe integration is only supported for Storefront Sales Channels.",messageGlobalIframeError:"Iframe integration cannot be set globally because you have non-Storefront Sales Channels.",options:{cardTitle:"Options",emailEnabled:{label:"Send order confirmation email",tooltipText:"If this setting is enabled your customers will receive an email from your store when their order payment is authorised"},orderCloseEnabled:{label:"Keep order open on failed payment",tooltipText:"If this setting is enabled the order will be kept open for failed payments"},integration:{label:"Integration",options:{iframe:"Iframe",payment_page:"Payment Page"},tooltipText:"Integration"},lineItemConsistencyEnabled:{label:"Line item consistency",tooltipText:"If this option is enabled line item totals in PostFinanceCheckoutPayment will always match Shopware order total"},spaceViewId:{label:"Space View ID",tooltipText:"Space View ID"}},save:"Save",storefrontOptions:{cardTitle:"Storefront Options",invoiceDownloadEnabled:{label:"Invoice Download",tooltipText:"If this setting is enabled your customers will be able to download order invoices from PostFinanceCheckout"}},advancedOptions:{cardTitle:"Advanced Options",webhooksUpdateEnabled:{label:"Webhooks Update",tooltipText:"If this setting is enabled webhook update will be triggered when you save settings"},paymentsUpdateEnabled:{label:"Payments Update",tooltipText:"If this setting is enabled payment methods update will be triggered when you save settings"}},titleError:"Error",titleSuccess:"Success"}}};var W={"sw-privileges":{permissions:{parents:{postfinancecheckout:"PostFinanceCheckout brancher"},postfinancecheckout:{label:"PostFinanceCheckout autorisations"}}},"postfinancecheckout-settings":{general:{descriptionTextModule:"Param\xE8tres de PostFinanceCheckout",mainMenuItemGeneral:"PostFinanceCheckout"},header:"PostFinanceCheckout",messageNotBlank:"Cette valeur ne doit pas \xEAtre vide.",salesChannelCard:{button:{description:"Cliquez sur ce bouton pour d\xE9finir PostFinanceCheckout comme gestionnaire de paiement par d\xE9faut dans le canal de vente s\xE9lectionn\xE9.",label:"D\xE9finir PostFinanceCheckout comme gestionnaire de paiement par d\xE9faut"},messageDefaultPaymentError:"PostFinanceCheckout comme paiement par d\xE9faut n'a pas pu \xEAtre d\xE9fini.",messageDefaultPaymentUpdated:"PostFinanceCheckout comme paiement par d\xE9faut a \xE9t\xE9 d\xE9fini."},settingForm:{credentials:{applicationKey:{label:"Application Key",tooltipText:"La cl\xE9 d'application est utilis\xE9e pour authentifier ce plugin avec l'API."},cardTitle:"R\xE9f\xE9rences",spaceId:{label:"Space ID",tooltipText:"L'ID de l'espace est utilis\xE9 pour authentifier ce plugin avec l'API PostFinanceCheckout.."},userId:{label:"User ID",tooltipText:"L'ID utilisateur est utilis\xE9 pour authentifier ce plugin avec l'API PostFinanceCheckout."},button:{description:"Cliquez sur ce bouton pour tester l'API PostFinanceCheckout.",label:"Test de connexion \xE0 l'API"},alert:{title:"Test API",successMessage:"La connexion a \xE9t\xE9 test\xE9e avec succ\xE8s.",errorMessage:"La connexion a \xE9chou\xE9. R\xE9essayez."}},messageSaveSuccess:"Les param\xE8tres de PostFinanceCheckout ont \xE9t\xE9 enregistr\xE9s.",messageOrderDeliveryStateError:"Les param\xE8tres de PostFinanceCheckout OrderDeliveryState n'ont pas pu \xEAtre enregistr\xE9s.",messageOrderDeliveryStateUpdated:"PostFinanceCheckout OrderDeliveryState a \xE9t\xE9 mis \xE0 jour.",messagePaymentMethodConfigurationError:"PostFinanceCheckout PaymentMethodConfiguration n'a pas pu \xEAtre enregistr\xE9. Veuillez v\xE9rifier vos informations d'identification.",messagePaymentMethodConfigurationUpdated:"PostFinanceCheckout PaymentMethodConfiguration a \xE9t\xE9 enregistr\xE9.",messageWebHookError:"PostFinanceCheckout WebHook n'a pas pu \xEAtre enregistr\xE9. Veuillez v\xE9rifier vos informations d'identification.",messageWebHookUpdated:"PostFinanceCheckout WebHook a \xE9t\xE9 mis \xE0 jour.",options:{cardTitle:"Options",emailEnabled:{label:"Envoyer un e-mail de confirmation de commande",tooltipText:"Si ce param\xE8tre est activ\xE9, vos clients recevront un e-mail de votre magasin lorsque le paiement de leur commande sera autoris\xE9"},orderCloseEnabled:{label:"Garder la commande ouverte en cas de paiement \xE9chou\xE9",tooltipText:"Si ce param\xE8tre est activ\xE9, la commande sera gard\xE9e ouverte en cas d'\xE9chec de paiement."},integration:{label:"Integration",options:{iframe:"Iframe",payment_page:"Page de paiement"},tooltipText:"Integration"},lineItemConsistencyEnabled:{label:"Coh\xE9rence des postes de ligne",tooltipText:"Si cette option est activ\xE9e, les totaux des articles dans PostFinanceCheckoutPayment correspondront toujours au total de la commande Shopware."},spaceViewId:{label:"Space View ID",tooltipText:"Space View ID"}},save:"Enregistrer",storefrontOptions:{cardTitle:"Storefront Options",invoiceDownloadEnabled:{label:"T\xE9l\xE9chargement de facture",tooltipText:"Si ce param\xE8tre est activ\xE9, vos clients pourront t\xE9l\xE9charger les factures de commande depuis PostFinanceCheckout"}},advancedOptions:{cardTitle:"Options avanc\xE9es",webhooksUpdateEnabled:{label:"Mise \xE0 jour des webhooks",tooltipText:"Si ce param\xE8tre est activ\xE9, la mise \xE0 jour des webhooks sera d\xE9clench\xE9e lorsque vous enregistrerez les param\xE8tres."},paymentsUpdateEnabled:{label:"Mise \xE0 jour des paiements",tooltipText:"Si ce param\xE8tre est activ\xE9, la mise \xE0 jour des m\xE9thodes de paiement sera d\xE9clench\xE9e lorsque vous enregistrez les param\xE8tres."}},titleError:"Erreur",titleSuccess:"Succ\xE8s"}}};var Q={"sw-privileges":{permissions:{parents:{postfinancecheckout:"PostFinanceCheckout brancher"},postfinancecheckout:{label:"PostFinanceCheckout autorisations"}}},"postfinancecheckout-settings":{general:{descriptionTextModule:"Impostazioni PostFinanceCheckout",mainMenuItemGeneral:"PostFinanceCheckout"},header:"PostFinanceCheckout",messageNotBlank:"Questo valore non dovrebbe essere vuoto.",salesChannelCard:{button:{description:"Fai clic su questo pulsante per impostare PostFinanceCheckout come gestore di pagamento predefinito nel SalesChannel selezionato",label:"Imposta PostFinanceCheckout come gestore di pagamento predefinito"},messageDefaultPaymentError:"Non \xE8 stato possibile impostare PostFinanceCheckout come pagamento predefinito.",messageDefaultPaymentUpdated:"PostFinanceCheckout come pagamento predefinito \xE8 stato impostato."},settingForm:{credentials:{applicationKey:{label:"Chiave di applicazione",tooltipText:"La chiave dell'applicazione \xE8 usata per autenticare questo plugin con l'API PostFinanceCheckout."},cardTitle:"Credenziali",spaceId:{label:"ID spazio",tooltipText:"L'ID dello spazio \xE8 usato per autenticare questo plugin con l'API PostFinanceCheckout."},userId:{label:"ID utente",tooltipText:"L'ID utente \xE8 usato per autenticare questo plugin con l'API PostFinanceCheckout."},button:{description:"Fare clic su questo pulsante per testare l'API PostFinanceCheckout.",label:"Test di connessione API"},alert:{title:"Test API",successMessage:"La connessione \xE8 stata testata con successo.",errorMessage:"La connessione \xE8 fallita. Riprovare."}},messageSaveSuccess:"Le impostazioni di PostFinanceCheckout sono state salvate.",messageOrderDeliveryStateError:"PostFinanceCheckout OrderDeliveryState non pu\xF2 essere salvato.",messageOrderDeliveryStateUpdated:"PostFinanceCheckout OrderDeliveryState \xE8 stato aggiornato.",messagePaymentMethodConfigurationError:"PostFinanceCheckout PaymentMethodConfiguration non pu\xF2 essere salvato. Per favore controlla le tue credenziali.",messagePaymentMethodConfigurationUpdated:"PostFinanceCheckout PaymentMethodConfiguration \xE8 stato registrato.",messageWebHookError:"PostFinanceCheckout WebHook non pu\xF2 essere salvato. Per favore controlla le tue credenziali.",messageWebHookUpdated:"PostFinanceCheckout WebHook \xE8 stato aggiornato.",options:{cardTitle:"Opzioni",emailEnabled:{label:"Invia email di conferma dell'ordine",tooltipText:"Se questa impostazione \xE8 abilitata i tuoi clienti riceveranno un'email dal tuo negozio quando il pagamento del loro ordine sar\xE0 autorizzato"},orderCloseEnabled:{label:"Mantieni l'ordine aperto in caso di pagamento non riuscito.",tooltipText:"Se questa impostazione \xE8 abilitata, l'ordine rimarr\xE0 aperto in caso di mancato pagamento."},integration:{label:"Integrazione",options:{iframe:"Iframe",payment_page:"Pagina di pagamento"},tooltipText:"Integrazione"},lineItemConsistencyEnabled:{label:"Coerenza dell'elemento linea",tooltipText:"Se questa opzione \xE8 abilitata i totali degli articoli in PostFinanceCheckoutPayment corrisponderanno sempre al totale dell'ordine Shopware"},spaceViewId:{label:"ID della vista spazio",tooltipText:"ID della vista spaziale"}},save:"Salva",storefrontOptions:{cardTitle:"Opzioni vetrina",invoiceDownloadEnabled:{label:"Scaricamento fattura",tooltipText:"Se questa impostazione \xE8 abilitata i tuoi clienti potranno scaricare le fatture degli ordini da PostFinanceCheckout"}},advancedOptions:{cardTitle:"Opzioni avanzate",webhooksUpdateEnabled:{label:"Aggiornamento webhooks",tooltipText:"Se questa impostazione \xE8 abilitata l'aggiornamento dei webhook sar\xE0 attivato quando si salvano le impostazioni"},paymentsUpdateEnabled:{label:"Aggiornamento pagamenti",tooltipText:"Se questa impostazione \xE8 abilitata l'aggiornamento dei metodi di pagamento verr\xE0 attivato quando si salvano le impostazioni"}},titleError:"Errore",titleSuccess:"Successo"}}};var{Module:ft}=Shopware;ft.register("postfinancecheckout-settings",{type:"plugin",name:"PostFinanceCheckout",title:"postfinancecheckout-settings.general.descriptionTextModule",description:"postfinancecheckout-settings.general.descriptionTextModule",color:"#28d8ff",icon:"default-action-settings",version:"1.0.1",targetVersion:"1.0.1",snippets:{"de-DE":H,"en-GB":K,"fr-FR":W,"it-IT":Q},routes:{index:{component:"postfinancecheckout-settings",path:"index",meta:{parentPath:"sw.settings.index",privilege:"postfinancecheckout.viewer"},props:{default:e=>({hash:e.params.hash})}}},settingsItem:{group:"plugins",to:"postfinancecheckout.settings.index",iconComponent:"sw-postfinancecheckout-settings-icon",backgroundEnabled:!0,privilege:"postfinancecheckout.viewer"}});var h=Shopware.Classes.ApiService,f=class extends h{constructor(t,n,a="postfinancecheckout"){super(t,n,a)}registerWebHooks(t=null){let n=this.getBasicHeaders(),a=`${Shopware.Context.api.apiPath}/_action/${this.getApiBasePath()}/configuration/register-web-hooks`;return this.httpClient.post(a,{salesChannelId:t},{headers:n}).then(i=>h.handleResponse(i))}checkApiConnection(t=null,n=null,a=null){let i=this.getBasicHeaders(),r=`${Shopware.Context.api.apiPath}/_action/${this.getApiBasePath()}/configuration/check-api-connection`;return this.httpClient.post(r,{spaceId:t,userId:n,applicationId:a},{headers:i}).then(s=>h.handleResponse(s))}setPostFinanceCheckoutAsSalesChannelPaymentDefault(t=null){let n=this.getBasicHeaders(),a=`${Shopware.Context.api.apiPath}/_action/${this.getApiBasePath()}/configuration/set-postfinancecheckout-as-sales-channel-payment-default`;return this.httpClient.post(a,{salesChannelId:t},{headers:n}).then(i=>h.handleResponse(i))}synchronizePaymentMethodConfiguration(t=null){let n=this.getBasicHeaders(),a=`${Shopware.Context.api.apiPath}/_action/${this.getApiBasePath()}/configuration/synchronize-payment-method-configuration`;return this.httpClient.post(a,{salesChannelId:t},{headers:n}).then(i=>h.handleResponse(i))}installOrderDeliveryStates(){let t=this.getBasicHeaders(),n=`${Shopware.Context.api.apiPath}/_action/${this.getApiBasePath()}/configuration/install-order-delivery-states`;return this.httpClient.post(n,{},{headers:t}).then(a=>h.handleResponse(a))}},Y=f;var m=Shopware.Classes.ApiService,g=class extends m{constructor(t,n,a="postfinancecheckout"){super(t,n,a)}createRefund(t,n,a,i){let r=this.getBasicHeaders(),s=`${Shopware.Context.api.apiPath}/_action/${this.getApiBasePath()}/refund/create-refund/`;return this.httpClient.post(s,{salesChannelId:t,transactionId:n,quantity:a,lineItemId:i},{headers:r}).then(o=>m.handleResponse(o))}createRefundByAmount(t,n,a){let i=this.getBasicHeaders(),r=`${Shopware.Context.api.apiPath}/_action/${this.getApiBasePath()}/refund/create-refund-by-amount/`;return this.httpClient.post(r,{salesChannelId:t,transactionId:n,refundableAmount:a},{headers:i}).then(s=>m.handleResponse(s))}createPartialRefund(t,n,a,i){let r=this.getBasicHeaders(),s=`${Shopware.Context.api.apiPath}/_action/${this.getApiBasePath()}/refund/create-partial-refund/`;return this.httpClient.post(s,{salesChannelId:t,transactionId:n,refundableAmount:a,lineItemId:i},{headers:r}).then(o=>m.handleResponse(o))}},Z=g;var j=Shopware.Classes.ApiService,k=class extends j{constructor(t,n,a="postfinancecheckout"){super(t,n,a)}getTransactionData(t,n){let a=this.getBasicHeaders(),i=`${Shopware.Context.api.apiPath}/_action/${this.getApiBasePath()}/transaction/get-transaction-data/`;return this.httpClient.post(i,{salesChannelId:t,transactionId:n},{headers:a}).then(r=>j.handleResponse(r))}getInvoiceDocument(t,n){return`${Shopware.Context.api.apiPath}/_action/${this.getApiBasePath()}/transaction/get-invoice-document/${t}/${n}`}getPackingSlip(t,n){return`${Shopware.Context.api.apiPath}/_action/${this.getApiBasePath()}/transaction/get-packing-slip/${t}/${n}`}},J=k;var X=Shopware.Classes.ApiService,b=class extends X{constructor(t,n,a="postfinancecheckout"){super(t,n,a)}createTransactionCompletion(t,n){let a=this.getBasicHeaders(),i=`${Shopware.Context.api.apiPath}/_action/${this.getApiBasePath()}/transaction-completion/create-transaction-completion/`;return this.httpClient.post(i,{salesChannelId:t,transactionId:n},{headers:a}).then(r=>X.handleResponse(r))}},ee=b;var te=Shopware.Classes.ApiService,_=class extends te{constructor(t,n,a="postfinancecheckout"){super(t,n,a)}createTransactionVoid(t,n){let a=this.getBasicHeaders(),i=`${Shopware.Context.api.apiPath}/_action/${this.getApiBasePath()}/transaction-void/create-transaction-void/`;return this.httpClient.post(i,{salesChannelId:t,transactionId:n},{headers:a}).then(r=>te.handleResponse(r))}},ne=_;var{Application:u}=Shopware;u.addServiceProvider("PostFinanceCheckoutConfigurationService",e=>{let t=u.getContainer("init");return new Y(t.httpClient,e.loginService)});u.addServiceProvider("PostFinanceCheckoutRefundService",e=>{let t=u.getContainer("init");return new Z(t.httpClient,e.loginService)});u.addServiceProvider("PostFinanceCheckoutTransactionService",e=>{let t=u.getContainer("init");return new J(t.httpClient,e.loginService)});u.addServiceProvider("PostFinanceCheckoutTransactionCompletionService",e=>{let t=u.getContainer("init");return new ee(t.httpClient,e.loginService)});u.addServiceProvider("PostFinanceCheckoutTransactionVoidService",e=>{let t=u.getContainer("init");return new ne(t.httpClient,e.loginService)});})();
