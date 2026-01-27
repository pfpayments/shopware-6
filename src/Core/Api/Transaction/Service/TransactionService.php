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
    Util\Payload\TransactionPayload,
    Util\Analytics\Analytics
};
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Framework\Struct\ArrayEntity;
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
            $pendingTransactionId = $this->createPendingTransaction($salesChannelContext);
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

        $salesChannelContext->getContext()->addExtension(
            'checkoutState',
            new ArrayEntity([
                'transactionId' => null,
                'addressHash'   => null,
                'currency'      => null,
            ])
        );

        $salesChannelContext->getContext()->addExtension(
            'possibleMethods',
            new ArrayEntity(['ids' => []])
        );


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
        string      $paymentMethodId = null,
        string      $salesChannelId = null
    ): void {
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
        $expiredTransaction = true;
        // Get transaction ID from cache (headless) or session (storefront).
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
                    $expiredTransaction = false;
                }
            } catch (\Exception $e) {
                // Transaction may have been deleted, expired, or is invalid - treat as expired.
                $expiredTransaction = true;
            }
        }

        if (!$transactionId || $expiredTransaction) {
            $settings = $this->settingsService->getValidSettings($salesChannelContext->getSalesChannel()->getId());

            $customer = $salesChannelContext->getCustomer();
            if ($customer === null) {
                throw new \Exception('Customer is required to create a transaction');
            }
            $lineItems = $this->extractLineItems($event);

            $customerId = "";
            if ($customer->getGuest() === false) {
                $customerId = $customer->getCustomerNumber();
            }

            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
            $homeUrl = $protocol . $_SERVER['HTTP_HOST'];
            $currency = $salesChannelContext->getCurrency()->getIsoCode();

            $billingAddress = $this->buildAddress($salesChannelContext, $customer->getActiveBillingAddress());
            $shippingAddress = $this->buildAddress($salesChannelContext, $customer->getActiveShippingAddress());

            if (!$settings) {
                throw new \Exception('Space settings not configured');
            }

            $transactionPayload = (new TransactionCreate())
                ->setBillingAddress($billingAddress)
                ->setShippingAddress($shippingAddress)
                ->setLineItems($lineItems)
                ->setCurrency($currency)
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
            $transactionId = $transaction->getId();

            // Store in cache and session for transaction reuse.
            $this->storeTransactionIdInContext($salesChannelContext, $transactionId);
        }

        return $transactionId;
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @param int $transactionId
     * @return void
     */
    public function updateTempTransaction(SalesChannelContext $salesChannelContext, int $transactionId, array $lineItems = []): void
    {
        $pendingTransaction = new TransactionPending();
        $pendingTransaction->setId($transactionId);

        $settings = $this->settingsService->getValidSettings($salesChannelContext->getSalesChannel()->getId());
        $transaction = $settings->getApiClient()->getTransactionService()->read($settings->getSpaceId(), $transactionId);
        $pendingTransaction->setVersion($transaction->getVersion());

        $currency = $salesChannelContext->getCurrency()->getIsoCode();

        $pendingTransaction->setCurrency($currency);
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
     * Extracts line items from the given source (Event or Cart).
     *
     * @param mixed $source
     * @return array
     */
    public function extractLineItems($source): array
    {
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

        $roundedPrice = $this->round($productData->getPrice()->getUnitPrice());

        if ($productData instanceof LineItem) {
            $lineItem->setName($productData->getLabel());
            $lineItem->setUniqueId($productData->getId());
            $lineItem->setSku($productData->getReferencedId() ?? $productData->getId());
            $lineItem->setQuantity($productData->getQuantity());
            $lineItem->setAmountIncludingTax($roundedPrice);
        } elseif ($productData instanceof OrderLineItemEntity) {
            $lineItem->setName($productData->getLabel());
            $lineItem->setUniqueId($productData->getId());
            $lineItem->setSku($productData->getProductId() ?? $productData->getIdentifier() ?? $productData->getId());
            $lineItem->setQuantity($productData->getQuantity());
            $lineItem->setAmountIncludingTax($roundedPrice);
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
     * Generates a cache key for the pending transaction ID.
     * Uses customer ID for authenticated users, which works for both headless and storefront.
     *
     * @param SalesChannelContext $salesChannelContext
     * @return string|null
     */
    private function getPendingTransactionCacheKey(SalesChannelContext $salesChannelContext): ?string
    {
        $customer = $salesChannelContext->getCustomer();
        if ($customer) {
            return 'pfcn_pending_transaction_id_customer_' . $customer->getId();
        }
        return null;
    }

    /**
     * Retrieves the stored pending transaction ID from cache or session.
     * Uses customer ID as cache key for headless (stateless) support.
     * Falls back to session for Storefront (stateful) compatibility.
     *
     * @param SalesChannelContext $salesChannelContext
     * @return int|null The transaction ID if found, otherwise null.
     */
    private function getTransactionIdFromContext(SalesChannelContext $salesChannelContext): ?int
    {
        // Try cache first (for headless/API where session might not persist or be shared).
        $cacheKey = $this->getPendingTransactionCacheKey($salesChannelContext);
        if ($cacheKey) {
            $item = $this->cache->getItem($cacheKey);
            if ($item->isHit()) {
                return (int) $item->get();
            }
        }

        // Fallback to PHP session for traditional Storefront compatibility.
        if (isset($_SESSION['transactionId'])) {
            return (int) $_SESSION['transactionId'];
        }

        return null;
    }

    /**
     * Clears the pending transaction ID from cache and session.
     *
     * @param SalesChannelContext $salesChannelContext
     */
    private function clearTransactionIdFromContext(SalesChannelContext $salesChannelContext): void
    {
        // Clear from cache key.
        $cacheKey = $this->getPendingTransactionCacheKey($salesChannelContext);
        if ($cacheKey) {
            $this->cache->deleteItem($cacheKey);
        }

        // Clear from session.
        if (isset($_SESSION['transactionId'])) {
            unset($_SESSION['transactionId']);
        }
    }

    /**
     * Stores the pending transaction ID in cache and session.
     * This persists in the database (via cache) and works across all request types (Storefront & headless).
     *
     * @param SalesChannelContext $salesChannelContext
     * @param int $transactionId
     */
    private function storeTransactionIdInContext(SalesChannelContext $salesChannelContext, int $transactionId): void
    {
        // Store in cache for headless.
        $cacheKey = $this->getPendingTransactionCacheKey($salesChannelContext);
        if ($cacheKey) {
            $item = $this->cache->getItem($cacheKey);
            $item->set($transactionId);
            // Expire after 2 hours to avoid stale data (matching typical cart lifetime).
            $item->expiresAfter(7200);
            $this->cache->save($item);
        }

        // Store in session for Storefront.
        $_SESSION['transactionId'] = $transactionId;
    }
}
