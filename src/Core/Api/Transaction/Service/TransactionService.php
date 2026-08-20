<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Api\Transaction\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\{
    Checkout\Cart\CartException,
    Checkout\Cart\LineItem\LineItem,
    Checkout\Order\OrderEntity,
    Checkout\Payment\Cart\AsyncPaymentTransactionStruct,
    Framework\Context,
    Framework\DataAbstractionLayer\Search\Criteria,
    Framework\DataAbstractionLayer\Search\Filter\EqualsFilter,
    System\SalesChannel\SalesChannelContext
};
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use PostFinanceCheckout\Sdk\{
    Model\AddressCreate,
    Model\ChargeAttempt,
    Model\CreationEntityState,
    Model\CriteriaOperator,
    Model\EntityQuery,
    Model\EntityQueryFilter,
    Model\EntityQueryFilterType,
    Model\Gender,
    Model\LineItemAttributeCreate,
    Model\LineItemCreate,
    Model\LineItemType,
    Model\Transaction,
    Model\TransactionCreate,
    Model\TransactionPending,
    Model\TransactionState,
};
use PostFinanceCheckoutPayment\Core\{
    Api\OrderDeliveryState\Handler\OrderDeliveryStateHandler,
    Api\Refund\Entity\RefundEntityCollection,
    Api\Refund\Entity\RefundEntityDefinition,
    Api\Transaction\Entity\TransactionEntity,
    Api\Transaction\Entity\TransactionEntityDefinition,
    Settings\Options\Integration,
    Settings\Service\SettingsService,
    Util\LocaleCodeProvider,
    Util\Payload\CustomProducts\CustomProductsLineItemTypes,
    Util\Payload\PayloadLimits,
    Util\Payload\TransactionPayload
};

use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;

/**
 * Class TransactionService
 *
 * @package PostFinanceCheckoutPayment\Core\Api\Transaction\Service
 */
class TransactionService
{
    /**
     * @var \Psr\Container\ContainerInterface
     */
    protected $container;

    /**
     * @var \PostFinanceCheckoutPayment\Core\Util\LocaleCodeProvider
     */
    private $localeCodeProvider;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @var \PostFinanceCheckoutPayment\Core\Settings\Service\SettingsService
     */
    private $settingsService;

    /**
     * Context extension holding the per-request checkout state (pending transaction ID and the
     * fingerprint of the payload last sent to the portal).
     */
    public const CHECKOUT_STATE_EXTENSION = 'checkoutState';

    /**
     * Context extension holding the payment method IDs the portal allows for the pending transaction.
     */
    public const POSSIBLE_METHODS_EXTENSION = 'possibleMethods';

    /**
     * Session key of the fingerprint of the payload last sent for the pending transaction.
     */
    private const PENDING_FINGERPRINT_SESSION_KEY = 'postfinancecheckout_pending_fingerprint';

    const CARD_HOLDER_KEY = '1456765000789';
    const PSEUDO_CODE_KEY = '1485172176673';
    const CARD_VALIDITY_KEY = '1456765711187';
    const PAY_ID_KEY = '1484042941549';
    const ADDITIONAL_TRANSACTION_DETAILS_ORDER_ID_KEY = '1464680013786';

    /**
     * TransactionService constructor.
     *
     * @param \Psr\Container\ContainerInterface $container
     * @param \PostFinanceCheckoutPayment\Core\Util\LocaleCodeProvider $localeCodeProvider
     * @param \PostFinanceCheckoutPayment\Core\Settings\Service\SettingsService $settingsService
     */
    public function __construct(
        ContainerInterface $container,
        LocaleCodeProvider $localeCodeProvider,
        SettingsService    $settingsService
    )
    {
        $this->container = $container;
        $this->localeCodeProvider = $localeCodeProvider;
        $this->settingsService = $settingsService;
    }

    /**
     * @param \Psr\Log\LoggerInterface $logger
     *
     * @internal
     * @required
     *
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * The pay function will be called after the customer completed the order.
     * Allows to process the order and store additional information.
     *
     * A redirect to the url will be performed
     *
     * @param \Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct $transaction
     * @param \Shopware\Core\System\SalesChannel\SalesChannelContext $salesChannelContext
     *
     * @return string
     * @throws \PostFinanceCheckout\Sdk\ApiException
     * @throws \PostFinanceCheckout\Sdk\Http\ConnectionException
     * @throws \PostFinanceCheckout\Sdk\VersioningException
     */
    public function create(
        AsyncPaymentTransactionStruct $transaction,
        SalesChannelContext           $salesChannelContext
    ): string
    {
        $salesChannelId = $salesChannelContext->getSalesChannel()->getId();
        $settings = $this->settingsService->getSettings($salesChannelId);
        $apiClient = $settings->getApiClient();

        $transactionId = $_SESSION['transactionId'] ?? null;
        if ($transactionId !== null) {
            $pendingTransaction = $this->read($_SESSION['transactionId'], $salesChannelId);
        }

        if ($transactionId === null || $pendingTransaction === null || $pendingTransaction->getState() !== TransactionState::PENDING) {
            unset($_SESSION['transactionId']);
            // The stored ID was just cleared, so go straight to creation instead of letting
            // createPendingTransaction() repeat the lookup we already did above.
            $pendingTransactionId = $this->createTransaction($salesChannelContext);
            $pendingTransaction = $this->read($pendingTransactionId, $salesChannelId);
        }

        $transactionPayloadClass = (new TransactionPayload(
            $this->container,
            $this->localeCodeProvider,
            $salesChannelContext,
            $settings,
            $transaction
        ));
        $transactionPayloadClass->setLogger($this->logger);
        $transactionPayload = $transactionPayloadClass->get($pendingTransaction->getVersion());

        $createdTransaction = $apiClient->getTransactionService()
            ->confirm($settings->getSpaceId(), $transactionPayload);

        $this->addPostFinanceCheckoutTransactionId(
            $transaction,
            $salesChannelContext->getContext(),
            $createdTransaction->getId(),
            $settings->getSpaceId()
        );

        $redirectUrl = $this->container->get('router')->generate(
            'frontend.postfinancecheckout.checkout.pay',
            ['orderId' => $transaction->getOrder()->getId(),],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        if ($settings->getIntegration() == Integration::PAYMENT_PAGE) {
            $redirectUrl = $apiClient->getTransactionPaymentPageService()
                ->paymentPageUrl($settings->getSpaceId(), $createdTransaction->getId());
        }

        $this->upsert(
            $createdTransaction,
            $salesChannelContext->getContext(),
            $transaction->getOrderTransaction()->getPaymentMethodId(),
            $transaction->getOrder()->getSalesChannelId()
        );
		// The pending transaction has just been confirmed and is no longer reusable. Drop the
		// per-request checkout state and the allowed method list so nothing downstream in this
		// request keeps filtering against the consumed transaction, and drop the fingerprint so the
		// next checkout cannot skip an update based on it.
		$salesChannelContext->getContext()->removeExtension(self::CHECKOUT_STATE_EXTENSION);
		$salesChannelContext->getContext()->removeExtension(self::POSSIBLE_METHODS_EXTENSION);
		$this->clearPendingTransactionFingerprint();


        $this->holdDelivery($transaction->getOrder()->getId(), $salesChannelContext->getContext());

        return $redirectUrl;
    }

    /**
     * @param \Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct $transaction
     * @param \Shopware\Core\Framework\Context $context
     * @param int $postfinancecheckoutTransactionId
     * @param int $spaceId
     */
    protected function addPostFinanceCheckoutTransactionId(
        AsyncPaymentTransactionStruct $transaction,
        Context                       $context,
        int                           $postfinancecheckoutTransactionId,
        int                           $spaceId
    ): void
    {
        $data = [
            'id' => $transaction->getOrderTransaction()->getId(),
            'customFields' => [
                TransactionPayload::ORDER_TRANSACTION_CUSTOM_FIELDS_POSTFINANCECHECKOUT_TRANSACTION_ID => $postfinancecheckoutTransactionId,
                TransactionPayload::ORDER_TRANSACTION_CUSTOM_FIELDS_POSTFINANCECHECKOUT_SPACE_ID => $spaceId,
            ],
        ];
        $this->container->get('order_transaction.repository')->update([$data], $context);
    }

    /**
     * Persist PostFinanceCheckout transaction
     *
     * @param \PostFinanceCheckout\Sdk\Model\Transaction $transaction
     * @param \Shopware\Core\Framework\Context $context
     * @param string|null $paymentMethodId
     * @param string|null $salesChannelId
     */
    public function upsert(
        Transaction $transaction,
        Context     $context,
        string      $paymentMethodId = null,
        string      $salesChannelId = null
    ): void
    {
        try {

            $transactionId = $transaction->getId();
            $transactionMetaData = $transaction->getMetaData();

            if (!$salesChannelId) {
                $salesChannelId = $transactionMetaData['salesChannelId'] ?? '';
            }

            $orderId = $transactionMetaData[TransactionPayload::POSTFINANCECHECKOUT_METADATA_ORDER_ID];
            $orderTransactionId = $transactionMetaData[TransactionPayload::POSTFINANCECHECKOUT_METADATA_ORDER_TRANSACTION_ID];

            $dataParamValue = json_decode(strval($transaction), true);
            $brandName = '';
            if (isset($dataParamValue['paymentConnectorConfiguration'])) {
                $brandName = $dataParamValue['paymentConnectorConfiguration']
                    ? $dataParamValue['paymentConnectorConfiguration']['name']
                    : '';
            }
            $dataParamValue['brandName'] = $brandName;

            $paymentMethodName = '';
            if (isset($dataParamValue['paymentConnectorConfiguration'])) {
                $paymentMethodName = $dataParamValue['paymentConnectorConfiguration']
                    ? $dataParamValue['paymentConnectorConfiguration']['paymentMethodConfiguration']['name']
                    : '';
            }
            $dataParamValue['paymentMethodName'] = $paymentMethodName;

            $chargeAttempt = $this->getChargeAttempt($salesChannelId, $transactionId);

            $erpMerchantId = null;
            if ($chargeAttempt) {
                $creditCardHolder = $this->getChargeAttemptAdditionalData($chargeAttempt, self::CARD_HOLDER_KEY);
                $dataParamValue['creditCardHolder'] = $creditCardHolder ? $creditCardHolder[0] : '';

                $pseudoCardNumber = $this->getChargeAttemptAdditionalData($chargeAttempt, self::PSEUDO_CODE_KEY);
                $dataParamValue['pseudoCardNumber'] = $pseudoCardNumber ? $pseudoCardNumber[0] : '';

                $payId = $this->getChargeAttemptAdditionalData($chargeAttempt, self::PAY_ID_KEY);
                $dataParamValue['payId'] = $payId ? $payId[0] : '';

                $dataParamValue['customerName'] = isset($transactionMetaData[TransactionPayload::POSTFINANCECHECKOUT_METADATA_CUSTOMER_NAME])
                    ? $transactionMetaData[TransactionPayload::POSTFINANCECHECKOUT_METADATA_CUSTOMER_NAME]
                    : '';

                $creditCardValidity = $this->getChargeAttemptAdditionalData($chargeAttempt, self::CARD_VALIDITY_KEY);

                if (isset($creditCardValidity['cardExpireMonth']) && isset($creditCardValidity['cardExpireYear'])) {
                    $creditCardExpireMonth = $creditCardValidity['cardExpireMonth'] ?? null;
                    if (!empty($creditCardExpireMonth)) {
                        $dataParamValue['cardExpireMonth'] = sprintf("%02d", $creditCardExpireMonth);
                    }
                    $creditCardExpireYear = $creditCardValidity['cardExpireYear'] ?? null;
                    if (!empty($creditCardExpireYear)) {
                        $dataParamValue['cardExpireYear'] = $creditCardExpireYear;
                    }
                }

                $erpMerchantId = $this->getChargeAttemptAdditionalData($chargeAttempt, self::ADDITIONAL_TRANSACTION_DETAILS_ORDER_ID_KEY);
                $erpMerchantId = $erpMerchantId ? $erpMerchantId[0] : null;
            }

            $data = [
                'id' => $orderId,
                'erpMerchantId' => $erpMerchantId,
                'data' => $dataParamValue,
                'paymentMethodId' => $paymentMethodId,
                'orderId' => $orderId,
                'orderTransactionId' => $orderTransactionId,
                'spaceId' => $transaction->getLinkedSpaceId(),
                'state' => $transaction->getState(),
                'salesChannelId' => $salesChannelId,
                'transactionId' => $transaction->getId(),
            ];

            $data = array_filter($data);
            $this->container->get(TransactionEntityDefinition::ENTITY_NAME . '.repository')->upsert([$data], $context);

        } catch (\Exception $exception) {
            $this->logger->critical(__CLASS__ . ' : ' . __FUNCTION__ . ' : ' . $exception->getMessage());
        }
    }

    /**
     * Hold delivery
     *
     * @param string $orderId
     * @param \Shopware\Core\Framework\Context $context
     */
    private function holdDelivery(string $orderId, Context $context)
    {
        try {
            /**
             * @var OrderDeliveryStateHandler $orderDeliveryStateHandler
             */
            $orderEntity = $this->getOrderEntity($orderId, $context);
            $orderDeliveryStateHandler = $this->container->get(OrderDeliveryStateHandler::class);
            if (null !== $orderEntity->getDeliveries()->last()) {
                $orderDeliveryStateHandler->hold($orderEntity->getDeliveries()->last()->getId(), $context);
            }
        } catch (\Exception $exception) {
            $this->logger->critical($exception->getTraceAsString());
        }
    }

    /**
     * Get order
     *
     * @param String $orderId
     * @param \Shopware\Core\Framework\Context $context
     *
     * @return \Shopware\Core\Checkout\Order\OrderEntity
     */
    private function getOrderEntity(string $orderId, Context $context): OrderEntity
    {
        try {
            $criteria = (new Criteria([$orderId]))->addAssociations(['deliveries']);
            $order = $this->container->get('order.repository')->search(
                $criteria,
                $context
            )->first();
            if (is_null($order)) {
                throw CartException::orderNotFound($orderId);
            }
            return $order;
        } catch (\Exception $e) {
            throw CartException::orderNotFound($orderId);
        }

    }

    /**
     * Get transaction entity by orderId
     *
     * @param string $orderId
     * @param \Shopware\Core\Framework\Context $context
     *
     * @return \PostFinanceCheckoutPayment\Core\Api\Transaction\Entity\TransactionEntity
     */
    public function getByOrderId(string $orderId, Context $context): TransactionEntity
    {
        return $this->container->get(TransactionEntityDefinition::ENTITY_NAME . '.repository')
            ->search(new Criteria([$orderId]), $context)
            ->get($orderId);
    }

    /**
     * Read transaction from PostFinanceCheckout API
     *
     * @param int $transactionId
     * @param string $salesChannelId
     *
     * @return \PostFinanceCheckout\Sdk\Model\Transaction
     * @throws \PostFinanceCheckout\Sdk\ApiException
     * @throws \PostFinanceCheckout\Sdk\Http\ConnectionException
     * @throws \PostFinanceCheckout\Sdk\VersioningException
     */
    public function read(int $transactionId, string $salesChannelId): Transaction
    {
        $settings = $this->settingsService->getSettings($salesChannelId);
        return $settings->getApiClient()->getTransactionService()->read($settings->getSpaceId(), $transactionId);
    }

    /**
     * Get transaction entity by PostFinanceCheckout transaction id
     *
     * @param int $transactionId
     * @param \Shopware\Core\Framework\Context $context
     *
     * @return \PostFinanceCheckoutPayment\Core\Api\Transaction\Entity\TransactionEntity|null
     */
    public function getByTransactionId(int $transactionId, Context $context): ?TransactionEntity
    {
        return $this->container->get(TransactionEntityDefinition::ENTITY_NAME . '.repository')
            ->search(
                (new Criteria())->addFilter(new EqualsFilter('transactionId', $transactionId))
                    ->addAssociations(['refunds']), $context
            )
            ->first();
    }

    /**
     * Get transaction entity by PostFinanceCheckout order transaction id
     *
     * @param string $transactionId
     * @param \Shopware\Core\Framework\Context $context
     *
     * @return \PostFinanceCheckoutPayment\Core\Api\Transaction\Entity\TransactionEntity|null
     */
    public function getByOrderTransactionId(string $orderTransactionId, Context $context): ?TransactionEntity
    {
        return $this->container->get(TransactionEntityDefinition::ENTITY_NAME . '.repository')
            ->search(
                (new Criteria())->addFilter(new EqualsFilter('orderTransactionId', $orderTransactionId))
                    ->addAssociations(['refunds']), $context
            )
            ->first();
    }

    /**
     * Get transaction entity by PostFinanceCheckout transaction id
     *
     * @param int $transactionId
     * @param \Shopware\Core\Framework\Context $context
     *
     * @return \PostFinanceCheckoutPayment\Core\Api\Refund\Entity\RefundEntityCollection
     */
    public function getRefundEntityCollectionByTransactionId(int $transactionId, Context $context): ?RefundEntityCollection
    {
        return $this->container->get(RefundEntityDefinition::ENTITY_NAME . '.repository')
            ->search(
                (new Criteria())->addFilter(new EqualsFilter('transactionId', $transactionId)), $context
            )
            ->getEntities();
    }

    /**
     * @param string $orderId
     * @param float $invoicePaidAmount
     * @param Context $context
     * @return void
     */
    public function updateOrderTotalPriceByInvoiceTotal(string $orderId, float $invoicePaidAmount, Context $context): void
    {
        $price = $this->getOrderEntity($orderId, $context)->getPrice();

        if ($price->getTotalPrice() === $invoicePaidAmount) {
            return;
        }

        $data = [
            'id' => $orderId,
            'price' => [
                'netPrice' => $price->getNetPrice(),
                'rawTotal' => $price->getRawTotal(),
                'taxRules' => $price->getTaxRules(),
                'taxStatus' => $price->getTaxStatus(),
                'totalPrice' => $invoicePaidAmount,
                'positionPrice' => $price->getPositionPrice(),
                'calculatedTaxes' => $price->getCalculatedTaxes()
            ],
        ];

        $this->container->get('order.repository')->update([$data], $context);
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @param $event
     * @return int
     */

	public function createPendingTransaction(SalesChannelContext $salesChannelContext, $event = null): int
	{
        return $this->resolvePendingTransaction($salesChannelContext, $event)[0];
    }

    /**
     * Resolves the pending transaction, creating one when the stored ID is missing or no longer usable.
     *
     * Returning the transaction alongside its ID lets callers reuse its version instead of paying for
     * a second read API call.
     *
     * @param SalesChannelContext $salesChannelContext
     * @param mixed $event Optional event the line items are extracted from.
     * @return array{0: int, 1: Transaction|null} The transaction ID, and the transaction as read from
     *                                            the API - null when it was created in this call.
     * @throws \Exception If settings are not configured.
     */
    public function resolvePendingTransaction(SalesChannelContext $salesChannelContext, $event = null): array
    {
        $transactionId = $_SESSION['transactionId'] ?? null;
        $settings = $this->settingsService->getValidSettings($salesChannelContext->getSalesChannel()->getId());
		if (!$settings) {
			throw new \Exception('Space settings not configured');
		}

        if ($transactionId) {
            $failedStates = [
                TransactionState::DECLINE,
                TransactionState::FAILED,
                TransactionState::VOIDED,
				null
            ];

            try {
                $transactionService = $settings->getApiClient()->getTransactionService();
                $pendingTransaction = $transactionService->read($settings->getSpaceId(), $transactionId);
                if (!in_array($pendingTransaction->getState(), $failedStates)) {
                    return [(int) $transactionId, $pendingTransaction];
                }
            } catch (\Exception $e) {
                // Transaction may have been deleted, expired, or is invalid - fall through and create a new one.
            }
        }

        return [$this->createTransaction($salesChannelContext, $event), null];
    }

    /**
     * Unconditionally creates a new pending transaction in the portal and persists its ID.
     *
     * Split out of createPendingTransaction() so callers that already validated the stored
     * transaction do not pay for a second lookup and a second read API call.
     *
     * @param SalesChannelContext $salesChannelContext
     * @param mixed $event Optional event the line items are extracted from.
     * @return int The newly created transaction ID.
     * @throws \Exception If settings are not configured.
     */
    public function createTransaction(SalesChannelContext $salesChannelContext, $event = null): int
    {
        $settings = $this->settingsService->getValidSettings($salesChannelContext->getSalesChannel()->getId());
        if (!$settings) {
            throw new \Exception('Space settings not configured');
        }

        $customer = $salesChannelContext->getCustomer();
        $customerBillingAddress = $customer->getActiveBillingAddress();

        $billingAddress = new AddressCreate();

        $customerAddressEntity = $customer->getActiveBillingAddress();

        $familyName = "";
        if (!empty($customerAddressEntity->getLastName())) {
            $familyName = $customerAddressEntity->getLastName();
        } else {
            if (!empty($customer->getLastName())) {
                $familyName = $customer->getLastName();
            }
        }
        $billingAddress->setFamilyName($familyName);

        $givenName = "";
        if (!empty($customerAddressEntity->getFirstName())) {
            $givenName = $customerAddressEntity->getFirstName();
        } else {
            if (!empty($customer->getFirstName())) {
                $givenName = $customer->getFirstName();
            }
        }
        $billingAddress->setGivenName($givenName);
        $billingAddress->setOrganizationName($customerBillingAddress->getCompany());
        $billingAddress->setPhoneNumber($customerAddressEntity->getPhoneNumber());
        $billingAddress->setCountry($customerBillingAddress->getCountry()->getIso());
        $postalState = $customerBillingAddress?->getCountryState()?->getName() ?? '';
        if (empty($postalState)) {
            $postalState = $customerBillingAddress?->getCountryState()?->getShortCode() ?? '';
        }
        $billingAddress->setPostalState($postalState);
        $billingAddress->setPostCode($customerBillingAddress->getZipcode());
        $billingAddress->setStreet($customerBillingAddress->getStreet());
        $billingAddress->setEmailAddress($customer->getEmail());


        if (!empty($customer->getBirthday())) {
            $birthday = new \DateTime();
            $birthday->setTimestamp($customer->getBirthday()->getTimestamp());
            $birthday = $birthday->format('Y-m-d');
            $billingAddress->setDateOfBirth($birthday);
        }

        $salutation = "";
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

        $billingAddress->setGender(strtolower($customerAddressEntity->getSalutation()->getSalutationKey()) === 'mr' ? Gender::MALE : Gender::FEMALE);
        $billingAddress->setSalutation($salutation);

        $lineItems = [];
        if ($event) {
				if ($event instanceof CheckoutConfirmPageLoadedEvent) {
					$cartLineItems = $event->getPage()->getCart()->getLineItems()->getElements();
					foreach ($cartLineItems as $cartLineItem) {
						if ($cartLineItem->getType() === CustomProductsLineItemTypes::LINE_ITEM_TYPE_CUSTOMIZED_PRODUCTS) {
							continue;
						}
						$lineItems[] = $this->createTempLineItem($cartLineItem);
					}
				} elseif ($event instanceof AccountEditOrderPageLoadedEvent) {
					$order = $event->getPage()->getOrder();
					foreach ($order->getLineItems() as $orderLineItem) {
						$lineItems[] = $this->createTempLineItem($orderLineItem);
					}
				}
        }

        $customerId = "";
        if ($customer->getGuest() === false) {
            $customerId = $customer->getCustomerNumber();
        }

        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
        $homeUrl = $protocol . $_SERVER['HTTP_HOST'];
        $currency = $salesChannelContext->getCurrency()->getIsoCode();
        $language = $this->localeCodeProvider->getLocaleCodeFromContext($salesChannelContext->getContext());

        $transactionPayload = (new TransactionCreate())
            ->setBillingAddress($billingAddress)
            ->setLineItems($lineItems)
            ->setCurrency($currency)
            ->setLanguage($language)
            ->setSpaceViewId($settings->getSpaceViewId())
            ->setAutoConfirmationEnabled(false)
            ->setChargeRetryEnabled(false)
            ->setCustomerEmailAddress($customer->getEmail())
            ->setCustomerId($customerId)
            ->setSuccessUrl($homeUrl . '?success')
            ->setFailedUrl($homeUrl . '?fail');

        $transactionService = $settings->getApiClient()->getTransactionService();
        $transaction = $transactionService->create($settings->getSpaceId(), $transactionPayload);
        $transactionId = (int) $transaction->getId();
        $_SESSION['transactionId'] = $transactionId;

        // The fingerprint is dropped rather than recalculated: it belonged to the previous
        // transaction, and keeping it would let the next pass skip an update for a transaction the
        // fingerprint was never calculated for.
        $this->clearPendingTransactionFingerprint();

        return $transactionId;
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @param int $transactionId
     * @param int|null $version Version of the transaction as already known by the caller. When given,
     *                          the redundant read API call is skipped.
     * @return void
     */
    public function updateTempTransaction(
        SalesChannelContext $salesChannelContext,
        int $transactionId,
        ?int $version = null
    ): void
    {
        $pendingTransaction = new TransactionPending();
        $pendingTransaction->setId($transactionId);

        $settings = $this->settingsService->getValidSettings($salesChannelContext->getSalesChannel()->getId());
        if ($version === null) {
            $transaction = $settings->getApiClient()->getTransactionService()->read($settings->getSpaceId(), $transactionId);
            $version = $transaction->getVersion();
        }
        $pendingTransaction->setVersion($version);

        $billingAddress = $this->buildTempBillingAddress($salesChannelContext);

        $currency = $salesChannelContext->getCurrency()->getIsoCode();
        $language = $this->localeCodeProvider->getLocaleCodeFromContext($salesChannelContext->getContext());

        $pendingTransaction->setCurrency($currency);
        $pendingTransaction->setLanguage($language);
        $pendingTransaction->setBillingAddress($billingAddress);

        $settings->getApiClient()->getTransactionService()
            ->update($settings->getSpaceId(), $pendingTransaction);
    }

    /**
     * Builds the billing address updateTempTransaction() transmits.
     *
     * Shared with the fingerprinting below so the fingerprint is guaranteed to cover exactly the
     * address that would be sent.
     *
     * @param SalesChannelContext $salesChannelContext
     * @return AddressCreate
     */
    private function buildTempBillingAddress(SalesChannelContext $salesChannelContext): AddressCreate
    {
        $customerBillingAddress = $salesChannelContext->getCustomer()->getActiveBillingAddress();

        $billingAddress = new AddressCreate();
        $billingAddress->setStreet($customerBillingAddress->getStreet());
        $billingAddress->setCity($customerBillingAddress->getCity());
        $billingAddress->setCountry($customerBillingAddress->getCountry()->getIso());
        $billingAddress->setPostCode($customerBillingAddress->getZipcode());

        $postalState = $customerBillingAddress?->getCountryState()?->getName() ?? '';
        if (empty($postalState)) {
            $postalState = $customerBillingAddress?->getCountryState()?->getShortCode() ?? '';
        }

        $billingAddress->setPostalState($postalState);
        $billingAddress->setOrganizationName($customerBillingAddress->getCompany());

        return $billingAddress;
    }

    /**
     * Builds a fingerprint of the payload updateTempTransaction() would send.
     *
     * Covers exactly the fields that are transmitted - currency, language and billing address - so
     * that an unchanged checkout produces an unchanged fingerprint and the update API call can be
     * skipped. Anything not sent to the portal is deliberately excluded, so unrelated customer
     * entity changes (last login, lazily loaded associations) do not invalidate it.
     *
     * @param SalesChannelContext $salesChannelContext
     * @return string
     */
    public function buildTempTransactionFingerprint(SalesChannelContext $salesChannelContext): string
    {
        $customer = $salesChannelContext->getCustomer();

        $parts = [
            (string) $salesChannelContext->getCurrency()->getIsoCode(),
            (string) $this->localeCodeProvider->getLocaleCodeFromContext($salesChannelContext->getContext()),
            $customer === null || $customer->getActiveBillingAddress() === null
                ? 'no-billing-address'
                : $this->serializeForFingerprint($this->buildTempBillingAddress($salesChannelContext)),
        ];

        return \hash('sha256', \implode('|', $parts));
    }

    /**
     * Serializes a value deterministically for fingerprinting.
     *
     * SDK models keep their data in a protected container and do not implement JsonSerializable, so
     * json_encode() on them yields "{}". Their __toString() runs the SDK object serializer over the
     * static property map, which is both complete and stable in ordering.
     *
     * @param mixed $value
     * @return string
     */
    private function serializeForFingerprint($value): string
    {
        if (\is_array($value)) {
            return \implode('|', \array_map(fn ($item) => $this->serializeForFingerprint($item), $value));
        }

        if (\is_object($value)) {
            return \method_exists($value, '__toString') ? (string) $value : \serialize($value);
        }

        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    /**
     * Returns the fingerprint of the payload last sent to the portal for the given transaction.
     *
     * Deliberately returns null when the stored record points at a *different* transaction, so a
     * fingerprint can never be applied to a transaction it was not calculated for.
     *
     * @param int $transactionId
     * @return string|null
     */
    public function getPendingTransactionFingerprint(int $transactionId): ?string
    {
        $record = $_SESSION[self::PENDING_FINGERPRINT_SESSION_KEY] ?? null;

        if (!\is_array($record) || (int) ($record['transactionId'] ?? 0) !== $transactionId) {
            return null;
        }

        return isset($record['fingerprint']) ? (string) $record['fingerprint'] : null;
    }

    /**
     * Stores the fingerprint of the payload that was just sent to the portal.
     *
     * @param int $transactionId
     * @param string $fingerprint
     */
    public function storePendingTransactionFingerprint(int $transactionId, string $fingerprint): void
    {
        $_SESSION[self::PENDING_FINGERPRINT_SESSION_KEY] = [
            'transactionId' => $transactionId,
            'fingerprint'   => $fingerprint,
        ];
    }

    /**
     * Drops the stored fingerprint.
     */
    public function clearPendingTransactionFingerprint(): void
    {
        unset($_SESSION[self::PENDING_FINGERPRINT_SESSION_KEY]);
    }

    /**
     * @param ChargeAttempt|null $chargeAttempt
     * @param string $descriptorKey
     * @return array
     */
    private function getChargeAttemptAdditionalData(?ChargeAttempt $chargeAttempt, string $descriptorKey): array
    {
        if (!$chargeAttempt) {
            return [];
        }

        $labels = $chargeAttempt->getLabels() ?? [];

        if (empty($labels)) {
            return [];
        }

        foreach ($labels as $label) {
            $descriptor = $label->getDescriptor();
            if ((string)$descriptor->getId() !== $descriptorKey) {
                continue;
            }

            switch ($descriptorKey) {
                case self::CARD_HOLDER_KEY:
                    return [$label->getContentAsString()];

                case self::PSEUDO_CODE_KEY:
                    return [$label->getContentAsString()];

                case self::PAY_ID_KEY:
                    return [$label->getContentAsString()];

                case self::ADDITIONAL_TRANSACTION_DETAILS_ORDER_ID_KEY:
                    return [$label->getContentAsString()];

                case self::CARD_VALIDITY_KEY:
                    $validityYear = '';
                    $validityMonth = '';
                    foreach ($label->getContent() as $cardValidityItem) {
                        if (strlen((string)$cardValidityItem) === 1 || strlen((string)$cardValidityItem) === 2) {
                            $validityMonth = $cardValidityItem;
                        } elseif (strlen((string)$cardValidityItem) === 4) {
                            $validityYear = $cardValidityItem;
                        }
                    }

                    if (empty($validityYear) || empty($validityMonth)) {
                        return [];
                    }

                    return [
                        'cardExpireMonth' => $validityMonth,
                        'cardExpireYear' => $validityYear,
                    ];
            }
        }

        return [];
    }

    /**
     * @param string $salesChannelId
     * @param int $transactionId
     * @return ChargeAttempt|null
     */
    private function getChargeAttempt(string $salesChannelId, int $transactionId): ?ChargeAttempt
    {
        /** @noinspection PhpParamsInspection */
        $entityQueryFilter = (new EntityQueryFilter())
            ->setType(EntityQueryFilterType::LEAF)
            ->setOperator(CriteriaOperator::EQUALS)
            ->setFieldName('charge.transaction')
            ->setValue($transactionId);

        $query = (new EntityQuery())->setFilter($entityQueryFilter);

        $settings = $this->settingsService->getSettings($salesChannelId);

        $chargeAttempts = $settings->getApiClient()->getChargeAttemptService()->search($settings->getSpaceId(), $query);

        return $chargeAttempts ? $chargeAttempts[0] : null;
    }

    /**
     * @param LineItem $productData
     * @return LineItemCreate
     */
	private function createTempLineItem($productData): LineItemCreate
	{
		$lineItem = new LineItemCreate();

		if ($productData instanceof LineItem) {
			$lineItem->setName(PayloadLimits::fixLength($productData->getLabel(), PayloadLimits::LINE_ITEM_NAME));
			$lineItem->setUniqueId(PayloadLimits::fixLength($productData->getId(), PayloadLimits::LINE_ITEM_UNIQUE_ID));
			$lineItem->setSku(PayloadLimits::fixLength($productData->getReferencedId() ?? $productData->getId(), PayloadLimits::LINE_ITEM_SKU));
			$lineItem->setQuantity($productData->getQuantity());
			$lineItem->setAmountIncludingTax($productData->getPrice()->getUnitPrice());
		} elseif ($productData instanceof OrderLineItemEntity) {
			$lineItem->setName(PayloadLimits::fixLength($productData->getLabel(), PayloadLimits::LINE_ITEM_NAME));
			$lineItem->setUniqueId(PayloadLimits::fixLength($productData->getId(), PayloadLimits::LINE_ITEM_UNIQUE_ID));
			$lineItem->setSku(PayloadLimits::fixLength($productData->getProductId() ?? $productData->getIdentifier() ?? $productData->getId(), PayloadLimits::LINE_ITEM_SKU));
			$lineItem->setQuantity($productData->getQuantity());
			$lineItem->setAmountIncludingTax($productData->getUnitPrice());
		} else {
			throw new \InvalidArgumentException('Unsupported line item type: ' . get_class($productData));
		}

		$lineItem->setType(LineItemType::PRODUCT);

		return $lineItem;
	}
}
