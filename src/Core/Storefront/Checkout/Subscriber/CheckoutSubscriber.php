<?php

declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Storefront\Checkout\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\{
    Checkout\Cart\Event\CheckoutOrderPlacedEvent,
    Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection,
    Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates,
    Checkout\Order\OrderEntity,
    Content\MailTemplate\Service\Event\MailBeforeValidateEvent,
    Content\MailTemplate\Service\Event\MailErrorEvent
};
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use PostFinanceCheckoutPayment\Core\Api\Transaction\Service\OrderMailService;
use PostFinanceCheckoutPayment\Core\Checkout\PaymentHandler\PostFinanceCheckoutPaymentHandler;
use PostFinanceCheckoutPayment\Core\Settings\Service\SettingsService;
use PostFinanceCheckoutPayment\Core\Checkout\Service\PaymentMethodFilterService;
use PostFinanceCheckoutPayment\Core\Checkout\Service\PaymentIntegrationService;

/**
 * This subscriber listens to page load events in the Storefront to filter out
 * WhitelabelMachineName payment methods that are not applicable for the current cart or customer.
 */
class CheckoutSubscriber implements EventSubscriberInterface
{
    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * @var SettingsService
     */
    private SettingsService $settingsService;

    /**
     * @var PaymentMethodFilterService
     */
    private PaymentMethodFilterService $paymentMethodFilterService;

    /**
     * @var PaymentIntegrationService
     */
    private PaymentIntegrationService $paymentIntegrationService;

    /**
     * @param SettingsService $settingsService
     * @param PaymentMethodFilterService $paymentMethodFilterService
     * @param PaymentIntegrationService $paymentIntegrationService
     */
    public function __construct(
        SettingsService $settingsService,
        PaymentMethodFilterService $paymentMethodFilterService,
        PaymentIntegrationService $paymentIntegrationService
    ) {
        $this->settingsService = $settingsService;
        $this->paymentMethodFilterService = $paymentMethodFilterService;
        $this->paymentIntegrationService = $paymentIntegrationService;
    }

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Register events to listen to.
     *
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class  => 'onPageLoaded',
            AccountEditOrderPageLoadedEvent::class => 'onPageLoaded',
            "subscription." . CheckoutConfirmPageLoadedEvent::class => ['onPageLoaded', 1],
            MailBeforeValidateEvent::class => ['onMailBeforeValidate', 1],
            MailErrorEvent::class => 'onMailError',
        ];
    }

    /**
     * Handles filtering of payment methods when a relevant page is loaded.
     *
     * @param mixed $event The page loaded event.
     */
    public function onPageLoaded($event): void
    {
        try {
            $salesChannelContext = $event->getSalesChannelContext();

            // Access the payment methods available for the current page.
            $paymentMethodCollection = $event->getPage()->getPaymentMethods();

            // Delegate filtering to the centralized service.
            $filteredCollection = $this->paymentMethodFilterService->filterPaymentMethods(
                $paymentMethodCollection,
                $salesChannelContext,
                $event
            );

            // Update the page with the filtered list.
            $event->getPage()->setPaymentMethods($filteredCollection);

            // Provide the integration data the confirm page JS needs (SDK script URLs and the cart
            // recreate / checkout URLs). Without it the storefront plugin cannot find #checkoutUrl
            // and silently gives up, leaving the iframe/lightbox payment form unrendered.
            //
            // Restricted to the confirm page on purpose: it is the only page whose templates read
            // this extension, and for the iframe and lightbox integrations building the script URL
            // costs an API round trip.
            if ($event instanceof CheckoutConfirmPageLoadedEvent) {
                $paymentConfig = $this->paymentIntegrationService->getConfigForCheckoutPage($salesChannelContext);
                if ($paymentConfig !== null) {
                    $event->getPage()->addExtension('postFinanceCheckoutData', $paymentConfig);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }
    }

    /**
     * Handles logic before a mail is validated/sent.
     *
     * @param MailBeforeValidateEvent $event
     */
    public function onMailBeforeValidate(MailBeforeValidateEvent $event): void
    {
        $templateData = $event->getTemplateData();

        if (!empty($templateData[OrderMailService::EMAIL_ORIGIN_IS_POSTFINANCECHECKOUT])) {
            return;
        }

        if (($templateData['eventName'] ?? null) !== CheckoutOrderPlacedEvent::EVENT_NAME) {
            return;
        }

        /** @var OrderEntity|null $order */
        $order = !empty($templateData['order']) && $templateData['order'] instanceof OrderEntity ? $templateData['order'] : null;

        if (!empty($order) && $order->getAmountTotal() > 0) {
            $orderTransactions = $order->getTransactions();
            if (!($orderTransactions instanceof OrderTransactionCollection)) {
                return;
            }

            $orderTransactionLast = $orderTransactions->last();
            if (empty($orderTransactionLast) || empty($orderTransactionLast->getPaymentMethod())) {
                return;
            }

            // Check if the payment method used belongs to this plugin.
            $isPostFinanceCheckoutPM = PostFinanceCheckoutPaymentHandler::class == $orderTransactionLast->getPaymentMethod()->getHandlerIdentifier();
            if (!$isPostFinanceCheckoutPM) {
                return;
            }

            // Check if WhitelabelMachineName emails are enabled for this sales channel.
            $isPostFinanceCheckoutEmailSettingEnabled = (bool)$this->settingsService->getSettings($order->getSalesChannelId())->isEmailEnabled();
            if (!$isPostFinanceCheckoutEmailSettingEnabled) {
                $this->logger->debug('Order confirmation mail not sent. "Send order confirmation email" setting is disabled.', [
                    'orderId' => $order->getId(),
                ]);
                $event->stopPropagation();
                return;
            }

            // Verify if the transaction is in a state where an email should be handled.
            $isOrderTransactionStateOpen = in_array(
                $orderTransactionLast->getStateMachineState()->getTechnicalName(),
                [
                    OrderTransactionStates::STATE_OPEN,
                    OrderTransactionStates::STATE_IN_PROGRESS,
                ]
            );

            if (!$isOrderTransactionStateOpen) {
                return;
            }

            $this->logger->debug('Order confirmation mail deferred until the payment is successful.', [
                'orderId' => $order->getId(),
            ]);
            $event->stopPropagation();
        }
    }

    public function onMailError(MailErrorEvent $event): void
    {
        $templateData = $event->getTemplateData() ?? [];
        if (empty($templateData[OrderMailService::EMAIL_ORIGIN_IS_POSTFINANCECHECKOUT])) {
            return;
        }

        $this->logger->error('Order confirmation mail could not be sent and will not be resent: '. $event->getMessage(), [
            'orderId' => $templateData['orderId'] ?? null,
        ]);
    }
}
