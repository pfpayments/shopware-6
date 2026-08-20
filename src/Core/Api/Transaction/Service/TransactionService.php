<?php

declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Api\Transaction\Service;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\{
    Checkout\Cart\CartException,
    Checkout\Cart\LineItem\LineItem,
    Checkout\Order\OrderEntity,
    Checkout\Payment\Cart\PaymentTransactionStruct,
    Framework\Context,
    Framework\DataAbstractionLayer\Search\Criteria,
    Framework\DataAbstractionLayer\Search\Filter\EqualsFilter,
    System\SalesChannel\SalesChannelContext
};
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use PostFinanceCheckout\Sdk\Model\{
    AddressCreate,
    ChargeAttempt,
    CreationEntityState,
    CriteriaOperator,
    EntityQuery,
    EntityQueryFilter,
    EntityQueryFilterType,
    Gender,
    LineItemAttributeCreate,
    LineItemCreate,
    LineItemType,
    TaxCreate,
    TokenizationMode,
    Transaction,
    TransactionCreate,
    TransactionPending,
    TransactionState,
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
    Util\Payload\TransactionPayload,
    Util\Analytics\Analytics
};
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Commercial\Subscription\Framework\Struct\SubscriptionContextStruct;

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
     * Cache for storing pending transaction IDs across headless requests.
     * @var CacheItemPoolInterface
     */
    private CacheItemPoolInterface $cache;

    /**
     * Context extension holding the per-request checkout state (pending transaction ID and the
     * fingerprint of the payload last sent to the portal).
     */
    public const CHECKOUT_STATE_EXTENSION = 'checkoutState';

    /**
     * Context extension holding the memoized payment method filter result for the current request.
     */
    public const FILTER_MEMO_EXTENSION = 'postfinancecheckout_filter_memo';

    /**
     * Cache key prefix for the persisted pending transaction record.
     */
    private const PENDING_TRANSACTION_CACHE_PREFIX = 'pfcn_pending_transaction_v2_';

    /**
     * Lifetime of the pending transaction record, matching a typical cart lifetime.
     */
    private const PENDING_TRANSACTION_CACHE_TTL = 7200;

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
     * @param CacheItemPoolInterface $cache Cache for headless transaction persistence
     */
    public function __construct(
        ContainerInterface $container,
        LocaleCodeProvider $localeCodeProvider,
        SettingsService    $settingsService,
        CacheItemPoolInterface $cache
    ) {
        $this->container = $container;
        $this->localeCodeProvider = $localeCodeProvider;
        $this->settingsService = $settingsService;
        $this->cache = $cache;
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
     * @param \Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct $transaction
     * @param \Shopware\Core\System\SalesChannel\SalesChannelContext $salesChannelContext
     *
     * @return string
     * @throws \PostFinanceCheckout\Sdk\ApiException
     * @throws \PostFinanceCheckout\Sdk\Http\ConnectionException
     * @throws \PostFinanceCheckout\Sdk\VersioningException
     */
    public function create(
        PaymentTransactionStruct $transaction,
        SalesChannelContext      $salesChannelContext
    ): string {
        $criteria = new Criteria([$transaction->getOrderTransactionId()]);
        $criteria->addAssociation('order');
        $orderTransaction = $this->container->get('order_transaction.repository')->search($criteria, $salesChannelContext->getContext())->first();

        $salesChannelId = $salesChannelContext->getSalesChannel()->getId();
        $settings = $this->settingsService->getSettings($salesChannelId);
        $apiClient = $settings->getApiClient();

        // Get transaction ID from cache (headless) or session (storefront).
        $transactionId = $this->getTransactionIdFromContext($salesChannelContext);
        $pendingTransaction = null;

        // Try to read the pending transaction if we have an ID stored.
        if ($transactionId !== null) {
            try {
                $pendingTransaction = $this->read($transactionId, $salesChannelId);
                // Verify it's still in PENDING state - otherwise we can't reuse it.
                if ($pendingTransaction != null && $pendingTransaction->getState() !== TransactionState::PENDING) {
                    $pendingTransaction = null;
                }
            } catch (\Exception $e) {
                // Transaction may have been deleted, expired, or is invalid - we'll create a new one.
                $this->logger?->debug('Could not read pending transaction, will create new one: ' . $e->getMessage());
                $pendingTransaction = null;
            }
        }

        // Create a new transaction if we don't have a valid pending one.
        if ($pendingTransaction === null) {
            $this->clearTransactionIdFromContext($salesChannelContext);
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
        $transactionPayloadClass->setTransactionId($pendingTransaction->getId());
        $transactionPayload = $transactionPayloadClass->get($pendingTransaction->getVersion());

        $createdTransaction = $apiClient->getTransactionService()
            ->confirm($settings->getSpaceId(), $transactionPayload);

        $this->addPostFinanceCheckoutTransactionId(
            $transaction,
            $salesChannelContext->getContext(),
            $createdTransaction->getId(),
            $settings->getSpaceId(),
            $salesChannelContext->getToken()
        );

        $redirectUrl = $this->container->get('router')->generate(
            'frontend.postfinancecheckout.checkout.pay',
            ['orderId' => $orderTransaction->getOrder()->getId(),],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        // If the request comes from the Store API (headless), we should not redirect to a Storefront Twig page.
        // Instead, we return the returnUrl so the headless client can handle the next steps (e.g. rendering the iframe).
        $request = $this->container->get('request_stack')->getCurrentRequest();
        if ($request) {
            $routeScope = $request->attributes->get('_route_scope', []);
            if (in_array('store-api', $routeScope, true)) {
                $redirectUrl = $transaction->getReturnUrl();
            }
        }

        if ($settings->getIntegration() == Integration::PAYMENT_PAGE) {
            $redirectUrl = $apiClient->getTransactionPaymentPageService()
                ->paymentPageUrl($settings->getSpaceId(), $createdTransaction->getId());
        }

        $this->upsert(
            $createdTransaction,
            $salesChannelContext->getContext(),
            $orderTransaction->getPaymentMethodId(),
            $orderTransaction->getOrder()->getSalesChannelId()
        );

        // The pending transaction has just been confirmed and is no longer reusable. Drop the
        // per-request checkout state and the filter memo so nothing downstream in this request
        // keeps filtering against the consumed transaction.
        $salesChannelContext->getContext()->removeExtension(self::CHECKOUT_STATE_EXTENSION);
        $salesChannelContext->getContext()->removeExtension(self::FILTER_MEMO_EXTENSION);

        // Drop the persisted record entirely. The transaction was just confirmed and can never be
        // reused as a pending one, so keeping its ID would only cost the next checkout a read API
        // call to rediscover that.
        $this->clearTransactionIdFromContext($salesChannelContext);

        $this->holdDelivery($orderTransaction->getOrder()->getId(), $salesChannelContext->getContext());

        return $redirectUrl;
    }

    /**
     * Creates the transaction in the portal using the SDK.
     *
     * @return void
     */
    public function createRecurringTransaction(TransactionCreate $sdkTransactionCreate, string $spaceId = ""): Transaction
    {
        $settings = $this->settingsService->getSettings();
        if (empty($spaceId)) {
            $spaceId = $settings->getSpaceId();
        }

        $apiClient = $settings->getApiClient();
        Analytics::addHeaders($apiClient, [
            Analytics::SUBSCRIPTION_TRANSACTION => true
        ]);

        $sdkTransaction = $apiClient->getTransactionService()->create($spaceId, $sdkTransactionCreate);
        if ($sdkTransaction->valid()) {
            return $apiClient->getTransactionService()->processWithoutUserInteraction($spaceId, $sdkTransaction->getId());
        }

        throw new \Exception("The transacion is not valid and could not be created.");
    }

    /**
     * @param \Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct $transaction
     * @param \Shopware\Core\Framework\Context $context
     * @param int $postfinancecheckoutTransactionId
     * @param int $spaceId
     */
    protected function addPostFinanceCheckoutTransactionId(
        PaymentTransactionStruct $transaction,
        Context                       $context,
        int                           $postfinancecheckoutTransactionId,
        int                           $spaceId,
        ?string                       $token = null
    ): void {
        $customFields = [
            TransactionPayload::ORDER_TRANSACTION_CUSTOM_FIELDS_POSTFINANCECHECKOUT_TRANSACTION_ID => $postfinancecheckoutTransactionId,
            TransactionPayload::ORDER_TRANSACTION_CUSTOM_FIELDS_POSTFINANCECHECKOUT_SPACE_ID => $spaceId,
        ];

        if ($token) {
            $customFields[TransactionPayload::ORDER_TRANSACTION_CUSTOM_FIELDS_POSTFINANCECHECKOUT_TOKEN] = $token;
        }

        $data = [
            'id' => $transaction->getOrderTransactionId(),
            'customFields' => $customFields,
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
        ?string     $paymentMethodId = null,
        ?string     $salesChannelId = null
    ): void {
        try {

            $transactionId = $transaction->getId();
            $transactionMetaData = $transaction->getMetaData();

            if (!$salesChannelId) {
                $salesChannelId = $transactionMetaData['salesChannelId'] ?? '';
            }

            $orderId = $transactionMetaData[TransactionPayload::POSTFINANCECHECKOUT_METADATA_ORDER_ID];
            $orderTransactionId = $transactionMetaData[TransactionPayload::POSTFINANCECHECKOUT_METADATA_ORDER_TRANSACTION_ID];

            if (!$paymentMethodId) {
                $criteria = new Criteria([$orderTransactionId]);
                $criteria->addAssociation('order');
                $orderTransaction = $this->container->get('order_transaction.repository')->search($criteria, $context)->first();
                $paymentMethodId = $orderTransaction->getPaymentMethodId();
            }

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
    public function getOrderEntity(string $orderId, Context $context): OrderEntity
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
    public function read(int $transactionId, string $salesChannelId = ""): Transaction
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
                    ->addAssociations(['refunds']),
                $context
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
                    ->addAssociations(['refunds']),
                $context
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
                (new Criteria())->addFilter(new EqualsFilter('transactionId', $transactionId)),
                $context
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
     *
     * @return int
     */
    public function createPendingTransaction(SalesChannelContext $salesChannelContext, $event = null): int
    {
        // Get transaction ID from the persisted pending transaction record.
        $transactionId = $this->getTransactionIdFromContext($salesChannelContext);
        $settings = $this->settingsService->getValidSettings($salesChannelContext->getSalesChannel()->getId());
        if (!$settings) {
            throw new \Exception('Space settings not configured');
        }

        if ($transactionId) {
            try {
                $transactionService = $settings->getApiClient()->getTransactionService();
                $pendingTransaction = $transactionService->read($settings->getSpaceId(), $transactionId);
                if ($pendingTransaction->getState() === TransactionState::PENDING) {
                    return $transactionId;
                }
            } catch (\Exception $e) {
                // Transaction may have been deleted, expired, or is invalid - fall through and create a new one.
            }
        }

        return $this->createTransaction($salesChannelContext, $event);
    }

    /**
     * Unconditionally creates a new pending transaction in the portal and persists its ID.
     *
     * Split out of createPendingTransaction() so callers that already validated the stored
     * transaction do not pay for a second lookup and a second read API call.
     *
     * @param SalesChannelContext $salesChannelContext
     * @param mixed $event Optional event/cart used to extract line items.
     * @param array|null $lineItems Pre-extracted line items. Passing them guarantees the created
     *                              transaction matches the fingerprint the caller calculated.
     * @return int The newly created transaction ID.
     * @throws \Exception If settings are not configured or there is no customer.
     */
    public function createTransaction(SalesChannelContext $salesChannelContext, $event = null, ?array $lineItems = null): int
    {
        $settings = $this->settingsService->getValidSettings($salesChannelContext->getSalesChannel()->getId());
        if (!$settings) {
            throw new \Exception('Space settings not configured');
        }

        $customer = $salesChannelContext->getCustomer();
        if ($customer === null) {
            throw new \Exception('Customer is required to create a transaction');
        }
        if ($lineItems === null) {
            $lineItems = $this->extractLineItems(
                $event,
                $salesChannelContext,
            );
        }

        /*
         * For guest checkouts, the customer ID is set to null rather than an empty string.
         * This ensures consistency with TransactionPayload which also uses null for guests,
         * preventing the Portal from treating the difference as an update/change in customer details.
         */
        $customerId = null;
        if ($customer->getGuest() === false) {
            $customerId = $customer->getCustomerNumber();
        }

        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
        $homeUrl = $protocol . $_SERVER['HTTP_HOST'];
        $currency = $salesChannelContext->getCurrency()->getIsoCode();

        $billingAddress = $this->buildAddress($salesChannelContext, $customer->getActiveBillingAddress());
        $shippingAddress = $this->buildAddress($salesChannelContext, $customer->getActiveShippingAddress());

        $language = $this->localeCodeProvider->getLocaleCodeFromContext($salesChannelContext->getContext());

        $transactionPayload = (new TransactionCreate())
            ->setBillingAddress($billingAddress)
            ->setShippingAddress($shippingAddress)
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

        if ($this->isSubscription($salesChannelContext)) {
            $transactionPayload->setTokenizationMode(TokenizationMode::FORCE_CREATION);
        }

        $transactionService = $settings->getApiClient()->getTransactionService();
        $transaction = $transactionService->create($settings->getSpaceId(), $transactionPayload);
        $transactionId = (int) $transaction->getId();

        // Persist the ID for reuse. The fingerprint is reset: the freshly created transaction
        // already carries the current payload, so the next pass must not skip an update based on a
        // fingerprint that belonged to the previous transaction.
        $this->storeTransactionIdInContext($salesChannelContext, $transactionId);

        return $transactionId;
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @param int $transactionId
     * @param array $lineItems
     * @param int|null $version Version of the transaction as already known by the caller. When given,
     *                          the redundant read API call is skipped.
     * @return void
     */
    public function updateTempTransaction(
        SalesChannelContext $salesChannelContext,
        int $transactionId,
        array $lineItems = [],
        ?int $version = null
    ): void {
        $pendingTransaction = new TransactionPending();
        $pendingTransaction->setId($transactionId);

        $settings = $this->settingsService->getValidSettings($salesChannelContext->getSalesChannel()->getId());
        if ($version === null) {
            $transaction = $settings->getApiClient()->getTransactionService()->read($settings->getSpaceId(), $transactionId);
            $version = $transaction->getVersion();
        }
        $pendingTransaction->setVersion($version);

        $currency = $salesChannelContext->getCurrency()->getIsoCode();

        $language = $this->localeCodeProvider->getLocaleCodeFromContext($salesChannelContext->getContext());

        $pendingTransaction->setCurrency($currency);
        $pendingTransaction->setLanguage($language);
        $billingAddress = $this->buildAddress($salesChannelContext, $salesChannelContext->getCustomer()->getActiveBillingAddress());
        $shippingAddress = $this->buildAddress($salesChannelContext, $salesChannelContext->getCustomer()->getActiveShippingAddress());

        $pendingTransaction->setBillingAddress($billingAddress);
        $pendingTransaction->setShippingAddress($shippingAddress);

        if (!empty($lineItems)) {
            $pendingTransaction->setLineItems($lineItems);
        }

        $settings->getApiClient()->getTransactionService()
            ->update($settings->getSpaceId(), $pendingTransaction);
    }

    /**
     * Builds a fingerprint of the payload updateTempTransaction() would send.
     *
     * Covers exactly the fields that are transmitted - currency, language, billing address,
     * shipping address and line items - so that an unchanged checkout produces an unchanged
     * fingerprint and the update API call can be skipped. Anything not sent to the portal is
     * deliberately excluded, so unrelated customer entity changes (last login, lazily loaded
     * associations) do not invalidate it.
     *
     * @param SalesChannelContext $salesChannelContext
     * @param array $lineItems Line items as they would be sent to the portal.
     * @param string|null $previousFingerprint Fingerprint of the previous update, used to carry over
     *                                         the line item part when the current list is empty.
     * @return string
     */
    public function buildTempTransactionFingerprint(
        SalesChannelContext $salesChannelContext,
        array $lineItems,
        ?string $previousFingerprint = null
    ): string {
        $customer = $salesChannelContext->getCustomer();
        $billingAddress = $customer?->getActiveBillingAddress();
        $shippingAddress = $customer?->getActiveShippingAddress();

        $parts = [
            (string) $salesChannelContext->getCurrency()->getIsoCode(),
            (string) $this->localeCodeProvider->getLocaleCodeFromContext($salesChannelContext->getContext()),
            $billingAddress === null
                ? 'no-billing-address'
                : $this->serializeForFingerprint($this->buildAddress($salesChannelContext, $billingAddress)),
            $shippingAddress === null
                ? 'no-shipping-address'
                : $this->serializeForFingerprint($this->buildAddress($salesChannelContext, $shippingAddress)),
        ];

        // updateTempTransaction() only transmits line items when it has some, so an empty list must
        // not invalidate the fingerprint - carry over the previous line item hash instead.
        $lineItemHash = empty($lineItems)
            ? ($this->extractLineItemFingerprintPart($previousFingerprint) ?? 'no-line-items')
            : \hash('sha256', $this->serializeForFingerprint($lineItems));

        return \hash('sha256', \implode('|', $parts)) . ':' . $lineItemHash;
    }

    /**
     * Returns the line item part of a fingerprint produced by buildTempTransactionFingerprint().
     *
     * @param string|null $fingerprint
     * @return string|null
     */
    private function extractLineItemFingerprintPart(?string $fingerprint): ?string
    {
        if ($fingerprint === null) {
            return null;
        }

        $separatorPosition = \strpos($fingerprint, ':');

        return $separatorPosition === false ? null : \substr($fingerprint, $separatorPosition + 1);
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
     * Extracts line items from the given source (Event or Cart) and appends shipping costs.
     *
     * @param mixed $source
     * @param SalesChannelContext|null $salesChannelContext
     * @return array
     */
    public function extractLineItems(
        $source,
        ?SalesChannelContext $salesChannelContext = null,
    ): array {
        $lineItems = [];
        if ($source) {
            if ($source instanceof CheckoutConfirmPageLoadedEvent) {
                $cartLineItems = $source->getPage()->getCart()->getLineItems()->getElements();
                foreach ($cartLineItems as $cartLineItem) {
                    if ($cartLineItem->getType() === CustomProductsLineItemTypes::LINE_ITEM_TYPE_CUSTOMIZED_PRODUCTS) {
                        continue;
                    }
                    $lineItems[] = $this->createTempLineItem($cartLineItem);
                }
            } elseif ($source instanceof AccountEditOrderPageLoadedEvent) {
                $order = $source->getPage()->getOrder();
                foreach ($order->getLineItems() as $orderLineItem) {
                    $lineItems[] = $this->createTempLineItem($orderLineItem);
                }
            } elseif ($source instanceof \Shopware\Core\Checkout\Cart\Cart) {
                $cartLineItems = $source->getLineItems()->getElements();
                foreach ($cartLineItems as $cartLineItem) {
                    if ($cartLineItem->getType() === CustomProductsLineItemTypes::LINE_ITEM_TYPE_CUSTOMIZED_PRODUCTS) {
                        continue;
                    }
                    $lineItems[] = $this->createTempLineItem($cartLineItem);
                }
            }

            // Extract and append shipping costs as a line item if applicable.
            $shippingCosts = null;
            $taxStatus = 'gross';

            if ($source instanceof CheckoutConfirmPageLoadedEvent) {
                $cart = $source->getPage()->getCart();
                $shippingCosts = $cart->getDeliveries()->getShippingCosts();
                if ($salesChannelContext !== null) {
                    $taxStatus = $salesChannelContext->getTaxState();
                }
            } elseif ($source instanceof AccountEditOrderPageLoadedEvent) {
                $order = $source->getPage()->getOrder();
                $shippingCosts = $order->getShippingCosts();
                $taxStatus = $order->getTaxStatus();
            } elseif ($source instanceof \Shopware\Core\Checkout\Cart\Cart) {
                $shippingCosts = $source->getDeliveries()->getShippingCosts();
                if ($salesChannelContext !== null) {
                    $taxStatus = $salesChannelContext->getTaxState();
                }
            }

            if ($shippingCosts !== null) {
                $shippingLineItem = $this->extractShippingLineItem(
                    $shippingCosts,
                    $taxStatus,
                    $salesChannelContext,
                );
                if ($shippingLineItem !== null) {
                    $lineItems[] = $shippingLineItem;
                }
            }
        }
        return $lineItems;
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

    private function createTempLineItem($productData): LineItemCreate
    {
        $lineItem = new LineItemCreate();

        $price = $productData->getPrice();
        $unit = $price->getUnitPrice();

        // Expects discounts as separate items, avoid negative prices
        if ($unit < 0) {
            return $this->mapDiscountLineItem($productData);
        }

        if ($productData instanceof LineItem) {
            $lineItem->setName($this->fixLength($productData->getLabel(), PayloadLimits::LINE_ITEM_NAME));
            $lineItem->setUniqueId($this->fixLength($productData->getId(), PayloadLimits::LINE_ITEM_UNIQUE_ID));
            $lineItem->setSku($this->fixLength($productData->getReferencedId() ?? $productData->getId(), PayloadLimits::LINE_ITEM_SKU));
            $lineItem->setQuantity($productData->getQuantity());
            $lineItem->setAmountIncludingTax($this->round($unit));
        } elseif ($productData instanceof OrderLineItemEntity) {
            $lineItem->setName($this->fixLength($productData->getLabel(), PayloadLimits::LINE_ITEM_NAME));
            $lineItem->setUniqueId($this->fixLength($productData->getId(), PayloadLimits::LINE_ITEM_UNIQUE_ID));
            $lineItem->setSku($this->fixLength($productData->getProductId() ?? $productData->getIdentifier() ?? $productData->getId(), PayloadLimits::LINE_ITEM_SKU));
            $lineItem->setQuantity($productData->getQuantity());
            $lineItem->setAmountIncludingTax($this->round($unit));
        } else {
            throw new \InvalidArgumentException('Unsupported line item type: ' . get_class($productData));
        }

        $lineItem->setType(LineItemType::PRODUCT);

        return $lineItem;
    }

    /**
     * Build a PostFinanceCheckout address from Shopware customer address.
     *
     * @param \Shopware\Core\System\SalesChannel\SalesChannelContext $salesChannelContext
     * @param \Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity $addressEntity
     * @return \PostFinanceCheckout\Sdk\Model\AddressCreate
     */
    private function buildAddress(
        SalesChannelContext $salesChannelContext,
        \Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity $addressEntity
    ): AddressCreate {
        $customer = $salesChannelContext->getCustomer();

        $address = new AddressCreate();

        $address->setFamilyName($addressEntity->getLastName() ?: $customer->getLastName() ?: '');
        $address->setGivenName($addressEntity->getFirstName() ?: $customer->getFirstName() ?: '');
        $address->setOrganizationName($addressEntity->getCompany());
        $address->setPhoneNumber($addressEntity->getPhoneNumber());
        $address->setCountry($addressEntity->getCountry()->getIso());
        $address->setCity($addressEntity->getCity() ?: '');

        $postalState = $addressEntity?->getCountryState()?->getName()
            ?: $addressEntity?->getCountryState()?->getShortCode()
            ?: '';
        $address->setPostalState($postalState);

        $address->setPostCode($addressEntity->getZipcode());
        $address->setStreet($addressEntity->getStreet());
        $address->setEmailAddress($customer->getEmail());

        if (!empty($customer->getBirthday())) {
            $birthday = (new \DateTimeImmutable())
                ->setTimestamp($customer->getBirthday()->getTimestamp())
                ->format('Y-m-d');
            $address->setDateOfBirth($birthday);
        }

        $salutationEntity = $addressEntity->getSalutation() ?: $customer->getSalutation();
        $address->setSalutation($salutationEntity?->getDisplayName() ?? '');
        $address->setGender(
            strtolower($salutationEntity?->getSalutationKey() ?? '') === 'mr'
                ? Gender::MALE
                : Gender::FEMALE
        );

        return $address;
    }

    /**
     * Checks if it's subscription context.
     *
     * @param \Shopware\Core\System\SalesChannel\SalesChannelContext $salesChannelContext
     * @return bool
     */
    private function isSubscription(SalesChannelContext $salesChannelContext): bool
    {
        $extensionName = 'subscription';
        if (class_exists(\Shopware\Commercial\Subscription\Framework\Struct\SubscriptionContextStruct::class)) {
            $extensionName = SubscriptionContextStruct::SUBSCRIPTION_EXTENSION;
        }
        if ($salesChannelContext->hasExtension($extensionName)) {
            return true;
        }
        return false;
    }

    /**
     * @param     $amount
     * @param int $precision
     *
     * @return float
     */
    private function round($value, $precision = 2): float
    {
        return \round($value, $precision);
    }

    /**
     * Generates a cache key for the pending transaction record.
     *
     * The key is scoped by sales channel *and* customer. The previous implementation keyed on the
     * customer alone, which made the same customer share one pending transaction across sales
     * channels.
     *
     * Note this still does not separate the cart checkout from the "edit order" flow: the order
     * context assembled by OrderConverter carries the same sales channel and customer, so both
     * flows resolve to this key and the later one rewrites the transaction. Scoping by context
     * token instead is not an option, because OrderConverter mints a random token per page load,
     * which would create a new portal transaction on every view of the edit order page.
     *
     * @param SalesChannelContext $salesChannelContext
     * @return string|null Null when there is no customer (no transaction can be created either).
     */
    private function getPendingTransactionCacheKey(SalesChannelContext $salesChannelContext): ?string
    {
        $customer = $salesChannelContext->getCustomer();
        if (!$customer) {
            return null;
        }

        return self::PENDING_TRANSACTION_CACHE_PREFIX
            . $salesChannelContext->getSalesChannelId() . '_' . $customer->getId();
    }

    /**
     * Generates a session key for the transaction ID.
     *
     * @param SalesChannelContext $salesChannelContext
     * @return string|null
     */
    public function getSessionTransactionKey(SalesChannelContext $salesChannelContext): ?string
    {
        $customer = $salesChannelContext->getCustomer();
        if (!$customer) {
            return null;
        }

        return sprintf(
            'pfcn_transaction_id_%s_%s',
            $salesChannelContext->getSalesChannelId(),
            $customer->getId()
        );
    }

    /**
     * Reads the persisted pending transaction record.
     *
     * @param SalesChannelContext $salesChannelContext
     * @return array{transactionId: int, fingerprint: string|null}|null
     */
    private function readPendingTransactionRecord(SalesChannelContext $salesChannelContext): ?array
    {
        $cacheKey = $this->getPendingTransactionCacheKey($salesChannelContext);
        if (!$cacheKey) {
            return null;
        }

        $item = $this->cache->getItem($cacheKey);
        if (!$item->isHit()) {
            return null;
        }

        $value = $item->get();
        if (!\is_array($value) || empty($value['transactionId'])) {
            return null;
        }

        return [
            'transactionId' => (int) $value['transactionId'],
            'fingerprint'   => isset($value['fingerprint']) ? (string) $value['fingerprint'] : null,
        ];
    }

    /**
     * Persists the pending transaction record.
     *
     * @param SalesChannelContext $salesChannelContext
     * @param array{transactionId: int, fingerprint: string|null} $record
     */
    private function writePendingTransactionRecord(SalesChannelContext $salesChannelContext, array $record): void
    {
        $cacheKey = $this->getPendingTransactionCacheKey($salesChannelContext);
        if (!$cacheKey) {
            return;
        }

        $item = $this->cache->getItem($cacheKey);
        $item->set($record);
        // Expire after 2 hours to avoid stale data (matching typical cart lifetime).
        $item->expiresAfter(self::PENDING_TRANSACTION_CACHE_TTL);
        $this->cache->save($item);
    }

    /**
     * Retrieves the stored pending transaction ID.
     *
     * @param SalesChannelContext $salesChannelContext
     * @return int|null The transaction ID if found, otherwise null.
     */
    public function getTransactionIdFromContext(SalesChannelContext $salesChannelContext): ?int
    {
        $record = $this->readPendingTransactionRecord($salesChannelContext);

        return $record === null ? null : $record['transactionId'];
    }

    /**
     * Returns the fingerprint of the payload last sent to the portal for the given transaction.
     *
     * Deliberately returns null when the persisted record points at a *different* transaction, so a
     * fingerprint can never be applied to a transaction it was not calculated for.
     *
     * @param SalesChannelContext $salesChannelContext
     * @param int $transactionId
     * @return string|null
     */
    public function getPendingTransactionFingerprint(SalesChannelContext $salesChannelContext, int $transactionId): ?string
    {
        $record = $this->readPendingTransactionRecord($salesChannelContext);
        if ($record === null || $record['transactionId'] !== $transactionId) {
            return null;
        }

        return $record['fingerprint'];
    }

    /**
     * Stores the fingerprint of the payload that was just sent to the portal.
     *
     * @param SalesChannelContext $salesChannelContext
     * @param int $transactionId
     * @param string $fingerprint
     */
    public function storePendingTransactionFingerprint(
        SalesChannelContext $salesChannelContext,
        int $transactionId,
        string $fingerprint
    ): void {
        $this->writePendingTransactionRecord(
            $salesChannelContext,
            ['transactionId' => $transactionId, 'fingerprint' => $fingerprint]
        );
    }

    /**
     * Clears the pending transaction record.
     *
     * @param SalesChannelContext $salesChannelContext
     */
    public function clearTransactionIdFromContext(SalesChannelContext $salesChannelContext): void
    {
        $cacheKey = $this->getPendingTransactionCacheKey($salesChannelContext);
        if ($cacheKey) {
            $this->cache->deleteItem($cacheKey);
        }
    }

    /**
     * Stores the pending transaction ID, discarding any fingerprint that belonged to a previous
     * transaction.
     *
     * @param SalesChannelContext $salesChannelContext
     * @param int $transactionId
     */
    private function storeTransactionIdInContext(SalesChannelContext $salesChannelContext, int $transactionId): void
    {
        $this->writePendingTransactionRecord(
            $salesChannelContext,
            ['transactionId' => $transactionId, 'fingerprint' => null]
        );
    }

    /**
     * Creates a discount line item for negative-priced cart entries.
     *
     * @param $productData
     * @return LineItemCreate
     *
     */
    private function mapDiscountLineItem($productData): LineItemCreate
    {
        $price = $productData->getPrice();

        $lineItem = new LineItemCreate();

        $amount = abs($price->getTotalPrice());

        $lineItem->setName($this->fixLength($productData->getLabel() ?: 'Discount', PayloadLimits::LINE_ITEM_NAME));
        $lineItem->setUniqueId($this->fixLength('discount-' . $productData->getId(), PayloadLimits::LINE_ITEM_UNIQUE_ID));
        $lineItem->setSku('discount');
        $lineItem->setQuantity(1);
        $lineItem->setAmountIncludingTax($this->round($amount));
        $lineItem->setType(LineItemType::DISCOUNT);

        return $lineItem;
    }

    /**
     * Extracts shipping line item from cart/order shipping costs.
     *
     * @param mixed $shippingCosts
     * @param string $taxStatus
     * @param SalesChannelContext|null $salesChannelContext
     * @return LineItemCreate|null
     */
    private function extractShippingLineItem(
        $shippingCosts,
        string $taxStatus,
        ?SalesChannelContext $salesChannelContext = null,
    ): ?LineItemCreate {
        // When shipping costs are extracted from a Cart, they are returned as a PriceCollection
        // containing multiple CalculatedPrice items. We must sum them to get a single aggregated price.
        if ($shippingCosts instanceof \Shopware\Core\Checkout\Cart\Price\Struct\PriceCollection) {
            $shippingCosts = $shippingCosts->sum();
        }

        if (!$shippingCosts instanceof \Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice) {
            return null;
        }

        if ($shippingCosts->getTotalPrice() <= 0) {
            return null;
        }

        $amount = $shippingCosts->getTotalPrice();
        if ($taxStatus === 'net') {
            $amount += $shippingCosts->getCalculatedTaxes()->getAmount();
        }
        $roundedAmount = $this->round($amount);

        $shippingMethodName = $salesChannelContext?->getShippingMethod()?->getName();
        $translator = $this->container->has('translator') ? $this->container->get('translator') : null;
        $fallbackName = $translator ? $translator->trans('postfinancecheckout.payload.shipping.name') : 'Shipping';
        $shippingName = $shippingMethodName ?? $fallbackName;

        $shippingLineItem = new LineItemCreate();
        $shippingLineItem->setAmountIncludingTax($roundedAmount)
            ->setName($this->fixLength($shippingName . ' ' . ($translator ? $translator->trans('postfinancecheckout.payload.shipping.lineItem') : 'Shipping'), PayloadLimits::LINE_ITEM_NAME))
            ->setQuantity($shippingCosts->getQuantity() ?? 1)
            ->setSku($this->fixLength($shippingName . '-Shipping', PayloadLimits::LINE_ITEM_SKU))
            ->setType(LineItemType::SHIPPING)
            ->setUniqueId($this->fixLength($shippingName . '-Shipping', PayloadLimits::LINE_ITEM_UNIQUE_ID));

        if ($taxStatus !== 'tax-free') {
            $taxes = [];
            foreach ($shippingCosts->getCalculatedTaxes() as $calculatedTax) {
                $tax = (new TaxCreate())
                    ->setRate($calculatedTax->getTaxRate())
                    ->setTitle($this->fixLength($shippingName . ' : ' . $calculatedTax->getTaxRate(), PayloadLimits::TAX_TITLE));
                $taxes[] = $tax;
            }
            $shippingLineItem->setTaxes($taxes);
        }

        return $shippingLineItem;
    }

    /**
     * Fix string length to specific length.
     *
     * @param string|null $string
     * @param int $maxLength
     * @return string
     */
    private function fixLength(?string $string, int $maxLength): string
    {
        return PayloadLimits::fixLength($string, $maxLength);
    }
}
