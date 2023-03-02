<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Util\Payload;


use Psr\Container\ContainerInterface;
use Shopware\Core\{
	Checkout\Cart\Tax\Struct\CalculatedTaxCollection,
	Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity,
	Checkout\Customer\CustomerEntity,
	Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity,
	Checkout\Payment\Cart\AsyncPaymentTransactionStruct,
	Framework\DataAbstractionLayer\Search\Criteria,
	System\SalesChannel\SalesChannelContext
};
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use PostFinanceCheckout\Sdk\{
	Model\AddressCreate,
	Model\ChargeAttempt,
	Model\CreationEntityState,
	Model\CriteriaOperator,
	Model\EntityQuery,
	Model\EntityQueryFilter,
	Model\EntityQueryFilterType,
	Model\LineItemAttributeCreate,
	Model\LineItemCreate,
	Model\LineItemType,
	Model\TaxCreate,
	Model\TransactionCreate
};
use PostFinanceCheckoutPayment\Core\{
	Api\PaymentMethodConfiguration\Entity\PaymentMethodConfigurationEntity,
	Settings\Struct\Settings,
	Util\Exception\InvalidPayloadException,
	Util\LocaleCodeProvider,
	Util\Payload\CustomProducts\CustomProductsLineItems,
	Util\Payload\CustomProducts\CustomProductsLineItemTypes
};

/**
 * Class TransactionPayload
 *
 * @package PostFinanceCheckoutPayment\Core\Util\Payload
 */
class TransactionPayload extends AbstractPayload
{

	use CustomProductsLineItems;

	public const ORDER_TRANSACTION_CUSTOM_FIELDS_POSTFINANCECHECKOUT_SPACE_ID = 'postfinancecheckout_space_id';
	public const ORDER_TRANSACTION_CUSTOM_FIELDS_POSTFINANCECHECKOUT_TRANSACTION_ID = 'postfinancecheckout_transaction_id';

	public const POSTFINANCECHECKOUT_METADATA_SALES_CHANNEL_ID = 'salesChannelId';
	public const POSTFINANCECHECKOUT_METADATA_ORDER_ID = 'orderId';
	public const POSTFINANCECHECKOUT_METADATA_ORDER_TRANSACTION_ID = 'orderTransactionId';
	public const POSTFINANCECHECKOUT_METADATA_CUSTOMER_NAME = 'customerName';


	/**
	 * @var \Shopware\Core\System\SalesChannel\SalesChannelContext
	 */
	protected $salesChannelContext;

	/**
	 * @var \Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct
	 */
	protected $transaction;

	/**
	 * @var \PostFinanceCheckoutPayment\Core\Settings\Struct\Settings
	 */
	protected $settings;

	/**
	 * @var \Psr\Container\ContainerInterface
	 */
	protected $container;

	/**
	 * @var \PostFinanceCheckoutPayment\Core\Util\LocaleCodeProvider
	 */
	private $localeCodeProvider;

	/**
	 * @var TranslatorInterface
	 */
	protected $translator;

	/**
	 * TransactionPayload constructor.
	 *
	 * @param \Psr\Container\ContainerInterface $container
	 * @param \PostFinanceCheckoutPayment\Core\Util\LocaleCodeProvider $localeCodeProvider
	 * @param \Shopware\Core\System\SalesChannel\SalesChannelContext $salesChannelContext
	 * @param \PostFinanceCheckoutPayment\Core\Settings\Struct\Settings $settings
	 * @param \Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct $transaction
	 */
	public function __construct(
		ContainerInterface            $container,
		LocaleCodeProvider            $localeCodeProvider,
		SalesChannelContext           $salesChannelContext,
		Settings                      $settings,
		AsyncPaymentTransactionStruct $transaction
	)
	{
		$this->localeCodeProvider = $localeCodeProvider;
		$this->salesChannelContext = $salesChannelContext;
		$this->settings = $settings;
		$this->transaction = $transaction;
		$this->container = $container;
		$this->translator = $this->container->get('translator');
	}

	/**
	 * Get Transaction Payload
	 *
	 * @return \PostFinanceCheckout\Sdk\Model\TransactionCreate
	 * @throws \Exception
	 */
	public function get(): TransactionCreate
	{
		$customer = $this->salesChannelContext->getCustomer();

		$lineItems = $this->getLineItems();
		$billingAddress = $this->getAddressPayload($customer, $customer->getActiveBillingAddress());
		$shippingAddress = $this->getAddressPayload($customer, $customer->getActiveShippingAddress(), false);


		$customerId = null;
		$customerName = null;
		if ($customer->getGuest() === false) {
			$customerId = $customer->getCustomerNumber();
			$customerName = '';
			if ($customer->getGuest() === false) {
				$customerId = $customer->getCustomerNumber();
				$customerName = $customer->getSalutation()->getDisplayName() . ' ' . $customer->getFirstName() . ' ' . $customer->getLastName();
			}
		}

		$transactionData = [
			'currency' => $this->salesChannelContext->getCurrency()->getIsoCode(),
			'customer_email_address' => $billingAddress->getEmailAddress(),
			'customer_id' => $customerId,
			'language' => $this->localeCodeProvider->getLocaleCodeFromContext($this->salesChannelContext->getContext()) ?? null,
			'merchant_reference' => $this->fixLength($this->transaction->getOrder()->getOrderNumber(), 100),
			'meta_data' => [
				self::POSTFINANCECHECKOUT_METADATA_ORDER_ID => $this->transaction->getOrder()->getId(),
				self::POSTFINANCECHECKOUT_METADATA_ORDER_TRANSACTION_ID => $this->transaction->getOrderTransaction()->getId(),
				self::POSTFINANCECHECKOUT_METADATA_SALES_CHANNEL_ID => $this->salesChannelContext->getSalesChannel()->getId(),
				self::POSTFINANCECHECKOUT_METADATA_CUSTOMER_NAME => $customerName,
			],
			'shipping_method' => $this->salesChannelContext->getShippingMethod()->getName() ? $this->fixLength($this->salesChannelContext->getShippingMethod()->getName(), 200) : null,
			'space_view_id' => $this->settings->getSpaceViewId() ?? null,
		];

		// we have to manually check for these additional fields as they might not be active
		if (!empty($additionalAddress1 = $customer->getDefaultBillingAddress()->getAdditionalAddressLine1())) {
			$transactionData['meta_data']['additionalAddress1'] = $additionalAddress1;
		}

		if (!empty($additionalAddress2 = $customer->getDefaultBillingAddress()->getAdditionalAddressLine2())) {
			$transactionData['meta_data']['additionalAddress2'] = $additionalAddress2;
		}

		if (!empty($this->transaction->getOrder()->getCustomerComment())) {
			$transactionData['meta_data']['customer_comment'] = $this->transaction->getOrder()->getCustomerComment();
		}

		$vatIds = $customer->getVatIds();
		if (!empty($vatIds)) {
			$taxNumber = $vatIds[0];
			$transactionData['meta_data']['taxNumber'] = $taxNumber;
		}

		if (!empty($companyDepartment = $customer->getDefaultBillingAddress()->getDepartment())) {
			$transactionData['meta_data']['billingCompanyDepartment'] = $companyDepartment;
		}
		
		if (!empty($companyDepartment = $customer->getDefaultShippingAddress()->getDepartment())) {
			$transactionData['meta_data']['shippingCompanyDepartment'] = $companyDepartment;
		}

		$transactionPayload = (new TransactionCreate())
			->setAutoConfirmationEnabled(false)
			->setBillingAddress($billingAddress)
			->setChargeRetryEnabled(false)
			->setCurrency($transactionData['currency'])
			->setCustomerEmailAddress($transactionData['customer_email_address'])
			->setCustomerId($transactionData['customer_id'])
			->setLanguage($transactionData['language'])
			->setLineItems($lineItems)
			->setMerchantReference($transactionData['merchant_reference'])
			->setMetaData($transactionData['meta_data'])
			->setShippingAddress($shippingAddress)
			->setShippingMethod($transactionData['shipping_method'])
			->setSpaceViewId($transactionData['space_view_id']);

		$paymentConfiguration = $this->getPaymentConfiguration($this->salesChannelContext->getPaymentMethod()->getId());

		$transactionPayload->setAllowedPaymentMethodConfigurations([$paymentConfiguration->getPaymentMethodConfigurationId()]);

		$successUrl = $this->transaction->getReturnUrl() . '&status=paid';
		$failedUrl = $this->getFailUrl($this->transaction->getOrder()->getId()) . '&status=fail';
		$transactionPayload->setSuccessUrl($successUrl)
			->setFailedUrl($failedUrl);

		if (!$transactionPayload->valid()) {
			$this->logger->critical('Transaction payload invalid:', $transactionPayload->listInvalidProperties());
			throw new InvalidPayloadException('Transaction payload invalid:' . json_encode($transactionPayload->listInvalidProperties()));
		}

		return $transactionPayload;
	}

	/**
	 * Get transaction line items
	 *
	 * @return \PostFinanceCheckout\Sdk\Model\LineItemCreate[]
	 * @throws \Exception
	 */
	protected function getLineItems(): array
	{
		/**
		 * @var \PostFinanceCheckout\Sdk\Model\LineItemCreate[] $lineItems
		 */
		$lineItems = [];

		/**
		 * @var \Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity $shopLineItem
		 */
		foreach ($this->transaction->getOrder()->getLineItems() as $shopLineItem) {

			if ($shopLineItem->getType() === CustomProductsLineItemTypes::LINE_ITEM_TYPE_CUSTOMIZED_PRODUCTS) {
				continue;
			}

			if ($shopLineItem->getType() === CustomProductsLineItemTypes::LINE_ITEM_TYPE_CUSTOMIZED_PRODUCTS_OPTION) {
				$customProductOptionParentLabel = $this->getCustomProductOptionLabel($shopLineItem->getParentId());
				$label = $customProductOptionParentLabel . ': ' . $shopLineItem->getLabel();
				$shopLineItem->setLabel($label);
			}

			$lineItem = $this->createLineItem($shopLineItem);

			if (!$lineItem->valid()) {
				$this->logger->critical('LineItem payload invalid:', $lineItem->listInvalidProperties());
				throw new InvalidPayloadException('LineItem payload invalid:' . json_encode($lineItem->listInvalidProperties()));
			}

			$lineItems[] = $lineItem;
		}

		usort($lineItems, function ($shopLineItem1, $shopLineItem2) {
			if ($shopLineItem1->getName() == $shopLineItem2->getName()) {
				return 0;
			}

			return ($shopLineItem1->getName() < $shopLineItem2->getName()) ? -1 : 1;
		});

		$shippingLineItem = $this->getShippingLineItem();
		if (!is_null($shippingLineItem)) {
			$lineItems[] = $shippingLineItem;
		}

		$adjustmentLineItem = $this->getAdjustmentLineItem($lineItems);
		if (!is_null($adjustmentLineItem)) {
			$lineItems[] = $adjustmentLineItem;
		}

		return $lineItems;
	}

	/**
	 * @param string $lineItemParentId
	 * @return string
	 */
	protected function getCustomProductOptionLabel(string $lineItemParentId): string {
		$label = '';
		foreach ($this->transaction->getOrder()->getLineItems() as $shopLineItem) {
			if ($shopLineItem->getParentId() === $lineItemParentId && $shopLineItem->getType() === CustomProductsLineItemTypes::LINE_ITEM_TYPE_PRODUCT) {
				$label = $shopLineItem->getLabel();
				break;
			}
		}

		return $label;
	}

	/**
	 *
	 * @return \PostFinanceCheckout\Sdk\Model\LineItemCreate|null
	 * @throws \Exception
	 * @var \Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity $shopLineItem
	 */
	protected function createLineItem(OrderLineItemEntity $shopLineItem): ?LineItemCreate
	{
		$productAttributes = null;
		$taxes = null;

		$uniqueId = $shopLineItem->getId();
		$sku = $shopLineItem->getProductId() ? $shopLineItem->getProductId() : $uniqueId;
		$payLoad = $shopLineItem->getPayload();
		if (!empty($payLoad) && !empty($payLoad['productNumber'])) {
			$sku = $payLoad['productNumber'];
		}
		$sku = $this->fixLength($sku, 200);
		$amount = $shopLineItem->getTotalPrice() ? self::round($shopLineItem->getTotalPrice()) : 0;

		//include Tax Excluded for Net Tax display customer group
		if ( $this->transaction->getOrder()->getTaxStatus() === 'net' ) {
		    $amount = self::round($amount + $shopLineItem->getPrice()->getCalculatedTaxes()->getAmount());
		}

		$lineItem = (new LineItemCreate())
			->setName($this->fixLength($shopLineItem->getLabel(), 150))
			->setUniqueId($uniqueId)
			->setSku($sku)
			->setQuantity($shopLineItem->getQuantity() ?? 1)
			->setAmountIncludingTax($amount);


		if (!empty($shopLineItem->getType()) && $shopLineItem->getType() == CustomProductsLineItemTypes::LINE_ITEM_TYPE_CUSTOMIZED_PRODUCTS) {

			$productAttributes = $this->getCustomProductLineItemAttribute($shopLineItem);
			$taxes = $this->getCustomProductTaxes(
				$shopLineItem->getPrice()->getCalculatedTaxes(),
				$this->translator->trans('postfinancecheckout.payload.taxes'),
				$amount
			);

		} else {
			$productAttributes = $this->getProductAttributes($shopLineItem);

			$taxes = $this->getTaxes(
				$shopLineItem->getPrice()->getCalculatedTaxes(),
				$this->translator->trans('postfinancecheckout.payload.taxes')
			);
		}


		if (!empty($productAttributes)) {
			$lineItem->setAttributes($productAttributes);
		}

		if (!empty($taxes)) {
			$lineItem->setTaxes($taxes);
		}

		if ($shopLineItem->getTotalPrice() >= 0) {
			$lineItem->setType(LineItemType::PRODUCT);
		} else {
			$lineItem->setType(LineItemType::DISCOUNT);
		}

		return $lineItem;
	}

	/**
	 * @param \Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection $calculatedTaxes
	 * @param string $title
	 *
	 * @return array
	 */
	protected function getTaxes(CalculatedTaxCollection $calculatedTaxes, string $title): array
	{
		$taxes = [];
		foreach ($calculatedTaxes as $calculatedTax) {

			$tax = (new TaxCreate())
				->setRate($calculatedTax->getTaxRate())
				->setTitle($this->fixLength($title . ' : ' . $calculatedTax->getTaxRate(), 40));

			if (!$tax->valid()) {
				$this->logger->critical('Tax payload invalid:', $tax->listInvalidProperties());
				throw new InvalidPayloadException('Tax payload invalid:' . json_encode($tax->listInvalidProperties()));
			}

			$taxes [] = $tax;
		}

		return $taxes;
	}

	/**
	 * @param \Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity $shopLineItem
	 *
	 * @return array|null
	 */
	protected function getProductAttributes(OrderLineItemEntity $shopLineItem): ?array
	{
		$productAttributes = [];
		$lineItemPayload = $shopLineItem->getPayload();

		if (is_array($lineItemPayload) && !empty($lineItemPayload['options'])) {
			foreach ($lineItemPayload['options'] as $option) {

				$label = $option['group'];
				$lineItemAttributeCreate = (new LineItemAttributeCreate())
					->setLabel($this->fixLength($label, 512))
					->setValue($this->fixLength($option['option'], 512));

				if ($lineItemAttributeCreate->valid()) {
					$key = $this->fixLength('option_' . md5($label), 40);
					$productAttributes[$key] = $lineItemAttributeCreate;
				} else {
					$this->logger->critical('LineItemAttributeCreate payload invalid:', $lineItemAttributeCreate->listInvalidProperties());
					throw new InvalidPayloadException('LineItemAttributeCreate payload invalid:' . json_encode($lineItemAttributeCreate->listInvalidProperties()));
				}
			}
		}

		return empty($productAttributes) ? null : $productAttributes;
	}

	/**
	 * @return \PostFinanceCheckout\Sdk\Model\LineItemCreate|null
	 */
	protected function getShippingLineItem(): ?LineItemCreate
	{
		try {

			$amount = $this->transaction->getOrder()->getShippingTotal();
			$amount = self::round($amount);

			if ($amount > 0) {

				$shippingName = $this->salesChannelContext->getShippingMethod()->getName() ?? $this->translator->trans('postfinancecheckout.payload.shipping.name');
				$taxes = $this->getTaxes(
					$this->transaction->getOrder()->getShippingCosts()->getCalculatedTaxes(),
					$shippingName
				);

				$lineItem = (new LineItemCreate())
					->setAmountIncludingTax($amount)
					->setName($this->fixLength($shippingName . ' ' . $this->translator->trans('postfinancecheckout.payload.shipping.lineItem'), 150))
					->setQuantity($this->transaction->getOrder()->getShippingCosts()->getQuantity() ?? 1)
					->setTaxes($taxes)
					->setSku($this->fixLength($shippingName . '-Shipping', 200))
					/** @noinspection PhpParamsInspection */
					->setType(LineItemType::SHIPPING)
					->setUniqueId($this->fixLength($shippingName . '-Shipping', 200));

				if (!$lineItem->valid()) {
					$this->logger->critical('Shipping LineItem payload invalid:', $lineItem->listInvalidProperties());
					throw new InvalidPayloadException('Shipping LineItem payload invalid:' . json_encode($lineItem->listInvalidProperties()));
				}

				return $lineItem;
			}

		} catch (\Exception $exception) {
			$this->logger->critical(__CLASS__ . ' : ' . __FUNCTION__ . ' : ' . $exception->getMessage());
		}
		return null;
	}

	/**
	 * Get Adjustment Line Item
	 *
	 * @param \PostFinanceCheckout\Sdk\Model\LineItemCreate[] $lineItems
	 *
	 * @return \PostFinanceCheckout\Sdk\Model\LineItemCreate|null
	 * @throws \Exception
	 */
	protected function getAdjustmentLineItem(array &$lineItems): ?LineItemCreate
	{
		$lineItem = null;

		$lineItemPriceTotal = array_sum(array_map(static function (LineItemCreate $lineItem) {
			return $lineItem->getAmountIncludingTax();
		}, $lineItems));

		$adjustmentPrice = $this->transaction->getOrder()->getAmountTotal() - $lineItemPriceTotal;
	    	$adjustmentPrice = self::round($adjustmentPrice);

		if (abs($adjustmentPrice) != 0) {
			if ($this->settings->isLineItemConsistencyEnabled()) {
				$error = strtr('LineItems total :lineItemTotal does not add up to order total :orderTotal', [
					':lineItemTotal' => $lineItemPriceTotal,
					':orderTotal' => $this->transaction->getOrder()->getAmountTotal(),
				]);
				$this->logger->critical($error);
				throw new \Exception($error);

			} else {
				$lineItem = (new LineItemCreate())
					->setName($this->translator->trans('postfinancecheckout.payload.adjustmentLineItem'))
					->setUniqueId('Adjustment-Line-Item')
					->setSku('Adjustment-Line-Item')
					->setQuantity(1);
				/** @noinspection PhpParamsInspection */
				$lineItem->setAmountIncludingTax($adjustmentPrice)
					->setType(($adjustmentPrice > 0) ? LineItemType::FEE : LineItemType::DISCOUNT);

				if (!$lineItem->valid()) {
					$this->logger->critical('Adjustment LineItem payload invalid:', $lineItem->listInvalidProperties());
					throw new InvalidPayloadException('Adjustment LineItem payload invalid:' . json_encode($lineItem->listInvalidProperties()));
				}
			}
		}

		return $lineItem;
	}

	/**
	 * Get address payload
	 *
	 * @param \Shopware\Core\Checkout\Customer\CustomerEntity $customer
	 * @param \Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity $customerAddressEntity
	 *
	 * @return \PostFinanceCheckout\Sdk\Model\AddressCreate
	 * @throws \Exception
	 */
	protected function getAddressPayload(CustomerEntity $customer, CustomerAddressEntity $customerAddressEntity, bool $returnSalesTaxNumber = true): AddressCreate
	{
		// Family name
		$family_name = null;
		if (!empty($customerAddressEntity->getLastName())) {
			$family_name = $customerAddressEntity->getLastName();
		} else {
			if (!empty($customer->getLastName())) {
				$family_name = $customer->getLastName();
			}
		}
		$family_name = !empty($family_name) ? $this->fixLength($family_name, 100) : null;

		// Given name
		$given_name = null;
		if (!empty($customerAddressEntity->getFirstName())) {
			$given_name = $customerAddressEntity->getFirstName();
		} else {
			if (!empty($customer->getFirstName())) {
				$given_name = $customer->getFirstName();
			}
		}
		$given_name = !empty($given_name) ? $this->fixLength($given_name, 100) : null;

		// Organization name
		$organization_name = null;
		if (!empty($customerAddressEntity->getCompany())) {
			$organization_name = $customerAddressEntity->getCompany();
		}

		$organization_name = !empty($organization_name) ? $this->fixLength($organization_name, 100) : null;
		
		$salesTaxNumber = null;
		if ($returnSalesTaxNumber) {
			// salesTaxNumber
			$vatIds = $customer->getVatIds();
			if (!empty($vatIds)) {
				$salesTaxNumber = $vatIds[0];
			}
		}

		// Salutation
		$salutation = null;
		if (!(
			empty($customerAddressEntity->getSalutation()) ||
			empty($customerAddressEntity->getSalutation()->getDisplayName())
		)) {
			$salutation = $customerAddressEntity->getSalutation()->getDisplayName();
		} else {
			if (!empty($customer->getSalutation())) {
				$salutation = $customer->getSalutation()->getDisplayName();

			}
		}
		$salutation = !empty($salutation) ? $this->fixLength($salutation, 20) : null;

		$birthday = null;
		if (!empty($customer->getBirthday())) {
			$birthday = new \DateTime();
			$birthday->setTimestamp($customer->getBirthday()->getTimestamp());
			$birthday = $birthday->format('Y-m-d');
		}

		$addressData = [
			'city' => $customerAddressEntity->getCity() ? $this->fixLength($customerAddressEntity->getCity(), 100) : null,
			'country' => $customerAddressEntity->getCountry() ? $customerAddressEntity->getCountry()->getIso() : null,
			'email_address' => $customer->getEmail() ? $this->fixLength($customer->getEmail(), 254) : null,
			'family_name' => $family_name,
			'given_name' => $given_name,
			'organization_name' => $organization_name,
			'phone_number' => $customerAddressEntity->getPhoneNumber() ? $this->fixLength($customerAddressEntity->getPhoneNumber(), 100) : null,
			'postcode' => $customerAddressEntity->getZipcode() ? $this->fixLength($customerAddressEntity->getZipcode(), 40) : null,
			'postal_state' => $customerAddressEntity->getCountryState() ? $customerAddressEntity->getCountryState()->getShortCode() : null,
			'salutation' => $salutation,
			'street' => $customerAddressEntity->getStreet() ? $this->fixLength($customerAddressEntity->getStreet(), 300) : null,
			'birthday' => $birthday
		];
		
		if ($returnSalesTaxNumber) {
			$addressData['sales_tax_number'] = $salesTaxNumber;
		}

		$addressPayload = (new AddressCreate())
			->setCity($addressData['city'])
			->setCountry($addressData['country'])
			->setEmailAddress($addressData['email_address'])
			->setFamilyName($addressData['family_name'])
			->setGivenName($addressData['given_name'])
			->setOrganizationName($addressData['organization_name'])
			->setPhoneNumber($addressData['phone_number'])
			->setPostCode($addressData['postcode'])
			->setPostalState($addressData['postal_state'])
			->setSalutation($addressData['salutation'])
			->setStreet($addressData['street']);
		
		if ($returnSalesTaxNumber) {
			$addressPayload->setSalesTaxNumber($addressData['sales_tax_number']);
		}
		
		if (!empty($addressData['birthday'])) {
			$addressPayload->setDateOfBirth($addressData['birthday']);
		}

		if (!$addressPayload->valid()) {
			$this->logger->critical('Address payload invalid:', $addressPayload->listInvalidProperties());
			throw new InvalidPayloadException('Address payload invalid:' . json_encode($addressPayload->listInvalidProperties()));
		}

		return $addressPayload;
	}

	/**
	 * @param string $id
	 *
	 * @return \PostFinanceCheckoutPayment\Core\Api\PaymentMethodConfiguration\Entity\PaymentMethodConfigurationEntity
	 */
	protected function getPaymentConfiguration(string $id): PaymentMethodConfigurationEntity
	{
		$criteria = (new Criteria([$id]));

		return $this->container->get('postfinancecheckout_payment_method_configuration.repository')
			->search($criteria, $this->salesChannelContext->getContext())
			->getEntities()->first();
	}

	/**
	 * Get failure URL
	 *
	 * @param string $orderId
	 *
	 * @return string
	 */
	protected function getFailUrl(string $orderId): string
	{
		return $this->container->get('router')->generate(
			'frontend.postfinancecheckout.checkout.recreate-cart',
			['orderId' => $orderId,],
			UrlGeneratorInterface::ABSOLUTE_URL
		);
	}
}
