<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Api\Configuration\Controller;

use Psr\Log\LoggerInterface;
use Shopware\Core\{
	Framework\Context,
	Framework\Routing\Annotation\RouteScope,};
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\{
	HttpFoundation\JsonResponse,
	HttpFoundation\Request,
	HttpFoundation\Response,
	Routing\Annotation\Route};
use PostFinanceCheckoutPayment\Core\{
	Api\OrderDeliveryState\Service\OrderDeliveryStateService,
	Api\PaymentMethodConfiguration\Service\PaymentMethodConfigurationService,
	Api\WebHooks\Service\WebHooksService,
	Api\Space\Service\SpaceService,
	Settings\Service\SettingsService,
	Util\PaymentMethodUtil};

/**
 * Class ConfigurationController
 *
 * This class handles web calls that are made via the PostFinanceCheckoutPayment settings page.
 *
 * @package PostFinanceCheckoutPayment\Core\Api\Config\Controller
 * @Route(defaults={"_routeScope"={"api"}})
 */
class ConfigurationController extends AbstractController {

	/**
	 * @var \PostFinanceCheckoutPayment\Core\Api\WebHooks\Service\WebHooksService
	 */
	protected $webHooksService;

	/**
	 * @var \PostFinanceCheckoutPayment\Core\Api\Space\Service\SpaceService
	 */
	protected $spaceService;

	/**
	 * @var \Psr\Log\LoggerInterface
	 */
	protected $logger;

	/**
	 * @var \PostFinanceCheckoutPayment\Core\Settings\Service\SettingsService
	 */
	protected $settingsService;

	/**
	 * @var \PostFinanceCheckoutPayment\Core\Util\PaymentMethodUtil
	 */
	private $paymentMethodUtil;

	/**
	 * @var \PostFinanceCheckoutPayment\Core\Api\PaymentMethodConfiguration\Service\PaymentMethodConfigurationService
	 */
	private $paymentMethodConfigurationService;

	/**
	 * @param PaymentMethodUtil $paymentMethodUtil
	 * @param PaymentMethodConfigurationService $paymentMethodConfigurationService
	 * @param WebHooksService $webHooksService
	 * @param SpaceService $spaceService
	 * @param SettingsService $settingsService
	 */
	public function __construct(
		PaymentMethodUtil $paymentMethodUtil,
		PaymentMethodConfigurationService $paymentMethodConfigurationService,
		WebHooksService $webHooksService,
		SpaceService $spaceService,
		SettingsService $settingsService
	)
	{
		$this->webHooksService   = $webHooksService;
		$this->spaceService = $spaceService;
		$this->paymentMethodUtil = $paymentMethodUtil;
		$this->paymentMethodConfigurationService = $paymentMethodConfigurationService;
		$this->settingsService = $settingsService;
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
	 * Set PostFinanceCheckoutPayment as the default payment for a give sales channel
	 *
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 * @param \Shopware\Core\Framework\Context          $context
	 * @return \Symfony\Component\HttpFoundation\JsonResponse
	 *
	 * @Route(
	 *     "/api/_action/postfinancecheckout/configuration/set-postfinancecheckout-as-sales-channel-payment-default",
	 *     name="api.action.postfinancecheckout.configuration.set-postfinancecheckout-as-sales-channel-payment-default",
	 *     methods={"POST"}
	 *     )
	 */
	public function setPostFinanceCheckoutAsSalesChannelPaymentDefault(Request $request, Context $context): JsonResponse
	{
		$salesChannelId = $request->request->get('salesChannelId');
		$salesChannelId = ($salesChannelId == 'null') ? null : $salesChannelId;

		$this->paymentMethodUtil->setPostFinanceCheckoutAsDefaultPaymentMethod($context, $salesChannelId);
		return new JsonResponse([]);
	}

	/**
	 * Register web hooks
	 *
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 * @return \Symfony\Component\HttpFoundation\JsonResponse
	 * @throws \PostFinanceCheckout\Sdk\ApiException
	 * @throws \PostFinanceCheckout\Sdk\Http\ConnectionException
	 * @throws \PostFinanceCheckout\Sdk\VersioningException
	 *
	 * @Route(
	 *     "/api/_action/postfinancecheckout/configuration/register-web-hooks",
	 *     name="api.action.postfinancecheckout.configuration.register-web-hooks",
	 *     methods={"POST"}
	 *   )
	 */
	public function registerWebHooks(Request $request): JsonResponse
	{
		$settings = $this->settingsService->getSettings();
		if ($settings->isWebhooksUpdateEnabled() === false) {
			$this->logger->info('Webhooks update disabled by settings');
			return new JsonResponse([]);
		}

		$salesChannelId = $request->request->get('salesChannelId');
		$salesChannelId = ($salesChannelId == 'null') ? null : $salesChannelId;

		$result = $this->webHooksService->setSalesChannelId($salesChannelId)->install();

		return new JsonResponse(['result' => $result]);
	}

	/**
	 * Test API connection
	 * If the API data is incorrect, an entry must appear in the event log file in the Shopware folder /var/log/
	 * @see https://developer.shopware.com/docs/resources/guidelines/testing/store/quality-guidelines-plugins/#every-app-accessing-external-api-services
	 *
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 * @return \Symfony\Component\HttpFoundation\JsonResponse
	 * @throws \PostFinanceCheckout\Sdk\ApiException
	 * @throws \PostFinanceCheckout\Sdk\Http\ConnectionException
	 * @throws \PostFinanceCheckout\Sdk\VersioningException
	 *
	 * @Route(
	 *     "/api/_action/postfinancecheckout/configuration/check-api-connection",
	 *     name="api.action.postfinancecheckout.configuration.check-api-connection",
	 *     methods={"POST"}
	 *   )
	 */
	public function checkApiConnection(Request $request): JsonResponse
	{
		$spaceId = (int)$request->request->getInt('spaceId');
		$userId = (int)$request->request->getInt('userId');
		$applicationId = $request->request->get('applicationId');

		$result = $this->spaceService
			->setSpaceId($spaceId)
			->setUserId($userId)
			->setApplicationId($applicationId)
			->checkSpace();

		if (null === $result) {
			$this->logger->error('API test connection was failed. Wrong credentials');
			return new JsonResponse([['result' => 400]]);
		}

		$this->logger->info('API test connection was successfully tested.');
		return new JsonResponse(['result' => 200]);
	}

	/**
	 * Synchronize payment method configurations
	 *
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 * @param \Shopware\Core\Framework\Context          $context
	 * @return \Symfony\Component\HttpFoundation\JsonResponse
	 *
	 * @Route(
	 *     "/api/_action/postfinancecheckout/configuration/synchronize-payment-method-configuration",
	 *     name="api.action.postfinancecheckout.configuration.synchronize-payment-method-configuration",
	 *     methods={"POST"}
	 *   )
	 */
	public function synchronizePaymentMethodConfiguration(Request $request, Context $context): JsonResponse
	{
		$settings = $this->settingsService->getSettings();
		if ($settings->isPaymentsUpdateEnabled() === false) {
			$this->logger->info('Payment methods update disabled by settings');
			return new JsonResponse([]);
		}

		$salesChannelId = $request->request->get('salesChannelId');
		$salesChannelId = ($salesChannelId == 'null') ? null : $salesChannelId;
		$status         = Response::HTTP_OK;
		try {
			$result = $this->paymentMethodConfigurationService->setSalesChannelId($salesChannelId)->synchronize($context);
		} catch (\Exception $exception) {
			$status = Response::HTTP_NOT_ACCEPTABLE;
			$result = [
				'errorTitle' => $exception->getMessage(),
				'errorMessage' => $exception->getTraceAsString()
			];
			$this->logger->emergency($exception->getTraceAsString());
		}

		return new JsonResponse(['result' => $result], $status);
	}

	/**
	 * Install OrderDeliveryStates
	 *
	 * @param \Shopware\Core\Framework\Context $context
	 * @return \Symfony\Component\HttpFoundation\JsonResponse
	 *
	 * @Route(
	 *     "/api/_action/postfinancecheckout/configuration/install-order-delivery-states",
	 *     name="api.action.postfinancecheckout.configuration.install-order-delivery-states",
	 *     methods={"POST"}
	 *   )
	 */
	public function installOrderDeliveryStates(Context $context): JsonResponse
	{
		/**
		 * @var \PostFinanceCheckoutPayment\Core\Api\OrderDeliveryState\Service\OrderDeliveryStateService $orderDeliveryStateService
		 */
		$orderDeliveryStateService = $this->container->get(OrderDeliveryStateService::class);
		$orderDeliveryStateService->install($context);

		return new JsonResponse([]);
	}
}
