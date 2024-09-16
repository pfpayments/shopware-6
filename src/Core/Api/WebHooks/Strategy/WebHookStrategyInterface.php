<?php

namespace PostFinanceCheckoutPayment\Core\Api\WebHooks\Strategy;

use Symfony\Component\HttpFoundation\Response;
use PostFinanceCheckoutPayment\Core\Api\WebHooks\Struct\WebHookRequest;

/**
 * Class Entity
 * Defines a strategy interface for processing webhook requests.
 *
 * @package PostFinanceCheckoutPayment\Core\Api\WebHooks\Strategy
 */
interface WebHookStrategyInterface {

	/**
	 * Checks if the provided webhook entity ID matches the expected ID.
	 *
	 * This method is intended to verify whether the entity ID from a webhook request matches
	 * a specific ID configured within the WebHooksService. This can be used to validate that the
	 * webhook is relevant and should be processed further.
	 *
	 * @param string $webhookEntityId The entity ID from the webhook request.
	 * @return bool Returns true if the ID matches the system's criteria, false otherwise.
	 */
	public function match(string $webhookEntityId): bool;

	/**
	 * Process the webhook request.
	 *
	 * @param WebHookRequest $request The webhook request object.
	 * @return Response
	 */
	public function process(WebHookRequest $request): Response;
}
