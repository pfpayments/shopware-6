<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Storefront\Checkout\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\{Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection,
  Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates,
  Checkout\Order\OrderEntity,
  Content\MailTemplate\Service\Event\MailBeforeValidateEvent};
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent;
use Shopware\Storefront\Page\Account\PaymentMethod\AccountPaymentMethodPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use PostFinanceCheckoutPayment\Core\{Api\Transaction\Service\TransactionService,
  Checkout\PaymentHandler\PostFinanceCheckoutPaymentHandler,
  Settings\Service\SettingsService,
  Settings\Struct\Settings,
  Util\PaymentMethodUtil};
use PostFinanceCheckoutPayment\Core\Api\PaymentMethodConfiguration\Service\PaymentMethodConfigurationService;
use PostFinanceCheckoutPayment\Sdk\{Model\AddressCreate,
  Model\ChargeAttempt,
  Model\CreationEntityState,
  Model\CriteriaOperator,
  Model\EntityQuery,
  Model\EntityQueryFilter,
  Model\EntityQueryFilterType,
  Model\LineItemAttributeCreate,
  Model\LineItemCreate,
  Model\LineItemType,
  Model\TaxCreate,
  Model\Transaction,
  Model\TransactionCreate,
  Model\TransactionPending};
use Shopware\Core\Framework\Struct\ArrayEntity;

/**
 * Class CheckoutSubscriber
 *
 * @package PostFinanceCheckoutPayment\Storefront\Checkout\Subscriber
 */
class CheckoutSubscriber implements EventSubscriberInterface
{

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var \PostFinanceCheckoutPayment\Core\Api\PaymentMethodConfiguration\Service\PaymentMethodConfigurationService
     */
    private $paymentMethodConfigurationService;

    /**
     * @var \PostFinanceCheckoutPayment\Core\Api\Transaction\Service\TransactionService
     */
    private $transactionService;

    /**
     * @var \PostFinanceCheckoutPayment\Core\Settings\Service\SettingsService
     */
    private $settingsService;

    /**
     * @var \PostFinanceCheckoutPayment\Core\Util\PaymentMethodUtil
     */
    private $paymentMethodUtil;

	/** @var EntityRepository  */
	private EntityRepository $paymentMethodRepository;

    /**
     * CheckoutSubscriber constructor.
     *
     * @param \PostFinanceCheckoutPayment\Core\Api\PaymentMethodConfiguration\Service\PaymentMethodConfigurationService $paymentMethodConfigurationService
     * @param \PostFinanceCheckoutPayment\Core\Api\Transaction\Service\TransactionService $transactionService
     * @param \PostFinanceCheckoutPayment\Core\Settings\Service\SettingsService $settingsService
     * @param \PostFinanceCheckoutPayment\Core\Util\PaymentMethodUtil $paymentMethodUtil
     * @param EntityRepository $paymentMethodRepository
     */
    public function __construct(PaymentMethodConfigurationService $paymentMethodConfigurationService, TransactionService $transactionService, SettingsService $settingsService, PaymentMethodUtil $paymentMethodUtil, EntityRepository $paymentMethodRepository)
    {
		$this->paymentMethodConfigurationService = $paymentMethodConfigurationService;
		$this->transactionService = $transactionService;
		$this->settingsService = $settingsService;
		$this->paymentMethodUtil = $paymentMethodUtil;
		$this->paymentMethodRepository = $paymentMethodRepository;
    }

    /**
     * @param \Psr\Log\LoggerInterface $logger
     *
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
            CheckoutConfirmPageLoadedEvent::class      => 'onCheckoutConfirmLoaded',
            AccountEditOrderPageLoadedEvent::class     => 'onAccountOrderEditLoaded',
            AccountPaymentMethodPageLoadedEvent::class => 'onAccountPaymentMethodLoaded',
            "subscription." . CheckoutConfirmPageLoadedEvent::class => ['onConfirmPageLoaded', 1],
            MailBeforeValidateEvent::class => ['onMailBeforeValidate', 1],
        ];
    }

    /**
     * Stop order emails being sent out
     *
     * @param \Shopware\Core\Content\MailTemplate\Service\Event\MailBeforeValidateEvent $event
     */
    public function onMailBeforeValidate(MailBeforeValidateEvent $event): void
    {
        $templateData = $event->getTemplateData();

        /**
         * @var $order \Shopware\Core\Checkout\Order\OrderEntity
         */
        $order = !empty($templateData['order']) && $templateData['order'] instanceof OrderEntity ? $templateData['order'] : null;

        if (!empty($order) && $order->getAmountTotal() > 0) {

            $isPostFinanceCheckoutEmailSettingEnabled = $this->settingsService->getSettings($order->getSalesChannelId())->isEmailEnabled();

            if (!$isPostFinanceCheckoutEmailSettingEnabled) { //setting is disabled
                return;
            }

            $orderTransactions = $order->getTransactions();
            if (!($orderTransactions instanceof OrderTransactionCollection)) {
                return;
            }
            $orderTransactionLast = $orderTransactions->last();
            if (empty($orderTransactionLast) || empty($orderTransactionLast->getPaymentMethod())) { // no payment method available
                return;
            }

            $isPostFinanceCheckoutPM = PostFinanceCheckoutPaymentHandler::class == $orderTransactionLast->getPaymentMethod()->getHandlerIdentifier();
            if (!$isPostFinanceCheckoutPM) { // not our payment method
                return;
            }

            $isOrderTransactionStateOpen = in_array(
                $orderTransactionLast->getStateMachineState()->getTechnicalName(), [
                OrderTransactionStates::STATE_OPEN,
                OrderTransactionStates::STATE_IN_PROGRESS,
            ]);

            if (!$isOrderTransactionStateOpen) { // order payment status is open or in progress
                return;
            }
        }
    }

    /**
     * @param CheckoutConfirmPageLoadedEvent $event
     * @return void
     */
    public function onCheckoutConfirmLoaded(CheckoutConfirmPageLoadedEvent $event): void
    {
        try {
            $salesChannelContext = $event->getSalesChannelContext();
            $settings = $this->settingsService->getValidSettings($salesChannelContext->getSalesChannel()->getId());
            if (is_null($settings)) {
                $this->logger->notice('Removing payment methods because settings are invalid');
                $this->removePostFinanceCheckoutPaymentMethodFromConfirmPage($event);
            }

            $createdTransactionId = $this->transactionService->createPendingTransaction($salesChannelContext, $event);
            $this->updateTempTransactionIfNeeded($salesChannelContext, $createdTransactionId);

            $this->getAvailablePaymentMethods($settings, $createdTransactionId, $salesChannelContext);
            $this->setPossiblePaymentMethods($settings->getSpaceId(), $event);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            $this->removePostFinanceCheckoutPaymentMethodFromConfirmPage($event);
        }
    }

    /**
     * @param AccountEditOrderPageLoadedEvent $event
     * @return void
     */
    public function onAccountOrderEditLoaded(AccountEditOrderPageLoadedEvent $event): void
    {
        try {
            $this->handlePaymentMethodFiltering($event);
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage());
            $this->removePostFinanceCheckoutPaymentMethodFromConfirmPage($event);
        }
    }

    /**
     * @param AccountPaymentMethodPageLoadedEvent $event
     * @return void
     */
    public function onAccountPaymentMethodLoaded(AccountPaymentMethodPageLoadedEvent $event): void
    {
        try {
            $this->handlePaymentMethodFiltering($event);
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage());
            $this->removePostFinanceCheckoutPaymentMethodFromConfirmPage($event);
        }
    }

    /**
     * @param \Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent $event
     */
    public function onConfirmPageLoaded(CheckoutConfirmPageLoadedEvent $event): void
    {
        try {
            $this->handlePaymentMethodFiltering($event);
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage());
            $this->removePostFinanceCheckoutPaymentMethodFromConfirmPage($event);
        }
    }

    /**
     * @param $event
     * @return void
     */
    private function handlePaymentMethodFiltering($event): void
    {
        $salesChannelContext = $event->getSalesChannelContext();
        $settings = $this->settingsService->getValidSettings($salesChannelContext->getSalesChannel()->getId());

        if (is_null($settings)) {
            $this->logger->notice('Removing payment methods because settings are invalid');
            $this->removePostFinanceCheckoutPaymentMethodFromConfirmPage($event);
            return;
        }

        $createdTransactionId = $this->transactionService->createPendingTransaction($salesChannelContext, $event);
        $this->updateTempTransactionIfNeeded($salesChannelContext, $createdTransactionId);

        $this->getAvailablePaymentMethods($settings, $createdTransactionId, $salesChannelContext);
        $this->setPossiblePaymentMethods($settings->getSpaceId(), $event);
    }

    /**
     * @param $event
     * @return void
     */
    private function removePostFinanceCheckoutPaymentMethodFromConfirmPage($event): void
    {
        $paymentMethodCollection = $event->getPage()->getPaymentMethods();
        $paymentMethodIds = $this->paymentMethodUtil->getPostFinanceCheckoutPaymentMethodIds($event->getContext());
        foreach ($paymentMethodIds as $paymentMethodId) {
            $paymentMethodCollection->remove($paymentMethodId);
        }
    }

    /**
     * @param Settings $settings
     * @param int $createdTransactionId
     * @return void
     */
    private function getAvailablePaymentMethods(Settings $settings, int $createdTransactionId, SalesChannelContext $salesChannelContext): void
    {
        $transactionService = $settings->getApiClient()->getTransactionService();
        $possiblePaymentMethods = $transactionService->fetchPaymentMethods(
            $settings->getSpaceId(),
            $createdTransactionId,
            $settings->getIntegration()
        );
        $arrayOfPossibleMethods = [];
        foreach ($possiblePaymentMethods as $possiblePaymentMethod) {
            $arrayOfPossibleMethods[] = $possiblePaymentMethod->getId();
        }

        $salesChannelContext->getContext()->addExtension(
            'possibleMethods',
            new ArrayEntity(['ids' => $arrayOfPossibleMethods])
        );
    }

    /**
     * @param int $spaceId
     * @param CheckoutConfirmPageLoadedEvent $event
     * @return void
     */
    private function setPossiblePaymentMethods(int $spaceId, $event): void
    {
        $paymentIds = [];
        $paymentMethodCollection = $event->getPage()->getPaymentMethods();

        foreach ($paymentMethodCollection as $paymentMethodCollectionItem) {
            $isPostFinanceCheckoutPM = PostFinanceCheckoutPaymentHandler::class === $paymentMethodCollectionItem->getHandlerIdentifier();
            if (!$isPostFinanceCheckoutPM) {
                $paymentIds[] = $paymentMethodCollectionItem->getId();
            }
        }

        $allowedWLMethods = [];
        $paymentMethodConfigurations = $this->paymentMethodConfigurationService
            ->getAllPaymentMethodConfigurations($spaceId, $event->getSalesChannelContext()->getContext());

        foreach ($paymentMethodConfigurations as $paymentMethodConfiguration) {
            if ($paymentMethodConfiguration->getPaymentMethod() === null) {
                continue;
            }

            $pmId = $paymentMethodConfiguration->getPaymentMethod()->getId();
            $pmConfigId = $paymentMethodConfiguration->getPaymentMethodConfigurationId();
            $allowedIds = $this->getAllowedPaymentMethodIds($event->getSalesChannelContext());

            if ($paymentMethodConfiguration->getSpaceId() === $spaceId
                && \in_array($pmConfigId, $allowedIds, true)) {
                $allowedWLMethods[] = $pmId;
            }
        }

        $allPaymentIds = array_unique(array_merge($paymentIds, $allowedWLMethods));
        $collection = new PaymentMethodCollection();
        if (!empty($allPaymentIds)) {
            $criteria = new Criteria($allPaymentIds);
            $criteria->addFilter(new EqualsFilter('active', true));
            $criteria->addFilter(
                new EqualsFilter('salesChannels.id', $event->getSalesChannelContext()->getSalesChannelId())
            );

            $result = $this->paymentMethodRepository->search($criteria, $event->getContext());
            foreach ($result->getEntities() as $method) {
                if (!$collection->has($method->getId())) {
                    $collection->add($method);
                }
            }
        }

        $event->getPage()->setPaymentMethods($collection);
    }

	/**
	 * @param SalesChannelContext $salesChannelContext
	 * @param int $createdTransactionId
	 * @return void
	 */
	private function updateTempTransactionIfNeeded(SalesChannelContext $salesChannelContext, int $createdTransactionId): void
	{
		$ctx = $salesChannelContext->getContext();

		/** @var ArrayEntity|null $ext */
		$ext = $ctx->getExtension('checkoutState');

		$oldAddressHash = $ext instanceof ArrayEntity ? $ext->get('addressHash') : null;
		$oldCurrency    = $ext instanceof ArrayEntity ? $ext->get('currency') : null;

		$customer    = $salesChannelContext->getCustomer();
		$addressHash = md5(json_encode((array) $customer));
		$currency    = $salesChannelContext->getCurrency()->getIsoCode();

		$needsUpdate = ($oldAddressHash !== $addressHash) || ($oldCurrency !== $currency);

		if ($needsUpdate) {
			if ($createdTransactionId) {
				$this->transactionService->updateTempTransaction($salesChannelContext, $createdTransactionId);
			}

			$ctx->addExtension('possibleMethods', new ArrayEntity(['ids' => []]));
			$ctx->addExtension(
			  'checkoutState',
			  new ArrayEntity([
				'addressHash' => $addressHash,
				'currency'    => $currency,
			  ])
			);
		}
	}

	/**
	 * @param SalesChannelContext $salesChannelContext
	 * @return array
	 */
	private function getAllowedPaymentMethodIds(SalesChannelContext $salesChannelContext): array
	{
		$ext = $salesChannelContext->getContext()->getExtension('possibleMethods');
		return $ext instanceof ArrayEntity ? ($ext->get('ids') ?? []) : [];
	}
}
