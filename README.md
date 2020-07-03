PostFinanceCheckout Payment for Shopware 6
=============================

The PostFinanceCheckout Payment plugin wraps around the PostFinanceCheckout API. This library facilitates your interaction with various services such as transactions.

## Requirements

- PHP 7.2 and above
- Shopware 6.2

## Installation

You can use **Composer** or **install manually**

### Composer

The preferred method is via [composer](https://getcomposer.org). Follow the
[installation instructions](https://getcomposer.org/doc/00-intro.md) if you do not already have
composer installed.

Once composer is installed, execute the following command in your project root to install this library:

```sh
composer require postfinancecheckout/shopware-6
php bin/console plugin:refresh
php bin/console plugin:install PostFinanceCheckoutPayment
php bin/console plugin:activate PostFinanceCheckoutPayment
php bin/console cache:clear
```

### Manual Installation

Alternatively you can download the package in its entirety. The [Releases](../../releases) page lists all stable versions.

Uncompress the zip file you download, and include the autoloader in your project:

```php
# unzip to ShopwareInstallDir/custom/plugins/PostFinanceCheckoutPayment
php bin/console plugin:refresh
php bin/console plugin:install PostFinanceCheckoutPayment
php bin/console plugin:activate PostFinanceCheckoutPayment
php bin/console cache:clear
```

## Usage
The library needs to be configured with your account's space id, user id, and application key which are available in your PostFinanceCheckout
account dashboard.

## License

Please see the [license file](https://github.com/pfpayments/shopware-6/blob/master/LICENSE.txt) for more information.
