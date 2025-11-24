

PostFinance Checkout Integration for Shopware 6
=============================

The PostFinance Checkout plugin wraps around the PostFinance Checkout API. This library facilitates your interaction with various services such as transactions.
Please note that this plugin is for versions 6.5, 6.6 or 6.7. For the 6.4 plugin please visit [our Shopware 6.4 plugin](https://github.com/pfpayments/shopware-6-4).

## Requirements

- Shopware 6.7.x, 6.6.x or 6.5.x. See table below.
- PHP minimum version supported by the each shop version.

## Documentation

- For English documentation click [here](https://plugin-documentation.postfinance-checkout.ch/pfpayments/shopware-6/7.1.6/docs/en/documentation.html)
- Für die deutsche Dokumentation klicken Sie [hier](https://plugin-documentation.postfinance-checkout.ch/pfpayments/shopware-6/7.1.6/docs/de/documentation.html)
- Pour la documentation Française, cliquez [ici](https://plugin-documentation.postfinance-checkout.ch/pfpayments/shopware-6/7.1.6/docs/fr/documentation.html)
- Per la documentazione in tedesco, clicca [qui](https://plugin-documentation.postfinance-checkout.ch/pfpayments/shopware-6/7.1.6/docs/it/documentation.html)

## Installation

### **Via Composer (Recommended)**  
1. Navigate to your Shopware root directory.
2. Run:

```bash
Copy
composer require postfinancecheckout/shopware-6
php bin/console plugin:refresh
php bin/console plugin:install --activate --clearCache PostFinanceCheckout
```

### Manual Installation

1. Download the latest [Release](../../releases)
2. Extract the ZIP to custom/plugins/PostFinanceCheckout.

```bash
Copy
bin/console plugin:refresh  
bin/console plugin:install --activate --clearCache PostFinanceCheckout
```

## Configuration
### API Credentials

1. Navigate to Shopware Admin > Settings > PostFinanceCheckout.
2. Enter your Space ID, User ID, and API Key (obtained from the [PostFinance Checkout Portal](https://checkout.postfinance.ch/)).

### PostFinanceCheckout does not appear in Settings

1. You should run the following commmand

```bash
Copy
bin/build-administration.sh
```

### Payment Methods

Configure supported methods (e.g., credit cards, Apple Pay) via the [PostFinance Checkout Portal](https://checkout.postfinance.ch/).

### Key Features
**iFrame Integration**: Embed payment forms directly into your checkout.

**Refunds & Captures**: Trigger full/partial refunds and captures from Shopware or the [PostFinance Checkout Portal](https://checkout.postfinance.ch/).

**Multi-Store Support**: Manage configurations across multiple stores.

**Automatic Updates**: Payment methods sync dynamically via the PostFinanceCheckout API.

**Subscriptions**: Make recurring payments with Subscriptions (Shopware Commercial) and PostFinanceCheckout.

## Compatibiliity

___________________________________________________________________________________
| Shopware 6 version            | Plugin major version   | Supported until        |
|-------------------------------|------------------------|------------------------|
| Shopware 6.7.x                | 7.x                    | Further notice         |
| Shopware 6.6.x                | 6.x                    | December 2025          |
| Shopware 6.5.x                | 5.x                    | October 2024           |
-----------------------------------------------------------------------------------

### Troubleshooting
**Logs**: Check payment logs with:

```bash
Copy
tail -f var/log/postfinancecheckout*.log
```
### Common Issues:

Ensure composer update postfinancecheckout/shopware-6 is run after updates.

Verify API credentials match your PostFinanceCheckout account.

## FAQs
**Q: Does this plugin support one-click payments?**
A: Yes, via tokenization in the PostFinanceCheckout Portal.

**Q: How do I handle PCI compliance?**
A: The plugin uses iFrame integration, reducing PCI requirements to SAQ-A.

### Changelog
For version-specific updates, see the [GitHub Releases](https://github.com/pfpayments/shopware-6/releases).

### Contributing
Report issues via GitHub Issues.

Follow the Shopware Plugin Base Guide for development.

This template combines technical clarity with user-friendly guidance. For advanced customization (e.g., overriding templates or payment handlers), refer to the Shopware Documentation.

## License

Please see the [license file](https://github.com/pfpayments/shopware-6/blob/master/LICENSE.txt) for more information.
