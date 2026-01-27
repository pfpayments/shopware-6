<?php

declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Checkout\Service;

use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use PostFinanceCheckoutPayment\Core\Api\PaymentMethodConfiguration\Service\PaymentMethodConfigurationService;
use PostFinanceCheckoutPayment\Core\Api\Transaction\Service\TransactionService;
use PostFinanceCheckoutPayment\Core\Checkout\PaymentHandler\PostFinanceCheckoutPaymentHandler;
use PostFinanceCheckoutPayment\Core\Settings\Service\SettingsService;
use PostFinanceCheckoutPayment\Core\Util\PaymentMethodUtil;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Psr\Log\LoggerInterface;

/**
 * This service centralizes the logic for filtering WhitelabelMachineName payment methods.
 * It ensures that only valid and available payment methods are displayed to the customer,
 * based on the current transaction state and configured settings.
 */
class PaymentMethodFilterService
{
    /**
     * @var PaymentMethodConfigurationService
     * Service to handle WhitelabelMachineName payment method configurations.
     */
    private PaymentMethodConfigurationService $paymentMethodConfigurationService;

    /**
     * @var TransactionService
     * Service to manage WhitelabelMachineName transactions via API.
     */
    private TransactionService $transactionService;

    /**
     * @var SettingsService
     * Service to retrieve plugin settings.
     */
    private SettingsService $settingsService;

    /**
     * @var PaymentMethodUtil
     * Utility for payment method operations.
     */
    private PaymentMethodUtil $paymentMethodUtil;

    /**
     * @var EntityRepository
     * Repository for Shopware payment methods.
     */
    private EntityRepository $paymentMethodRepository;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var TransactionManagementService
     * Service to manage transaction state consistency.
     */
    private TransactionManagementService $transactionManagementService;

    /**
     * @var CartService
     */
    private CartService $cartService;

    /**
     * @param SettingsService $settingsService
     * @param TransactionService $transactionService
     * @param PaymentMethodConfigurationService $paymentMethodConfigurationService
     * @param PaymentMethodUtil $paymentMethodUtil
     * @param EntityRepository $paymentMethodRepository
     * @param TransactionManagementService $transactionManagementService
     */
    public function __construct(
        SettingsService $settingsService,
        TransactionService $transactionService,
        PaymentMethodConfigurationService $paymentMethodConfigurationService,
        PaymentMethodUtil $paymentMethodUtil,
        EntityRepository $paymentMethodRepository,
        TransactionManagementService $transactionManagementService,
        CartService $cartService
    ) {
        $this->settingsService = $settingsService;
        $this->transactionService = $transactionService;
        $this->paymentMethodConfigurationService = $paymentMethodConfigurationService;
        $this->paymentMethodUtil = $paymentMethodUtil;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->transactionManagementService = $transactionManagementService;
        $this->cartService = $cartService;
    }

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Filters the given collection of payment methods based on WhitelabelMachineName's availability logic.
     *
     * @param PaymentMethodCollection $paymentMethodCollection The initial collection of payment methods.
     * @param SalesChannelContext $salesChannelContext The current sales channel context.
     * @param mixed $event Optional event that triggered the filtering.
     * @return PaymentMethodCollection The filtered collection of payment methods.
     */
    public function filterPaymentMethods(
        PaymentMethodCollection $paymentMethodCollection,
        SalesChannelContext $salesChannelContext,
        $event = null
    ): PaymentMethodCollection {
        // Fetch valid settings for the current sales channel.
        $settings = $this->settingsService->getValidSettings($salesChannelContext->getSalesChannel()->getId());

        // If settings are missing, remove all WhitelabelMachineName payment methods to prevent incorrect behavior.
        if (is_null($settings)) {
            return $this->removePostFinanceCheckoutPaymentMethods($paymentMethodCollection, $salesChannelContext);
        }

        // If there is no customer, we cannot create a transaction or perform API-based filtering.
        // This typically happens on non-checkout pages like the frontpage footer.
        if ($salesChannelContext->getCustomer() === null) {
            return $paymentMethodCollection;
        }

        $source = $event;
        if ($source === null) {
            // In headless (Store API) flow, event is null. We explicitly fetch the cart to get line items.
            $source = $this->cartService->getCart($salesChannelContext->getToken(), $salesChannelContext);
        }

        // Ensure a pending transaction exists in WhitelabelMachineName for correct filtering,
        // using the transaction management service for state consistency.
        $createdTransactionId = $this->transactionManagementService->getOrCreatePendingTransaction($salesChannelContext, $source);

        // Update the temporary transaction if customer data has changed.
        $this->transactionManagementService->updateTempTransactionIfNeeded($salesChannelContext, $createdTransactionId, $source);

        // Fetch available payment method IDs from WhitelabelMachineName API for this transaction.
        $allowedIds = $this->fetchAvailablePaymentMethodIds($settings, $createdTransactionId, $salesChannelContext);

        // Return a new collection containing only allowed methods.
        return $this->buildFilteredCollection($paymentMethodCollection, $allowedIds, $settings->getSpaceId(), $salesChannelContext);
    }

    /**
     * Removes all WhitelabelMachineName-related payment methods from the collection.
     *
     * @param PaymentMethodCollection $paymentMethodCollection The collection to clean.
     * @param SalesChannelContext $salesChannelContext The context.
     * @return PaymentMethodCollection The cleaned collection.
     */
    private function removePostFinanceCheckoutPaymentMethods(
        PaymentMethodCollection $paymentMethodCollection,
        SalesChannelContext $salesChannelContext
    ): PaymentMethodCollection {
        $paymentMethodIds = $this->paymentMethodUtil->getPostFinanceCheckoutPaymentMethodIds($salesChannelContext->getContext());
        foreach ($paymentMethodIds as $paymentMethodId) {
            $paymentMethodCollection->remove($paymentMethodId);
        }
        return $paymentMethodCollection;
    }

    /**
     * Fetches the list of allowed payment method IDs from the WhitelabelMachineName API.
     *
     * @param mixed $settings The plugin settings.
     * @param int $createdTransactionId The WhitelabelMachineName transaction ID.
     * @param SalesChannelContext $salesChannelContext The context.
     * @return string[] Array of allowed payment method configuration IDs.
     */
    private function fetchAvailablePaymentMethodIds(
        $settings,
        int $createdTransactionId,
        SalesChannelContext $salesChannelContext
    ): array {
        $transactionService = $settings->getApiClient()->getTransactionService();
        $possiblePaymentMethods = $transactionService->fetchPaymentMethods(
            $settings->getSpaceId(),
            $createdTransactionId,
            $settings->getIntegration()
        );

        $arrayOfPossibleMethods = [];
        foreach ($possiblePaymentMethods as $possiblePaymentMethod) {
            $arrayOfPossibleMethods[] = (string) $possiblePaymentMethod->getId();
        }

        // Store the allowed IDs in context extension for later use.
        $salesChannelContext->getContext()->addExtension(
            'possibleMethods',
            new ArrayEntity(['ids' => $arrayOfPossibleMethods])
        );

        return $arrayOfPossibleMethods;
    }

    /**
     * Builds a filtered PaymentMethodCollection based on allowed IDs.
     *
     * @param PaymentMethodCollection $paymentMethodCollection Original collection.
     * @param string[] $allowedIds List of allowed configuration IDs.
     * @param int $spaceId WhitelabelMachineName space ID.
     * @param SalesChannelContext $salesChannelContext The context.
     * @return PaymentMethodCollection The final collection.
     */
    private function buildFilteredCollection(
        PaymentMethodCollection $paymentMethodCollection,
        array $allowedIds,
        int $spaceId,
        SalesChannelContext $salesChannelContext
    ): PaymentMethodCollection {
        $paymentIds = [];
        // Extract non-WhitelabelMachineName payment methods first.
        foreach ($paymentMethodCollection as $paymentMethodCollectionItem) {
            $isPostFinanceCheckoutPM = PostFinanceCheckoutPaymentHandler::class === $paymentMethodCollectionItem->getHandlerIdentifier();
            if (!$isPostFinanceCheckoutPM) {
                $paymentIds[] = $paymentMethodCollectionItem->getId();
            }
        }

        $allowedWLMethods = [];
        // Fetch all WhitelabelMachineName payment method configurations for the space.
        $paymentMethodConfigurations = $this->paymentMethodConfigurationService
            ->getAllPaymentMethodConfigurations($spaceId, $salesChannelContext->getContext());

        // Check each configuration against the list of allowed IDs from WhitelabelMachineName API.
        foreach ($paymentMethodConfigurations as $paymentMethodConfiguration) {
            if ($paymentMethodConfiguration->getPaymentMethod() === null) {
                continue;
            }

            $pmId = $paymentMethodConfiguration->getPaymentMethod()->getId();
            $pmConfigId = (string) $paymentMethodConfiguration->getPaymentMethodConfigurationId();

            if (
                $paymentMethodConfiguration->getSpaceId() === $spaceId
                && \in_array($pmConfigId, $allowedIds, true)
            ) {
                $allowedWLMethods[] = $pmId;
            }
        }

        // Combine non-WhitelabelMachineName and allowed WhitelabelMachineName payment methods.
        $allPaymentIds = array_unique(array_merge($paymentIds, $allowedWLMethods));
        $collection = new PaymentMethodCollection();

        if (!empty($allPaymentIds)) {
            $criteria = new Criteria($allPaymentIds);
            $criteria->addFilter(new EqualsFilter('active', true));
            $criteria->addFilter(
                new EqualsFilter('salesChannels.id', $salesChannelContext->getSalesChannelId())
            );
            $criteria->addSorting(new FieldSorting('position', FieldSorting::ASCENDING));

            // Re-fetch the entities to ensure we have valid objects with all associations.
            $result = $this->paymentMethodRepository->search($criteria, $salesChannelContext->getContext());
            /** @var \Shopware\Core\Checkout\Payment\PaymentMethodEntity $method */
            foreach ($result->getEntities() as $method) {
                if (!$collection->has((string)$method->getId())) {
                    // Attach the configuration to the payment method as an extension for Twig access.
                    foreach ($paymentMethodConfigurations as $paymentMethodConfiguration) {
                        if ($paymentMethodConfiguration->getPaymentMethodId() === $method->getId()) {
                            $method->addExtension('postfinancecheckout_config', $paymentMethodConfiguration);
                            break;
                        }
                    }
                    $collection->add($method);
                }
            }
        }

        return $collection;
    }
}
