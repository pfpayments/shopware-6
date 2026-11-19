<?php

declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\PluginCore\Sdk;

use PostFinanceCheckout\PluginCore\Charge\ChargeGatewayInterface;
use PostFinanceCheckout\PluginCore\Document\DocumentGatewayInterface;
use PostFinanceCheckout\PluginCore\GlobalData\GlobalDataGatewayInterface;
use PostFinanceCheckout\PluginCore\Log\LoggerInterface;
use PostFinanceCheckout\PluginCore\PaymentMethod\PaymentMethodGatewayInterface;
use PostFinanceCheckout\PluginCore\Refund\RefundGatewayInterface;
use PostFinanceCheckout\PluginCore\Sdk\SdkProvider;
use PostFinanceCheckout\PluginCore\Sdk\WebServiceAPIV1\ChargeGateway;
use PostFinanceCheckout\PluginCore\Sdk\WebServiceAPIV1\DocumentGateway;
use PostFinanceCheckout\PluginCore\Sdk\WebServiceAPIV1\GlobalDataGateway;
use PostFinanceCheckout\PluginCore\Sdk\WebServiceAPIV1\InvoiceGateway;
use PostFinanceCheckout\PluginCore\Sdk\WebServiceAPIV1\PaymentMethodGateway;
use PostFinanceCheckout\PluginCore\Sdk\WebServiceAPIV1\RecurringTransactionGateway;
use PostFinanceCheckout\PluginCore\Sdk\WebServiceAPIV1\RefundGateway;
use PostFinanceCheckout\PluginCore\Sdk\WebServiceAPIV1\TransactionCommentGateway;
use PostFinanceCheckout\PluginCore\Sdk\WebServiceAPIV1\TransactionCompletionGateway;
use PostFinanceCheckout\PluginCore\Sdk\WebServiceAPIV1\TransactionGateway;
use PostFinanceCheckout\PluginCore\Sdk\WebServiceAPIV1\WebhookManagementGateway;
use PostFinanceCheckout\PluginCore\Sdk\WebServiceAPIV1\WebhookSignatureGateway;
use PostFinanceCheckout\PluginCore\Settings\Settings;
use PostFinanceCheckout\PluginCore\Transaction\Completion\TransactionCompletionGatewayInterface;
use PostFinanceCheckout\PluginCore\Transaction\Invoice\InvoiceGatewayInterface;
use PostFinanceCheckout\PluginCore\Transaction\RecurringTransactionGatewayInterface;
use PostFinanceCheckout\PluginCore\Transaction\TransactionCommentGatewayInterface;
use PostFinanceCheckout\PluginCore\Transaction\TransactionGatewayInterface;
use PostFinanceCheckout\PluginCore\Webhook\WebhookManagementGatewayInterface;
use PostFinanceCheckout\PluginCore\Webhook\WebhookSignatureGatewayInterface;

/**
 * Creates the plugin-core gateways for one sales channel and keeps one instance of each.
 */
class SdkGatewayProvider
{
    /** @var array<string, object> */
    private array $instances = [];

    /**
     * @param SdkProvider $sdkProvider
     * @param Settings $settings
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly SdkProvider $sdkProvider,
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Returns the charge gateway.
     *
     * @return ChargeGatewayInterface
     */
    public function charge(): ChargeGatewayInterface
    {
        return $this->once(
            ChargeGatewayInterface::class,
            fn() => new ChargeGateway($this->sdkProvider, $this->logger),
        );
    }

    /**
     * Returns the document gateway.
     *
     * @return DocumentGatewayInterface
     */
    public function document(): DocumentGatewayInterface
    {
        return $this->once(
            DocumentGatewayInterface::class,
            fn() => new DocumentGateway($this->sdkProvider, $this->logger),
        );
    }

    /**
     * Returns the global data gateway.
     *
     * @return GlobalDataGatewayInterface
     */
    public function globalData(): GlobalDataGatewayInterface
    {
        return $this->once(
            GlobalDataGatewayInterface::class,
            fn() => new GlobalDataGateway($this->sdkProvider, $this->logger),
        );
    }

    /**
     * Returns the invoice gateway.
     *
     * @return InvoiceGatewayInterface
     */
    public function invoice(): InvoiceGatewayInterface
    {
        return $this->once(
            InvoiceGatewayInterface::class,
            fn() => new InvoiceGateway($this->sdkProvider, $this->logger),
        );
    }

    /**
     * Returns the payment method gateway.
     *
     * @return PaymentMethodGatewayInterface
     */
    public function paymentMethod(): PaymentMethodGatewayInterface
    {
        return $this->once(
            PaymentMethodGatewayInterface::class,
            fn() => new PaymentMethodGateway($this->sdkProvider, $this->logger),
        );
    }

    /**
     * Returns the recurring transaction gateway.
     *
     * @return RecurringTransactionGatewayInterface
     */
    public function recurringTransaction(): RecurringTransactionGatewayInterface
    {
        return $this->once(
            RecurringTransactionGatewayInterface::class,
            fn() => new RecurringTransactionGateway($this->sdkProvider, $this->logger),
        );
    }

    /**
     * Returns the refund gateway.
     *
     * @return RefundGatewayInterface
     */
    public function refund(): RefundGatewayInterface
    {
        return $this->once(
            RefundGatewayInterface::class,
            fn() => new RefundGateway($this->sdkProvider, $this->logger),
        );
    }

    /**
     * Returns the transaction gateway.
     *
     * @return TransactionGatewayInterface
     */
    public function transaction(): TransactionGatewayInterface
    {
        return $this->once(
            TransactionGatewayInterface::class,
            fn() => new TransactionGateway($this->sdkProvider, $this->logger, $this->settings),
        );
    }

    /**
     * Returns the transaction comment gateway.
     *
     * @return TransactionCommentGatewayInterface
     */
    public function transactionComment(): TransactionCommentGatewayInterface
    {
        return $this->once(
            TransactionCommentGatewayInterface::class,
            fn() => new TransactionCommentGateway($this->sdkProvider, $this->logger),
        );
    }

    /**
     * Returns the transaction completion gateway.
     *
     * @return TransactionCompletionGatewayInterface
     */
    public function transactionCompletion(): TransactionCompletionGatewayInterface
    {
        return $this->once(
            TransactionCompletionGatewayInterface::class,
            fn() => new TransactionCompletionGateway($this->sdkProvider, $this->logger),
        );
    }

    /**
     * Returns the webhook management gateway.
     *
     * @return WebhookManagementGatewayInterface
     */
    public function webhookManagement(): WebhookManagementGatewayInterface
    {
        return $this->once(
            WebhookManagementGatewayInterface::class,
            fn() => new WebhookManagementGateway($this->sdkProvider, $this->logger),
        );
    }

    /**
     * Returns the webhook signature gateway.
     *
     * @return WebhookSignatureGatewayInterface
     */
    public function webhookSignature(): WebhookSignatureGatewayInterface
    {
        return $this->once(
            WebhookSignatureGatewayInterface::class,
            fn() => new WebhookSignatureGateway($this->sdkProvider, $this->logger),
        );
    }

    /**
     * Returns the stored instance for the given ID, creating it on first call.
     *
     * @param class-string<T> $id
     * @param callable(): T $build
     * @return T
     */
    private function once(string $id, callable $build): object
    {
        if (!isset($this->instances[$id])) {
            $this->instances[$id] = $build();
        }

        return $this->instances[$id];
    }
}
