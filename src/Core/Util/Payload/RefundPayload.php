<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Util\Payload;

use PostFinanceCheckout\Sdk\{
  Model\LineItem,
  Model\LineItemReductionCreate,
  Model\LineItemType,
  Model\RefundCreate,
  Model\RefundType,
  Model\Transaction,
  Model\TransactionState
};
use PostFinanceCheckoutPayment\Core\Util\Exception\InvalidPayloadException;

/**
 * Class RefundPayload
 *
 * @package PostFinanceCheckoutPayment\Core\Util\Payload
 */
class RefundPayload extends AbstractPayload
{
    
    /**
     * Builds a refund for a quantity of one line item.
     *
     * For a product, the refund also gives back the product's share of the order's discounts,
     * so the customer gets back what they actually paid for it.
     *
     * @param \PostFinanceCheckout\Sdk\Model\Transaction $transaction
     * @param string $lineItemId
     * @param int $quantity
     * @param array $previousReductions
     * @return \PostFinanceCheckout\Sdk\Model\RefundCreate|null
     * @throws \Exception
     */
    public function get(Transaction $transaction, string $lineItemId, int $quantity, array $previousReductions = []): ?RefundCreate
    {
        $lineItem = $this->findRefundableLineItem($transaction, $lineItemId);

        // If refund the whole line item
        if ($quantity === 0) {
            $quantity = $lineItem['quantity'];
        }

        $amount = floatval($quantity * $lineItem['unit_price_including_tax']);

        $reduction = new LineItemReductionCreate();
        $reduction->setLineItemUniqueId($lineItem['unique_id']);
        $reduction->setQuantityReduction($quantity);
        // Reduce the quantity only: a price reduction on top of it makes the line count twice
        // when a later refund works out what is still open.
        $reduction->setUnitPriceReduction(0);
        $reductions = [$reduction];

        $amount = $this->addDiscountReductions($transaction, $lineItem, $amount, $previousReductions, $reductions);
        $refundableAmount = $this->getRefundableAmount($transaction);

        if (
          ($transaction->getState() == TransactionState::FULFILL) &&
          ($amount <= $refundableAmount)
        ) {
            $refund = (new RefundCreate())
              ->setReductions($reductions)
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

        $this->logger->warning(sprintf(
            'Line item refund not created for transaction %s: state %s, refund amount %s, refundable amount %s',
            $transaction->getId(),
            $transaction->getState(),
            $amount,
            $refundableAmount
        ));

        return null;
    }

    /**
     * Adds a promotion to the payload as one discount line item per tax rate.
     *
     * For each discount line with the product's tax rate, the share is the product amount's
     * fraction of the products of that rate that are not refunded yet. The last product
     * refunded therefore gets exactly what is left of the discount. Non-product line items
     * get no discount share.
     *
     * @param Transaction $transaction
     * @param LineItem $product
     * @param float $productAmount
     * @param array $previousReductions
     * @param array &$reductions
     * @return float
     */
    private function addDiscountReductions(Transaction $transaction, LineItem $product, float $productAmount, array $previousReductions, array &$reductions): float
    {
        $rate = $product->getType() === LineItemType::PRODUCT ? $this->getTaxRate($product) : null;
        $openProducts = 0.0;
        $discounts = [];
        foreach ($transaction->getLineItems() as $item) {
            if ($rate === null || $this->getTaxRate($item) !== $rate) {
                continue;
            }
            if ($item->getType() === LineItemType::PRODUCT) {
                $openProducts += $this->getOpenAmount($item, $previousReductions);
            } elseif ($item->getType() === LineItemType::DISCOUNT) {
                $discounts[] = $item;
            }
        }
        // Nothing open to spread the discount over: refund the product amount as it is.
        if ($openProducts <= 0) {
            return self::round($productAmount);
        }
        $ratio = min(1.0, $productAmount / $openProducts);

        $amount = $productAmount;
        foreach ($discounts as $discount) {
            $share = self::round($this->getOpenAmount($discount, $previousReductions) * $ratio);
            if ($share == 0.0) {
                continue;
            }
            // Give back the whole line if nothing of it was refunded yet, otherwise lower its price.
            $whole = $share == self::round($discount->getAmountIncludingTax());
            $reductions[] = (new LineItemReductionCreate())
                ->setLineItemUniqueId($discount->getUniqueId())
                ->setQuantityReduction($whole ? $discount->getQuantity() : 0)
                ->setUnitPriceReduction($whole ? 0 : $share / $discount->getQuantity());
            $amount += $share;
        }

        return self::round($amount);
    }

    /**
     * Returns the amount still left to refund on the transaction.
     *
     * @param Transaction $transaction
     * @return float
     */
    private function getRefundableAmount(Transaction $transaction): float
    {
        return self::round(floatval($transaction->getAuthorizationAmount()) - floatval($transaction->getRefundedAmount()));
    }

    /**
     * Finds the line item to refund.
     *
     * Discount lines are rejected: they are only refunded together with their products.
     *
     * @param Transaction $transaction
     * @param string $lineItemId
     * @return LineItem
     */
    private function findRefundableLineItem(Transaction $transaction, string $lineItemId): LineItem
    {
        $lineItem = $this->findLineItemByUniqueId($transaction['line_items'], $lineItemId);
        if ($lineItem === null || $lineItem->getType() === LineItemType::DISCOUNT) {
            $errorMessage = sprintf($lineItem === null ? 'Line item doesn\'t exist: %s' : 'Discount line item %s cannot be refunded on its own', $lineItemId);
            $this->logger->critical($errorMessage);
            throw new InvalidPayloadException($errorMessage);
        }
        return $lineItem;
     }

    /**
     * Returns the part of a line item's amount that is not refunded yet.
     *
     * @param LineItem $lineItem
     * @param array $previousReductions
     * @return float
     */
    private function getOpenAmount(LineItem $lineItem, array $previousReductions): float
    {
        $open = floatval($lineItem->getAmountIncludingTax());
        foreach ($previousReductions as $reduction) {
            if ($reduction->getLineItemUniqueId() === $lineItem->getUniqueId()) {
                $open -= $reduction->getQuantityReduction() * $lineItem->getUnitPriceIncludingTax()
                  + $reduction->getUnitPriceReduction() * $lineItem->getQuantity();
            }
        }
        return self::round($open);
    }

    /**
     * Returns the line item's tax rate.
     *
     * @param LineItem $lineItem
     * @return float|null
     */
    private function getTaxRate(LineItem $lineItem): ?float
    {
        $taxes = $lineItem->getTaxes() ?? [];
        return count($taxes) === 1 ? round(floatval($taxes[0]->getRate()), 4) : null;
    }
    
    /**
     * @param \PostFinanceCheckout\Sdk\Model\Transaction $transaction
     * @param float $amount
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
     * Builds a refund that takes an amount off one line item's price.
     *
     * The amount is taken off the item's price, not paid out as is: for a product, its share
     * of the order's discounts goes back too, so the customer receives less than the amount.
     *
     * @param \PostFinanceCheckout\Sdk\Model\Transaction $transaction
     * @param string $lineItemId
     * @param int $quantity
     * @param array $previousReductions
     * @return \PostFinanceCheckout\Sdk\Model\RefundCreate|null
     * @throws \Exception
     */
    public function getForPartial(Transaction $transaction, string $lineItemId, float $amount, array $previousReductions = []): ?RefundCreate
    {
        $lineItem = $this->findRefundableLineItem($transaction, $lineItemId);
        $quantityAvailable = intval($lineItem['quantity']);
        $openItemAmount = $this->getOpenAmount($lineItem, $previousReductions);

        $reduction = new LineItemReductionCreate();
        $reduction->setLineItemUniqueId($lineItemId);
        $reduction->setQuantityReduction(0);
        $reduction->setUnitPriceReduction($amount / $quantityAvailable);
        $reductions = [$reduction];
        $refundAmount = $this->addDiscountReductions($transaction, $lineItem, $amount, $previousReductions, $reductions);
        $refundableAmount = $this->getRefundableAmount($transaction);
        
        if (
          ($transaction->getState() == TransactionState::FULFILL) &&
          (self::round($amount) <= $openItemAmount) &&
          ($refundAmount <= $refundableAmount)
        ) {
            $refund = (new RefundCreate())
              ->setReductions($reductions)
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

        $this->logger->warning(
            sprintf(
                'Partial refund for transaction %s not created: state %s, amount %s (item open %s), refund %s, refundable %s',
                $transaction->getId(),
                $transaction->getState(),
                $amount,
                $openItemAmount,
                $refundAmount,
                $refundableAmount
        ));
        
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
