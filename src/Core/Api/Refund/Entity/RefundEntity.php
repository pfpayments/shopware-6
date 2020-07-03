<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Api\Refund\Entity;

use Shopware\Core\{
	Framework\DataAbstractionLayer\Entity,
	Framework\DataAbstractionLayer\EntityIdTrait};

/**
 * Class RefundEntity
 *
 * @package PostFinanceCheckoutPayment\Core\Api\Refund\Entity
 */
class RefundEntity extends Entity {

	use EntityIdTrait;

	/**
	 * @var array
	 */
	protected $data;

	/**
	 * @var int
	 */
	protected $refundId;

	/**
	 * @var int
	 */
	protected $spaceId;

	/**
	 * @var string
	 */
	protected $state;

	/**
	 * @var int
	 */
	protected $transactionId;

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
	public function getRefundId(): int
	{
		return $this->refundId;
	}

	/**
	 * @param int $refundId
	 */
	public function setRefundId(int $refundId): void
	{
		$this->refundId = $refundId;
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

	/**
	 * @return int
	 */
	public function getTransactionId(): int
	{
		return $this->transactionId;
	}

	/**
	 * @param int $transactionId
	 */
	public function setTransactionId(int $transactionId): void
	{
		$this->transactionId = $transactionId;
	}
}