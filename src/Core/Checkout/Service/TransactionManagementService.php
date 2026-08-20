<?php

declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Checkout\Service;

use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use PostFinanceCheckoutPayment\Core\Api\Transaction\Service\TransactionService;
use PostFinanceCheckoutPayment\Core\Settings\Service\SettingsService;
use PostFinanceCheckout\Sdk\Model\Transaction;
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
     * @param TransactionService $transactionService
     * @param SettingsService $settingsService
     */
    public function __construct(
        TransactionService $transactionService,
        SettingsService $settingsService
    ) {
        $this->transactionService = $transactionService;
        $this->settingsService = $settingsService;
    }

    /**
     * Resolves the pending transaction for the current checkout and brings it up to date.
     *
     * This is the single entry point used by the payment method filtering. Its API budget is:
     *  - read:   once, to validate that the stored transaction is still PENDING
     *  - create: only when no valid pending transaction exists
     *  - update: only when the payload actually differs from what was last sent
     *
     * @param SalesChannelContext $salesChannelContext The current sales channel context.
     * @param mixed $event Optional event or cart the line items are extracted from.
     * @param array|null $lineItems Pre-extracted line items, to avoid extracting them twice.
     * @return int The WhitelabelMachineName transaction ID.
     * @throws \Exception If settings are not configured.
     */
    public function prepareTransaction(SalesChannelContext $salesChannelContext, $event = null, ?array $lineItems = null): int
    {
        if ($lineItems === null) {
            $lineItems = $this->transactionService->extractLineItems($event, $salesChannelContext);
        }

        [$transactionId, $transaction] = $this->resolvePendingTransaction($salesChannelContext, $event, $lineItems);

        // A transaction that was just created already carries the current payload, so no update is
        // due. Record its fingerprint so the next page view does not send a pointless update either.
        if ($transaction === null) {
            $fingerprint = $this->transactionService->buildTempTransactionFingerprint($salesChannelContext, $lineItems);
            $this->storeCheckoutState($salesChannelContext, $transactionId, $fingerprint);
            $this->transactionService->storePendingTransactionFingerprint($salesChannelContext, $transactionId, $fingerprint);

            return $transactionId;
        }

        $this->updateTempTransactionIfNeeded($salesChannelContext, $transactionId, $lineItems, $transaction);

        return $transactionId;
    }

    /**
     * Resolves the pending transaction, creating one when the stored ID is missing or no longer usable.
     *
     * @param SalesChannelContext $salesChannelContext
     * @param mixed $event
     * @param array|null $lineItems Pre-extracted line items, used when a transaction has to be created.
     * @return array{0: int, 1: Transaction|null} The transaction ID, and the transaction as read from
     *                                            the API - null when it was created in this call.
     * @throws \Exception If settings are not configured.
     */
    private function resolvePendingTransaction(SalesChannelContext $salesChannelContext, $event = null, ?array $lineItems = null): array
    {
        $settings = $this->settingsService->getValidSettings($salesChannelContext->getSalesChannel()->getId());

        if (!$settings) {
            throw new \Exception('Space settings not configured');
        }

        $transactionId = $this->getTransactionIdFromContext($salesChannelContext);

        if ($transactionId) {
            try {
                // Verify if the transaction still exists and is in a PENDING state.
                $pendingTransaction = $this->transactionService->read(
                    $transactionId,
                    (string) $salesChannelContext->getSalesChannel()->getId()
                );
                if ($pendingTransaction->getState() === TransactionState::PENDING) {
                    return [$transactionId, $pendingTransaction];
                }
            } catch (\Exception $e) {
                // If the transaction cannot be read, we treat it as expired or invalid.
            }
        }

        $transactionId = $this->transactionService->createTransaction($salesChannelContext, $event, $lineItems);
        $this->storeTransactionIdInContext($salesChannelContext, $transactionId);

        return [$transactionId, null];
    }

    /**
     * Updates the WhitelabelMachineName transaction when the payload that would be sent differs from
     * the one that was last sent for this transaction.
     *
     * The comparison is a fingerprint over exactly the transmitted fields (currency, language,
     * billing and shipping address, line items). It is persisted next to the transaction ID, so an
     * unchanged checkout does not trigger an update API call on every single page view.
     *
     * @param SalesChannelContext $salesChannelContext The current context.
     * @param int $transactionId The WhitelabelMachineName transaction ID to update.
     * @param array $lineItems Line items as they would be sent to the portal.
     * @param Transaction $transaction The transaction as read from the API, providing its version.
     */
    private function updateTempTransactionIfNeeded(
        SalesChannelContext $salesChannelContext,
        int $transactionId,
        array $lineItems,
        Transaction $transaction
    ): void {
        $previousFingerprint = $this->getFingerprintFromContext($salesChannelContext, $transactionId);

        $fingerprint = $this->transactionService->buildTempTransactionFingerprint(
            $salesChannelContext,
            $lineItems,
            $previousFingerprint
        );

        if ($previousFingerprint === $fingerprint) {
            // Nothing the portal knows about has changed - keep the per-request state in sync and skip the call.
            $this->storeCheckoutState($salesChannelContext, $transactionId, $fingerprint);

            return;
        }

        // Pass the version we already know, so updateTempTransaction() does not read the transaction
        // again. A missing version falls back to the read, keeping the previous behaviour.
        $version = $transaction->getVersion();

        try {
            $this->transactionService->updateTempTransaction(
                $salesChannelContext,
                $transactionId,
                $lineItems,
                $version === null ? null : (int) $version
            );
        } catch (\Exception $e) {
            // Reusing the version read earlier widens the window for a concurrent request to bump
            // it in between, which the portal rejects. Retry once without a version so the SDK
            // re-reads the current one. The route decorator has no error handling of its own, so an
            // unhandled versioning conflict here would surface as a 500 on the confirm page.
            $this->transactionService->updateTempTransaction(
                $salesChannelContext,
                $transactionId,
                $lineItems
            );
        }

        $this->storeCheckoutState($salesChannelContext, $transactionId, $fingerprint);
        $this->transactionService->storePendingTransactionFingerprint($salesChannelContext, $transactionId, $fingerprint);
    }

    /**
     * Retrieves the stored WhitelabelMachineName transaction ID from the request context or the
     * persisted pending transaction record.
     *
     * @param SalesChannelContext $salesChannelContext The context.
     * @return int|null The transaction ID if found, otherwise null.
     */
    public function getTransactionIdFromContext(SalesChannelContext $salesChannelContext): ?int
    {
        /** @var ArrayEntity|null $ext */
        $ext = $salesChannelContext->getContext()->getExtension(TransactionService::CHECKOUT_STATE_EXTENSION);
        if ($ext instanceof ArrayEntity && $ext->get('transactionId')) {
            return (int) $ext->get('transactionId');
        }

        return $this->transactionService->getTransactionIdFromContext($salesChannelContext);
    }

    /**
     * Retrieves the fingerprint of the payload last sent for the given transaction, preferring the
     * per-request context state over the persisted record.
     *
     * @param SalesChannelContext $salesChannelContext
     * @param int $transactionId
     * @return string|null
     */
    private function getFingerprintFromContext(SalesChannelContext $salesChannelContext, int $transactionId): ?string
    {
        /** @var ArrayEntity|null $ext */
        $ext = $salesChannelContext->getContext()->getExtension(TransactionService::CHECKOUT_STATE_EXTENSION);
        if (
            $ext instanceof ArrayEntity
            && (int) $ext->get('transactionId') === $transactionId
            && $ext->get('fingerprint') !== null
        ) {
            return (string) $ext->get('fingerprint');
        }

        return $this->transactionService->getPendingTransactionFingerprint($salesChannelContext, $transactionId);
    }

    /**
     * Stores the checkout state for the remainder of the request.
     *
     * @param SalesChannelContext $salesChannelContext
     * @param int $transactionId
     * @param string|null $fingerprint
     */
    private function storeCheckoutState(SalesChannelContext $salesChannelContext, int $transactionId, ?string $fingerprint): void
    {
        $salesChannelContext->getContext()->addExtension(
            TransactionService::CHECKOUT_STATE_EXTENSION,
            new ArrayEntity([
                'transactionId' => $transactionId,
                'fingerprint'   => $fingerprint,
            ])
        );
    }

    /**
     * Persists the transaction ID in the request context. Cache persistence is handled by
     * TransactionService when the transaction is created.
     *
     * @param SalesChannelContext $salesChannelContext The context.
     * @param int $transactionId The transaction ID to store.
     */
    private function storeTransactionIdInContext(SalesChannelContext $salesChannelContext, int $transactionId): void
    {
        $this->storeCheckoutState($salesChannelContext, $transactionId, null);
    }
}
