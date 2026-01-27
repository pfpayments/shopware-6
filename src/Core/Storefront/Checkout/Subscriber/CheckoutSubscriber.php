<?php

declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Storefront\Checkout\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\{
    Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection,
    Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates,
    Checkout\Order\OrderEntity,
    Content\MailTemplate\Service\Event\MailBeforeValidateEvent
};
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent;
use Shopware\Storefront\Page\Account\PaymentMethod\AccountPaymentMethodPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
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
            CheckoutConfirmPageLoadedEvent::class      => 'onPageLoaded',
            AccountEditOrderPageLoadedEvent::class     => 'onPageLoaded',
            AccountPaymentMethodPageLoadedEvent::class => 'onPageLoaded',
            "subscription." . CheckoutConfirmPageLoadedEvent::class => ['onPageLoaded', 1],
            MailBeforeValidateEvent::class => ['onMailBeforeValidate', 1],
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

            // If we are on a checkout or account page and have a pending transaction, provide integration data.
            $transactionId = $salesChannelContext->getContext()->getExtension('postfinancecheckout_transaction_id');
            if ($transactionId) {
                $paymentConfig = $this->paymentIntegrationService->getConfigForTransaction(
                    (int) $transactionId->getVars()['value'],
                    $salesChannelContext
                );
                $event->getPage()->addExtension('postFinanceCheckoutData', $paymentConfig);
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

        /** @var OrderEntity|null $order */
        $order = !empty($templateData['order']) && $templateData['order'] instanceof OrderEntity ? $templateData['order'] : null;

        if (!empty($order) && $order->getAmountTotal() > 0) {
            // Check if WhitelabelMachineName emails are enabled for this sales channel.
            $isPostFinanceCheckoutEmailSettingEnabled = (bool)$this->settingsService->getSettings($order->getSalesChannelId())->isEmailEnabled();

            if (!$isPostFinanceCheckoutEmailSettingEnabled) {
                return;
            }

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
        }
    }
}
