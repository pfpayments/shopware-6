

PostFinanceCheckout Payment for Shopware 6
=============================

The PostFinanceCheckout Payment plugin wraps around the PostFinanceCheckout API. This library facilitates your interaction with various services such as transactions.
Please note that this plugin is for versions 6.5 and 6.6. For the 6.4 plugin please visit [our Shopware 6.4 plugin](https://github.com/pfpayments/shopware-6-4).

## Requirements

- Shopware 6.5.x or Shopware 6.6.x. See table below.
- PHP minimum version supported by the each shop version.

## Supported versions

___________________________________________________________________________________
| Shopware 6 version            | Plugin major version   | Supported until        |
|-------------------------------|------------------------|------------------------|
| Shopware 6.6.x                | 6.x                    | Further notice         |
| Shopware 6.5.x                | 5.x                    | October 2024           |
-----------------------------------------------------------------------------------

## Installation

You can use **Composer** or **install manually**

### Composer

The preferred method is via [composer](https://getcomposer.org). Follow the
[installation instructions](https://getcomposer.org/doc/00-intro.md) if you do not already have
composer installed.

Once composer is installed, execute the following command in your project root to install this library:

```bash
composer require postfinancecheckout/shopware-6
php bin/console plugin:refresh
php bin/console plugin:install --activate --clearCache PostFinanceCheckoutPayment
```

#### Update via composer
```bash
composer update postfinancecheckout/shopware-6
php bin/console plugin:refresh
php bin/console plugin:install --activate --clearCache PostFinanceCheckoutPayment
```

### Manual Installation

Alternatively you can download the package in its entirety. The [Releases](../../releases) page lists all stable versions.

Uncompress the zip file you download, and include the autoloader in your project:

```bash
# unzip to ShopwareInstallDir/custom/plugins/PostFinanceCheckoutPayment
composer require postfinancecheckout/sdk 4.0.2
php bin/console plugin:refresh
php bin/console plugin:install --activate --clearCache PostFinanceCheckoutPayment
```

## Usage
The library needs to be configured with your account's space id, user id, and application key which are available in your PostFinanceCheckout
account dashboard.

### Logs and debugging
To view the logs please run the command below:
```bash
cd shopware/install/dir
tail -f var/log/postfinancecheckout_payment*.log
```

## Documentation

[Documentation](https://plugin-documentation.postfinance-checkout.ch/pfpayments/shopware-6/master/docs/en/documentation.html)

## License

Please see the [license file](https://github.com/pfpayments/shopware-6/blob/master/LICENSE.txt) for more information.
