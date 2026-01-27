<?php

declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Checkout\Service;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use PostFinanceCheckoutPayment\Core\Api\Transaction\Service\TransactionService;
use PostFinanceCheckoutPayment\Core\Settings\Service\SettingsService;


/**
 * This service provides methods for retrieving invoice documents associated with WhitelabelMachineName transactions.
 * It abstracts the API calls needed to fetch invoice data from the WhitelabelMachineName platform.
 */
class InvoiceService
{
    /**
     * @var SettingsService
     * The service used to access sales channel specific settings.
     */
    protected SettingsService $settingsService;

    /**
     * @var TransactionService
     * The service used to handle transaction-specific operations.
     */
    protected TransactionService $transactionService;

    /**
     * @param SettingsService $settingsService
     * @param TransactionService $transactionService
     */
    public function __construct(
        SettingsService $settingsService,
        TransactionService $transactionService
    ) {
        $this->settingsService = $settingsService;
        $this->transactionService = $transactionService;
    }

    /**
     * Fetches the invoice document metadata for a given order.
     *
     * @param string $orderId The Shopware order ID.
     * @param SalesChannelContext $salesChannelContext The current context.
     *
     * @return object The invoice document metadata (instance of \PostFinanceCheckout\Sdk\Model\RenderedDocument).
     */
    public function getInvoiceDocument(string $orderId, SalesChannelContext $salesChannelContext): object
    {
        // Retrieve valid settings for the current sales channel.
        $settings = $this->settingsService->getSettings($salesChannelContext->getSalesChannel()->getId());

        // Fetch the local transaction entity associated with the order.
        $transactionEntity = $this->transactionService->getByOrderId($orderId, $salesChannelContext->getContext());

        // Perform the API call to WhitelabelMachineName to get the invoice document metadata.
        return $settings->getApiClient()->getTransactionService()->getInvoiceDocument(
            (int)$settings->getSpaceId(),
            (int)$transactionEntity->getTransactionId()
        );
    }
}
