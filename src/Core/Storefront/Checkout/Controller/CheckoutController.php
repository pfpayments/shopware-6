<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Storefront\Checkout\Controller;

use Psr\Log\LoggerInterface;
use Shopware\Core\{
	Checkout\Cart\Cart,
	Checkout\Cart\Exception\OrderNotFoundException,
	Checkout\Cart\LineItemFactoryRegistry,
	Checkout\Cart\SalesChannel\CartService,
	Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection,
	Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity,
	Checkout\Order\OrderEntity,
	Framework\Context,
	Framework\DataAbstractionLayer\Search\Criteria,
	Framework\Routing\Annotation\RouteScope,
	Framework\Routing\Exception\MissingRequestParameterException,
	Framework\Validation\DataBag\RequestDataBag,
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
	Routing\Annotation\Route
};
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use PostFinanceCheckout\Sdk\{
	Model\Transaction,
	Model\TransactionState
};
use PostFinanceCheckoutPayment\Core\{
	Api\Transaction\Service\TransactionService,
	Settings\Options\Integration,
	Settings\Service\SettingsService,
	Storefront\Checkout\Struct\CheckoutPageData,
	Util\Payload\CustomProducts\CustomProductsLineItemTypes
};


/**
 * Class CheckoutController
 *
 * @package PostFinanceCheckoutPayment\Core\Storefront\Checkout\Controller
 *
 * @RouteScope(scopes={"storefront"})
 */
class CheckoutController extends StorefrontController {

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
	 * PaymentController constructor.
	 *
	 * @param \Shopware\Core\Checkout\Cart\LineItemFactoryRegistry                          $lineItemFactoryRegistry
	 * @param \Shopware\Core\Checkout\Cart\SalesChannel\CartService                         $cartService
	 * @param \PostFinanceCheckoutPayment\Core\Settings\Service\SettingsService           $settingsService
	 * @param \PostFinanceCheckoutPayment\Core\Api\Transaction\Service\TransactionService $transactionService
	 * @param \Shopware\Storefront\Page\GenericPageLoaderInterface                          $genericLoader
	 */
	public function __construct(
		LineItemFactoryRegistry $lineItemFactoryRegistry,
		CartService $cartService,
		SettingsService $settingsService,
		TransactionService $transactionService,
		GenericPageLoaderInterface $genericLoader
	)
	{
		$this->cartService             = $cartService;
		$this->genericLoader           = $genericLoader;
		$this->settingsService         = $settingsService;
		$this->transactionService      = $transactionService;
		$this->lineItemFactoryRegistry = $lineItemFactoryRegistry;
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
	 * @Route(
	 *     "/postfinancecheckout/checkout/pay",
	 *     name="frontend.postfinancecheckout.checkout.pay",
	 *     options={"seo": "false"},
	 *     methods={"GET"}
	 *     )
	 */
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
				TransactionState::PROCESSING,
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
			->setDeviceJavascriptUrl($this->settings->getSpaceId(), $this->container->get('session')->getId())
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
		$page->setOrder($this->getOrder($request->get('orderId'), $salesChannelContext->getContext()));

		return $page;
	}

	/**
	 * @param string                           $orderId
	 * @param \Shopware\Core\Framework\Context $context
	 *
	 * @return \Shopware\Core\Checkout\Order\OrderEntity
	 */
	private function getOrder(string $orderId, Context $context): OrderEntity
	{
		$criteria = (new Criteria([$orderId]))->addAssociations([
			'lineItems.cover',
			'transactions.paymentMethod',
			'deliveries.shippingMethod',
		]);

		try {
			$order = $this->container->get('order.repository')->search(
				$criteria,
				$context
			)->first();
		} catch (\Exception $exception) {
			$this->logger->notice($exception->getMessage());
			throw new OrderNotFoundException($orderId);
		}

		if (is_null($order)) {
			throw new OrderNotFoundException($orderId);
		}

		return $order;
	}

	/**
	 * Recreate Cart
	 *
	 * @param \Shopware\Core\Checkout\Cart\Cart                      $cart
	 * @param \Symfony\Component\HttpFoundation\Request              $request
	 * @param \Shopware\Core\System\SalesChannel\SalesChannelContext $salesChannelContext
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 *
	 * @Route(
	 *     "/postfinancecheckout/checkout/recreate-cart",
	 *     name="frontend.postfinancecheckout.checkout.recreate-cart",
	 *     options={"seo": "false"},
	 *     methods={"GET"}
	 *     )
	 */
	public function recreateCart(Cart $cart, Request $request, SalesChannelContext $salesChannelContext)
	{
		$orderId = $request->query->get('orderId');

		if (empty($orderId)) {
			throw new MissingRequestParameterException('orderId');
		}

		try {
			// Configuration
			$this->settings = $this->settingsService->getSettings($salesChannelContext->getSalesChannel()->getId());
			$orderEntity    = $this->getOrder($orderId, $salesChannelContext->getContext());

			$transaction = $this->getTransaction($orderId, $salesChannelContext->getContext());
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

				$cart = $this->cartService->add($cart, $lineItem, $salesChannelContext);

			}

		} catch (\Exception $exception) {
			$this->addFlash('danger', $this->trans('error.addToCartError'));
			$this->logger->critical($exception->getMessage());
			return $this->redirectToRoute('frontend.home.page');
		}

		return $this->redirectToRoute('frontend.checkout.confirm.page');
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

			$params = new RequestDataBag([]);

			if (!empty($optionValues)) {
				$params = new RequestDataBag([
					'customized-products-template' => new RequestDataBag([
						'id'      => $orderItem->getReferencedId(),
						'options' => new RequestDataBag($optionValues),
					]),
				]);
			}

			$request->request = new RequestDataBag([
				'lineItems' => [
					'quantity'     => $orderItem->getQuantity(),
					'id'           => $product->getProductId(),
					'type'         => CustomProductsLineItemTypes::LINE_ITEM_TYPE_PRODUCT,
					'referencedId' => $product->getReferencedId(),
					'stackable'    => $orderItem->getStackable(),
					'removable'    => $orderItem->getRemovable(),
				],
			]);

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