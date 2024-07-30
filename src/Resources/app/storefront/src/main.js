// Import all necessary Storefront plugins and scss files
import WeArePlanetCheckoutPlugin
    from './weareplanet-checkout-plugin/weareplanet-checkout-plugin.plugin';

// Register them via the existing PluginManager
const PluginManager = window.PluginManager;
PluginManager.register(
    'WeArePlanetCheckoutPlugin',
    WeArePlanetCheckoutPlugin,
    '[data-weareplanet-checkout-plugin]'
);

if (module.hot) {
    // noinspection JSValidateTypes
    module.hot.accept();
}