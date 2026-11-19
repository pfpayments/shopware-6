<?php

declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\PluginCore;

use PostFinanceCheckout\PluginCore\Charge\ChargeService;
use PostFinanceCheckout\PluginCore\Document\DocumentService;
use PostFinanceCheckout\PluginCore\GlobalData\GlobalDataService;
use PostFinanceCheckout\PluginCore\LineItem\LineItemConsistencyService;
use PostFinanceCheckout\PluginCore\Refund\RefundService;
use PostFinanceCheckout\PluginCore\Render\IntegratedPaymentRenderService;
use PostFinanceCheckout\PluginCore\Sdk\ClientMetadataProviderInterface;
use PostFinanceCheckout\PluginCore\Sdk\SdkProvider;
use PostFinanceCheckout\PluginCore\Settings\Settings;
use PostFinanceCheckout\PluginCore\SharedKernel\CacheInterface;
use PostFinanceCheckout\PluginCore\Transaction\Completion\TransactionCompletionService;
use PostFinanceCheckout\PluginCore\Transaction\Invoice\InvoiceService;
use PostFinanceCheckout\PluginCore\Transaction\RecurringTransactionService;
use PostFinanceCheckout\PluginCore\Transaction\TransactionCommentService;
use PostFinanceCheckout\PluginCore\Transaction\TransactionService;
use PostFinanceCheckout\PluginCore\Webhook\WebhookService;
use PostFinanceCheckoutPayment\Core\PluginCore\Exception\PluginCoreNotConfiguredException;
use PostFinanceCheckoutPayment\Core\PluginCore\Log\PluginCoreLogger;
use PostFinanceCheckoutPayment\Core\PluginCore\Sdk\SdkGatewayProvider;
use PostFinanceCheckoutPayment\Core\PluginCore\Settings\SettingsProvider;

/**
 * Holds the plugin-core objects for one sales channel: settings, SdkProvider, gateways
 * and services.
 */
class PluginCoreServices
{
    private ?Settings $settings = null;
    private ?SdkProvider $sdkProvider = null;
    private ?SdkGatewayProvider $gateways = null;

    /** @var array<class-string, object> */
    private array $services = [];

    /**
     * @param SettingsProvider $settingsProvider
     * @param PluginCoreLogger $logger
     * @param ClientMetadataProviderInterface|null $clientMetadataProvider
     * @param CacheInterface|null $cache
     */
    public function __construct(
        private readonly SettingsProvider $settingsProvider,
        private readonly PluginCoreLogger $logger,
        private readonly ?ClientMetadataProviderInterface $clientMetadataProvider,
        private readonly ?CacheInterface $cache,
    ) {
    }

    /**
     * Returns the sales channel ID these services belong to.
     *
     * @return string|null
     */
    public function getSalesChannelId(): ?string
    {
        return $this->settingsProvider->getSalesChannelId();
    }

    /**
     * Returns the settings provider for this sales channel.
     *
     * @return SettingsProvider
     */
    public function getSettingsProvider(): SettingsProvider
    {
        return $this->settingsProvider;
    }

    /**
     * Returns plugin-core's settings for this sales channel.
     *
     * @return Settings
     */
    public function getSettings(): Settings
    {
        if (!isset($this->settings)) {
            $this->settings = new Settings($this->settingsProvider);
        }
        return $this->settings;
    }

    /**
     * Returns the logger with specified sales channel's ID.
     *
     * @return PluginCoreLogger
     */
    public function getLogger(): PluginCoreLogger
    {
        return $this->logger;
    }

    /**
     * Returns the configured space ID for this sales channel.
     *
     * @return int
     * @throws PluginCoreNotConfiguredException
     */
    public function getSpaceId(): int
    {
        try {
            return $this->getSettings()->getSpaceId();
        } catch (\InvalidArgumentException $e) {
            throw PluginCoreNotConfiguredException::forSalesChannel($this->getSalesChannelId(), $e);
        }
    }

    /**
     * Returns the SdkProvider with API client containing credentials for this sales channel.
     *
     * @return SdkProvider
     * @throws PluginCoreNotConfiguredException
     */
    public function getSdkProvider(): SdkProvider
    {
        if ($this->sdkProvider === null) {
            try {
                $this->sdkProvider = new SdkProvider($this->getSettings(), $this->clientMetadataProvider, $this->cache);
            } catch (\InvalidArgumentException $e) {
                throw PluginCoreNotConfiguredException::forSalesChannel($this->getSalesChannelId(), $e);
            }
        }
        return $this->sdkProvider;
    }

    /**
     * Returns the gateway provider for this sales channel.
     *
     * @return SdkGatewayProvider
     * @throws PluginCoreNotConfiguredException
     */
    public function getGatewayProviders(): SdkGatewayProvider
    {
        if (!isset($this->gateways)) {
            $this->gateways = new SdkGatewayProvider($this->getSdkProvider(), $this->getSettings(), $this->logger);
        }
        return $this->gateways;
    }

    /**
     * Returns the charge service.
     *
     * @return ChargeService
     */
    public function getChargeService(): ChargeService
    {
        return $this->service(
            ChargeService::class,
            fn() => new ChargeService($this->getGatewayProviders()->charge(), $this->logger),
        );
    }

    /**
     * Returns the document service.
     *
     * @return DocumentService
     */
    public function getDocumentService(): DocumentService
    {
        return $this->service(
            DocumentService::class,
            fn() => new DocumentService($this->getGatewayProviders()->document()),
        );
    }

    /**
     * Returns the global data service.
     *
     * @return GlobalDataService
     */
    public function getGlobalDataService(): GlobalDataService
    {
        return $this->service(
            GlobalDataService::class,
            fn() => new GlobalDataService($this->getGatewayProviders()->globalData(), $this->logger),
        );
    }

    /**
     * Returns the invoice service.
     *
     * @return InvoiceService
     */
    public function getInvoiceService(): InvoiceService
    {
        return $this->service(
            InvoiceService::class,
            fn() => new InvoiceService($this->getGatewayProviders()->invoice(), $this->logger),
        );
    }

    /**
     * Returns the line item consistency service.
     *
     * @return LineItemConsistencyService
     */
    public function getLineItemConsistencyService(): LineItemConsistencyService
    {
        return $this->service(
            LineItemConsistencyService::class,
            fn() => new LineItemConsistencyService($this->getSettings(), $this->logger),
        );
    }

    /**
     * Returns the integrated payment render service.
     *
     * @return IntegratedPaymentRenderService
     */
    public function getIntegratedPaymentRenderService(): IntegratedPaymentRenderService
    {
        return $this->service(
            IntegratedPaymentRenderService::class,
            fn() => new IntegratedPaymentRenderService(),
        );
    }

    /**
     * Returns the recurring transaction service.
     *
     * @return RecurringTransactionService
     */
    public function getRecurringTransactionService(): RecurringTransactionService
    {
        return $this->service(RecurringTransactionService::class, fn() => new RecurringTransactionService(
            $this->getTransactionService(),
            $this->getGatewayProviders()->recurringTransaction(),
            $this->logger,
        ));
    }

    /**
     * Returns the refund service.
     *
     * @return RefundService
     */
    public function getRefundService(): RefundService
    {
        return $this->service(RefundService::class, fn() => new RefundService(
            $this->getGatewayProviders()->refund(),
            $this->getTransactionService(),
            $this->logger,
        ));
    }

    /**
     * Returns the transaction comment service.
     *
     * @return TransactionCommentService
     */
    public function getTransactionCommentService(): TransactionCommentService
    {
        return $this->service(
            TransactionCommentService::class,
            fn() => new TransactionCommentService($this->getGatewayProviders()->transactionComment(), $this->logger),
        );
    }

    /**
     * Returns the transaction completion service.
     *
     * @return TransactionCompletionService
     */
    public function getTransactionCompletionService(): TransactionCompletionService
    {
        return $this->service(
            TransactionCompletionService::class,
            fn() => new TransactionCompletionService(
                $this->getGatewayProviders()->transactionCompletion(),
                $this->logger,
            ),
        );
    }

    /**
     * Returns the transaction service.
     *
     * @return TransactionService
     */
    public function getTransactionService(): TransactionService
    {
        return $this->service(TransactionService::class, fn() => new TransactionService(
            $this->getGatewayProviders()->transaction(),
            $this->getLineItemConsistencyService(),
            $this->logger,
        ));
    }

    /**
     * Returns the webhook management service.
     *
     * @return WebhookService
     */
    public function getWebhookService(): WebhookService
    {
        return $this->service(
            WebhookService::class,
            fn() => new WebhookService($this->getGatewayProviders()->webhookManagement(), $this->logger),
        );
    }

    /**
     * Returns the stored service for the given class, creating it on first call.
     *
     * @param class-string<T> $id
     * @param callable(): T $build
     * @return object
     */
    private function service(string $id, callable $build): object
    {
        if (!isset($this->services[$id])) {
            $this->services[$id] = $build();
        }

        return $this->services[$id];
    }
}
