<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Storefront\Checkout\Controller;

use Psr\Log\LoggerInterface;
use Shopware\Core\{
	Checkout\Cart\Cart,
	Checkout\Cart\Exception\OrderNotFoundException,
	Checkout\Cart\LineItem\LineItem,
	Checkout\Cart\SalesChannel\CartService,
	Checkout\Order\OrderEntity,
	Content\Product\Exception\ProductNotFoundException,
	Framework\Context,
	Framework\DataAbstractionLayer\Search\Criteria,
	Framework\Routing\Annotation\RouteScope,
	Framework\Routing\Exception\MissingRequestParameterException,
	System\SalesChannel\SalesChannelContext};
use Shopware\Storefront\{
	Controller\StorefrontController,
	Page\Checkout\Finish\CheckoutFinishPage,
	Page\GenericPageLoader,};
use Symfony\Component\{
	HttpFoundation\JsonResponse,
	HttpFoundation\Request,
	HttpFoundation\Response,
	Routing\Annotation\Route};
use PostFinanceCheckout\Sdk\{
	Model\Transaction,
	Model\TransactionPending,
	Model\TransactionState};
use PostFinanceCheckoutPayment\Core\{
	Api\Transaction\Service\TransactionService,
	Settings\Options\Integration,
	Settings\Service\SettingsService,
	Storefront\Checkout\Struct\CheckoutPageData};

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
	 * PaymentController constructor.
	 *
	 * @param \Shopware\Core\Checkout\Cart\SalesChannel\CartService                         $cartService
	 * @param \PostFinanceCheckoutPayment\Core\Settings\Service\SettingsService           $settingsService
	 * @param \PostFinanceCheckoutPayment\Core\Api\Transaction\Service\TransactionService $transactionService
	 * @param \Shopware\Storefront\Page\GenericPageLoader                                   $genericLoader
	 */
	public function __construct(
		CartService $cartService,
		SettingsService $settingsService,
		TransactionService $transactionService,
		GenericPageLoader $genericLoader
	)
	{
		$this->cartService        = $cartService;
		$this->genericLoader      = $genericLoader;
		$this->settingsService    = $settingsService;
		$this->transactionService = $transactionService;
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
	 * @param \Shopware\Core\System\SalesChannel\SalesChannelContext $salesChannelContext
	 * @param \Symfony\Component\HttpFoundation\Request              $request
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

		$transaction = $this->getTransaction($orderId, $salesChannelContext->getContext());

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

		$page          = $this->load($request, $salesChannelContext);
		$javascriptUrl = '';

		$possiblePaymentMethods = $this->settings->getApiClient()
												 ->getTransactionService()
												 ->fetchPaymentMethods(
													 $this->settings->getSpaceId(),
													 $transaction->getId(),
													 $this->settings->getIntegration()
												 );

		switch ($this->settings->getIntegration()) {
			case Integration::IFRAME:
				$javascriptUrl = $this->settings->getApiClient()->getTransactionIframeService()
												->javascriptUrl($this->settings->getSpaceId(), $transaction->getId());
				break;
			case Integration::LIGHTBOX:
				$javascriptUrl = $this->settings->getApiClient()->getTransactionLightboxService()
												->javascriptUrl($this->settings->getSpaceId(), $transaction->getId());
				break;
			default:
				$this->logger->critical(strtr('invalid integration : :integration', [':integration' => $this->settings->getIntegration()]));

		}

		// Set Checkout Page Data
		$checkoutPageData = (new CheckoutPageData())
			->setIntegration($this->settings->getIntegration())
			->setJavascriptUrl($javascriptUrl)
			->setDeviceJavascriptUrl($this->settings->getSpaceId(), $this->container->get('session')->getId())
			->setTransactionPossiblePaymentMethods($possiblePaymentMethods);

		$page->addExtension('postFinanceCheckoutData', $checkoutPageData);

		return $this->renderStorefront(
			'@PostFinanceCheckoutPayment/storefront/page/checkout/order/postfinancecheckout.html.twig',
			['page' => $page]
		);
	}

	/**
	 * @param                                  $orderId
	 * @param \Shopware\Core\Framework\Context $context
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
	 * @return \Symfony\Component\HttpFoundation\Response
	 * @throws \PostFinanceCheckout\Sdk\ApiException
	 * @throws \PostFinanceCheckout\Sdk\Http\ConnectionException
	 * @throws \PostFinanceCheckout\Sdk\VersioningException
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

		// Configuration
		$this->settings = $this->settingsService->getSettings($salesChannelContext->getSalesChannel()->getId());

		$orderEntity = $this->getOrder($orderId, $salesChannelContext->getContext());

		try {
			foreach ($orderEntity->getLineItems() as $orderLineItemEntity) {
				$lineItem = (new LineItem($orderLineItemEntity->getProductId(), $orderLineItemEntity->getType()))
					->setStackable($orderLineItemEntity->getStackable())
					->setReferencedId($orderLineItemEntity->getReferencedId())
					->setQuantity($orderLineItemEntity->getQuantity())
					->setRemovable($orderLineItemEntity->getRemovable());

				$cart = $this->cartService->add($cart, $lineItem, $salesChannelContext);
			}
			$transaction = $this->getTransaction($orderId, $salesChannelContext->getContext());
			if (!empty($transaction->getUserFailureMessage())) {
				$this->addFlash('danger', $transaction->getUserFailureMessage());
			}

		} catch (ProductNotFoundException $exception) {
			$this->addFlash('danger', $this->trans('error.addToCartError'));
		}

		return $this->redirectToRoute('frontend.checkout.confirm.page');
	}
}