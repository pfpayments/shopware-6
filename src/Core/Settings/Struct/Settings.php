<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Settings\Struct;

use Shopware\Core\Framework\Struct\Struct;
use PostFinanceCheckout\Sdk\ApiClient;

/**
 * Class Settings
 *
 * @package PostFinanceCheckoutPayment\Core\Settings\Struct
 */
class Settings extends Struct {

	/**
	 * @var \PostFinanceCheckout\Sdk\ApiClient
	 */
	protected $apiClient;

	/**
	 * Application Key
	 *
	 * @var string
	 */
	protected $applicationKey;

	/**
	 * Enable emails
	 *
	 * @var bool
	 */
	protected $emailEnabled;

	/**
	 * Preferred integration
	 *
	 * @var string
	 */
	protected $integration;

	/**
	 * Enforce line item consistency
	 *
	 * @var bool
	 */
	protected $lineItemConsistencyEnabled;

	/**
	 * Space Id
	 *
	 * @var int
	 */
	protected $spaceId;

	/**
	 * Space View Id
	 *
	 * @var ?int
	 */
	protected $spaceViewId;

	/**
	 * User id
	 *
	 * @var int
	 */
	protected $userId;

	/**
	 * @return bool
	 */
	public function isEmailEnabled(): bool
	{
		return boolval($this->emailEnabled);
	}

	/**
	 * @param bool $emailEnabled
	 */
	public function setEmailEnabled(bool $emailEnabled): void
	{
		$this->emailEnabled = $emailEnabled;
	}


	/**
	 * @return string
	 */
	public function getIntegration(): string
	{
		return strval($this->integration);
	}

	/**
	 * @param string $integration
	 */
	public function setIntegration(string $integration): void
	{
		$this->integration = $integration;
	}

	/**
	 * @return bool
	 */
	public function isLineItemConsistencyEnabled(): bool
	{
		return boolval($this->lineItemConsistencyEnabled);
	}

	/**
	 * @param bool $lineItemConsistencyEnabled
	 */
	public function setLineItemConsistencyEnabled(bool $lineItemConsistencyEnabled): void
	{
		$this->lineItemConsistencyEnabled = $lineItemConsistencyEnabled;
	}

	/**
	 * @return int
	 */
	public function getSpaceId(): int
	{
		return intval($this->spaceId);
	}

	/**
	 * @param int $spaceId
	 */
	public function setSpaceId(int $spaceId): void
	{
		$this->spaceId = $spaceId;
	}

	/**
	 * @return int|null
	 */
	public function getSpaceViewId(): ?int
	{
		if (!empty($this->spaceViewId) && is_numeric($this->spaceViewId)) {
			return intval($this->spaceViewId);
		}

		return null;
	}

	/**
	 * @param int $spaceViewId
	 */
	public function setSpaceViewId(int $spaceViewId): void
	{
		$this->spaceViewId = $spaceViewId;
	}

	/**
	 * Get SDK ApiClient
	 *
	 * @return \PostFinanceCheckout\Sdk\ApiClient
	 */
	public function getApiClient(): ApiClient
	{
		if (is_null($this->apiClient)) {
			$this->apiClient   = new ApiClient($this->getUserId(), $this->getApplicationKey());
			$apiClientBasePath = getenv('POSTFINANCECHECKOUT_API_BASE_PATH') ? getenv('POSTFINANCECHECKOUT_API_BASE_PATH') : $this->apiClient->getBasePath();
			$this->apiClient->setBasePath($apiClientBasePath);
		}
		return $this->apiClient;
	}

	/**
	 * @return int
	 */
	public function getUserId(): int
	{
		return intval($this->userId);
	}

	/**
	 * @param int $userId
	 */
	public function setUserId(int $userId): void
	{
		$this->userId = $userId;
	}

	/**
	 * @return string
	 */
	public function getApplicationKey(): string
	{
		return strval($this->applicationKey);
	}

	/**
	 * @param string $applicationKey
	 */
	public function setApplicationKey(string $applicationKey): void
	{
		$this->applicationKey = $applicationKey;
	}
}