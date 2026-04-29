<?php

declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Checkout\Service;

use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
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
     * @param TransactionManagementService $transactionManagementService
     * @param CartService $cartService
     */
    public function __construct(
        SettingsService $settingsService,
        TransactionService $transactionService,
        PaymentMethodConfigurationService $paymentMethodConfigurationService,
        PaymentMethodUtil $paymentMethodUtil,
        TransactionManagementService $transactionManagementService,
        CartService $cartService
    ) {
        $this->settingsService = $settingsService;
        $this->transactionService = $transactionService;
        $this->paymentMethodConfigurationService = $paymentMethodConfigurationService;
        $this->paymentMethodUtil = $paymentMethodUtil;
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
     * Filters the original collection (which already has Shopware's availability rules applied)
     * to only include WhitelabelMachineName methods that are also allowed by the API.
     * Non-WhitelabelMachineName methods are kept as-is.
     *
     * @param PaymentMethodCollection $paymentMethodCollection Original collection (already rule-filtered by Shopware).
     * @param string[] $allowedIds List of allowed configuration IDs from the WhitelabelMachineName API.
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
        // Fetch all WhitelabelMachineName payment method configurations for the space.
        $paymentMethodConfigurations = $this->paymentMethodConfigurationService
            ->getAllPaymentMethodConfigurations($spaceId, $salesChannelContext->getContext());

        // Build a map of Shopware payment method ID => configuration for methods allowed by the API.
        $allowedWLConfigByPmId = [];
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
                $allowedWLConfigByPmId[$pmId] = $paymentMethodConfiguration;
            }
        }

        // Filter the original collection to preserve Shopware's availability rule filtering.
        // Non-WLM methods pass through unchanged; WLM methods are kept only if allowed by the API.
        $collection = new PaymentMethodCollection();
        foreach ($paymentMethodCollection as $method) {
            $isPostFinanceCheckoutPM = PostFinanceCheckoutPaymentHandler::class === $method->getHandlerIdentifier();

            if (!$isPostFinanceCheckoutPM) {
                $collection->add($method);
                continue;
            }

            if (isset($allowedWLConfigByPmId[$method->getId()])) {
                $method->addExtension('postfinancecheckout_config', $allowedWLConfigByPmId[$method->getId()]);
                $collection->add($method);
            }
        }

        $collection->sort(function ($a, $b) {
            return ($a->getPosition() ?? 0) <=> ($b->getPosition() ?? 0);
        });

        return $collection;
    }
}
