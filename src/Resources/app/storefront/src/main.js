// Import all necessary Storefront plugins and scss files
import PostFinanceCheckoutCheckoutPlugin
    from './postfinancecheckout-checkout-plugin/postfinancecheckout-checkout-plugin.plugin';

// Register them via the existing PluginManager
const PluginManager = window.PluginManager;
PluginManager.register(
    'PostFinanceCheckoutCheckoutPlugin',
    PostFinanceCheckoutCheckoutPlugin,
    '[data-postfinancecheckout-checkout-plugin]'
);

if (module.hot) {
    // noinspection JSValidateTypes
    module.hot.accept();
}