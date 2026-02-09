<?php

declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Checkout\PaymentMethod\SalesChannel;

use Shopware\Core\Checkout\Payment\PaymentMethodDefinition;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractPaymentMethodRoute;
use Shopware\Core\Checkout\Payment\SalesChannel\PaymentMethodRouteResponse;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use PostFinanceCheckoutPayment\Core\Checkout\Service\PaymentMethodFilterService;

#[Package('checkout')]
/**
 * This decorator intercepts the Store API payment method route to apply WhitelabelMachineName-specific filtering.
 * It ensures that only payment methods valid for the current transaction (given currency, amount, etc.) are shown.
 */
class PaymentMethodRouteDecorator extends AbstractPaymentMethodRoute
{
    /**
     * @var AbstractPaymentMethodRoute
     * The original route being decorated.
     */
    private AbstractPaymentMethodRoute $decorated;

    /**
     * @var PaymentMethodFilterService
     * Service used to filter the payment methods based on WhitelabelMachineName API availability.
     */
    private PaymentMethodFilterService $paymentMethodFilterService;

    /**
     * @param AbstractPaymentMethodRoute $decorated
     * @param PaymentMethodFilterService $paymentMethodFilterService
     */
    public function __construct(
        AbstractPaymentMethodRoute $decorated,
        PaymentMethodFilterService $paymentMethodFilterService
    ) {
        $this->decorated = $decorated;
        $this->paymentMethodFilterService = $paymentMethodFilterService;
    }

    /**
     * Returns the decorated service.
     *
     * @return AbstractPaymentMethodRoute
     */
    public function getDecorated(): AbstractPaymentMethodRoute
    {
        return $this->decorated;
    }

    /**
     * Loads the payment methods and applies the WhitelabelMachineName filter to the result.
     *
     * @param Request $request
     * @param SalesChannelContext $context
     * @param Criteria $criteria
     * @return PaymentMethodRouteResponse
     */
    #[Route(
        path: '/store-api/payment-method',
        name: 'store-api.payment.method',
        methods: ['GET', 'POST'],
        defaults: ['_entity' => 'payment_method']
    )]
    public function load(Request $request, SalesChannelContext $context, Criteria $criteria): PaymentMethodRouteResponse
    {
        // Fetch the initial list of payment methods from the decorated service.
        $response = $this->decorated->load($request, $context, $criteria);

        $currentRoute = $request->attributes->get('_route');
        if ($currentRoute === 'frontend.checkout.finish.page' || !$this->allowFilterPaymentMethods($request)) {
            return $response;
        }

        $paymentMethods = $response->getPaymentMethods();

        // Apply WhitelabelMachineName-specific filtering logic via the dedicated service.
        $filteredCollection = $this->paymentMethodFilterService->filterPaymentMethods(
            $paymentMethods,
            $context
        );

        // Return the filtered results as a new response.
        return new PaymentMethodRouteResponse(
            new EntitySearchResult(
                'payment_method',
                (int)$filteredCollection->count(),
                $filteredCollection,
                null,
                $criteria,
                $context->getContext()
            )
        );
    }

    /**
     * We prevent filtering methods unless onlyAvailable is true.
     * This is because the filterPaymentMethods() function creates unnecessary pending transactions in the
     * portal when logged-in users navigate between pages.
     * The onlyAvailable flag applies rule-based filtering of payment methods and is usually true on checkout pages,
     * so we apply filterPaymentMethods() only when relevant.
     *
     * @param Request $request
     * @return bool
     */
    private function allowFilterPaymentMethods(Request $request): bool
    {
        if ($request->query->getBoolean('onlyAvailable') || $request->request->getBoolean('onlyAvailable')) {
            return true;
        }
        return false;
    }
}
