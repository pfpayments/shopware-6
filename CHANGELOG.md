# 4.0.53
- Creation of a new column in the transaction table called erp_merchant_id

# 4.0.52
- Support of Shopware 6.4.20.1

# 4.0.51
- Wrong link format in error message.

# 4.0.45
- Add additional information of the Credit Card (Validity Date, Pseudo Credit Card number and PayID) for transaction using this Payment Method
- Compatibility SW v6.4.17.1
- 
# 4.0.42
- Rollback to remove functionality of sending version to payment portal

# 4.0.41
- Sends to the payment portal a more specific version of shopware being used.

# 4.0.36
- Compatibility SW v6.4.13.0

# 4.0.29
- Fix to hide birthdate field if it's already provided
- Tested against SW v6.4.9.0

# 4.0.28
- Added italian translations
- Tested against SW v6.4.9.0

# 4.0.26
- Added documentation around flow builder

# 4.0.25
- Fixed transaction invoice instant payment handling.
- Tested with v6.4.7.0

# 4.0.24
- Added refunds by amount

# 4.0.23
- Fixed cart recreate function for custom products

# 4.0.22
- Added support for French

# 4.0.21
- Custom products options displayed as separate line items

# 4.0.20
- Fixed company name for shipping address

# 4.0.17
- Fixed settings to import webhooks and payment methods

# 4.0.16
- Added settings to control update of webhooks and payment methods

# 4.0.15
- Adjust wallee/SW6 documentation - how to do refunds

# 4.0.14
- Support for Shopware 6.4.6

# 4.0.13
- Loader Chrome IOS fix

# 4.0.12
- Security fix

# 4.0.11
- Reverted auto-submit on empty iframe as it is not working properly at all cases

# 4.0.10
- Fixed "Allow payment change after checkout" option behavior

# 4.0.9
- Allow to mark payment status as paid from status reminded

# 4.0.8
- Checkout form auto submission implemented when iFrame returns no input fields

# 4.0.7
- Fix Transaction Rollback error on unsupported languages

# 4.0.6
- Fix for delivery state change error

# 4.0.5
- Fixed plugin uninstall action

# 4.0.4
- Line item based refunds

# 4.0.3
- Update SDK

# 4.0.2
- Fixed shipping line item name

# 4.0.1
- Fixed tax calculation for custom products

# 4.0.0
- Support for Shopware 6.4

# 3.1.0
- Support for Custom Products plugin

# 3.0.0
- Fix transaction versioning
- Update SDK

# 2.1.1
- Round amounts
- Redirect if the cart can not be recreated

# 2.1.0
- Fix email issues

# 2.0.0
- Fix cart recreation on promotions
- Remove availability rules
- Handle orders less than or equal to zero

# 1.4.3
- Silence missing order webhook errors
- Fix iframe breakout

# 1.4.2
- Fix payment method bug on first time install

# 1.4.1
- Fetch active payment methods only

# 1.4.0
- Fix payment method availability rule
- Fix email sending
- Cancel failed orders

# 1.3.0
- Update payment method syncing

# 1.2.0
- Add payment method availability rule
- Hardcoded system languages

# 1.1.27
- Retry orders on unavailable payment method

# 1.1.26
- Fix locales and translations

# 1.1.25
- Fix Email sending

# 1.1.24
- Fix webhook response
- Fix translation
- Prepare for Shopware 6.4

# 1.1.23
- Submit payment form when iframe has no fields

# 1.1.22
- Order invoice download setting

# 1.1.21
- Remove hardcoded Shopware API version

# 1.1.20
- Update webhook URLs on plugin update
- Add translations
- Fix email bug

# 1.1.19
- Allow customers to download order invoices

# 1.1.18
- Test against Shopware 6.3
- Fix error on invalid space id
- Remove hardcoded Shopware API version

# 1.1.17
- Use DAL on webhook locks

# 1.1.16
- Only provide translations for available languages
- Return CustomerCanceledAsyncPaymentException on cancelled transactions
- Update SDK to 2.1.1

# 1.1.15
- Send customer first name and last name from billing and shipping profiles
- Respect Shop URL

# 1.1.14
- Add cookies to the cookie manager
- Resize icon to 40px * 40px
- Fix line item attributes

# 1.1.13
- Include vendor folder in Shopware store releases

# 1.1.12
- Update doc path

# 1.1.11
- Add documentation

# 1.1.10
- Stop responding with server errors when orders are not found

# 1.1.9
- Put try catch on webhook install

# 1.1.8
- Remove unhelpful tickets info in release comments

# 1.1.7
- Implement promotions
- Code refactoring

# 1.1.6
- Disable sales channel selection on showcases
- Add product attributes to transaction payload

# 1.1.5
- Fix settings bug

# 1.1.4
- Disable changing credentials on the showcases

# 1.1.3
- Make line item consistency default
- Confirm transaction right away
- Update settings descriptions

# 1.1.2
- Prepare internal server side install for showcases and demos

# 1.1.1
- Stop default emails being sent
- Prettify payment page

# 1.1.0
- Handle empty/default Settings values
- Save refunds to db, and reload order tab on changes

# 1.0.0
- First version of the PostFinanceCheckout integrations for Shopware 6
