<?php

declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Checkout\StoreApi\Route;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use PostFinanceCheckoutPayment\Core\Checkout\Service\PaymentIntegrationService;

/**
 * This route provides transaction-specific configuration for a given order.
 * It is primarily used by headless clients to initialize the WhitelabelMachineName payment iframe or lightbox.
 */
#[Package('checkout')]
#[Route(defaults: ['_routeScope' => ['store-api']])]
class WhitelabelMachineNameTransactionInfoRoute
{
    /**
     * @var PaymentIntegrationService
     * The service responsible for generating the unified payment configuration.
     */
    private PaymentIntegrationService $paymentIntegrationService;

    /**
     * @param PaymentIntegrationService $paymentIntegrationService
     */
    public function __construct(PaymentIntegrationService $paymentIntegrationService)
    {
        $this->paymentIntegrationService = $paymentIntegrationService;
    }

    /**
     * Loads the payment configuration for a specific order.
     *
     * @param string $orderId The Shopware order ID.
     * @param Request $request The incoming request object.
     * @param SalesChannelContext $context The current sales channel context.
     * @return JsonResponse JSON response containing the PaymentConfigStruct data.
     */
    #[Route(
        path: '/store-api/postfinancecheckout/transaction/info/{orderId}',
        name: 'store-api.postfinancecheckout.transaction.info',
        methods: ['GET']
    )]
    public function load(string $orderId, Request $request, SalesChannelContext $context): JsonResponse
    {
        try {
            // Retrieve the payment configuration using the common service.
            // This ensures logic parity between Storefront and Store API.
            $config = $this->paymentIntegrationService->getPaymentConfig($orderId, $context);

            // Return the configuration as a JSON response for the headless client.
            return new JsonResponse($config);
        } catch (\Exception $e) {
            // In case of error, return a 400 response with the error message.
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }
}
