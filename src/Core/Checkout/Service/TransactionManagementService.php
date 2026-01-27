<?php

declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Checkout\Service;

use Psr\Cache\CacheItemPoolInterface;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use PostFinanceCheckoutPayment\Core\Api\Transaction\Service\TransactionService;
use PostFinanceCheckoutPayment\Core\Settings\Service\SettingsService;
use PostFinanceCheckout\Sdk\Model\TransactionState;

/**
 * This service manages the lifecycle of WhitelabelMachineName transactions and their state within the Shopware context.
 * It provides methods to retrieve, create, and update transactions while ensuring state consistency.
 */
class TransactionManagementService
{
    /**
     * @var TransactionService
     * The service used to interact with the WhitelabelMachineName API for transaction operations.
     */
    private TransactionService $transactionService;

    /**
     * @var SettingsService
     * The service used to retrieve configuration settings for the current sales channel.
     */
    private SettingsService $settingsService;

    /**
     * @var CacheItemPoolInterface
     * Cache for headless transaction persistence
     */
    private CacheItemPoolInterface $cache;

    /**
     * @param TransactionService $transactionService
     * @param SettingsService $settingsService
     * @param CacheItemPoolInterface $cache
     */
    public function __construct(
        TransactionService $transactionService,
        SettingsService $settingsService,
        CacheItemPoolInterface $cache
    ) {
        $this->transactionService = $transactionService;
        $this->settingsService = $settingsService;
        $this->cache = $cache;
    }

    /**
     * Retrieves an existing pending transaction ID from the context or creates a new one if necessary.
     *
     * @param SalesChannelContext $salesChannelContext The current sales channel context.
     * @param mixed $event Optional event context.
     * @return int The WhitelabelMachineName transaction ID.
     * @throws \Exception If settings are not configured.
     */
    public function getOrCreatePendingTransaction(SalesChannelContext $salesChannelContext, $event = null): int
    {
        // Try to get the transaction ID from the current context state.
        $transactionId = $this->getTransactionIdFromContext($salesChannelContext);
        $settings = $this->settingsService->getValidSettings($salesChannelContext->getSalesChannel()->getId());

        if (!$settings) {
            throw new \Exception('Space settings not configured');
        }

        $expiredTransaction = true;
        if ($transactionId) {
            try {
                // Verify if the transaction still exists and is in a PENDING state.
                $pendingTransaction = $this->transactionService->read($transactionId, (string)$salesChannelContext->getSalesChannel()->getId());
                if ($pendingTransaction->getState() === TransactionState::PENDING) {
                    $expiredTransaction = false;
                }
            } catch (\Exception $e) {
                // If the transaction cannot be read, we treat it as expired or invalid.
            }
        }

        // Create a new transaction if none exists or the existing one is no longer valid.
        if (!$transactionId || $expiredTransaction) {
            $transactionId = (int)$this->transactionService->createPendingTransaction($salesChannelContext, $event);
            $this->storeTransactionIdInContext($salesChannelContext, $transactionId);
        }

        return $transactionId;
    }

    /**
     * Updates the WhitelabelMachineName transaction if the customer context (address or currency) or line items have changed.
     *
     * @param SalesChannelContext $salesChannelContext The current context.
     * @param int $transactionId The WhitelabelMachineName transaction ID to update.
     * @param mixed $event The event that triggered this update (optional).
     */
    public function updateTempTransactionIfNeeded(SalesChannelContext $salesChannelContext, int $transactionId, $event = null): void
    {
        $ctx = $salesChannelContext->getContext();

        /** @var ArrayEntity|null $ext */
        $ext = $ctx->getExtension('checkoutState');

        $oldAddressHash = $ext instanceof ArrayEntity ? (string)$ext->get('addressHash') : null;
        $oldCurrency    = $ext instanceof ArrayEntity ? (string)$ext->get('currency') : null;
        $oldLineItemHash = $ext instanceof ArrayEntity ? (string)$ext->get('lineItemHash') : null;

        $customer    = $salesChannelContext->getCustomer();
        $addressHash = $customer ? md5(json_encode((array) $customer)) : null;
        $currency    = (string)$salesChannelContext->getCurrency()->getIsoCode();

        $lineItems = $this->transactionService->extractLineItems($event);
        $lineItemHash = !empty($lineItems) ? md5(json_encode($lineItems)) : $oldLineItemHash;

        $needsUpdate = ($oldAddressHash !== $addressHash)
            || ($oldCurrency !== $currency)
            || ($oldLineItemHash !== $lineItemHash);

        if ($needsUpdate) {
            // Update the transaction in WhitelabelMachineName to reflect current cart and customer data.
            if ($transactionId) {
                $this->transactionService->updateTempTransaction($salesChannelContext, $transactionId, $lineItems);
            }

            // Clear payment method cache as options might have changed due to address/currency change.
            $ctx->addExtension('possibleMethods', new ArrayEntity(['ids' => []]));

            // Persist the new state hash in the context.
            $ctx->addExtension(
                'checkoutState',
                new ArrayEntity([
                    'transactionId' => $transactionId,
                    'addressHash'   => $addressHash,
                    'currency'      => $currency,
                    'lineItemHash'  => $lineItemHash,
                ])
            );
        }
    }

    /**
     * Retrieves the stored WhitelabelMachineName transaction ID from the context, cache, or session.
     *
     * @param SalesChannelContext $salesChannelContext The context.
     * @return int|null The transaction ID if found, otherwise null.
     */
    public function getTransactionIdFromContext(SalesChannelContext $salesChannelContext): ?int
    {
        /** @var ArrayEntity|null $ext */
        $ext = $salesChannelContext->getContext()->getExtension('checkoutState');
        if ($ext instanceof ArrayEntity && $ext->get('transactionId')) {
            return (int) $ext->get('transactionId');
        }

        // Try to get from cache (headless support).
        $customer = $salesChannelContext->getCustomer();
        if ($customer) {
            $cacheKey = 'pfcn_pending_transaction_id_customer_' . $customer->getId();
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
     * Persists the transaction ID in the context state, cache, and session.
     *
     * @param SalesChannelContext $salesChannelContext The context.
     * @param int $transactionId The transaction ID to store.
     */
    private function storeTransactionIdInContext(SalesChannelContext $salesChannelContext, int $transactionId): void
    {
        $ctx = $salesChannelContext->getContext();
        /** @var ArrayEntity|null $ext */
        $ext = $ctx->getExtension('checkoutState');

        $data = $ext instanceof ArrayEntity ? $ext->all() : [];
        $data['transactionId'] = $transactionId;

        // Store in context extension for stateless (headless) support within the request.
        $ctx->addExtension('checkoutState', new ArrayEntity($data));

        // Store in cache for persistent headless support.
        $customer = $salesChannelContext->getCustomer();
        if ($customer) {
            $cacheKey = 'pfcn_pending_transaction_id_customer_' . $customer->getId();
            $item = $this->cache->getItem($cacheKey);
            $item->set($transactionId);
            $item->expiresAfter(7200);
            $this->cache->save($item);
        }

        // Sync with PHP session for stateful Storefront support.
        $_SESSION['transactionId'] = $transactionId;
    }
}
