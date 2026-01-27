<?php

declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Checkout\StoreApi\Route;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use PostFinanceCheckoutPayment\Core\Checkout\Service\InvoiceService;

#[Package('checkout')]
#[Route(defaults: ['_routeScope' => ['store-api']])]
/**
 * This Store API route provides access to invoice documents for headless clients.
 */
class InvoiceRoute
{
    /**
     * @var InvoiceService
     * Service to handle the retrieval of invoice documents from WhitelabelMachineName.
     */
    private InvoiceService $invoiceService;

    /**
     * @param InvoiceService $invoiceService
     */
    public function __construct(InvoiceService $invoiceService)
    {
        $this->invoiceService = $invoiceService;
    }

    /**
     * Fetches the invoice document metadata and content for a given order.
     *
     * @param string $orderId The ID of the order.
     * @param Request $request The incoming request.
     * @param SalesChannelContext $context The current sales channel context.
     * @return JsonResponse A JSON response containing the invoice title, MIME type, and base64-encoded data.
     */
    #[Route(
        path: '/store-api/postfinancecheckout/account/order/invoice/{orderId}',
        name: 'store-api.postfinancecheckout.account.order.invoice',
        methods: ['GET']
    )]
    public function load(string $orderId, Request $request, SalesChannelContext $context): JsonResponse
    {
        try {
            // Retrieve the invoice document via the dedicated service.
            $invoice = $this->invoiceService->getInvoiceDocument($orderId, $context);

            // Structure the response for headless consumption.
            return new JsonResponse([
                'title' => (string)$invoice->getTitle(),
                'mimeType' => (string)$invoice->getMimeType(),
                'data' => (string)$invoice->getData(), // Base64 encoded
            ]);
        } catch (\Exception $e) {
            // Handle errors (e.g., order not found or API failure).
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }
}
