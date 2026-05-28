<?php

declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Checkout\StoreApi\Route;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use PostFinanceCheckoutPayment\Core\Api\Transaction\Service\TransactionService;
use PostFinanceCheckoutPayment\Core\Checkout\Service\CartRecoveryService;

#[Package('checkout')]
#[Route(defaults: ['_routeScope' => ['store-api']])]
/**
 * This Store API route allows headless clients to recreate a cart from an existing order.
 * This is particularly useful for 'Try Again' scenarios if a payment fails.
 */
class CartRecoveryRoute
{
    /**
     * @var CartRecoveryService
     * Service to handle the logic of reconstructing a cart from order items.
     */
    private CartRecoveryService $cartRecoveryService;

    /**
     * @var TransactionService
     * Service to clear and manage transactions.
     */
    private TransactionService $transactionService;

    /**
     * @param CartRecoveryService $cartRecoveryService
     * @param TransactionService $transactionService
     */
    public function __construct(
        CartRecoveryService $cartRecoveryService,
        TransactionService $transactionService,
    ) {
        $this->cartRecoveryService = $cartRecoveryService;
        $this->transactionService = $transactionService;
    }

    /**
     * Recreates a cart based on the provided order ID.
     *
     * @param string $orderId The ID of the order to recover the cart from.
     * @param Request $request The incoming request.
     * @param SalesChannelContext $context The current sales channel context.
     * @return JsonResponse A JSON response containing either the new cart data or an error message.
     */
    #[Route(
        path: '/store-api/postfinancecheckout/checkout/recreate-cart/{orderId}',
        name: 'store-api.postfinancecheckout.checkout.recreate-cart',
        methods: ['POST']
    )]
    public function recreate(string $orderId, Request $request, SalesChannelContext $context): JsonResponse
    {
        try {
            // Fetch the order entity.
            $order = $this->cartRecoveryService->getOrderEntity($orderId, $context->getContext());

            // Security check: ensure the order belongs to the current sales channel.
            if ($order->getSalesChannelId() !== $context->getSalesChannelId()) {
                return new JsonResponse(['error' => 'Sales channel mismatch'], 403);
            }

            // Clear the transaction ID from cache and session to prevent the subsequent
            // checkout attempt from reusing a stale/failed transaction.
            $this->transactionService->clearTransactionIdFromContext($context);

            // Perform the cart reconstruction.
            $cart = $this->cartRecoveryService->recreateCartFromOrder($order, $context);

            // Return the reconstructed cart data.
            return new JsonResponse($cart);
        } catch (\Exception $e) {
            // Handle any exceptions during the process.
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }
}
