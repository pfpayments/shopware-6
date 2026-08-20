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
use Psr\Cache\CacheItemPoolInterface;
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
     * @var CacheItemPoolInterface
     * Cache for the API's answer to "which payment methods are possible for this transaction".
     */
    private CacheItemPoolInterface $cache;

    /**
     * @var int
     * Lifetime of a cached payment method list, in seconds. Zero disables the cache entirely.
     */
    private int $possibleMethodsCacheTtl;

    /**
     * Per-request cache of the payment method configurations, keyed by space ID.
     *
     * The confirm page builds the filtered collection on both of its filter passes; without this the
     * same DAL query ran twice. Cleared between requests through the kernel.reset tag.
     *
     * @var array<string, array>
     */
    private array $paymentMethodConfigurationCache = [];

    /**
     * Cache key prefix for the possible payment methods of a transaction.
     */
    private const POSSIBLE_METHODS_CACHE_PREFIX = 'pfcn_possible_methods_';

    /**
     * @param SettingsService $settingsService
     * @param TransactionService $transactionService
     * @param PaymentMethodConfigurationService $paymentMethodConfigurationService
     * @param PaymentMethodUtil $paymentMethodUtil
     * @param TransactionManagementService $transactionManagementService
     * @param CartService $cartService
     * @param CacheItemPoolInterface $cache
     * @param int $possibleMethodsCacheTtl
     */
    public function __construct(
        SettingsService $settingsService,
        TransactionService $transactionService,
        PaymentMethodConfigurationService $paymentMethodConfigurationService,
        PaymentMethodUtil $paymentMethodUtil,
        TransactionManagementService $transactionManagementService,
        CartService $cartService,
        CacheItemPoolInterface $cache,
        int $possibleMethodsCacheTtl
    ) {
        $this->settingsService = $settingsService;
        $this->transactionService = $transactionService;
        $this->paymentMethodConfigurationService = $paymentMethodConfigurationService;
        $this->paymentMethodUtil = $paymentMethodUtil;
        $this->transactionManagementService = $transactionManagementService;
        $this->cartService = $cartService;
        $this->cache = $cache;
        $this->possibleMethodsCacheTtl = $possibleMethodsCacheTtl;
    }

    /**
     * Drops the per-request caches.
     *
     * Invoked by Symfony between requests via the kernel.reset tag.
     */
    public function reset(): void
    {
        $this->paymentMethodConfigurationCache = [];
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

        $lineItems = $this->transactionService->extractLineItems($source, $salesChannelContext);

        // The storefront confirm page filters twice per request: once through the store-api payment
        // method route (via CheckoutGatewayRoute, onlyAvailable=1) and once again from the
        // CheckoutConfirmPageLoadedEvent subscriber. Both passes see the same Context instance, so a
        // memo on it lets the second pass reuse the first pass' API results.
        //
        // On the "edit order" page the two passes run on different Context instances - the route
        // pass gets the order context assembled by OrderConverter - so the memo intentionally does
        // not apply there and both passes keep running against their own source data.
        $memoKey = $this->buildMemoKey($settings, $salesChannelContext, $lineItems);
        $allowedIds = $this->readMemoizedAllowedIds($salesChannelContext, $memoKey);

        if ($allowedIds === null) {
            // Ensure a pending transaction exists and is up to date, then ask the API which methods
            // it allows. The transaction management service keeps this to one read plus, only when
            // something actually changed, one update.
            $transactionId = $this->transactionManagementService->prepareTransaction($salesChannelContext, $source, $lineItems);

            $allowedIds = $this->fetchAvailablePaymentMethodIds($settings, $transactionId, $memoKey);

            $this->writeMemo($salesChannelContext, $memoKey, $transactionId, $allowedIds);
        }

        // Return a new collection containing only allowed methods. This always runs, also on a memo
        // hit: it is what attaches the payment method configuration extension the templates read.
        return $this->buildFilteredCollection($paymentMethodCollection, $allowedIds, $settings->getSpaceId(), $salesChannelContext);
    }

    /**
     * Builds the key identifying a filter result within the current request.
     *
     * It covers everything that can change the API's answer: the space and integration the question
     * is asked against, and the checkout payload (currency, language, addresses, line items) the
     * transaction would be updated with.
     *
     * @param mixed $settings The plugin settings.
     * @param SalesChannelContext $salesChannelContext The context.
     * @param array $lineItems The line items as they would be sent to the API.
     * @return string
     */
    private function buildMemoKey($settings, SalesChannelContext $salesChannelContext, array $lineItems): string
    {
        return hash('sha256', implode('|', [
            (string) $settings->getSpaceId(),
            (string) $settings->getIntegration(),
            $this->transactionService->buildTempTransactionFingerprint($salesChannelContext, $lineItems),
        ]));
    }

    /**
     * Reads the memoized allowed payment method IDs for the current request.
     *
     * @param SalesChannelContext $salesChannelContext The context.
     * @param string $memoKey The key the memo must match.
     * @return string[]|null The allowed IDs, or null when there is no usable memo. An empty array is
     *                       a valid result meaning "the API allows nothing" and is not a miss.
     */
    private function readMemoizedAllowedIds(SalesChannelContext $salesChannelContext, string $memoKey): ?array
    {
        /** @var ArrayEntity|null $memo */
        $memo = $salesChannelContext->getContext()->getExtension(TransactionService::FILTER_MEMO_EXTENSION);

        if (!$memo instanceof ArrayEntity || $memo->get('key') !== $memoKey) {
            return null;
        }

        $allowedIds = $memo->get('allowedIds');

        return is_array($allowedIds) ? $allowedIds : null;
    }

    /**
     * Memoizes a filter result for the remainder of the request.
     *
     * @param SalesChannelContext $salesChannelContext The context.
     * @param string $memoKey The key this result is valid for.
     * @param int $transactionId The transaction the result was fetched for.
     * @param string[] $allowedIds The allowed payment method configuration IDs.
     */
    private function writeMemo(
        SalesChannelContext $salesChannelContext,
        string $memoKey,
        int $transactionId,
        array $allowedIds
    ): void {
        $salesChannelContext->getContext()->addExtension(
            TransactionService::FILTER_MEMO_EXTENSION,
            new ArrayEntity([
                'key'           => $memoKey,
                'transactionId' => $transactionId,
                'allowedIds'    => $allowedIds,
            ])
        );
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
     * The answer is cached across requests under a key that already covers the space, the
     * integration, the transaction and the exact payload the transaction carries. Any change to the
     * cart, address, currency or language produces a different key, so the only staleness window is
     * a payment method being reconfigured in the portal - which the short TTL bounds.
     *
     * @param mixed $settings The plugin settings.
     * @param int $createdTransactionId The WhitelabelMachineName transaction ID.
     * @param string $stateKey Key identifying the checkout state the transaction carries.
     * @return string[] Array of allowed payment method configuration IDs.
     */
    private function fetchAvailablePaymentMethodIds(
        $settings,
        int $createdTransactionId,
        string $stateKey
    ): array {
        $cacheItem = null;

        if ($this->possibleMethodsCacheTtl > 0) {
            $cacheItem = $this->cache->getItem(
                self::POSSIBLE_METHODS_CACHE_PREFIX . $stateKey . '_' . $createdTransactionId
            );

            if ($cacheItem->isHit()) {
                $cached = $cacheItem->get();
                // An empty list is a valid answer, so only the type is checked here.
                if (is_array($cached)) {
                    return $cached;
                }
            }
        }

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

        if ($cacheItem !== null) {
            $cacheItem->set($arrayOfPossibleMethods);
            $cacheItem->expiresAfter($this->possibleMethodsCacheTtl);
            $this->cache->save($cacheItem);
        }

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
        // Fetch all WhitelabelMachineName payment method configurations for the space. Memoized for
        // the request, since the confirm page builds the collection on both filter passes.
        //
        // The key includes the language and version, because the result is a DAL search whose
        // translations and entity version depend on them: the edit order page runs its two passes
        // with different Contexts (the route pass gets the order context assembled by
        // OrderConverter), so keying on the space alone would hand pass 2 entities resolved in the
        // wrong language.
        $context = $salesChannelContext->getContext();
        $configurationCacheKey = $spaceId . '_' . $context->getLanguageId() . '_' . $context->getVersionId();

        if (!isset($this->paymentMethodConfigurationCache[$configurationCacheKey])) {
            $this->paymentMethodConfigurationCache[$configurationCacheKey] = $this->paymentMethodConfigurationService
                ->getAllPaymentMethodConfigurations($spaceId, $context);
        }
        $paymentMethodConfigurations = $this->paymentMethodConfigurationCache[$configurationCacheKey];

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
