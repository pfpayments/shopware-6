<?php

namespace PostFinanceCheckoutPayment\Core\Api\WebHooks\Strategy;

use Exception;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Symfony\Component\{
	HttpFoundation\JsonResponse,
	HttpFoundation\Response,};
use PostFinanceCheckoutPayment\Core\Api\WebHooks\Struct\WebHookRequest;

/**
 * Handles the management and processing of different webhook strategies.
 *
 * This manager class holds references to different webhook strategies and delegates
 * the processing of incoming webhook requests to the appropriate strategy based on
 * the type of the webhook. Each strategy corresponds to a specific type of webhook
 * and contains the logic needed to handle that specific webhook type.
 *
 * @package PostFinanceCheckoutPayment\Core\Api\WebHooks\Strategy
 */
class WebHookStrategyManager {

	/**
	 * @var iterable Holds instances of webhook strategies.
	 */
	protected $strategies;

	/**
	 * @var LoggerInterface
	 */
	protected $logger;

	/**
	 * Constructor for the webhook manager.
	 *
	 * Initializes instances of each specific webhook strategy and stores them in an associative array.
	 * Each key in this array corresponds to a webhook type, and each value is an instance of a strategy
	 * class that handles that specific type of webhook.
	 * @param iterable $strategies
	 * @param LoggerInterface $postfinancecheckoutPaymentLogger
	 * @see "$postfinancecheckoutPaymentLogger", please read the documentation "How to Autowire Logger Channels"
	 */
	public function __construct(
		iterable $strategies,
		LoggerInterface $postfinancecheckoutPaymentLogger
	) {
		$this->strategies = $strategies;
		$this->logger = $postfinancecheckoutPaymentLogger;
	}

	/**
	 * Resolves the appropriate strategy for handling the given webhook request based on webhook type.
	 *
	 * This method fetches the webhook entity using the listener entity ID from the request, checks if a corresponding
	 * strategy exists, and returns the strategy if found.
	 *
	 * @param WebHookRequest $request The incoming webhook request.
	 * @param Context $context The shopware context.
	 * @param string $salesChannelId The sales channel ID.
	 * @return WebhookStrategyInterface The strategy to handle the request.
	 * @throws Exception If no strategy can be resolved.
	 */
	private function resolveStrategy(WebHookRequest $request, Context $context, ?string $salesChannelId): ?WebHookStrategyInterface
	{
		// Check if the strategy exists for the retrieved transaction ID.
		foreach ($this->strategies as $strategy) {
			/** @var WebhookStrategyInterface $strategy */
			if ($strategy->match($request->getListenerEntityId())) {
				$strategy
					->setContext($context)
					->setSalesChannelId($salesChannelId)
					->setCurrentSettingsBySalesChannel();
				return $strategy;
			}
		}

        return null;
	}

	/**
	 * Processes the incoming webhook by delegating to the appropriate strategy.
	 *
	 * This method determines the type of the incoming webhook request and uses it
	 * to look up the corresponding strategy. If a strategy is found, it delegates the
	 * request processing to that strategy. If no strategy is found for the type, it
	 * throws an exception.
	 *
	 * @param WebHookRequest $request The incoming webhook request object.
	 * @param Context $context
	 * @param string $salesChannelId
	 * @return Response
	 * @throws Exception If no strategy is available for the webhook type provided in the request.
	 */
	public function process(WebHookRequest $request, Context $context, ?string $salesChannelId): Response
	{
		try {
			$strategy = $this->resolveStrategy($request, $context, $salesChannelId);

			//If there is no strategy available
			if (empty($strategy)) {
				$this->logger->warning("No strategy available for the transaction ID: {transactionId}", [
					'transactionId' => $request->getListenerEntityId(),
				]);
				return new JsonResponse(['data' => $request->jsonSerialize()], Response::HTTP_OK);
			}

			//This reduces the number of unnecessary api calls.
			if (!$strategy->isRequestStateApplicable($request)) {
				return new JsonResponse(['data' => $request->jsonSerialize()], Response::HTTP_OK);
			}

			//If the request state applies for current strategy, then it will be processed.
			return $strategy->process($request);
		} catch ( Exception $e) {
			throw $e;
		}
	}
}
