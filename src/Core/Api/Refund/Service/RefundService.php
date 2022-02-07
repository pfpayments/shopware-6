<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Api\Refund\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\{
	Framework\Context,
	Framework\DataAbstractionLayer\Search\Criteria,
	Framework\DataAbstractionLayer\Search\Filter\EqualsFilter,
	Framework\Uuid\Uuid
};
use PostFinanceCheckout\Sdk\{
	Model\Refund,
	Model\Transaction
};
use PostFinanceCheckoutPayment\Core\{
	Api\Refund\Entity\RefundEntity,
	Api\Transaction\Entity\TransactionEntity,
	Api\Transaction\Entity\TransactionEntityDefinition,
	Settings\Service\SettingsService,
	Util\Payload\RefundPayload
};

/**
 * Class RefundService
 *
 * @package PostFinanceCheckoutPayment\Core\Api\Refund\Service
 */
class RefundService {

	/**
	 * @var \Psr\Container\ContainerInterface
	 */
	protected $container;

	/**
	 * @var \Psr\Log\LoggerInterface
	 */
	private $logger;

	/**
	 * @var \PostFinanceCheckoutPayment\Core\Settings\Service\SettingsService
	 */
	private $settingsService;

	/**
	 * RefundService constructor.
	 *
	 * @param \Psr\Container\ContainerInterface                                   $container
	 * @param \PostFinanceCheckoutPayment\Core\Settings\Service\SettingsService $settingsService
	 */
	public function __construct(ContainerInterface $container, SettingsService $settingsService)
	{
		$this->container       = $container;
		$this->settingsService = $settingsService;
	}

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
	 * The pay function will be called after the customer completed the order.
	 * Allows to process the order and store additional information.
	 *
	 * A redirect to the url will be performed
	 *
	 * @param \PostFinanceCheckout\Sdk\Model\Transaction $transaction
	 * @param string|null                                  $lineItemId
	 * @param int                                          $quantity
	 * @param \Shopware\Core\Framework\Context             $context
	 *
	 * @return \PostFinanceCheckout\Sdk\Model\Refund|null
	 * @throws \Exception
	 */
	public function create(Transaction $transaction, Context $context, ?string $lineItemId = null, int $quantity): ?Refund
	{
		try {
			$transactionEntity  = $this->getTransactionEntityByTransactionId($transaction->getId(), $context);
			$settings           = $this->settingsService->getSettings($transactionEntity->getSalesChannel()->getId());
			$apiClient          = $settings->getApiClient();
			$refundPayloadClass = new RefundPayload();
			$refundPayloadClass->setLogger($this->logger);

			$refundPayload = $refundPayloadClass->get($transaction, $lineItemId, $quantity);

			if (!is_null($refundPayload)) {
				$refund = $apiClient->getRefundService()->refund($settings->getSpaceId(), $refundPayload);
				$this->upsert($refund, $context);
				return $refund;
			}
		} catch (\Exception $exception) {
			$this->logger->critical($exception->getMessage());
		}
		return null;
	}

	/**
	 * The pay function will be called after the customer completed the order.
	 * Allows to process the order and store additional information.
	 *
	 * A redirect to the url will be performed
	 *
	 * @param \PostFinanceCheckout\Sdk\Model\Transaction $transaction
	 * @param float                                        $refundableAmount
	 * @param \Shopware\Core\Framework\Context             $context
	 *
	 * @return \PostFinanceCheckout\Sdk\Model\Refund|null
	 * @throws \Exception
	 */
	public function createRefundByAmount(Transaction $transaction, float $refundableAmount, Context $context): ?Refund
	{
		try {
			$transactionEntity  = $this->getTransactionEntityByTransactionId($transaction->getId(), $context);
			$settings           = $this->settingsService->getSettings($transactionEntity->getSalesChannel()->getId());
			$apiClient          = $settings->getApiClient();
			$refundPayloadClass = new RefundPayload();
			$refundPayloadClass->setLogger($this->logger);

			$refundPayload = $refundPayloadClass->getByAmount($transaction, $refundableAmount);

			if (!is_null($refundPayload)) {
				$refund = $apiClient->getRefundService()->refund($settings->getSpaceId(), $refundPayload);
				$this->upsert($refund, $context);
				return $refund;
			}
		} catch (\Exception $exception) {
			$this->logger->critical($exception->getMessage());
		}
		return null;
	}

	/**
	 * Get transaction entity by PostFinanceCheckout transaction id
	 *
	 * @param int                              $transactionId
	 * @param \Shopware\Core\Framework\Context $context
	 *
	 * @return \PostFinanceCheckoutPayment\Core\Api\Transaction\Entity\TransactionEntity
	 */
	public function getTransactionEntityByTransactionId(int $transactionId, Context $context): TransactionEntity
	{
		return $this->container->get(TransactionEntityDefinition::ENTITY_NAME . '.repository')
							   ->search(
								   (new Criteria())->addFilter(new EqualsFilter('transactionId', $transactionId)),
								   $context
							   )
							   ->first();
	}

	/**
	 * Persist PostFinanceCheckout transaction
	 *
	 * @param \Shopware\Core\Framework\Context        $context
	 * @param \PostFinanceCheckout\Sdk\Model\Refund $refund
	 */
	public function upsert(Refund $refund, Context $context): void
	{
		$refundEntity = $this->getByRefundId($refund->getId(), $context);
		$id           = is_null($refundEntity) ? Uuid::randomHex() : $refundEntity->getId();
		try {

			$data = [
				'id'            => $id,
				'data'          => json_decode(strval($refund), true),
				'refundId'      => $refund->getId(),
				'spaceId'       => $refund->getLinkedSpaceId(),
				'state'         => $refund->getState(),
				'transactionId' => $refund->getTransaction()->getId(),
			];

			$data = array_filter($data);
			$this->container->get('postfinancecheckout_refund.repository')->upsert([$data], $context);

		} catch (\Exception $exception) {
			$this->logger->critical(__CLASS__ . ' : ' . __FUNCTION__ . ' : ' . $exception->getMessage());
		}
	}

	/**
	 * Get refund entity by PostFinanceCheckout refund id
	 *
	 * @param int                              $refundId
	 * @param \Shopware\Core\Framework\Context $context
	 *
	 * @return \PostFinanceCheckoutPayment\Core\Api\Refund\Entity\RefundEntity|null
	 */
	public function getByRefundId(int $refundId, Context $context): ?RefundEntity
	{
		return $this->container->get('postfinancecheckout_refund.repository')
							   ->search(
								   (new Criteria())->addFilter(new EqualsFilter('refundId', $refundId)),
								   $context
							   )
							   ->first();
	}

}
