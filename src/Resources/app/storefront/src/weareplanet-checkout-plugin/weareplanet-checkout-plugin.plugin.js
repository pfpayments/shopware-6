/* eslint-disable import/no-unresolved */

// noinspection NpmUsedModulesInstalled
import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';


class WeArePlanetCheckoutPlugin extends Plugin {

    static options = {
        payment_method_tabs: 'ul.weareplanet-payment-panel li',
        payment_method_iframe_prefix: 'iframe_payment_method_',
        payment_method_iframe_class: '.weareplanet-payment-iframe',
        payment_method_handler_name: 'weareplanet_payment_handler',
        payment_method_handler_prefix: 'weareplanet_handler_',
        payment_method_handler_status: 'input[name="weareplanet_payment_handler_validation_status"]',
        payment_form: 'confirmOrderForm',
    };

    init() {
        // @TODO Move JS to Plugin
        this._client = new HttpClient(window.accessKey);
    }

}

export default WeArePlanetCheckoutPlugin;