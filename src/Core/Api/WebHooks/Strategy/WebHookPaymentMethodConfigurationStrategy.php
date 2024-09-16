<?php

namespace PostFinanceCheckoutPayment\Core\Api\WebHooks\Strategy;

use Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use PostFinanceCheckoutPayment\Core\Api\WebHooks\Service\WebHooksService;
use PostFinanceCheckoutPayment\Core\Api\WebHooks\Struct\WebHookRequest;

/**
 * Handles the strategy for processing webhook requests related to manual tasks.
 *
 * This class extends the base webhook strategy class and is tailored specifically for handling
 * webhooks that deal with manual task updates. These tasks could involve manual interventions required
 * for certain operations within the system, which are triggered by external webhook events.
 *
 * @package PostFinanceCheckoutPayment\Core\Api\WebHooks\Strategy
 */
class WebHookPaymentMethodConfigurationStrategy extends WebHookStrategyBase {

	/**
	 * @inheritDoc
	 */
	public function match(string $webhookEntityId): bool {
		return WebHooksService::PAYMENT_METHOD_CONFIGURATION == $webhookEntityId;
	}

	/**
	 * Processes the incoming webhook request that pertains to manual tasks.
	 *
	 * This method activates the manual task service to handle updates based on the data provided
	 * in the webhook request. It could involve marking tasks as completed, updating their status, or
	 * initiating sub-processes required as part of the task resolution.
	 *
	 * @param WebHookRequest $request The webhook request object containing all necessary data.
	 * @return Response The method does not return a value but updates the state of manual tasks based on the webhook data.
	 * @throws Exception Throws an exception if there is a failure in processing the manual task updates.
	 */
	public function process(WebHookRequest $request): Response {
		$result = $this->paymentMethodConfigurationService
			->setSalesChannelId($this->getSalesChannelId())
			->synchronize($this->getContext());

		return new JsonResponse(['result' => $result]);
	}
}
