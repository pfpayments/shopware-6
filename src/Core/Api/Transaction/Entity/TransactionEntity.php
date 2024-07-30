<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Api\Transaction\Entity;

use Shopware\Core\{
	Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity,
	Checkout\Order\OrderEntity,
	Checkout\Payment\PaymentMethodEntity,
	Framework\DataAbstractionLayer\Entity,
	Framework\DataAbstractionLayer\EntityIdTrait,
	System\SalesChannel\SalesChannelEntity};
use PostFinanceCheckoutPayment\Core\Api\Refund\Entity\RefundEntityCollection;

/**
 * Class TransactionEntity
 *
 * @package PostFinanceCheckoutPayment\Core\Api\Transaction\Entity
 */
class TransactionEntity extends Entity {

	use EntityIdTrait;

	/**
	 * @var bool
	 */
	protected $confirmationEmailSent;
	
	/**
	 * @var string
	 */
	protected $erpMerchantId;

	/**
	 * @var array
	 */
	protected $data;

	/**
	 * @var \Shopware\Core\Checkout\Payment\PaymentMethodEntity
	 */
	protected $paymentMethod;

	/**
	 * @var string
	 */
	protected $paymentMethodId;

	/**
	 * @var \Shopware\Core\Checkout\Order\OrderEntity
	 */
	protected $order;

	/**
	 * @var string
	 */
	protected $orderId;

	/**
	 * @var \Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity
	 */
	protected $orderTransaction;

	/**
	 * @var string
	 */
	protected $orderTransactionId;

	/**
	 * @var \PostFinanceCheckoutPayment\Core\Api\Refund\Entity\RefundEntityCollection
	 */
	protected $refunds;

	/**
	 * @var int
	 */
	protected $spaceId;

	/**
	 * @var string
	 */
	protected $state;

	/**
	 * @var \Shopware\Core\System\SalesChannel\SalesChannelEntity
	 */
	protected $salesChannel;

	/**
	 * @var string
	 */
	protected $salesChannelId;

	/**
	 * @var int
	 */
	protected $transactionId;

	/**
	 * @return bool
	 */
	public function isConfirmationEmailSent(): bool
	{
		return $this->confirmationEmailSent;
	}

	/**
	 * @param bool $confirmationEmailSent
	 */
	public function setConfirmationEmailSent(bool $confirmationEmailSent): void
	{
		$this->confirmationEmailSent = $confirmationEmailSent;
	}

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
	public function getOrderId(): string
	{
		return $this->orderId;
	}

	/**
	 * @param string $orderId
	 */
	public function setOrderId(string $orderId): void
	{
		$this->orderId = $orderId;
	}

	/**
	 * @return string
	 */
	public function getOrderTransactionId(): string
	{
		return $this->orderTransactionId;
	}

	/**
	 * @param string $orderTransactionId
	 */
	public function setOrderTransactionId(string $orderTransactionId): void
	{
		$this->orderTransactionId = $orderTransactionId;
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
	 * @return string
	 */
	public function getSalesChannelId(): string
	{
		return $this->salesChannelId;
	}

	/**
	 * @param string $salesChannelId
	 */
	public function setSalesChannelId(string $salesChannelId): void
	{
		$this->salesChannelId = $salesChannelId;
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

	/**
	 * @return \Shopware\Core\Checkout\Payment\PaymentMethodEntity
	 */
	public function getPaymentMethod(): PaymentMethodEntity
	{
		return $this->paymentMethod;
	}

	/**
	 * @param \Shopware\Core\Checkout\Payment\PaymentMethodEntity $paymentMethod
	 */
	public function setPaymentMethod(PaymentMethodEntity $paymentMethod): void
	{
		$this->paymentMethod = $paymentMethod;
	}

	/**
	 * @return \Shopware\Core\Checkout\Order\OrderEntity
	 */
	public function getOrder(): OrderEntity
	{
		return $this->order;
	}

	/**
	 * @param \Shopware\Core\Checkout\Order\OrderEntity $order
	 */
	public function setOrder(OrderEntity $order): void
	{
		$this->order = $order;
	}

	/**
	 * @return \Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity
	 */
	public function getOrderTransaction(): OrderTransactionEntity
	{
		return $this->orderTransaction;
	}

	/**
	 * @param \Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity $orderTransaction
	 */
	public function setOrderTransaction(OrderTransactionEntity $orderTransaction): void
	{
		$this->orderTransaction = $orderTransaction;
	}

	/**
	 * @return \PostFinanceCheckoutPayment\Core\Api\Refund\Entity\RefundEntityCollection|null
	 */
	public function getRefunds(): ?RefundEntityCollection
	{
		return $this->refunds;
	}

	/**
	 * @param \PostFinanceCheckoutPayment\Core\Api\Refund\Entity\RefundEntityCollection $refunds
	 */
	public function setRefunds(RefundEntityCollection $refunds): void
	{
		$this->refunds = $refunds;
	}

	/**
	 * @return \Shopware\Core\System\SalesChannel\SalesChannelEntity
	 */
	public function getSalesChannel(): SalesChannelEntity
	{
		return $this->salesChannel;
	}

	/**
	 * @param \Shopware\Core\System\SalesChannel\SalesChannelEntity $salesChannel
	 */
	public function setSalesChannel(SalesChannelEntity $salesChannel): void
	{
		$this->salesChannel = $salesChannel;
	}
}
