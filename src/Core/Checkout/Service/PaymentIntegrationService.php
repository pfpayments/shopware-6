<?php

declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Checkout\Service;

use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use PostFinanceCheckoutPayment\Core\Api\Transaction\Service\TransactionService;
use PostFinanceCheckoutPayment\Core\Settings\Options\Integration;
use PostFinanceCheckoutPayment\Core\Settings\Service\SettingsService;
use PostFinanceCheckoutPayment\Core\Checkout\Struct\PaymentConfigStruct;
use PostFinanceCheckout\Sdk\Model\TransactionState;

/**
 * This service provides the consolidated payment configuration needed for the frontend.
 * it handles the generation of JS URLs and provides integration parameters for both Storefront and headless clients.
 */
class PaymentIntegrationService
{
    /**
     * @var TransactionService
     * Service to handle transaction-specific API calls.
     */
    private TransactionService $transactionService;

    /**
     * @var SettingsService
     * Service to retrieve sales channel specific settings.
     */
    private SettingsService $settingsService;

    /**
     * @var TransactionManagementService
     * Service to manage transaction state.
     */
    private TransactionManagementService $transactionManagementService;

    /**
     * @var RouterInterface
     * Shopware router for generating callback and redirect URLs.
     */
    private RouterInterface $router;

    /**
     * @param TransactionService $transactionService
     * @param SettingsService $settingsService
     * @param TransactionManagementService $transactionManagementService
     * @param RouterInterface $router
     */
    public function __construct(
        TransactionService $transactionService,
        SettingsService $settingsService,
        TransactionManagementService $transactionManagementService,
        RouterInterface $router
    ) {
        $this->transactionService = $transactionService;
        $this->settingsService = $settingsService;
        $this->transactionManagementService = $transactionManagementService;
        $this->router = $router;
    }

    /**
     * Generates the payment configuration for a given transaction ID.
     * This is used on the checkout confirm page before the order is created.
     *
     * @param int $transactionId The WhitelabelMachineName transaction ID.
     * @param SalesChannelContext $salesChannelContext The context.
     * @return PaymentConfigStruct The consolidated integration data.
     */
    public function getConfigForTransaction(
        int $transactionId,
        SalesChannelContext $salesChannelContext
    ): PaymentConfigStruct {
        $settings = $this->settingsService->getSettings($salesChannelContext->getSalesChannel()->getId());

        // Fetch the transaction details from WhitelabelMachineName API.
        $postfinancecheckoutTransaction = $settings->getApiClient()->getTransactionService()->read(
            $settings->getSpaceId(),
            $transactionId
        );

        $javascriptUrl = $this->getTransactionJavaScriptUrl($settings, $transactionId);

        $possiblePaymentMethods = $settings->getApiClient()
            ->getTransactionService()
            ->fetchPaymentMethods(
                $settings->getSpaceId(),
                $transactionId,
                $settings->getIntegration()
            );

        $cartRecreateUrl = $this->router->generate(
            'frontend.checkout.cart.page',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $checkoutUrl = $this->router->generate(
            'frontend.checkout.confirm.page',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        return (new PaymentConfigStruct())
            ->setIntegration((string)$settings->getIntegration())
            ->setJavascriptUrl($javascriptUrl)
            ->setDeviceJavascriptUrl($this->getDeviceJavascriptUrl((int)$settings->getSpaceId()))
            ->setTransactionPossiblePaymentMethods($possiblePaymentMethods)
            ->setTransactionId($transactionId)
            ->setSpaceId((int)$settings->getSpaceId())
            ->setCartRecreateUrl($cartRecreateUrl)
            ->setCheckoutUrl($checkoutUrl);
    }

    /**
     * Generates the payment configuration for a given order.
     *
     * @param string $orderId The Shopware order ID.
     * @param SalesChannelContext $salesChannelContext The context.
     * @param string|null $cartRecreateUrl Optional override for the cart recreation URL.
     * @param string|null $checkoutUrl Optional override for the checkout confirmation URL.
     * @return PaymentConfigStruct The consolidated integration data.
     */
    public function getPaymentConfig(
        string $orderId,
        SalesChannelContext $salesChannelContext,
        ?string $cartRecreateUrl = null,
        ?string $checkoutUrl = null
    ): PaymentConfigStruct {
        // Retrieve settings and the transaction entity for the order.
        $settings = $this->settingsService->getSettings($salesChannelContext->getSalesChannel()->getId());
        $transactionEntity = $this->transactionService->getByOrderId($orderId, $salesChannelContext->getContext());

        // Default to storefront URLs if no overrides are provided.
        if ($cartRecreateUrl === null) {
            $cartRecreateUrl = $this->router->generate(
                'frontend.postfinancecheckout.checkout.recreate-cart',
                ['orderId' => $orderId],
                UrlGeneratorInterface::ABSOLUTE_URL
            );
        }

        if ($checkoutUrl === null) {
            $checkoutUrl = $this->router->generate(
                'frontend.checkout.confirm.page',
                [],
                UrlGeneratorInterface::ABSOLUTE_URL
            );
        }

        return $this->getConfigForTransaction((int)$transactionEntity->getTransactionId(), $salesChannelContext)
            ->setCartRecreateUrl($cartRecreateUrl)
            ->setCheckoutUrl($checkoutUrl);
    }

    /**
     * Determines the JavaScript URL for the WhitelabelMachineName integration.
     *
     * @param mixed $settings The plugin settings.
     * @param int $transactionId The transaction ID.
     * @return string The absolute URL to the JavaScript component.
     */
    private function getTransactionJavaScriptUrl($settings, int $transactionId): string
    {
        $javascriptUrl = '';
        switch ($settings->getIntegration()) {
            case Integration::IFRAME:
                $javascriptUrl = $settings->getApiClient()->getTransactionIframeService()
                    ->javascriptUrl($settings->getSpaceId(), $transactionId);
                break;
            case Integration::LIGHTBOX:
                $javascriptUrl = $settings->getApiClient()->getTransactionLightboxService()
                    ->javascriptUrl($settings->getSpaceId(), $transactionId);
                break;
        }
        return $javascriptUrl;
    }

    /**
     * Generates the device tracking JavaScript URL.
     *
     * @param int $spaceId The WhitelabelMachineName space ID.
     * @return string The tracking URL.
     */
    private function getDeviceJavascriptUrl(int $spaceId): string
    {
        return 'https://checkout.postfinance.ch/s/' . $spaceId . '/payment/device.js?session=' . Uuid::randomHex();
    }
}
