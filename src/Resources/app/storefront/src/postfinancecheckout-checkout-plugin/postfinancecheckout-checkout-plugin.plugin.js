/* eslint-disable import/no-unresolved */

// noinspection NpmUsedModulesInstalled
import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';
import DomAccess from 'src/helper/dom-access.helper';

/**
 * PostFinanceCheckoutCheckoutPlugin
 *
 * This plugin handles the initialization and lifecycle of the WhitelabelMachineName payment iframe
 * within the Shopware 6 checkout confirm page.
 */
class PostFinanceCheckoutCheckoutPlugin extends Plugin {

    static options = {
        payment_panel_id: 'postfinancecheckout-payment-panel',
        payment_method_iframe_id: 'postfinancecheckout-payment-iframe',
        payment_method_handler_status: 'input[name="postfinancecheckout_payment_handler_validation_status"]',
        payment_form_id: 'confirmOrderForm',
        loader_id: 'postfinancecheckoutLoader',
        checkout_url_id: 'checkoutUrl',
        cart_recreate_url_id: 'cartRecreateUrl',
    };

    /**
     * Initializes the plugin, variables, and events.
     */
    init() {
        try {
            this._initVariables();
            this._registerEvents();
            this._getIframe();
        } catch (e) {
            // Silently fail if elements are not found; this allows the plugin to be loaded on pages where it might be conditionally absent.
        }
    }

    /**
     * Finds and stores references to relevant DOM elements.
     * @private
     */
    _initVariables() {
        this.checkoutUrl = DomAccess.getElement(document, `#${this.options.checkout_url_id}`).value;
        this.cartRecreateUrl = DomAccess.getElement(document, `#${this.options.cart_recreate_url_id}`).value;
        this.paymentForm = DomAccess.getElement(document, `#${this.options.payment_form_id}`);
        this.paymentPanel = this.el;
        this.iframeContainer = DomAccess.getElement(this.paymentPanel, `#${this.options.payment_method_iframe_id}`);
        this.handler = null;
    }

    /**
     * Registers event listeners for the payment form and browser history.
     * @private
     */
    _registerEvents() {
        this.paymentForm.addEventListener('submit', this._submitPayment.bind(this), false);

        // Handle back button/popstate to ensure cart consistency
        window.addEventListener('popstate', this._onPopstate.bind(this), false);
        window.history.pushState({}, document.title, this.cartRecreateUrl);
        window.history.pushState({}, document.title, this.checkoutUrl);
    }

    /**
     * Handles browser back button activity.
     * @private
     */
    _onPopstate() {
        if (window.history.state == null) {
            return;
        }
        window.location.href = this.cartRecreateUrl;
    }

    /**
     * Intercepts the form submission to validate the iframe content first.
     * @param {Event} event
     * @private
     */
    _submitPayment(event) {
        this._activateLoader(true);
        if (this.handler) {
            this.handler.validate();
            event.preventDefault();
            return false;
        }
    }

    /**
     * Initializes the WhitelabelMachineName Iframe handler and creates the iframe.
     * @private
     */
    _getIframe() {
        const paymentMethodConfigurationId = this.paymentPanel.dataset.id;

        if (!this.handler) {
            // IframeCheckoutHandler is expected to be global from the SDK script
            if (typeof window.IframeCheckoutHandler === 'function') {
                this.handler = window.IframeCheckoutHandler(paymentMethodConfigurationId);
                this.handler.setValidationCallback(this._validationCallBack.bind(this));
                this.handler.setInitializeCallback(this._hideLoader.bind(this));
                this.handler.setHeightChangeCallback((height) => {
                    if (height < 1) {
                        this.handler.submit();
                    }
                });
                this.handler.create(this.iframeContainer);
                setTimeout(this._hideLoader.bind(this), 10000);
            }
        }
    }

    /**
     * Callback for iframe validation results.
     * @param {Object} validationResult
     * @private
     */
    _validationCallBack(validationResult) {
        const statusInputs = document.querySelectorAll(this.options.payment_method_handler_status);
        if (validationResult.success) {
            statusInputs.forEach(input => {
                input.value = 'true';
            });
            this.handler.submit();
        } else {
            statusInputs.forEach(input => {
                input.value = 'false';
            });
            this._activateLoader(false);
            if (validationResult.errors) {
                this._showErrors(validationResult.errors);
            }
        }
    }

    /**
     * Enables or disables buttons on the page to indicate loading.
     * @param {boolean} activate
     * @private
     */
    _activateLoader(activate) {
        const buttons = document.querySelectorAll('button');
        buttons.forEach(button => {
            button.disabled = activate;
        });
    }

    /**
     * Hides the loader overlay once the iframe is ready.
     * @private
     */
    _hideLoader() {
        const loader = document.getElementById(this.options.loader_id);
        if (loader && loader.parentNode) {
            loader.parentNode.removeChild(loader);
        }
        this._activateLoader(false);
    }

    /**
     * Displays validation errors to the user.
     * @param {Array} errors
     * @private
     */
    _showErrors(errors) {
        // Fallback to alert if no native Shopware mechanism is easily accessible here
        alert(errors.join('\n'));
    }
}

export default PostFinanceCheckoutCheckoutPlugin;
