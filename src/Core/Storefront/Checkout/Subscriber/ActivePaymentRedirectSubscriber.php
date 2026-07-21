<?php

declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Storefront\Checkout\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * This subscriber monitors loaded pages and checks if the page is a result of
 * user using the browser back button during checkout. If so, it redirects the user to recreate-cart.
 */
class ActivePaymentRedirectSubscriber implements EventSubscriberInterface
{
    private const ALLOWED_ROUTES = [
        'frontend.account.order.page',
        'frontend.account.edit-order.page',
        'frontend.checkout.confirm.page',
    ];

    /**
     * @param UrlGeneratorInterface $urlGenerator
     */
    public function __construct(private readonly UrlGeneratorInterface $urlGenerator) {}

    /**
     * Register events to listen to.
     *
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 8]];
    }

    /**
     * Handles redirection to the recreate-cart when navigating back with the browser's back button.
     *
     * @param mixed $event The page loaded event.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $scope = $request->attributes->get('_routeScope', []);
        if (!in_array('storefront', $scope, true)) {
            return;
        }

        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        $route = (string) $request->attributes->get('_route');

        // Return early if the page loads as intended. Exceptions are ALLOWED_ROUTES, which might indicate navigation back.
        if (!in_array($route, self::ALLOWED_ROUTES, true)) {
            return;
        }

        // If a user navigates from an iframe page to another page (especially “account/order“, an allowed route
        // 'frontend.account.order.page'), a cookie postfinancecheckout_deliberate was set and read here to 
        // prevent this function from wrongly redirecting the user to recreate-cart.
        if ($request->cookies->get('postfinancecheckout_deliberate')) {
            $session->remove('postfinancecheckoutActivePaymentOrderId');
            return;
        }

        // If the user entered checkout, postfinancecheckoutActivePaymentOrderId should’ve been set.
        $orderId = (string) $session->get('postfinancecheckoutActivePaymentOrderId', '');
        if ($orderId === '') {
            return;
        }

        $session->remove('postfinancecheckoutActivePaymentOrderId');

        // If all checks above pass, it means the user navigated back using the browser back button.
        $event->setResponse(new RedirectResponse(
            $this->urlGenerator->generate(
                'frontend.postfinancecheckout.checkout.recreate-cart',
                ['orderId' => $orderId]
            )
        ));
    }
}