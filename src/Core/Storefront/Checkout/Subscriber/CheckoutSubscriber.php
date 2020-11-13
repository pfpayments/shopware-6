<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Storefront\Checkout\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\{
	Checkout\Order\OrderEntity,
	Content\MailTemplate\Service\Event\MailBeforeSentEvent,
	Content\MailTemplate\Service\Event\MailBeforeValidateEvent,
	Framework\Struct\ArrayStruct};
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use PostFinanceCheckoutPayment\Core\{
	Api\Transaction\Service\OrderMailService,
	Checkout\PaymentHandler\PostFinanceCheckoutPaymentHandler,
	Settings\Service\SettingsService,
	Util\PaymentMethodUtil};

/**
 * Class CheckoutSubscriber
 *
 * @package PostFinanceCheckoutPayment\Storefront\Checkout\Subscriber
 */
class CheckoutSubscriber implements EventSubscriberInterface {

	/**
	 * @var \Psr\Log\LoggerInterface
	 */
	protected $logger;

	/**
	 * @var \PostFinanceCheckoutPayment\Core\Util\PaymentMethodUtil
	 */
	private $paymentMethodUtil;

	/**
	 * @var \PostFinanceCheckoutPayment\Core\Settings\Service\SettingsService
	 */
	private $settingsService;

	/**
	 * CheckoutSubscriber constructor.
	 *
	 * @param \PostFinanceCheckoutPayment\Core\Settings\Service\SettingsService $settingsService
	 * @param \PostFinanceCheckoutPayment\Core\Util\PaymentMethodUtil           $paymentMethodUtil
	 */
	public function __construct(SettingsService $settingsService, PaymentMethodUtil $paymentMethodUtil)
	{
		$this->settingsService   = $settingsService;
		$this->paymentMethodUtil = $paymentMethodUtil;
	}

	/**
	 * @param \Psr\Log\LoggerInterface $logger
	 * @internal
	 * @required
	 *
	 */
	public function setLogger(LoggerInterface $logger): void
	{
		$this->logger = $logger;
	}

	/**
	 * @return array
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			CheckoutConfirmPageLoadedEvent::class => ['onConfirmPageLoaded', 1],
			MailBeforeValidateEvent::class        => ['onMailBeforeValidate', 1],
			MailBeforeSentEvent::class            => ['onMailBeforeSent', 1],
		];
	}

	/**
	 * Stop order emails being sent out
	 *
	 * @see https://issues.shopware.com/issues/NEXT-9067
	 *
	 * @param \Shopware\Core\Content\MailTemplate\Service\Event\MailBeforeValidateEvent $event
	 */
	public function onMailBeforeValidate(MailBeforeValidateEvent $event): void
	{
		$templateData = $event->getTemplateData();
		if (!empty($templateData['order']) && ($templateData['order'] instanceof OrderEntity)) {
			/**
			 * @var $order OrderEntity
			 */
			$order                                     = $templateData['order'];
			$isPostFinanceCheckoutEmail = isset($templateData[OrderMailService::EMAIL_ORIGIN_IS_POSTFINANCECHECKOUT]);

			if (
				$this->settingsService->getSettings($order->getSalesChannelId())->isEmailEnabled() &&
				!$isPostFinanceCheckoutEmail &&
				$order->getTransactions()->last()->getPaymentMethod() &&
				PostFinanceCheckoutPaymentHandler::class == $order->getTransactions()->last()->getPaymentMethod()->getHandlerIdentifier()
			) {
				$this->logger->info('Email disabled for ', ['orderId' => $order->getId()]);
				$event->getContext()->addExtension('postfinancecheckout-disable', new ArrayStruct());
				$event->stopPropagation();
			}
		}
	}

	/**
	 * @param \Shopware\Core\Content\MailTemplate\Service\Event\MailBeforeSentEvent $event
	 */
	public function onMailBeforeSent(MailBeforeSentEvent $event): void
	{
		if ($event->getContext()->hasExtension('postfinancecheckout-disable')) {
			$event->stopPropagation();
		}
	}

	/**
	 * @param \Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent $event
	 */
	public function onConfirmPageLoaded(CheckoutConfirmPageLoadedEvent $event): void
	{
		try {
			$settings = $this->settingsService->getValidSettings($event->getSalesChannelContext()->getSalesChannel()->getId());
			if (is_null($settings)) {
				$this->logger->notice('Removing payment methods because settings are invalid');
				$this->removePostFinanceCheckoutPaymentMethodFromConfirmPage($event);
			}

		} catch (\Exception $e) {
			$this->logger->error($e->getMessage());
			$this->removePostFinanceCheckoutPaymentMethodFromConfirmPage($event);
		}
	}

	/**
	 * @param \Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent $event
	 */
	private function removePostFinanceCheckoutPaymentMethodFromConfirmPage(CheckoutConfirmPageLoadedEvent $event): void
	{
		$paymentMethodCollection = $event->getPage()->getPaymentMethods();
		$paymentMethodIds        = $this->paymentMethodUtil->getPostFinanceCheckoutPaymentMethodIds($event->getContext());
		foreach ($paymentMethodIds as $paymentMethodId) {
			$paymentMethodCollection->remove($paymentMethodId);
		}
	}
}