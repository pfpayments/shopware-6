<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Checkout\PaymentHandler;

use Psr\Log\LoggerInterface;
use Shopware\Core\{
    Checkout\Order\OrderEntity,
    Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity,
    Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler,
    Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates,
    Checkout\Payment\Cart\PaymentTransactionStruct,
    Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler,
    Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType,
    Checkout\Payment\PaymentException,
    Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException,
    Framework\App\AppException,
    Framework\Api\Context\SalesChannelApiSource,
    Framework\Context,
    Framework\DataAbstractionLayer\EntityRepository,
    Framework\DataAbstractionLayer\Search\Criteria,
    Framework\DataAbstractionLayer\Search\Filter\EqualsFilter,
    Framework\DataAbstractionLayer\Search\Sorting\FieldSorting,
    Framework\Struct\Struct,
    Framework\Validation\DataBag\RequestDataBag,
    System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity,
    System\SalesChannel\Context\SalesChannelContextService,
    System\SalesChannel\Context\SalesChannelContextServiceParameters
};
use Shopware\Core\Framework\Util\Random;
use PostFinanceCheckoutPayment\Core\Checkout\Cart\CustomCartPersister;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartPersister;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

use Symfony\Component\{
    HttpFoundation\RedirectResponse,
    HttpFoundation\Request
};
use PostFinanceCheckout\Sdk\Model\TransactionState;
use PostFinanceCheckoutPayment\Core\Api\Transaction\Service\TransactionService as PluginTransactionService;
use PostFinanceCheckoutPayment\Core\Util\Payload\TransactionPayload;



/**
 * Class PostFinanceCheckoutPaymentHandler
 *
 * @package PostFinanceCheckoutPayment\Core\Checkout\PaymentHandler
 */
class PostFinanceCheckoutPaymentHandler extends AbstractPaymentHandler
{

    /**
     * @var CustomCartPersister
     */
    private CustomCartPersister $cartPersister;

    /**
     * @var \PostFinanceCheckoutPayment\Core\Api\Transaction\Service\PluginTransactionService
     */
    protected $pluginTransactionService;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;
    /**
     * @var \Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler
     */
    private $orderTransactionStateHandler;

    protected SalesChannelContextService $salesChannelContextService;

    protected EntityRepository $orderTransactionRepository;

    protected ?EntityRepository $subscriptionRepository;

    /**
     * PostFinanceCheckoutPaymentHandler constructor.
     */
    public function __construct(
        CustomCartPersister $cartPersister,
        PluginTransactionService $pluginTransactionService,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        SalesChannelContextService $salesChannelContextService,
        EntityRepository $orderTransactionRepository,
        ?EntityRepository $subscriptionRepository,
    ) {
        $this->cartPersister = $cartPersister;
        $this->pluginTransactionService = $pluginTransactionService;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
        $this->salesChannelContextService = $salesChannelContextService;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->subscriptionRepository = $subscriptionRepository;
    }
    /**
     * @param \Psr\Log\LoggerInterface $logger
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
     * @param \Symfony\Component\HttpFoundation\Request
     * @param \Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct $transaction
     * @param \Shopware\Core\Framework\Context $context
     * @param \Shopware\Core\Framework\Struct\Struct $validateStruct
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context           $context,
        ?Struct $validateStruct
    ): RedirectResponse
    {
        try {
            $orderTransactionId = $transaction->getOrderTransactionId();
            $orderTransaction = $this->orderTransactionRepository->search(
                (new Criteria([$orderTransactionId]))
                    ->addAssociation('order'), $context
            )->getEntities()->first();

            $contextSource = $context->getSource();
            if ($contextSource instanceof SalesChannelApiSource) {
                $salesChannelContextId = $contextSource->getSalesChannelId();
            }

            $orderCustomer = $orderTransaction->getOrder()?->getOrderCustomer();
            
            if ($orderCustomer) {
                $customerId = $orderCustomer->getCustomerId();
            } else {
                $customerId = null;
            }

            $parameters = new SalesChannelContextServiceParameters(
                $salesChannelContextId, 
                $request->getSession()->get("sw-context-token", Random::getAlphanumericString(32)), 
                originalContext: $context,
                customerId: $customerId
            );
            $salesChannelContext = $this->salesChannelContextService->get($parameters);
            $redirectUrl = $transaction->getReturnUrl();

            if ($orderTransaction->getOrder()->getAmountTotal() > 0) {
                $transactionId = $request->getSession()->get('transactionId');
                if ($transactionId === null) {
                    $this->pluginTransactionService->createPendingTransaction($salesChannelContext);
                }
                $redirectUrl = $this->pluginTransactionService->create($transaction, $salesChannelContext);
            }
            return new RedirectResponse($redirectUrl);
        } catch (\Throwable $e) {
            $request->getSession()->remove('transactionId');
            $errorMessage = 'An error occurred during the communication with external payment gateway : ' . $e->getMessage();
            $this->logger->critical($errorMessage);
            throw PaymentException::customerCanceled($orderTransactionId, $errorMessage);
        }
    }

    /**
     * The finalize function will be called when the user is redirected back to shop from the payment gateway.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param \Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct $transaction
     * @param \Shopware\Core\Framework\Context $context
     * @throws \PostFinanceCheckout\Sdk\ApiException
     * @throws \PostFinanceCheckout\Sdk\Http\ConnectionException
     * @throws \PostFinanceCheckout\Sdk\VersioningException
     * @throws \Exception when the payment was canceled by the customer
     */
    public function finalize(
        Request                  $request,
        PaymentTransactionStruct $transaction,
        Context                  $context
    ): void
    {
        $orderTransactionId = $transaction->getOrderTransactionId();
        $orderTransaction = $this->orderTransactionRepository->search(
            (new Criteria([$orderTransactionId]))
                ->addAssociation('order'), $context
        )->getEntities()->first();

        if ($orderTransaction->getOrder()->getAmountTotal() > 0) {
            $transactionEntity = $this->pluginTransactionService->getByOrderId(
                $orderTransaction->getOrder()->getId(),
                $context
            );

            $postFinanceCheckoutTransaction = $this->pluginTransactionService->read(
                $transactionEntity->getTransactionId(),
                $transactionEntity->getSalesChannelId()
            );

            if (in_array($postFinanceCheckoutTransaction->getState(), [TransactionState::FAILED])) {
                $errorMessage = strtr('Customer canceled payment for :orderId on SalesChannel :salesChannelName', [
                    ':orderId' => $orderTransaction->getOrder()->getId(),
                    ':salesChannelName' => $transactionEntity->getSalesChannelId(),
                ]);
                $request->getSession()->remove('transactionId');
                $this->logger->info($errorMessage);
                throw PaymentException::customerCanceled($orderTransactionId, $errorMessage);
            }
        } else {
            $this->orderTransactionStateHandler->paid($orderTransaction->getId(), $context);
        }

        $token = $request->getSession()->get('sw-context-token');
        if ($token) {
            $salesChannelId = $transactionEntity->getSalesChannelId();
            $parameters = new SalesChannelContextServiceParameters($salesChannelId, $token, originalContext: $context);
            $salesChannelContext = $this->salesChannelContextService->get($parameters);

            $salesChannelContext->getContext()->addState('do-cart-delete');
            $this->logger->info('Clearing cart with token: ' . $token);
            $this->cartPersister->delete($salesChannelContext->getToken(), $salesChannelContext);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function supports(
        PaymentHandlerType $type,
        string $paymentMethodId,
        Context $context
        ): bool {
        // Both PaymentHandlerType::RECURRING and PaymentHandlerType::REFUND are supported
        //TODO: check that the payment method really supports recurring.
        // In order to do that, we need to get this information in when synching the payment methods.
        // The payment methods in the portal are managed by their Connectors. The Connectors need
        // to support the recurrin and the refunding. These values are 1453357059666L and 1453351315899L for
        // tokenization and refunding respectively.
        return true;
    }

    public function recurring(
        PaymentTransactionStruct $transaction,
        Context $context
    ): void {
        if ($this->subscriptionRepository === null || !class_exists(\Shopware\Commercial\Subscription\Entity\Subscription\SubscriptionEntity::class)) {
            throw PaymentException::paymentTypeUnsupported(
                $transaction->getOrderTransactionId(),
                'Shopware Commercial plugin with Subscription feature is not installed or active. Recurring payments cannot be processed.'
            );
        }

        if ($transaction->isRecurring() === false) {
            //TODO: Provide payment-method-id instead of order-transaction-id
            throw PaymentException::paymentTypeUnsupported($transaction->getOrderTransaction()->getId(), PaymentHandlerType::RECURRING);
        }

        $recurringData = $transaction->getRecurring();
        $newTransactionId = $transaction->getOrderTransactionId();

        if ($recurringData === null) {
            throw PaymentException::recurringInterrupted($newTransactionId, 'Recurring payment data is missing from the transaction struct.');
        }

        try {
            // Get information about the subscription
            $subscriptionId = $recurringData->getSubscriptionId();
            $criteria = new Criteria([$subscriptionId]);
            $criteria->addAssociation('orders.transactions.stateMachineState');

            /** @var SubscriptionEntity|null $subscription */
            $subscription = $this->subscriptionRepository->search($criteria, $context)->get($subscriptionId);

            if ($subscription === null) {
                throw PaymentException::recurringInterrupted($newTransactionId, sprintf('Subscription with ID "%s" could not be found.', $subscriptionId));
            }

            // Find the original order and transaction
            $orders = $subscription->getOrders();
            if ($orders === null || $orders->count() === 0) {
                throw PaymentException::recurringInterrupted($newTransactionId, 'No orders found associated with the subscription.');
            }

            $orders->sort(fn (OrderEntity $a, OrderEntity $b) => $a->getCreatedAt() <=> $b->getCreatedAt());
            /** @var OrderEntity|null $originalOrder */
            $originalOrder = $orders->first();

            $originalTransactions = $originalOrder->getTransactions();

            if ($originalTransactions === null) {
                throw PaymentException::recurringInterrupted($newTransactionId, 'No transactions found on the original order.');
            }

            /** @var OrderTransactionEntity|null $originalTransaction */
            $originalTransaction = $originalTransactions->filter(
                fn (OrderTransactionEntity $t) => $t->getStateMachineState()?->getTechnicalName() === OrderTransactionStates::STATE_PAID
            )->first();

            if ($originalTransaction === null) {
                throw PaymentException::recurringInterrupted($newTransactionId, 'A successful, paid transaction could not be found on the original order to retrieve payment details.');
            }

            $newOrderTransaction = $this->orderTransactionRepository->search(
                (new Criteria([$newTransactionId]))
                    ->addAssociation('order'), $context
            )->getEntities()->first();
            $orderNumber = $newOrderTransaction->getOrder()->getOrderNumber();

            // Access the custom fields for getting the original transaction details
            $customFields = $originalTransaction->getCustomFields();
            // The tokenReference is not really needed because it's also stored in the original transaction
            $tokenReference = $customFields[TransactionPayload::ORDER_TRANSACTION_CUSTOM_FIELDS_POSTFINANCECHECKOUT_TOKEN] ?? null;
            $spaceId = (string) $customFields[TransactionPayload::ORDER_TRANSACTION_CUSTOM_FIELDS_POSTFINANCECHECKOUT_SPACE_ID] ?? null;
            $sdkTransactionId = $customFields[TransactionPayload::ORDER_TRANSACTION_CUSTOM_FIELDS_POSTFINANCECHECKOUT_TRANSACTION_ID] ?? null;

            if ($sdkTransactionId === null || $spaceId === null) {
                throw PaymentException::recurringInterrupted($newTransactionId, 'Required original transaction ID and spaceId is missing from order transaction custom fields.');
            }

            /** @var \PostFinanceCheckout\Sdk\Model\Transaction $originalSdkTransaction */
            $originalSdkTransaction = $this->pluginTransactionService->read($sdkTransactionId, "");

            //TODO: Consider moving this logic to its own function for improved readability
            $sdkTransactionCreate = new \PostFinanceCheckout\Sdk\Model\TransactionCreate;

            // Build the new transaction based on the original transaction
            $sdkTransactionCreate->setCurrency($originalSdkTransaction->getCurrency());
            $sdkTransactionCreate->setBillingAddress($this->addressCreateFromSdk($originalSdkTransaction->getBillingAddress()));
            $sdkTransactionCreate->setShippingAddress($this->addressCreateFromSdk($originalSdkTransaction->getShippingAddress()));
            $sdkTransactionCreate->setShippingMethod($originalSdkTransaction->getShippingMethod());
            $sdkTransactionCreate->setCustomerEmailAddress($originalSdkTransaction->getCustomerEmailAddress());
            $sdkTransactionCreate->setCustomerId($originalSdkTransaction->getCustomerId());
            $sdkTransactionCreate->setLanguage($originalSdkTransaction->getLanguage());
            // Get the merchant reference from the new Order, not the original one
            $sdkTransactionCreate->setMerchantReference($orderNumber);
            $sdkTransactionCreate->setInvoiceMerchantReference($originalSdkTransaction->getInvoiceMerchantReference());

            $lineItems = $originalSdkTransaction->getLineItems();
            $lineItemsCreate = [];
            foreach ($lineItems as $lineItem) {
                $lineItemsCreate[] = $this->lineItemCreateFromSdk($lineItem);
            }
            if (count($lineItemsCreate) > 0) {
                $sdkTransactionCreate->setLineItems($lineItemsCreate);
            }

            $sdkTransactionCreate->setSuccessUrl($originalSdkTransaction->getSuccessUrl());
            $sdkTransactionCreate->setToken($originalSdkTransaction->getToken());
            $sdkTransactionCreate->setTokenizationMode($originalSdkTransaction->getTokenizationMode());
            $sdkTransactionCreate->setMetaData($originalSdkTransaction->getMetaData());

            // Create the new recurring transaction
            $newSdkTransaction = $this->pluginTransactionService->createRecurringTransaction($sdkTransactionCreate, $spaceId);

            // Set the new state for the new order transaction
            if (in_array($newSdkTransaction->getState(), [TransactionState::AUTHORIZED, TransactionState::COMPLETED, TransactionState::CONFIRMED, TransactionState::FULFILL])) {
                $this->orderTransactionStateHandler->paid($newTransactionId, $context);
            } elseif (in_array($newSdkTransaction->getState(), [TransactionState::DECLINE, TransactionState::FAILED, TransactionState::VOIDED])) {
                $this->orderTransactionStateHandler->fail($newTransactionId, $context);
            } elseif (in_array($newSdkTransaction->getState(), [TransactionState::PENDING, TransactionState::PROCESSING])) {
                $this->orderTransactionStateHandler->process($newTransactionId, $context);
            } else {
                $this->orderTransactionStateHandler->reopen($newTransactionId, $context);
            }

            $data = [
                'id' => $newTransactionId,
                'customFields' => [
                    TransactionPayload::ORDER_TRANSACTION_CUSTOM_FIELDS_POSTFINANCECHECKOUT_TRANSACTION_ID => $newSdkTransaction->getId(),
                    TransactionPayload::ORDER_TRANSACTION_CUSTOM_FIELDS_POSTFINANCECHECKOUT_SPACE_ID => $spaceId,
                    TransactionPayload::ORDER_TRANSACTION_CUSTOM_FIELDS_POSTFINANCECHECKOUT_TOKEN => $tokenReference,
                ],
            ];

            // Update the new order transaction with the new transaction details
            $this->orderTransactionRepository->update([$data], $context);
            $this->pluginTransactionService->upsert($newSdkTransaction, $context);
        }
        catch (\Throwable $e) {
            $errorMessage = 'An error occurred during the communication with external payment gateway : ' . $e->getMessage();
            $this->logger->critical($errorMessage);
            throw PaymentException::recurringInterrupted($transaction->getOrderTransactionId(), $errorMessage);
        }
    }

    /**
     * Creates a new AddressCreate instance from the given SDK Address model.
     *
     * @param \PostFinanceCheckout\Sdk\Model\Address $address The address model from the SDK.
     * @return \PostFinanceCheckout\Sdk\Model\AddressCreate The newly created AddressCreate instance.
     */
    private function addressCreateFromSdk(\PostFinanceCheckout\Sdk\Model\Address $address): \PostFinanceCheckout\Sdk\Model\AddressCreate {
        $addressCreate = new \PostFinanceCheckout\Sdk\Model\AddressCreate;

        $addressCreate->setCity($address->getCity());
        $addressCreate->setCommercialRegisterNumber($address->getCommercialRegisterNumber());
        $addressCreate->setCountry($address->getCountry());
        $addressCreate->setDateOfBirth($address->getDateOfBirth());
        $addressCreate->setDependentLocality($address->getDependentLocality());
        $addressCreate->setEmailAddress($address->getEmailAddress());
        $addressCreate->setFamilyName($address->getFamilyName());
        $addressCreate->setGender($address->getGender());
        $addressCreate->setGivenName($address->getGivenName());
        $addressCreate->setMobilePhoneNumber($address->getMobilePhoneNumber());
        $addressCreate->setOrganizationName($address->getOrganizationName());
        $addressCreate->setPhoneNumber($address->getPhoneNumber());
        $addressCreate->setPostalState($address->getPostalState());
        $addressCreate->setPostcode($address->getPostcode());
        $addressCreate->setSalesTaxNumber($address->getSalesTaxNumber());
        $addressCreate->setSalutation($address->getSalutation());
        $addressCreate->setSocialSecurityNumber($address->getSocialSecurityNumber());
        $addressCreate->setSortingCode($address->getSortingCode());
        $addressCreate->setStreet($address->getStreet());

        return $addressCreate;
    }

    /**
     * Creates a LineItemCreate object from a given SDK LineItem.
     *
     * This method takes a \PostFinanceCheckout\Sdk\Model\LineItem instance and transforms it into a
     * \PostFinanceCheckout\Sdk\Model\LineItemCreate object, which can be used for further processing
     * or integration with the PostFinanceCheckout payment SDK.
     *
     * @param \PostFinanceCheckout\Sdk\Model\LineItem $lineItem The line item from the SDK to convert.
     * @return \PostFinanceCheckout\Sdk\Model\LineItemCreate The created LineItemCreate object.
     */
    private function lineItemCreateFromSdk(\PostFinanceCheckout\Sdk\Model\LineItem $lineItem): \PostFinanceCheckout\Sdk\Model\LineItemCreate
    {
        $lineItemCreate = new \PostFinanceCheckout\Sdk\Model\LineItemCreate();

        $lineItemCreate->setAmountIncludingTax($lineItem->getAmountIncludingTax());

        $attributes = $lineItem->getAttributes();
        $attributesCreate = [];
        foreach ($attributes as $id => $attribute) {
            $attributeCreate = new \PostFinanceCheckout\Sdk\Model\LineItemAttributeCreate();
            $attributeCreate->setLabel($attribute->getLabel());
            $attributeCreate->setValue($attribute->getValue());
            $attributesCreate[$id] = $attributeCreate;
        }
        if (count($attributesCreate) > 0) {
            $lineItemCreate->setAttributes($attributesCreate);
        }

        $lineItemCreate->setDiscountIncludingTax($lineItem->getDiscountIncludingTax());
        $lineItemCreate->setName($lineItem->getName());
        $lineItemCreate->setQuantity($lineItem->getQuantity());
        $lineItemCreate->setShippingRequired($lineItem->getShippingRequired());
        $lineItemCreate->setSku($lineItem->getSku());

        $taxes = $lineItem->getTaxes();
        $taxesCreate = [];
        foreach ($taxes as $tax) {
            $taxCreate = new \PostFinanceCheckout\Sdk\Model\TaxCreate();
            $taxCreate->setRate($tax->getRate());
            $taxCreate->setTitle($tax->getTitle());
            $taxesCreate[] = $taxCreate;
        }
        if (count($taxesCreate) > 0) {
            $lineItemCreate->setTaxes($taxesCreate);
        }

        $lineItemCreate->setType($lineItem->getType());
        $lineItemCreate->setUniqueId($lineItem->getUniqueId());

        return $lineItemCreate;
    }
}
