<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Api\Space\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\PlatformRequest;
use PostFinanceCheckout\Sdk\{
	ApiClient,
	Model\Space
};

/**
 * Class SpaceService
 *
 * @package PostFinanceCheckoutPayment\Core\Api\Space\Service
 */
class SpaceService {

	/**
	 * @var \PostFinanceCheckout\Sdk\ApiClient
	 */
	protected $apiClient;

	/**
	 * Space Id
	 *
	 * @var int
	 */
	protected $spaceId;

	/**
	 * Application Id
	 *
	 * @var string
	 */
	protected $applicationId;

	/**
	 * User Id
	 *
	 * @var int
	 */
	protected $userId;

	/**
	 * @var \Psr\Log\LoggerInterface
	 */
	protected $logger;


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
	 * @return \PostFinanceCheckout\Sdk\ApiClient
	 */
	public function getApiClient(): ApiClient
	{
		return $this->apiClient;
	}

	/**
	 * @param \PostFinanceCheckout\Sdk\ApiClient $apiClient
	 *
	 * @return \PostFinanceCheckoutPayment\Core\Api\Space\Service\SpaceService
	 */
	public function setApiClient(ApiClient $apiClient): SpaceService
	{
		$this->apiClient = $apiClient;
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
	 *
	 * @return \PostFinanceCheckoutPayment\Core\Api\Space\Service\SpaceService
	 */
	public function setSpaceId(int $spaceId): SpaceService
	{
		$this->spaceId = $spaceId;
		return $this;
	}

	/**
	 * Get user id
	 * @return int
	 */
	public function getUserId(): int
	{
		return $this->userId;
	}

	/**
	 * @param int $userId
	 *
	 * @return \PostFinanceCheckoutPayment\Core\Api\Space\Service\SpaceService
	 */
	public function setUserId(int $userId): SpaceService
	{
		$this->userId = $userId;
		return $this;
	}

	/**
	 * Get user key credential
	 * @return string
	 */
	public function getApplicationId(): string
	{
		return $this->applicationId;
	}

	/**
	 * @param string $applicationId
	 *
	 * @return \PostFinanceCheckoutPayment\Core\Api\Space\Service\SpaceService
	 */
	public function setApplicationId(string $applicationId): SpaceService
	{
		$this->applicationId = $applicationId;
		return $this;
	}

	/**
	 * Check Space
	 * Reads the entity with the given space id and user credentials and returns it.
	 * If the user credentials are not valid, an exception is thrown.
	 * The purpose of this method is simply to validate that a user has access
	 * with their credentials to a space on the portal.
	 * @see On the portal /doc/api/web-service#space-service--read
	 *
	 * @return Space|null
	 * @throws \PostFinanceCheckout\Sdk\ApiException
	 * @throws \PostFinanceCheckout\Sdk\Http\ConnectionException
	 * @throws \PostFinanceCheckout\Sdk\VersioningException
	 */
	public function checkSpace(): ?Space
	{
		// Configuration
		$this->setApiClient(new ApiClient($this->getUserId(), $this->getApplicationId()));

		return $this->read();
	}

	/**
	 * Read Space
	 *
	 * @return Space|null
	 */
	protected function read(): ?Space
	{
		$returnValue = null;
		try {
			$returnValue = $this->apiClient->getSpaceService()->read($this->getSpaceId());
		} catch (\Exception $exception) {
			$this->logger->critical($exception->getTraceAsString());
		}

		return $returnValue;
	}

}