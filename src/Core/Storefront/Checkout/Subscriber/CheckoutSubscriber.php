<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Storefront\Checkout\Subscriber;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\{Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection,
  Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates,
  Checkout\Order\OrderEntity,
  Content\MailTemplate\Service\Event\MailBeforeValidateEvent};
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use PostFinanceCheckoutPayment\Core\{Api\Transaction\Service\TransactionService,
  Checkout\PaymentHandler\PostFinanceCheckoutPaymentHandler,
  Settings\Service\SettingsService,
  Settings\Struct\Settings,
  Util\PaymentMethodUtil};
use PostFinanceCheckoutPayment\Core\Api\PaymentMethodConfiguration\Service\PaymentMethodConfigurationService;
// The previous import block here listed SDK models under PostFinanceCheckoutPayment\Sdk, which is
// not where the SDK lives - none of them were referenced, so the broken namespace never surfaced.
use PostFinanceCheckout\Sdk\Model\Transaction;
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

    /**
     * @var \Psr\Cache\CacheItemPoolInterface
     * Cache for the API's answer to "which payment methods are possible for this transaction".
     */
    private CacheItemPoolInterface $cache;

    /**
     * @var int
     * Lifetime of a cached payment method list, in seconds. Zero disables the cache entirely.
     */
    private int $possibleMethodsCacheTtl;

    /**
     * Cache key prefix for the possible payment methods of a transaction.
     */
    private const POSSIBLE_METHODS_CACHE_PREFIX = 'pfcn_possible_methods_';

    /**
     * CheckoutSubscriber constructor.
     *
     * @param \PostFinanceCheckoutPayment\Core\Api\PaymentMethodConfiguration\Service\PaymentMethodConfigurationService $paymentMethodConfigurationService
     * @param \PostFinanceCheckoutPayment\Core\Api\Transaction\Service\TransactionService $transactionService
     * @param \PostFinanceCheckoutPayment\Core\Settings\Service\SettingsService $settingsService
     * @param \PostFinanceCheckoutPayment\Core\Util\PaymentMethodUtil $paymentMethodUtil
     * @param \Psr\Cache\CacheItemPoolInterface $cache
     * @param int $possibleMethodsCacheTtl
     */
    public function __construct(PaymentMethodConfigurationService $paymentMethodConfigurationService, TransactionService $transactionService, SettingsService $settingsService, PaymentMethodUtil $paymentMethodUtil, CacheItemPoolInterface $cache, int $possibleMethodsCacheTtl)
    {
		$this->paymentMethodConfigurationService = $paymentMethodConfigurationService;
		$this->transactionService = $transactionService;
		$this->settingsService = $settingsService;
		$this->paymentMethodUtil = $paymentMethodUtil;
		$this->cache = $cache;
		$this->possibleMethodsCacheTtl = $possibleMethodsCacheTtl;
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
			CheckoutConfirmPageLoadedEvent::class  => 'onCheckoutConfirmLoaded',
			AccountEditOrderPageLoadedEvent::class => 'onAccountOrderEditLoaded',
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
			$this->handlePaymentMethodFiltering($event);
		} catch (\Throwable $e) {
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

		// Resolve the pending transaction once. The returned transaction carries the version, so
		// neither the update below nor anything else has to read it a second time. It is null when a
		// transaction was just created, which already carries the current payload.
		[$createdTransactionId, $pendingTransaction] = $this->transactionService
		  ->resolvePendingTransaction($salesChannelContext, $event);

		$fingerprint = $this->updateTempTransactionIfNeeded(
		  $salesChannelContext,
		  $createdTransactionId,
		  $pendingTransaction
		);

		$this->getAvailablePaymentMethods($settings, $createdTransactionId, $salesChannelContext, $fingerprint);
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
	 * Fetches the list of payment methods the portal allows for the pending transaction.
	 *
	 * The answer is cached under a key that already covers the space, the integration, the
	 * transaction and the exact payload the transaction carries. Any change to the address, currency
	 * or language produces a different key, so the only staleness window is a payment method being
	 * reconfigured in the portal - which the short TTL bounds.
	 *
	 * @param Settings $settings
	 * @param int $createdTransactionId
	 * @param SalesChannelContext $salesChannelContext
	 * @param string $stateFingerprint Fingerprint identifying the payload the transaction carries.
	 * @return void
	 */
	private function getAvailablePaymentMethods(
	  Settings $settings,
	  int $createdTransactionId,
	  SalesChannelContext $salesChannelContext,
	  string $stateFingerprint
	): void
	{
		$cacheItem = null;

		if ($this->possibleMethodsCacheTtl > 0) {
			$cacheKey = self::POSSIBLE_METHODS_CACHE_PREFIX . hash('sha256', implode('|', [
				(string) $settings->getSpaceId(),
				(string) $settings->getIntegration(),
				(string) $createdTransactionId,
				$stateFingerprint,
			]));

			$cacheItem = $this->cache->getItem($cacheKey);
			if ($cacheItem->isHit()) {
				$cached = $cacheItem->get();
				// An empty list is a valid answer, so only the type is checked here.
				if (is_array($cached)) {
					$salesChannelContext->getContext()->addExtension(
					  TransactionService::POSSIBLE_METHODS_EXTENSION,
					  new ArrayEntity(['ids' => $cached])
					);

					return;
				}
			}
		}

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

		if ($cacheItem !== null) {
			$cacheItem->set($arrayOfPossibleMethods);
			$cacheItem->expiresAfter($this->possibleMethodsCacheTtl);
			$this->cache->save($cacheItem);
		}

		$salesChannelContext->getContext()->addExtension(
		  TransactionService::POSSIBLE_METHODS_EXTENSION,
		  new ArrayEntity(['ids' => $arrayOfPossibleMethods])
		);
	}

	/**
	 * Filters the original payment method collection (which already has Shopware's availability rules applied)
	 * to only include WhitelabelMachineName methods that are also allowed by the API.
	 * Non-WhitelabelMachineName methods are kept as-is.
	 *
	 * @param int $spaceId
	 * @param $event
	 * @return void
	 */
	private function setPossiblePaymentMethods(int $spaceId, $event): void
	{
		$paymentMethodCollection = $event->getPage()->getPaymentMethods();

		$paymentMethodConfigurations = $this->paymentMethodConfigurationService
		  ->getAllPaymentMethodConfigurations($spaceId, $event->getSalesChannelContext()->getContext());

		$allowedIds = $this->getAllowedPaymentMethodIds($event->getSalesChannelContext());

		// Build a map of Shopware payment method ID => configuration for methods allowed by the API.
		$allowedWLConfigByPmId = [];
		foreach ($paymentMethodConfigurations as $paymentMethodConfiguration) {
			if ($paymentMethodConfiguration->getPaymentMethod() === null) {
				continue;
			}

			$pmId = $paymentMethodConfiguration->getPaymentMethod()->getId();
			$pmConfigId = $paymentMethodConfiguration->getPaymentMethodConfigurationId();

			if ($paymentMethodConfiguration->getSpaceId() === $spaceId
			  && \in_array($pmConfigId, $allowedIds, true)) {
				$allowedWLConfigByPmId[$pmId] = $paymentMethodConfiguration;
			}
		}

		// Filter the original collection to preserve Shopware's availability rule filtering.
		// Non-WLM methods pass through unchanged; WLM methods are kept only if allowed by the API.
		$collection = new PaymentMethodCollection();
		foreach ($paymentMethodCollection as $method) {
			$isPostFinanceCheckoutPM = PostFinanceCheckoutPaymentHandler::class === $method->getHandlerIdentifier();

			if (!$isPostFinanceCheckoutPM) {
				$collection->add($method);
				continue;
			}

			if (isset($allowedWLConfigByPmId[$method->getId()])) {
				$method->addExtension('postfinancecheckout_config', $allowedWLConfigByPmId[$method->getId()]);
				$collection->add($method);
			}
		}

		$collection->sort(function ($a, $b) {
			return ($a->getPosition() ?? 0) <=> ($b->getPosition() ?? 0);
		});

		$event->getPage()->setPaymentMethods($collection);
	}

	/**
	 * Updates the PostFinanceCheckout transaction when the payload that would be sent differs from
	 * the one that was last sent for this transaction.
	 *
	 * The comparison is a fingerprint over exactly the transmitted fields (currency, language and
	 * billing address). It is persisted next to the transaction ID, so an unchanged checkout does not
	 * trigger an update API call on every single page view. The previous implementation hashed the
	 * whole customer entity, which changes whenever an unrelated association is lazily loaded and
	 * therefore forced an update on nearly every request.
	 *
	 * @param SalesChannelContext $salesChannelContext
	 * @param int $createdTransactionId
	 * @param Transaction|null $pendingTransaction The transaction as read from the API, providing its
	 *                                            version. Null when it was just created.
	 * @return string The fingerprint the transaction now carries.
	 */
	private function updateTempTransactionIfNeeded(
	  SalesChannelContext $salesChannelContext,
	  int $createdTransactionId,
	  ?Transaction $pendingTransaction
	): string
	{
		$fingerprint = $this->transactionService->buildTempTransactionFingerprint($salesChannelContext);

		// A transaction that was just created already carries the current payload, so no update is
		// due. Record its fingerprint so the next page view does not send a pointless update either.
		if ($pendingTransaction === null || !$createdTransactionId) {
			$this->storeCheckoutState($salesChannelContext, $createdTransactionId, $fingerprint);

			return $fingerprint;
		}

		$previousFingerprint = $this->getFingerprintFromContext($salesChannelContext, $createdTransactionId);

		if ($previousFingerprint === $fingerprint) {
			// Nothing the portal knows about has changed - keep the per-request state in sync and skip the call.
			$this->storeCheckoutState($salesChannelContext, $createdTransactionId, $fingerprint);

			return $fingerprint;
		}

		// Pass the version we already know, so updateTempTransaction() does not read the transaction
		// again. A missing version falls back to the read, keeping the previous behaviour.
		$version = $pendingTransaction->getVersion();

		try {
			$this->transactionService->updateTempTransaction(
			  $salesChannelContext,
			  $createdTransactionId,
			  $version === null ? null : (int) $version
			);
		} catch (\Exception $e) {
			// Reusing the version read earlier widens the window for a concurrent request to bump it
			// in between, which the portal rejects. Retry once without a version so the SDK re-reads
			// the current one.
			$this->transactionService->updateTempTransaction($salesChannelContext, $createdTransactionId);
		}

		$this->storeCheckoutState($salesChannelContext, $createdTransactionId, $fingerprint);

		return $fingerprint;
	}

	/**
	 * Retrieves the fingerprint of the payload last sent for the given transaction, preferring the
	 * per-request context state over the persisted record.
	 *
	 * @param SalesChannelContext $salesChannelContext
	 * @param int $transactionId
	 * @return string|null
	 */
	private function getFingerprintFromContext(SalesChannelContext $salesChannelContext, int $transactionId): ?string
	{
		/** @var ArrayEntity|null $ext */
		$ext = $salesChannelContext->getContext()->getExtension(TransactionService::CHECKOUT_STATE_EXTENSION);
		if (
		  $ext instanceof ArrayEntity
		  && (int) $ext->get('transactionId') === $transactionId
		  && $ext->get('fingerprint') !== null
		) {
			return (string) $ext->get('fingerprint');
		}

		return $this->transactionService->getPendingTransactionFingerprint($transactionId);
	}

	/**
	 * Stores the checkout state for the remainder of the request and persists the fingerprint.
	 *
	 * @param SalesChannelContext $salesChannelContext
	 * @param int $transactionId
	 * @param string $fingerprint
	 */
	private function storeCheckoutState(SalesChannelContext $salesChannelContext, int $transactionId, string $fingerprint): void
	{
		$salesChannelContext->getContext()->addExtension(
		  TransactionService::CHECKOUT_STATE_EXTENSION,
		  new ArrayEntity([
			'transactionId' => $transactionId,
			'fingerprint'   => $fingerprint,
		  ])
		);

		if ($transactionId) {
			$this->transactionService->storePendingTransactionFingerprint($transactionId, $fingerprint);
		}
	}

	/**
	 * @param SalesChannelContext $salesChannelContext
	 * @return array
	 */
	private function getAllowedPaymentMethodIds(SalesChannelContext $salesChannelContext): array
	{
		$ext = $salesChannelContext->getContext()->getExtension(TransactionService::POSSIBLE_METHODS_EXTENSION);
		return $ext instanceof ArrayEntity ? ($ext->get('ids') ?? []) : [];
	}
}
