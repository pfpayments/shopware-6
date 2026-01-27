<?php

declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Storefront\Checkout\Controller;

use PostFinanceCheckout\Sdk\Model\TransactionState as SdkTransactionState;
use PostFinanceCheckoutPayment\Core\Api\Transaction\Service\TransactionService;
use Psr\Log\LoggerInterface;
use Shopware\Core\{
    Checkout\Cart\CartException,
    Checkout\Order\SalesChannel\AbstractOrderRoute,
    Framework\Log\Package,
    Framework\Routing\RoutingException,
    Framework\DataAbstractionLayer\Search\Criteria,
    System\SalesChannel\SalesChannelContext
};
use Shopware\Storefront\{
    Controller\StorefrontController,
    Page\Checkout\Finish\CheckoutFinishPage,
    Page\GenericPageLoaderInterface
};
use Symfony\Component\{
    HttpFoundation\Request,
    HttpFoundation\Response,
    Routing\Attribute\Route
};
use PostFinanceCheckoutPayment\Core\{
    Checkout\Service\CartRecoveryService,
    Checkout\Service\PaymentIntegrationService,
    Settings\Service\SettingsService
};


#[Package('checkout')]
#[Route(defaults: ['_routeScope' => ['storefront']])]
/**
 * This controller handles Storefront-specific actions for the WhitelabelMachineName integration,
 * such as rendering the payment page and recreating a cart from a failed order.
 */
class CheckoutController extends StorefrontController
{
    /**
     * @var GenericPageLoaderInterface
     * Loader for basic Shopware page data.
     */
    protected GenericPageLoaderInterface $genericLoader;

    /**
     * @var SettingsService
     * Plugin settings service.
     */
    protected SettingsService $settingsService;

    /**
     * @var LoggerInterface|null
     * Logger for recording errors and important information.
     */
    private ?LoggerInterface $logger = null;

    /**
     * @var AbstractOrderRoute
     * Shopware service for order retrieval.
     */
    private AbstractOrderRoute $orderRoute;

    /**
     * @var CartRecoveryService
     * Service to help customers recover their cart from a past order.
     */
    private CartRecoveryService $cartRecoveryService;

    /**
     * @var PaymentIntegrationService
     * Service to provide the integration parameters (JS URL, transaction ID, etc.).
     */
    private PaymentIntegrationService $paymentIntegrationService;

    /**
     * @var TransactionService
     * Service to check transaction details.
     */
    private TransactionService $transactionService;

    /**
     * @param SettingsService $settingsService
     * @param GenericPageLoaderInterface $genericLoader
     * @param AbstractOrderRoute $orderRoute
     * @param CartRecoveryService $cartRecoveryService
     * @param PaymentIntegrationService $paymentIntegrationService
     */
    public function __construct(
        SettingsService $settingsService,
        GenericPageLoaderInterface $genericLoader,
        AbstractOrderRoute $orderRoute,
        CartRecoveryService $cartRecoveryService,
        PaymentIntegrationService $paymentIntegrationService,
        TransactionService $transactionService
    ) {
        $this->genericLoader = $genericLoader;
        $this->settingsService = $settingsService;
        $this->orderRoute = $orderRoute;
        $this->cartRecoveryService = $cartRecoveryService;
        $this->paymentIntegrationService = $paymentIntegrationService;
        $this->transactionService = $transactionService;
    }

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Renders the WhitelabelMachineName payment page (usually contains the iframe or lightbox script).
     *
     * @param SalesChannelContext $salesChannelContext The current context.
     * @param Request $request The incoming request.
     * @return Response The rendered payment page.
     */
    #[Route(
        path: "/postfinancecheckout/checkout/pay",
        name: "frontend.postfinancecheckout.checkout.pay",
        options: ["seo" => false],
        methods: ["GET"],
    )]
    public function pay(SalesChannelContext $salesChannelContext, Request $request): Response
    {
        $orderId = (string)$request->query->get('orderId');

        if (empty($orderId)) {
            throw RoutingException::missingRequestParameter('orderId');
        }

        try {
            // Load the order with necessary associations for the product table and addresses.
            $criteria = new Criteria([$orderId]);
            $criteria->addAssociation('lineItems.product')
                ->addAssociation('deliveries.shippingOrderAddress.country')
                ->addAssociation('orderCustomer.customer')
                ->addAssociation('transactions.paymentMethod');

            $order = $this->orderRoute->load(new Request(), $salesChannelContext, $criteria)->getOrders()->first();

            if (!$order) {
                throw RoutingException::missingRequestParameter('orderId');
            }

            // Fetch the configuration required for the frontend integration.
            $paymentConfig = $this->paymentIntegrationService->getPaymentConfig($orderId, $salesChannelContext);

            // Load a generic Shopware page to have layout headers/footers.
            $page = $this->genericLoader->load($request, $salesChannelContext);
            $page->addExtension('postFinanceCheckoutData', $paymentConfig);

            // Assign the order to the page so the templates can access page.order.
            $page->assign(['order' => $order]);

            // Render the specialized Twig template for WhitelabelMachineName.
            return $this->renderStorefront(
                '@PostFinanceCheckoutPayment/storefront/page/checkout/order/postfinancecheckout.html.twig',
                ['page' => $page]
            );
        } catch (\Exception $e) {
            if ($this->logger) {
                $this->logger->error($e->getMessage());
            }
            $this->addFlash('danger', $this->trans('postfinancecheckout.paymentMethod.notAvailable'));
            return $this->redirectToRoute('frontend.home.page');
        }
    }

    /**
     * Redirects the user to a route that recreates their cart from an existing order.
     * This is useful for allowing users to try payment again with different details.
     *
     * @param Request $request The incoming request.
     * @param SalesChannelContext $salesChannelContext The context.
     * @return Response Redirect to the checkout confirmation page.
     */
    #[Route(
        path: "/postfinancecheckout/checkout/recreate-cart",
        name: "frontend.postfinancecheckout.checkout.recreate-cart",
        options: ["seo" => false],
        methods: ["GET"],
    )]
    public function recreateCart(Request $request, SalesChannelContext $salesChannelContext): Response
    {
        $orderId = (string)$request->query->get('orderId');

        if (empty($orderId)) {
            throw RoutingException::missingRequestParameter('orderId');
        }

        try {
            // Find the order that should be recovered.
            $order = $this->cartRecoveryService->getOrderEntity($orderId, $salesChannelContext->getContext());

            // Security: Order must belong to the active sales channel.
            if ($order->getSalesChannelId() !== $salesChannelContext->getSalesChannelId()) {
                return $this->redirectToRoute('frontend.home.page');
            }

            // Perform the recovery process.
            $transactionEntity = $this->transactionService->getByOrderId($order->getId(), $salesChannelContext->getContext());
            if ($transactionEntity) {
                $transaction = $this->transactionService->read($transactionEntity->getTransactionId(), $salesChannelContext->getSalesChannelId());
                if (in_array($transaction->getState(), [
                    SdkTransactionState::AUTHORIZED,
                    SdkTransactionState::CONFIRMED,
                    SdkTransactionState::FULFILL
                ])) {
                    return $this->redirectToRoute('frontend.checkout.finish.page', ['orderId' => $orderId]);
                }

                if ($transaction->getUserFailureMessage()) {
                    $this->addFlash('danger', $transaction->getUserFailureMessage());
                }
            }
            $this->cartRecoveryService->recreateCartFromOrder($order, $salesChannelContext);
        } catch (\Exception $exception) {
            $this->addFlash('danger', $this->trans('error.addToCartError'));
            if ($this->logger) {
                $this->logger->critical($exception->getMessage());
            }
            return $this->redirectToRoute('frontend.home.page');
        }

        // Send the user back to the checkout confirm page with their items restored.
        return $this->redirectToRoute('frontend.checkout.confirm.page');
    }
}
