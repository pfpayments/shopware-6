

WeArePlanet Payment for Shopware 6
=============================

The WeArePlanet Payment plugin wraps around the WeArePlanet API. This library facilitates your interaction with various services such as transactions. Please not this plugin is for version 6.5.
For the 6.4 plugin please visit https://github.com/weareplanet/shopware-6-4

## Requirements

- PHP 7.4 - 8.2
- Shopware 6.5.x

## Installation

You can use **Composer** or **install manually**

### Composer

The preferred method is via [composer](https://getcomposer.org). Follow the
[installation instructions](https://getcomposer.org/doc/00-intro.md) if you do not already have
composer installed.

Once composer is installed, execute the following command in your project root to install this library:

```bash
composer require weareplanet/shopware-6
php bin/console plugin:refresh
php bin/console plugin:install --activate --clearCache WeArePlanetPayment
```

#### Update via composer
```bash
composer update weareplanet/shopware-6
php bin/console plugin:refresh
php bin/console plugin:install --activate --clearCache WeArePlanetPayment
```

### Manual Installation

Alternatively you can download the package in its entirety. The [Releases](../../releases) page lists all stable versions.

Uncompress the zip file you download, and include the autoloader in your project:

```bash
# unzip to ShopwareInstallDir/custom/plugins/WeArePlanetPayment
composer require weareplanet/sdk 4.4.0
php bin/console plugin:refresh
php bin/console plugin:install --activate --clearCache WeArePlanetPayment
```

## Usage
The library needs to be configured with your account's space id, user id, and application key which are available in your WeArePlanet
account dashboard.

### Logs and debugging
To view the logs please run the command below:
```bash
cd shopware/install/dir
tail -f var/log/weareplanet_payment*.log
```

## Documentation

[Documentation](@WalleeDocPath(/docs/en/documentation.html))

## License

Please see the [license file](https://github.com/weareplanet/shopware-6/blob/master/LICENSE.txt) for more information.