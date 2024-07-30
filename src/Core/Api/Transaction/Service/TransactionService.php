<?php declare(strict_types=1);

namespace WeArePlanetPayment\Core\Api\Transaction\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\{Checkout\Cart\Exception\OrderNotFoundException,
    Checkout\Cart\LineItem\LineItem,
    Checkout\Order\OrderEntity,
    Checkout\Payment\Cart\AsyncPaymentTransactionStruct,
    Framework\Context,
    Framework\DataAbstractionLayer\Search\Criteria,
    Framework\DataAbstractionLayer\Search\Filter\EqualsFilter,
    System\SalesChannel\SalesChannelContext
};
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use WeArePlanet\Sdk\{Model\AddressCreate,
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
use WeArePlanetPayment\Core\{Api\OrderDeliveryState\Handler\OrderDeliveryStateHandler,
    Api\Refund\Entity\RefundEntityCollection,
    Api\Refund\Entity\RefundEntityDefinition,
    Api\Transaction\Entity\TransactionEntity,
    Api\Transaction\Entity\TransactionEntityDefinition,
    Settings\Options\Integration,
    Settings\Service\SettingsService,
    Util\LocaleCodeProvider,
    Util\Payload\CustomProducts\CustomProductsLineItemTypes,
    Util\Payload\TransactionPayload
};

/**
 * Class TransactionService
 *
 * @package WeArePlanetPayment\Core\Api\Transaction\Service
 */
class TransactionService
{
    /**
     * @var \Psr\Container\ContainerInterface
     */
    protected $container;

    /**
     * @var \WeArePlanetPayment\Core\Util\LocaleCodeProvider
     */
    private $localeCodeProvider;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @var \WeArePlanetPayment\Core\Settings\Service\SettingsService
     */
    private $settingsService;

    const CARD_HOLDER_KEY = '1456765000789';
    const PSEUDO_CODE_KEY = '1485172176673';
    const CARD_VALIDITY_KEY = '1456765711187';
    const PAY_ID_KEY = '1484042941549';
    const ADDITIONAL_TRANSACTION_DETAILS_ORDER_ID_KEY = '1464680013786';

    /**
     * TransactionService constructor.
     *
     * @param \Psr\Container\ContainerInterface $container
     * @param \WeArePlanetPayment\Core\Util\LocaleCodeProvider $localeCodeProvider
     * @param \WeArePlanetPayment\Core\Settings\Service\SettingsService $settingsService
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
     * @throws \WeArePlanet\Sdk\ApiException
     * @throws \WeArePlanet\Sdk\Http\ConnectionException
     * @throws \WeArePlanet\Sdk\VersioningException
     */
    public function create(
        AsyncPaymentTransactionStruct $transaction,
        SalesChannelContext           $salesChannelContext
    ): string
    {
        $salesChannelId = $salesChannelContext->getSalesChannel()->getId();
        $settings = $this->settingsService->getSettings($salesChannelId);
        $apiClient = $settings->getApiClient();

        $failedStates = [
            TransactionState::DECLINE,
            TransactionState::FAILED,
            TransactionState::VOIDED,
        ];
        $pendingTransaction = $this->read($_SESSION['transactionId'], $salesChannelId);
        if (in_array($pendingTransaction->getState(), $failedStates)) {
            unset($_SESSION['transactionId']);
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
        $transactionPayload = $transactionPayloadClass->get($pendingTransaction->getVersion());

        $createdTransaction = $apiClient->getTransactionService()
            ->confirm($settings->getSpaceId(), $transactionPayload);

        $this->addWeArePlanetTransactionId(
            $transaction,
            $salesChannelContext->getContext(),
            $createdTransaction->getId(),
            $settings->getSpaceId()
        );

        $redirectUrl = $this->container->get('router')->generate(
            'frontend.weareplanet.checkout.pay',
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
        $_SESSION['transactionId'] = null;
        $_SESSION['arrayOfPossibleMethods'] = null;
        $_SESSION['addressCheck'] = null;
        $_SESSION['currencyCheck'] = null;


        $this->holdDelivery($transaction->getOrder()->getId(), $salesChannelContext->getContext());

        return $redirectUrl;
    }

    /**
     * @param \Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct $transaction
     * @param \Shopware\Core\Framework\Context $context
     * @param int $weareplanetTransactionId
     * @param int $spaceId
     */
    protected function addWeArePlanetTransactionId(
        AsyncPaymentTransactionStruct $transaction,
        Context                       $context,
        int                           $weareplanetTransactionId,
        int                           $spaceId
    ): void
    {
        $data = [
            'id' => $transaction->getOrderTransaction()->getId(),
            'customFields' => [
                TransactionPayload::ORDER_TRANSACTION_CUSTOM_FIELDS_WEAREPLANET_TRANSACTION_ID => $weareplanetTransactionId,
                TransactionPayload::ORDER_TRANSACTION_CUSTOM_FIELDS_WEAREPLANET_SPACE_ID => $spaceId,
            ],
        ];
        $this->container->get('order_transaction.repository')->update([$data], $context);
    }

    /**
     * Persist WeArePlanet transaction
     *
     * @param \WeArePlanet\Sdk\Model\Transaction $transaction
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

            $orderId = $transactionMetaData[TransactionPayload::WEAREPLANET_METADATA_ORDER_ID];
            $orderTransactionId = $transactionMetaData[TransactionPayload::WEAREPLANET_METADATA_ORDER_TRANSACTION_ID];

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

                $dataParamValue['customerName'] = isset($transactionMetaData[TransactionPayload::WEAREPLANET_METADATA_CUSTOMER_NAME])
                    ? $transactionMetaData[TransactionPayload::WEAREPLANET_METADATA_CUSTOMER_NAME]
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
                throw new OrderNotFoundException($orderId);
            }
            return $order;
        } catch (\Exception $e) {
            throw new OrderNotFoundException($orderId);
        }

    }

    /**
     * Get transaction entity by orderId
     *
     * @param string $orderId
     * @param \Shopware\Core\Framework\Context $context
     *
     * @return \WeArePlanetPayment\Core\Api\Transaction\Entity\TransactionEntity
     */
    public function getByOrderId(string $orderId, Context $context): TransactionEntity
    {
        return $this->container->get(TransactionEntityDefinition::ENTITY_NAME . '.repository')
            ->search(new Criteria([$orderId]), $context)
            ->get($orderId);
    }

    /**
     * Read transaction from WeArePlanet API
     *
     * @param int $transactionId
     * @param string $salesChannelId
     *
     * @return \WeArePlanet\Sdk\Model\Transaction
     * @throws \WeArePlanet\Sdk\ApiException
     * @throws \WeArePlanet\Sdk\Http\ConnectionException
     * @throws \WeArePlanet\Sdk\VersioningException
     */
    public function read(int $transactionId, string $salesChannelId): Transaction
    {
        $settings = $this->settingsService->getSettings($salesChannelId);
        return $settings->getApiClient()->getTransactionService()->read($settings->getSpaceId(), $transactionId);
    }

    /**
     * Get transaction entity by WeArePlanet transaction id
     *
     * @param int $transactionId
     * @param \Shopware\Core\Framework\Context $context
     *
     * @return \WeArePlanetPayment\Core\Api\Transaction\Entity\TransactionEntity|null
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
     * Get transaction entity by WeArePlanet order transaction id
     *
     * @param string $transactionId
     * @param \Shopware\Core\Framework\Context $context
     *
     * @return \WeArePlanetPayment\Core\Api\Transaction\Entity\TransactionEntity|null
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
     * Get transaction entity by WeArePlanet transaction id
     *
     * @param int $transactionId
     * @param \Shopware\Core\Framework\Context $context
     *
     * @return \WeArePlanetPayment\Core\Api\Refund\Entity\RefundEntityCollection
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
     * @param CheckoutConfirmPageLoadedEvent|null $event
     * @return int
     */
    public function createPendingTransaction(SalesChannelContext $salesChannelContext, ?CheckoutConfirmPageLoadedEvent $event = null): int
    {
        $expiredTransaction = true;
        $transactionId = $_SESSION['transactionId'] ?? null;
        $settings = $this->settingsService->getValidSettings($salesChannelContext->getSalesChannel()->getId());

        if ($transactionId) {
            $transactionService = $settings->getApiClient()->getTransactionService();
            $pendingTransaction = $transactionService->read($settings->getSpaceId(), $transactionId);
            $failedStates = [
                TransactionState::DECLINE,
                TransactionState::FAILED,
                TransactionState::VOIDED,
            ];
            if (!in_array($pendingTransaction->getState(), $failedStates)) {
                $expiredTransaction = false;
            }
        }

        if (!$transactionId || $expiredTransaction) {
            $settings = $this->settingsService->getValidSettings($salesChannelContext->getSalesChannel()->getId());

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
                $cartLineItems = $event->getPage()->getCart()->getLineItems()->getElements();
                foreach ($cartLineItems as $cartLineItem) {
                    if ($cartLineItem->getType() === CustomProductsLineItemTypes::LINE_ITEM_TYPE_CUSTOMIZED_PRODUCTS) {
                        continue;
                    }
                    $lineItems[] = $this->createTempLineItem($cartLineItem);
                }
            }

            $customerId = "";
            if ($customer->getGuest() === false) {
                $customerId = $customer->getCustomerNumber();
            }

            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
            $homeUrl = $protocol . $_SERVER['HTTP_HOST'];
            $currency = $salesChannelContext->getCurrency()->getIsoCode();
            $transactionPayload = (new TransactionCreate())
                ->setBillingAddress($billingAddress)
                ->setLineItems($lineItems)
                ->setCurrency($currency)
                ->setSpaceViewId($settings->getSpaceViewId())
                ->setAutoConfirmationEnabled(false)
                ->setChargeRetryEnabled(false)
                ->setCustomerEmailAddress($customer->getEmail())
                ->setCustomerId($customerId)
                ->setSuccessUrl($homeUrl . '?success')
                ->setFailedUrl($homeUrl . '?fail');

            $transactionService = $settings->getApiClient()->getTransactionService();
            $transaction = $transactionService->create($settings->getSpaceId(), $transactionPayload);
            $transactionId = $transaction->getId();
            $_SESSION['transactionId'] = $transactionId;
        }

        return $transactionId;
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @param int $transactionId
     * @return void
     */
    public function updateTempTransaction(SalesChannelContext $salesChannelContext, int $transactionId): void
    {
        $pendingTransaction = new TransactionPending();
        $pendingTransaction->setId($transactionId);

        $settings = $this->settingsService->getValidSettings($salesChannelContext->getSalesChannel()->getId());
        $transaction = $settings->getApiClient()->getTransactionService()->read($settings->getSpaceId(), $transactionId);
        $pendingTransaction->setVersion($transaction->getVersion());

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

        $currency = $salesChannelContext->getCurrency()->getIsoCode();
        $pendingTransaction->setCurrency($currency);
        $pendingTransaction->setBillingAddress($billingAddress);

        $settings->getApiClient()->getTransactionService()
            ->update($settings->getSpaceId(), $pendingTransaction);
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
    private function createTempLineItem(LineItem $productData): LineItemCreate
    {
        $lineItem = new LineItemCreate();
        $lineItem->setName($productData->getLabel());
        $lineItem->setUniqueId($productData->getId());
        $lineItem->setSku($productData->getId());
        $lineItem->setQuantity($productData->getQuantity());
        $lineItem->setAmountIncludingTax($productData->getPrice()->getUnitPrice());
        $lineItem->setType(LineItemType::PRODUCT);

        return $lineItem;
    }
}
