/* global window */
// noinspection ThisExpressionReferencesGlobalObjectJS
(function (window) {
    /**
     * PostFinanceCheckoutCheckout
     * @type {
     *      {
     *          payment_method_handler_name: string,
     *          payment_method_iframe_class: string,
     *          init: init,
     *          validationCallBack: validationCallBack,
     *          payment_method_handler_status: string,
     *          submitPayment: (function(*): boolean),
     *          payment_method_iframe_prefix: string,
     *          payment_form_id: string,
     *          payment_method_handler_prefix: string,
     *          payment_method_tabs: string,
     *          getIframe: (function(): boolean
     *      }
     * }
     */
    const PostFinanceCheckoutCheckout = {
        /**
         * Variables
         */
        payment_panel_id: 'postfinancecheckout-payment-panel',
        payment_method_iframe_id: 'postfinancecheckout-payment-iframe',
        payment_method_handler_name: 'postfinancecheckout_payment_handler',
        payment_method_handler_status: 'input[name="postfinancecheckout_payment_handler_validation_status"]',
        payment_form_id: 'confirmOrderForm',
        button_cancel_id: 'postfinancecheckoutOrderCancel',
        order_id: '',
        loader_id: 'postfinancecheckoutLoader',
        pay_url: '/postfinancecheckout/checkout/pay?orderId=',
        recreate_cart_url: '/postfinancecheckout/checkout/recreate-cart?orderId=',
        handler: null,

        /**
         * Initialize plugin
         */
        init: function () {
            PostFinanceCheckoutCheckout.activateLoader(true);
            this.order_id = this.getParameterByName('orderId');
            this.pay_url += this.order_id;
            this.recreate_cart_url += this.order_id;

            document.getElementById(this.button_cancel_id).addEventListener('click', this.recreateCart, false);
            document.getElementById(this.payment_form_id).addEventListener('submit', this.submitPayment, false);

            PostFinanceCheckoutCheckout.getIframe();
        },

        activateLoader: function (activate) {
            const buttons = document.querySelectorAll('button');
            if (activate) {
                for (let i = 0; i < buttons.length; i++) {
                    buttons[i].disabled = true;
                }
            } else {
                for (let i = 0; i < buttons.length; i++) {
                    buttons[i].disabled = false;
                }
            }
        },

        recreateCart: function (e) {
            window.location.href = PostFinanceCheckoutCheckout.recreate_cart_url;
            e.preventDefault();
        },

        /**
         * Submit form
         *
         * @param event
         * @return {boolean}
         */
        submitPayment: function (event) {
            PostFinanceCheckoutCheckout.activateLoader(true);
            PostFinanceCheckoutCheckout.handler.validate();
            event.preventDefault();
            return false;
        },

        /**
         * Get iframe
         */
        getIframe: function () {
            const paymentPanel = document.getElementById(PostFinanceCheckoutCheckout.payment_panel_id);
            const paymentMethodConfigurationId = paymentPanel.dataset.id;
            if (!PostFinanceCheckoutCheckout.handler) { // iframe has not been loaded yet
                // noinspection JSUnresolvedFunction
                PostFinanceCheckoutCheckout.handler = window.IframeCheckoutHandler(paymentMethodConfigurationId);
                // noinspection JSUnresolvedFunction
                PostFinanceCheckoutCheckout.handler.setValidationCallback((validationResult) => {
                    PostFinanceCheckoutCheckout.hideErrors();
                    PostFinanceCheckoutCheckout.validationCallBack(validationResult);
                });
                PostFinanceCheckoutCheckout.handler.setInitializeCallback(() => {
                    var loader = document.getElementById(PostFinanceCheckoutCheckout.loader_id);
                    loader.parentNode.removeChild(loader);
                    PostFinanceCheckoutCheckout.activateLoader(false);
                });
                const iframeContainer = document.getElementById(PostFinanceCheckoutCheckout.payment_method_iframe_id);
                PostFinanceCheckoutCheckout.handler.create(iframeContainer);
            }
        },

        /**
         * validation callback
         * @param validationResult
         */
        validationCallBack: function (validationResult) {
            if (validationResult.success) {
                document.querySelector(this.payment_method_handler_status).value = true;
                PostFinanceCheckoutCheckout.handler.submit();
            } else {
                document.body.scrollTop = 0;
                document.documentElement.scrollTop = 0;

                if (validationResult.errors) {
                    PostFinanceCheckoutCheckout.showErrors(validationResult.errors);
                }
                document.querySelector(this.payment_method_handler_status).value = false;
                PostFinanceCheckoutCheckout.activateLoader(false);
            }
        },

        showErrors: function(errors) {
            var alert = document.createElement('div');
            alert.setAttribute('class', 'alert alert-danger');
            alert.setAttribute('role', 'alert');
            alert.setAttribute('id', 'postfinancecheckout-errors');
            document.getElementsByClassName('flashbags')[0].appendChild(alert);

            var alertContentContainer = document.createElement('div');
            alertContentContainer.setAttribute('class', 'alert-content-container');
            alert.appendChild(alertContentContainer);

            var alertContent = document.createElement('div');
            alertContent.setAttribute('class', 'alert-content');
            alertContentContainer.appendChild(alertContent);

            if (errors.length > 1) {
                var alertList = document.createElement('ul');
                alertList.setAttribute('class', 'alert-list');
                alertContent.appendChild(alertList);
                for (var index = 0; index < errors.length; index++) {
                    var alertListItem = document.createElement('li');
                    alertListItem.textContent = errors[index];
                    alertList.appendChild(alertListItem);
                }
            } else {
                alertContent.textContent = errors[0];
            }
        },

        hideErrors: function() {
            var errorElement = document.getElementById('postfinancecheckout-errors');
            if (errorElement) {
                errorElement.parentNode.removeChild(errorElement);
            }
        },

        /**
         * Get query name value
         *
         * @param name
         * @param url
         * @link https://stackoverflow.com/questions/901115/how-can-i-get-query-string-values-in-javascript
         * @return {*}
         */
        getParameterByName: function (name, url) {
            if (!url) url = window.location.href;
            name = name.replace(/[\[\]]/g, '\\$&');
            const regex = new RegExp('[?&]' + name + '(=([^&#]*)|&|#|$)'),
                results = regex.exec(url);
            if (!results) return null;
            if (!results[2]) return '';
            return decodeURIComponent(results[2].replace(/\+/g, ' '));
        }
    };

    window.PostFinanceCheckoutCheckout = PostFinanceCheckoutCheckout;

}(typeof window !== "undefined" ? window : this));

/**
 * Vanilla JS over JQuery
 */
window.addEventListener('load', function (e) {
    PostFinanceCheckoutCheckout.init();
    window.history.pushState({}, document.title, PostFinanceCheckoutCheckout.recreate_cart_url);
    window.history.pushState({}, document.title, PostFinanceCheckoutCheckout.pay_url);
}, false);

window.addEventListener('popstate', function (e) {
    if (window.history.state == null) { // This means it's page load
        return;
    }
    window.location.href = PostFinanceCheckoutCheckout.recreate_cart_url;
}, false);