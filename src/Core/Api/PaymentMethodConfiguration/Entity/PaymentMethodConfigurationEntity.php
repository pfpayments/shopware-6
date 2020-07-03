<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Api\PaymentMethodConfiguration\Entity;

use Shopware\Core\{
	Framework\DataAbstractionLayer\Entity,
	Framework\DataAbstractionLayer\EntityIdTrait};

/**
 * Class PaymentMethodConfigurationEntity
 *
 * @package PostFinanceCheckoutPayment\Core\Api\PaymentMethodConfiguration\Entity
 */
class PaymentMethodConfigurationEntity extends Entity {

	use EntityIdTrait;

	/**
	 * @var array
	 */
	protected $data;

	/**
	 * @var int
	 */
	protected $paymentMethodConfigurationId;

	/**
	 * @var string
	 */
	protected $paymentMethodId;

	/**
	 * @var string
	 */
	protected $sortOrder;

	/**
	 * @var int
	 */
	protected $spaceId;

	/**
	 * @var string
	 */
	protected $state;

	/**
	 * @return array
	 */
	public function getData(): array
	{
		return $this->data;
	}

	/**
	 * @param array $data
	 */
	public function setData(array $data): void
	{
		$this->data = $data;
	}

	/**
	 * @return int
	 */
	public function getPaymentMethodConfigurationId(): int
	{
		return $this->paymentMethodConfigurationId;
	}

	/**
	 * @param int $paymentMethodConfigurationId
	 */
	public function setPaymentMethodConfigurationId(int $paymentMethodConfigurationId): void
	{
		$this->paymentMethodConfigurationId = $paymentMethodConfigurationId;
	}

	/**
	 * @return string
	 */
	public function getPaymentMethodId(): string
	{
		return $this->paymentMethodId;
	}

	/**
	 * @param string $paymentMethodId
	 */
	public function setPaymentMethodId(string $paymentMethodId): void
	{
		$this->paymentMethodId = $paymentMethodId;
	}

	/**
	 * @return string
	 */
	public function getSortOrder(): string
	{
		return $this->sortOrder;
	}

	/**
	 * @param string $sortOrder
	 */
	public function setSortOrder(string $sortOrder): void
	{
		$this->sortOrder = $sortOrder;
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
	 */
	public function setSpaceId(int $spaceId): void
	{
		$this->spaceId = $spaceId;
	}

	/**
	 * @return string
	 */
	public function getState(): string
	{
		return $this->state;
	}

	/**
	 * @param string $state
	 */
	public function setState(string $state): void
	{
		$this->state = $state;
	}
}