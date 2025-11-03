<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Storefront\Checkout\Controller;

use Psr\{
	Log\LoggerInterface,
	Cache\CacheItemPoolInterface 
};
use Shopware\Core\{
	Checkout\Payment\PaymentException,
	Checkout\Cart\Cart,
	Checkout\Cart\CartException,
	Checkout\Cart\LineItemFactoryRegistry,
	Checkout\Cart\SalesChannel\CartService,
	Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection,
	Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity,
	Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler,
	Checkout\Order\OrderEntity,
	Checkout\Order\OrderDefinition,
	Checkout\Order\SalesChannel\AbstractOrderRoute,
	Framework\Context,
	Framework\DataAbstractionLayer\Search\Criteria,
	Framework\DataAbstractionLayer\Search\Filter\EqualsFilter,
	Framework\DataAbstractionLayer\Search\Sorting\FieldSorting,
    Framework\Log\Package,
	Framework\Routing\Exception\MissingRequestParameterException,
	Framework\Uuid\Uuid,
	Framework\Uuid\Exception\InvalidUuidException,
	Framework\Validation\DataBag\RequestDataBag,
	System\SalesChannel\SalesChannelContext,
	System\StateMachine\StateMachineRegistry,
	System\StateMachine\Transition,
};
use Shopware\Storefront\{
	Controller\StorefrontController,
	Page\Checkout\Finish\CheckoutFinishPage,
	Page\GenericPageLoaderInterface
};
use Symfony\Component\{
	HttpFoundation\Request,
	HttpFoundation\Response,
	HttpFoundation\RedirectResponse,
	Routing\Attribute\Route,
	Routing\Generator\UrlGeneratorInterface,
	Cache\Adapter\FilesystemAdapter,
	DependencyInjection\ParameterBag\ParameterBagInterface
};
use Symfony\Contracts\Cache\ItemInterface;
use PostFinanceCheckout\Sdk\{
	Model\Transaction,
	Model\TransactionState
};
use PostFinanceCheckoutPayment\Core\{
	Api\Transaction\Service\TransactionService,
	Settings\Options\Integration,
	Settings\Service\SettingsService,
	Storefront\Checkout\Struct\CheckoutPageData,
	Util\Payload\CustomProducts\CustomProductsLineItemTypes,
	Util\Payload\TransactionPayload
};

/**
 * Class CheckoutController
 *
 * @package PostFinanceCheckoutPayment\Core\Storefront\Checkout\Controller
 *
 */
#[Package('checkout')]
#[Route(defaults: ['_routeScope' => ['storefront']])]
class CheckoutController extends StorefrontController {

	public const ORDER_STATE_CANCEL = 'cancel';

	/**
	 * @var \Shopware\Core\System\StateMachine\StateMachineRegistry
	 */
	private $stateMachineRegistry;

	/**
	 * @var \Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler
	 */
	protected $orderTransactionStateHandler;

	/**
	 * @var \Shopware\Storefront\Page\GenericPageLoader
	 */
	protected $genericLoader;

	/**
	 * @var \Shopware\Core\Checkout\Cart\SalesChannel\CartService
	 */
	protected $cartService;

	/**
	 * @var \PostFinanceCheckoutPayment\Core\Settings\Service\SettingsService
	 */
	protected $settingsService;

	/**
	 * @var \PostFinanceCheckoutPayment\Core\Settings\Struct\Settings
	 */
	protected $settings;

	/**
	 * @var \PostFinanceCheckoutPayment\Core\Api\Transaction\Service\TransactionService
	 */
	protected $transactionService;

	/**
	 * @var \Psr\Log\LoggerInterface
	 */
	private $logger;

	/**
	 * @var \Shopware\Core\Checkout\Cart\LineItemFactoryRegistry
	 */
	private $lineItemFactoryRegistry;

	/**
	 * @var \Shopware\Core\Checkout\Order\SalesChannel\AbstractOrderRoute
	 */
	private $orderRoute;

	/**
	 * @var \Psr\Cache\CacheItemPoolInterface
	 */
	private CacheItemPoolInterface $cache;

	/**
	 * PaymentController constructor.
	 *
	 * @param \Shopware\Core\Checkout\Cart\LineItemFactoryRegistry                          $lineItemFactoryRegistry
	 * @param \Shopware\Core\Checkout\Cart\SalesChannel\CartService                         $cartService
	 * @param \PostFinanceCheckoutPayment\Core\Settings\Service\SettingsService           $settingsService
	 * @param \PostFinanceCheckoutPayment\Core\Api\Transaction\Service\TransactionService $transactionService
	 * @param \Shopware\Storefront\Page\GenericPageLoaderInterface                          $genericLoader
	 * @param \Shopware\Core\Checkout\Order\SalesChannel\AbstractOrderRoute                 $orderRoute
	 * @param \Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler $orderTransactionStateHandler
	 * @param \Shopware\Core\System\StateMachine\StateMachineRegistry 						$stateMachineRegistry
	 * @param Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface		$params
	 */
	public function __construct(
		LineItemFactoryRegistry $lineItemFactoryRegistry,
		CartService $cartService,
		SettingsService $settingsService,
		TransactionService $transactionService,
		GenericPageLoaderInterface $genericLoader,
		AbstractOrderRoute $orderRoute,
		OrderTransactionStateHandler $orderTransactionStateHandler,
		StateMachineRegistry $stateMachineRegistry,
		ParameterBagInterface $params
	)
	{
		$this->cartService             = $cartService;
		$this->genericLoader           = $genericLoader;
		$this->settingsService         = $settingsService;
		$this->transactionService      = $transactionService;
		$this->lineItemFactoryRegistry = $lineItemFactoryRegistry;
		$this->orderRoute = $orderRoute;
		$this->orderTransactionStateHandler = $orderTransactionStateHandler;
		$this->stateMachineRegistry = $stateMachineRegistry;
		$this->cache = new FilesystemAdapter('postfinancecheckout', 0, rtrim($params->get('kernel.cache_dir'), '/') . '/postfinancecheckout-cache');
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
	 * @param \Shopware\Core\System\SalesChannel\SalesChannelContext $salesChannelContext
	 * @param \Symfony\Component\HttpFoundation\Request              $request
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 * @throws \PostFinanceCheckout\Sdk\ApiException
	 * @throws \PostFinanceCheckout\Sdk\Http\ConnectionException
	 * @throws \PostFinanceCheckout\Sdk\VersioningException
	 *
	 */
    #[Route(
        path: "/postfinancecheckout/checkout/pay",
        name: "frontend.postfinancecheckout.checkout.pay",
        options: ["seo" => false],
        methods: ["GET"],
    )]
	public function pay(SalesChannelContext $salesChannelContext, Request $request): Response
	{
		$orderId = $request->query->get('orderId');

		if (empty($orderId)) {
			throw new MissingRequestParameterException('orderId');
		}

		// Configuration
		$this->settings = $this->settingsService->getSettings($salesChannelContext->getSalesChannel()->getId());

		$transaction     = $this->getTransaction($orderId, $salesChannelContext->getContext());
		$recreateCartUrl = $this->generateUrl(
			'frontend.postfinancecheckout.checkout.recreate-cart',
			['orderId' => $orderId,],
			UrlGeneratorInterface::ABSOLUTE_URL
		);

		if (in_array(
			$transaction->getState(),
			[
				TransactionState::AUTHORIZED,
				TransactionState::COMPLETED,
				TransactionState::FULFILL,
			]
		)) {
			return $this->redirect($transaction->getSuccessUrl(), Response::HTTP_MOVED_PERMANENTLY);
		} else {
			if (in_array(
				$transaction->getState(),
				[
					TransactionState::DECLINE,
					TransactionState::FAILED,
					TransactionState::VOIDED,
				]
			)) {
				return $this->redirect($transaction->getFailedUrl(), Response::HTTP_MOVED_PERMANENTLY);
			}
		}

		$possiblePaymentMethods = $this->settings->getApiClient()
												 ->getTransactionService()
												 ->fetchPaymentMethods(
													 $this->settings->getSpaceId(),
													 $transaction->getId(),
													 $this->settings->getIntegration()
												 );

		if (empty($possiblePaymentMethods)) {
			$this->addFlash('danger', $this->trans('postfinancecheckout.paymentMethod.notAvailable'));
			return $this->redirect($recreateCartUrl, Response::HTTP_MOVED_PERMANENTLY);
		}

		$javascriptUrl = $this->getTransactionJavaScriptUrl($transaction->getId());

		// Set Checkout Page Data
		$checkoutPageData = (new CheckoutPageData())
			->setIntegration($this->settings->getIntegration())
			->setJavascriptUrl($javascriptUrl)
			->setDeviceJavascriptUrl($this->settings->getSpaceId(), Uuid::randomHex())
			->setTransactionPossiblePaymentMethods($possiblePaymentMethods)
			->setCheckoutUrl($this->generateUrl(
				'frontend.postfinancecheckout.checkout.pay',
				['orderId' => $orderId,],
				UrlGeneratorInterface::ABSOLUTE_URL
			))
			->setCartRecreateUrl($recreateCartUrl);
		$page             = $this->load($request, $salesChannelContext);
		$page->addExtension('postFinanceCheckoutData', $checkoutPageData);

		return $this->renderStorefront(
			'@PostFinanceCheckoutPayment/storefront/page/checkout/order/postfinancecheckout.html.twig',
			['page' => $page]
		);
	}

	/**
	 * Get transaction Javascript URL
	 *
	 * @param int $transactionId
	 *
	 * @return string
	 * @throws \PostFinanceCheckout\Sdk\ApiException
	 * @throws \PostFinanceCheckout\Sdk\Http\ConnectionException
	 * @throws \PostFinanceCheckout\Sdk\VersioningException
	 */
	private function getTransactionJavaScriptUrl(int $transactionId): string
	{
		$javascriptUrl = '';
		switch ($this->settings->getIntegration()) {
			case Integration::IFRAME:
				$javascriptUrl = $this->settings->getApiClient()->getTransactionIframeService()
												->javascriptUrl($this->settings->getSpaceId(), $transactionId);
				break;
			case Integration::LIGHTBOX:
				$javascriptUrl = $this->settings->getApiClient()->getTransactionLightboxService()
												->javascriptUrl($this->settings->getSpaceId(), $transactionId);
				break;
			default:
				$this->logger->critical(strtr('invalid integration : :integration', [':integration' => $this->settings->getIntegration()]));

		}
		return $javascriptUrl;
	}

	/**
	 * @param                                  $orderId
	 * @param \Shopware\Core\Framework\Context $context
	 *
	 * @return \PostFinanceCheckout\Sdk\Model\Transaction
	 * @throws \PostFinanceCheckout\Sdk\ApiException
	 * @throws \PostFinanceCheckout\Sdk\Http\ConnectionException
	 * @throws \PostFinanceCheckout\Sdk\VersioningException
	 */
	private function getTransaction($orderId, Context $context): Transaction
	{
		$transactionEntity = $this->transactionService->getByOrderId($orderId, $context);
		return $this->settings->getApiClient()->getTransactionService()->read($this->settings->getSpaceId(), $transactionEntity->getTransactionId());
	}

	/**
	 * @param \Symfony\Component\HttpFoundation\Request              $request
	 * @param \Shopware\Core\System\SalesChannel\SalesChannelContext $salesChannelContext
	 *
	 * @return \Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPage
	 */
	protected function load(Request $request, SalesChannelContext $salesChannelContext): CheckoutFinishPage
	{
		$page = CheckoutFinishPage::createFrom($this->genericLoader->load($request, $salesChannelContext));
		$page->setOrder($this->getOrder($request, $salesChannelContext));

		return $page;
	}


	/**
	 * @param \Symfony\Component\HttpFoundation\Request              $request
	 * @param \Shopware\Core\System\SalesChannel\SalesChannelContext $salesChannelContext
	 *
	 * @return \Shopware\Core\Checkout\Order\OrderEntity
	 */
	private function getOrder(Request $request, SalesChannelContext $salesChannelContext): OrderEntity
	{

		$orderId = $request->get('orderId');
		if (!$orderId) {
			throw new MissingRequestParameterException('orderId', '/orderId');
		}

		$criteria = (new Criteria([$orderId]))
			->addAssociation('lineItems.cover')
			->addAssociation('transactions.paymentMethod')
			->addAssociation('deliveries.shippingMethod');

		$customer = $salesChannelContext->getCustomer();
		if ($customer !== null) {
			$criteria = $criteria->addFilter(new EqualsFilter('order.orderCustomer.customerId', $customer->getId()));
		}

		$criteria->getAssociation('transactions')->addSorting(new FieldSorting('createdAt'));

		try {
			$searchResult = $this->orderRoute
				->load(new Request(), $salesChannelContext, $criteria)
				->getOrders();
		} catch (InvalidUuidException $e) {
			throw CartException::orderNotFound($orderId);
		}

		/** @var OrderEntity|null $order */
		$order = $searchResult->get($orderId);

		if (!$order) {
			throw CartException::orderNotFound($orderId);
		}

		return $order;
	}

	/**
	 * Recreate Cart
	 *
	 * @param \Symfony\Component\HttpFoundation\Request              $request
	 * @param \Shopware\Core\System\SalesChannel\SalesChannelContext $salesChannelContext
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 *
	 */
    #[Route(
        path: "/postfinancecheckout/checkout/recreate-cart",
        name: "frontend.postfinancecheckout.checkout.recreate-cart",
        options: ["seo" => false],
        methods: ["GET"],
    )]
	public function recreateCart(Request $request, SalesChannelContext $salesChannelContext)
	{
		$orderId = $request->query->get('orderId');

		if (empty($orderId)) {
			throw new MissingRequestParameterException('orderId');
		}

		// Adoption for Headless Storefronts
		$orderRepo = $this->container->get('order.repository');
		$criteria = new Criteria([$orderId]);

		$orderEntity = $orderRepo->search($criteria, $salesChannelContext->getContext())->first();

		if($orderEntity->getSalesChannelId() !== $salesChannelContext->getSalesChannelId()) {
			$this->settings = $this->settingsService->getSettings($orderEntity->getSalesChannelId());
			$trans = $this->getTransaction($orderId, $salesChannelContext->getContext());

			// Adoption in case of duplicate requests
			// Get order specific value from cache
			$cacheKey = 'postfinancecheckout_recreate_order_' . $orderId;
			$isFound = $this->cache->get($cacheKey, function (ItemInterface $item) {
				$item->expiresAfter(10);
				return false;
			});

			// If value is found in cache - send user directly to successful checkout confirmation page for unpaid transactions
			if ($isFound === true && in_array($trans->getState(), [TransactionState::FAILED])) {
				$unpaidUrl = $this->getUnpaidUrlFromToken($trans->getSuccessUrl()) 
				?? $this->buildUnpaidUrl($orderEntity->getSalesChannelId(), $salesChannelContext, $orderId);
				if ($unpaidUrl) {
					return new RedirectResponse(
						$unpaidUrl . (parse_url($unpaidUrl, \PHP_URL_QUERY) ? '&' : '?') . 'error-code=' . PaymentException::PAYMENT_CUSTOMER_CANCELED_EXTERNAL
					);
				}
			}

			// Cache order specific value for some time on first request
			$this->cache->delete($cacheKey);
			$this->cache->get($cacheKey, function (ItemInterface $item) {
				$item->expiresAfter(10);
				return true;
			});
			return $this->redirect($trans->getSuccessUrl());
		}
		// End Adoption for Headless Storefronts

		try {
			$this->cartService->deleteCart($salesChannelContext);
			$cart = $this->cartService->createNew($salesChannelContext->getToken());

			// Configuration
			$this->settings = $this->settingsService->getSettings($salesChannelContext->getSalesChannel()->getId());
			$orderEntity    = $this->getOrder($request, $salesChannelContext);
			$lastTransaction = $orderEntity->getTransactions()->last();
			if ($lastTransaction && !$lastTransaction->getPaymentMethod()->getAfterOrderEnabled()) {
				return $this->redirectToRoute('frontend.home.page');
			}

			$transaction = $this->getTransaction($orderId, $salesChannelContext->getContext());
			$orderTransactionId = $transaction->getMetaData()[TransactionPayload::POSTFINANCECHECKOUT_METADATA_ORDER_TRANSACTION_ID];
			if (!empty($transaction->getUserFailureMessage())) {
				$this->addFlash('danger', $transaction->getUserFailureMessage());
			}

			$orderItems        = $orderEntity->getLineItems();
			$hasCustomProducts = $this->hasCustomProducts($orderItems);

			if ($hasCustomProducts === true) {
				$cart = $this->addCustomProducts($orderItems, $request, $salesChannelContext);
			}

			foreach ($orderItems as $orderLineItemEntity) {
				$type = $orderLineItemEntity->getType();

				if ($type !== CustomProductsLineItemTypes::LINE_ITEM_TYPE_PRODUCT || $orderLineItemEntity->getParentid() !== null) {
					continue;
				}

				$lineItem = $this->lineItemFactoryRegistry->create([
					'id'           => $orderLineItemEntity->getId(),
					'quantity'     => $orderLineItemEntity->getQuantity(),
					'referencedId' => $orderLineItemEntity->getReferencedId(),
					'type'         => $type,
				], $salesChannelContext);

				$lineItemPayload = $orderLineItemEntity->getPayload();
				if (!empty($lineItemPayload)) {
					$lineItem->setPayload($lineItemPayload);
				}

				$cart = $this->cartService->add($cart, $lineItem, $salesChannelContext);

			}

			// Close the old, existing order to prevent confusion for the customer
			$this->orderTransactionStateHandler->cancel($orderTransactionId, $salesChannelContext->getContext());
			$this->stateMachineRegistry->transition(
				new Transition(
					OrderDefinition::ENTITY_NAME,
					$orderId,
					self::ORDER_STATE_CANCEL,
					'stateId'
				),
				$salesChannelContext->getContext()
			);

		} catch (\Exception $exception) {
			$this->addFlash('danger', $this->trans('error.addToCartError'));
			$this->logger->critical($exception->getMessage());
			return $this->redirectToRoute('frontend.home.page');
		}

		return $this->redirectToRoute('frontend.checkout.confirm.page');
	}

	/**
	 * Tries to return successful checkout confirmation url for unpaid transactions.
	 * 
	 * It achieves that by getting payment token from successUrl, parsing and decoding 
	 * it, and finally reading the claims.
	 * 
	 * @param string $successUrl
	 *
	 * @return string|null
	 */
	private function getUnpaidUrlFromToken(string $successUrl): ?string {
		$query = [];
		parse_str((string) parse_url($successUrl, PHP_URL_QUERY), $query);
		$jwt = $query['_sw_payment_token'] ?? null;

		if (!$jwt) {
			return null;
		}

		$data = explode('.', $jwt, 3);
        if (count($data) !== 3) {
			return null;
        }
		
		[, $c, ] = $data;

		try {
			$urlSafeData = strtr($c, '-_', '+/');
			$paddedData = str_pad($urlSafeData, \strlen($urlSafeData) % 4, '=');
			$decoded = base64_decode($paddedData, true);
			if (!$decoded) {
				return null;
			}
			$claims = json_decode(json: $decoded, associative: true, flags: JSON_THROW_ON_ERROR);
			$unpaidUrl = $claims['eul'] ?? null;
			return $unpaidUrl;
		} catch (\Throwable $e) {
			$this->logger->warning("CheckoutController::getUnpaidUrlFromToken - JWT parse failed: {errorMessage}", [
				'errorMessage' => $e->getMessage(),
			]);
			return null;
		}
	}

	/**
	 * Tries to return successful checkout confirmation url for unpaid transactions.
	 * 
	 * It achieves that by fetching headless storefront's base url,
	 * and building custom url.
	 * 
	 * @param string $salesChannelId
	 * @param SalesChannelContext $salesChannelContext
	 * @param string $orderId
	 *
	 * @return string|null
	 */
	private function buildUnpaidUrl(string $salesChannelId, SalesChannelContext $salesChannelContext, string $orderId): ?string {
		$salesChannelDomainRepo = $this->container->get('sales_channel_domain.repository');
		$criteria = new Criteria();
		$criteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId))->setLimit(10);
		$domain = $salesChannelDomainRepo->search($criteria, $salesChannelContext->getContext())->first();
		if(!$domain) {
			return null;
		}
		$baseUrl = rtrim($domain->getUrl(), '/');
		return sprintf('%s/checkout/success/%s/unpaid', $baseUrl, $orderId);
	}

	/**
	 * @param OrderLineItemCollection $orderItems
	 *
	 * @return bool
	 */
	private function hasCustomProducts(OrderLineItemCollection $orderItems): bool
	{
		foreach ($orderItems as $orderItem) {
			if ($orderItem->getType() === CustomProductsLineItemTypes::LINE_ITEM_TYPE_CUSTOMIZED_PRODUCTS) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param OrderLineItemCollection $orderItems
	 * @param string                  $parentId
	 *
	 * @return OrderLineItemEntity|null
	 */
	private function getCustomProduct(OrderLineItemCollection $orderItems, string $parentId): ?OrderLineItemEntity
	{
		foreach ($orderItems as $orderItem) {
			if ($orderItem->getType() === CustomProductsLineItemTypes::LINE_ITEM_TYPE_PRODUCT && $orderItem->getParentId() === $parentId) {
				return $orderItem;
			}
		}
		return null;
	}

	/**
	 * @param OrderLineItemCollection $orderItems
	 * @param string                  $parentId
	 *
	 * @return array
	 */
	private function getCustomProductOptions(OrderLineItemCollection $orderItems, string $parentId): array
	{
		$options = [];
		foreach ($orderItems as $orderItem) {
			if ($orderItem->getType() === CustomProductsLineItemTypes::LINE_ITEM_TYPE_CUSTOMIZED_PRODUCTS_OPTION && $orderItem->getParentId() === $parentId) {
				$options[] = $orderItem;
			}
		}
		return $options;
	}

	/**
	 * @param $orderItems
	 * @param $request
	 * @param $salesChannelContext
	 *
	 * @return Cart
	 */
	private function addCustomProducts(OrderLineItemCollection $orderItems, Request $request, SalesChannelContext $salesChannelContext): Cart
	{

		$cart = $this->cartService->getCart($salesChannelContext->getToken(), $salesChannelContext);
		if (!\class_exists('Swag\\CustomizedProducts\\Core\\Checkout\\Cart\\Route\\AddCustomizedProductsToCartRoute')) {
			return $cart;
		}

		$customProductsService = $this->get('Swag\CustomizedProducts\Core\Checkout\Cart\Route\AddCustomizedProductsToCartRoute');

		foreach ($orderItems as $orderItem) {
			if ($orderItem->getType() !== CustomProductsLineItemTypes::LINE_ITEM_TYPE_CUSTOMIZED_PRODUCTS) {
				continue;
			}

			$product        = $this->getCustomProduct($orderItems, $orderItem->getId());
			$productOptions = $this->getCustomProductOptions($orderItems, $orderItem->getId());
			$optionValues   = $this->getOptionValues($productOptions);

			$params = new RequestDataBag([
				'customized-products-template' => new RequestDataBag([
					'id'      => $orderItem->getReferencedId(),
					'options' => new RequestDataBag($optionValues),
				]),
			]);

			$request->request->add(
				[
					'lineItems' =>
						[
							$product->getProductId() =>
								[
									'quantity' => $orderItem->getQuantity(),
									'id'           => $product->getProductId(),
									'type'         => CustomProductsLineItemTypes::LINE_ITEM_TYPE_PRODUCT,
									'referencedId' => $product->getReferencedId(),
									'stackable'    => $orderItem->getStackable(),
									'removable'    => $orderItem->getRemovable(),
								]
						]
				]
			);

			$customProductsService->add($params, $request, $salesChannelContext, $cart);
			$cart = $this->cartService->getCart($salesChannelContext->getToken(), $salesChannelContext);
		}

		return $cart;
	}

	/**
	 * @param array $productOptions
	 *
	 * @return array
	 */
	private function getOptionValues(array $productOptions): array
	{
		$optionValues = [];
		foreach ($productOptions as $productOption) {
			$optionType = $productOption->getPayload()['type'] ?: '';

			switch ($optionType) {
				case CustomProductsLineItemTypes::PRODUCT_OPTION_TYPE_IMAGE_UPLOAD:
				case CustomProductsLineItemTypes::PRODUCT_OPTION_TYPE_FILE_UPLOAD:
					$media = $productOption->getPayload()['media'] ?: [];
					foreach ($media as $mediaItem) {
						$optionValues[$productOption->getReferencedId()] = new RequestDataBag([
							'media' => new RequestDataBag([
								$mediaItem['filename'] => new RequestDataBag([
									'id'       => $mediaItem['mediaId'],
									'filename' => $mediaItem['filename'],
								]),
							]),
						]);
					}
					break;

				default:
					$optionValues[$productOption->getReferencedId()] = new RequestDataBag([
						'value' => $productOption->getPayload()['value'] ?: '',
					]);
			}
		}

		return $optionValues;
	}
}
