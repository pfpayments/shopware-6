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
  Model\Transaction,
  Model\CriteriaOperator,
  Model\EntityQueryFilter,
  Model\EntityQueryFilterType,
  Model\EntityQuery,
  ApiException
};
use PostFinanceCheckoutPayment\Core\{
  Api\Refund\Entity\RefundEntity,
  Api\Transaction\Entity\TransactionEntity,
  Api\Transaction\Entity\TransactionEntityDefinition,
  Settings\Service\SettingsService,
  Util\Payload\RefundPayload,
  Util\Exception\RefundNotSupportedException
};

/**
 * Class RefundService
 *
 * @package PostFinanceCheckoutPayment\Core\Api\Refund\Service
 */
class RefundService
{
    
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
     * @param \Psr\Container\ContainerInterface $container
     * @param \PostFinanceCheckoutPayment\Core\Settings\Service\SettingsService $settingsService
     */
    public function __construct(ContainerInterface $container, SettingsService $settingsService)
    {
        $this->container = $container;
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
     * @param string|null $lineItemId
     * @param int $quantity
     * @param \Shopware\Core\Framework\Context $context
     *
     * @return \PostFinanceCheckout\Sdk\Model\Refund|null
     * @throws \Exception
     */
    public function create(Transaction $transaction, Context $context, ?string $lineItemId, int $quantity): ?Refund
    {
        try {
            $transactionEntity = $this->getTransactionEntityByTransactionId($transaction->getId(), $context);
            $settings = $this->settingsService->getSettings($transactionEntity->getSalesChannel()->getId());
            $apiClient = $settings->getApiClient();
            $refundPayloadClass = new RefundPayload();
            $refundPayloadClass->setLogger($this->logger);
            
            $refundPayload = $refundPayloadClass->get($transaction, $lineItemId, $quantity);
            
            if (!is_null($refundPayload)) {
                $refund = $apiClient->getRefundService()->refund($settings->getSpaceId(), $refundPayload);
                $this->upsert($refund, $context);
                return $refund;
            }
        } catch (ApiException $exception) {
            $message = $exception->getMessage();
            $this->logger->critical($message);
            if ($exception->getCode() === 442 && str_contains($message, 'does not support online refunds')) {
                throw new RefundNotSupportedException($message, 0, $exception);
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
     * @param float $refundableAmount
     * @param \Shopware\Core\Framework\Context $context
     *
     * @return \PostFinanceCheckout\Sdk\Model\Refund|null
     * @throws \Exception
     */
    public function createRefundByAmount(Transaction $transaction, float $refundableAmount, Context $context): ?Refund
    {
        try {
            $transactionEntity = $this->getTransactionEntityByTransactionId($transaction->getId(), $context);
            $settings = $this->settingsService->getSettings($transactionEntity->getSalesChannel()->getId());
            $apiClient = $settings->getApiClient();
            $refundPayloadClass = new RefundPayload();
            $refundPayloadClass->setLogger($this->logger);
            
            $refundPayload = $refundPayloadClass->getByAmount($transaction, $refundableAmount);
            
            if (!is_null($refundPayload)) {
                $refund = $apiClient->getRefundService()->refund($settings->getSpaceId(), $refundPayload);
                $this->upsert($refund, $context);
                return $refund;
            }
        } catch (ApiException $exception) {
            $message = $exception->getMessage();
            $this->logger->critical($message);
            if ($exception->getCode() === 442 && str_contains($message, 'does not support online refunds')) {
                throw new RefundNotSupportedException($message, 0, $exception);
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
     * @param \Shopware\Core\Framework\Context $context
     * @param string $lineItemId
     * @param float $amount
     *
     * @return \PostFinanceCheckout\Sdk\Model\Refund|null
     * @throws \Exception
     */
    public function createPartialRefund(Transaction $transaction, Context $context, string $lineItemId, float $amount): ?Refund
    {
        try {
            $transactionEntity = $this->getTransactionEntityByTransactionId($transaction->getId(), $context);
            $settings = $this->settingsService->getSettings($transactionEntity->getSalesChannel()->getId());
            $apiClient = $settings->getApiClient();
            $refundPayloadClass = new RefundPayload();
            $refundPayloadClass->setLogger($this->logger);
            
            $refundPayload = $refundPayloadClass->getForPartial($transaction, $lineItemId, $amount);
            
            if (!is_null($refundPayload)) {
                $refund = $apiClient->getRefundService()->refund($settings->getSpaceId(), $refundPayload);
                $this->upsert($refund, $context);
                return $refund;
            }
        } catch (ApiException $exception) {
            $message = $exception->getMessage();
            $this->logger->critical($message);
            if ($exception->getCode() === 442 && str_contains($message, 'does not support online refunds')) {
                throw new RefundNotSupportedException($message, 0, $exception);
            }
        } catch (\Exception $exception) {
            $this->logger->critical($exception->getMessage());
        }
        return null;
    }
    
    /**
     * Get transaction entity by PostFinanceCheckout transaction id
     *
     * @param int $transactionId
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
     * @param \Shopware\Core\Framework\Context $context
     * @param \PostFinanceCheckout\Sdk\Model\Refund $refund
     */
    public function upsert(Refund $refund, Context $context): void
    {
        $refundEntity = $this->getByRefundId($refund->getId(), $context);
        $id = is_null($refundEntity) ? Uuid::randomHex() : $refundEntity->getId();
        try {
            
            $data = [
              'id' => $id,
              'data' => json_decode(strval($refund), true),
              'refundId' => $refund->getId(),
              'spaceId' => $refund->getLinkedSpaceId(),
              'state' => $refund->getState(),
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
     * @param int $refundId
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
    
    /**
     * Get total refunded quantity for transaction's line item by lineItemId.
     *
     * @param \PostFinanceCheckout\Sdk\Model\Transaction $transaction
     * @param \Shopware\Core\Framework\Context $context
     * @param string $lineItemId
     *
     * @return int
     */
    public function getRefundedQuantity(Transaction $transaction, Context $context, string $lineItemId): int {
        $transactionEntity = $this->getTransactionEntityByTransactionId($transaction->getId(), $context);
        $settings = $this->settingsService->getSettings($transactionEntity->getSalesChannel()->getId());
        $apiClient = $settings->getApiClient();

        $entityQueryFilter = (new EntityQueryFilter())
            ->setType(EntityQueryFilterType::LEAF)
            ->setOperator(CriteriaOperator::EQUALS)
            ->setFieldName('transaction.id')
            ->setValue($transaction->getId());

        $query = (new EntityQuery())->setFilter($entityQueryFilter);

        $refunds = $apiClient->getRefundService()->search($settings->getSpaceId(), $query);

        $refundedQuantity = 0;

        foreach ($refunds as $refund) {
            foreach ($refund->getReductions() as $reduction) {
                if ($reduction->getLineItemUniqueId() === $lineItemId) {
                    $refundedQuantity += (int) $reduction->getQuantityReduction();
                }
            }
        }

        return $refundedQuantity;
    }

    /**
     * Get maximum quantity of available items to refund for line item.
     *
     * @param \PostFinanceCheckout\Sdk\Model\Transaction $transaction
     * @param \Shopware\Core\Framework\Context $context
     * @param string $lineItemId
     *
     * @return int
     */
    public function getMaxRefundableQuantity(Transaction $transaction, Context $context, string $lineItemId): int {

        $originalQuantity = 0;

        foreach ($transaction->getLineItems() as $lineItem) {
            if ($lineItem->getUniqueId() === $lineItemId) {
                $originalQuantity = (int) $lineItem->getQuantity();
                break;
            }
        }

        $refundedQuantity = $this->getRefundedQuantity($transaction, $context, $lineItemId);

        $maxQuantity = $originalQuantity - $refundedQuantity;

        return $maxQuantity;
    }
}
