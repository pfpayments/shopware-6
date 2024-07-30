<?php

namespace PostFinanceCheckoutPayment\Core\Api\WebHooks\Struct;

use Shopware\Core\Framework\Struct\Struct;

/**
 * Class WebHookRequest
 *
 * @package PostFinanceCheckoutPayment\Core\Api\WebHooks\Struct
 */
class WebHookRequest extends Struct {

	public const PAYMENT_METHOD_CONFIGURATION = 'PaymentMethodConfiguration';
	public const REFUND                       = 'Refund';
	public const TRANSACTION                  = 'Transaction';
	public const TRANSACTION_INVOICE          = 'TransactionInvoice';

	/**
	 * @var int
	 */
	protected $eventId;

	/**
	 * @var int
	 */
	protected $entityId;

	/**
	 * @var int
	 */
	protected $listenerEntityId;

	/**
	 * @var string
	 */
	protected $listenerEntityTechnicalName;

	/**
	 * @var int
	 */
	protected $spaceId;

	/**
	 * @var int
	 */
	protected $webhookListenerId;

	/**
	 * @var string
	 */
	protected $timestamp;

	/**
	 * @return int
	 */
	public function getEventId(): int
	{
		return $this->eventId;
	}

	/**
	 * @param int $eventId
	 * @return WebHookRequest
	 */
	public function setEventId(int $eventId): WebHookRequest
	{
		$this->eventId = $eventId;
		return $this;
	}

	/**
	 * @return int
	 */
	public function getEntityId(): int
	{
		return $this->entityId;
	}

	/**
	 * @param int $entityId
	 * @return WebHookRequest
	 */
	public function setEntityId(int $entityId): WebHookRequest
	{
		$this->entityId = $entityId;
		return $this;
	}

	/**
	 * @return int
	 */
	public function getListenerEntityId(): int
	{
		return $this->listenerEntityId;
	}

	/**
	 * @param int $listenerEntityId
	 * @return WebHookRequest
	 */
	public function setListenerEntityId(int $listenerEntityId): WebHookRequest
	{
		$this->listenerEntityId = $listenerEntityId;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getListenerEntityTechnicalName(): string
	{
		return $this->listenerEntityTechnicalName;
	}

	/**
	 * @param string $listenerEntityTechnicalName
	 * @return WebHookRequest
	 */
	public function setListenerEntityTechnicalName(string $listenerEntityTechnicalName): WebHookRequest
	{
		$this->listenerEntityTechnicalName = $listenerEntityTechnicalName;
		return $this;
	}

	/**
	 * @return int
	 */
	public function getSpaceId(): int
	{
		return $this->spaceId;
	}

	/**
	 * @param int $spaceId
	 * @return WebHookRequest
	 */
	public function setSpaceId(int $spaceId): WebHookRequest
	{
		$this->spaceId = $spaceId;
		return $this;
	}

	/**
	 * @return int
	 */
	public function getWebhookListenerId(): int
	{
		return $this->webhookListenerId;
	}

	/**
	 * @param int $webhookListenerId
	 * @return WebHookRequest
	 */
	public function setWebhookListenerId(int $webhookListenerId): WebHookRequest
	{
		$this->webhookListenerId = $webhookListenerId;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getTimestamp(): string
	{
		return $this->timestamp;
	}

	/**
	 * @param string $timestamp
	 * @return WebHookRequest
	 */
	public function setTimestamp(string $timestamp): WebHookRequest
	{
		$this->timestamp = $timestamp;
		return $this;
	}
}