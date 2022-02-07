<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Util\Payload;

use PostFinanceCheckout\Sdk\{
	Model\LineItem,
	Model\RefundCreate,
	Model\RefundType,
	Model\Transaction,
	Model\TransactionState};
use PostFinanceCheckoutPayment\Core\Util\Exception\InvalidPayloadException;

/**
 * Class RefundPayload
 *
 * @package PostFinanceCheckoutPayment\Core\Util\Payload
 */
class RefundPayload extends AbstractPayload {

	/**
	 * @param \PostFinanceCheckout\Sdk\Model\Transaction $transaction
	 * @param string                                       $lineItemId
	 * @param int                                          $quantity
	 * @return \PostFinanceCheckout\Sdk\Model\RefundCreate|null
	 * @throws \Exception
	 */
	public function get(Transaction $transaction, string $lineItemId, int $quantity): ?RefundCreate
	{
		$lineItem = $this->findLineItemByUniqueId($transaction['line_items'], $lineItemId);

		if ($lineItem === null) {
			$errorMessage = sprintf('Line item doesn\'t exist: %s', $lineItemId);
			$this->logger->critical($errorMessage);
			throw new InvalidPayloadException($errorMessage);
		}

		$price = 0;

		// If refund the whole line item
		if ($quantity === 0) {
			$quantity = $lineItem['quantity'];
			$price = $lineItem['unit_price_including_tax'];
		}

		$amount = floatval($quantity * $lineItem['unit_price_including_tax']);

		if (
			($transaction->getState() == TransactionState::FULFILL) &&
			($amount <= floatval($transaction->getAuthorizationAmount()))
		) {
			$reduction = new \PostFinanceCheckout\Sdk\Model\LineItemReductionCreate();
			$reduction->setLineItemUniqueId($lineItem['unique_id']);
			$reduction->setQuantityReduction($quantity);
			$reduction->setUnitPriceReduction($price);

			$refund = (new RefundCreate())
				->setReductions([$reduction])
				->setTransaction($transaction->getId())
				->setMerchantReference($this->fixLength($transaction->getMerchantReference(), 100))
				->setExternalId($this->fixLength(uniqid('refund_', true), 100))
				/** @noinspection PhpParamsInspection */
				->setType(RefundType::MERCHANT_INITIATED_ONLINE);

			if (!$refund->valid()) {
				$this->logger->critical('Refund payload invalid:', $refund->listInvalidProperties());
				throw new InvalidPayloadException('Refund payload invalid:' . json_encode($refund->listInvalidProperties()));
			}

			return $refund;
		}

		return null;
	}

	/**
	 * @param \PostFinanceCheckout\Sdk\Model\Transaction $transaction
	 * @param float                                        $amount
	 * @return \PostFinanceCheckout\Sdk\Model\RefundCreate|null
	 * @throws \Exception
	 */
	public function getByAmount(Transaction $transaction, float $amount): ?RefundCreate
	{
		if (
			($transaction->getState() == TransactionState::FULFILL) &&
			($amount <= floatval($transaction->getAuthorizationAmount()))
		) {
			$refund = (new RefundCreate())
				->setAmount(self::round($amount))
				->setTransaction($transaction->getId())
				->setMerchantReference($this->fixLength($transaction->getMerchantReference(), 100))
				->setExternalId($this->fixLength(uniqid('refund_', true), 100))
				->setType(RefundType::MERCHANT_INITIATED_ONLINE);

			if (!$refund->valid()) {
				$this->logger->critical('Refund payload invalid:', $refund->listInvalidProperties());
				throw new InvalidPayloadException('Refund payload invalid:' . json_encode($refund->listInvalidProperties()));
			}

			return $refund;
		}

		return null;
	}

	/**
	 * @param array $lineItems
	 * @param string $uniqueId
	 * @return LineItem|null
	 */
	private function findLineItemByUniqueId(array $lineItems, string $uniqueId): ?LineItem
	{
		$lineItems = \array_values(
			\array_filter($lineItems, function ($item) use ($uniqueId) {
				return $item['unique_id'] === $uniqueId;
			})
		);

		return $lineItems[0] ?? null;
	}
}
