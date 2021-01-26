<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Util\Payload;

use PostFinanceCheckout\Sdk\{
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
	 * @param float                                        $amount
	 * @return \PostFinanceCheckout\Sdk\Model\RefundCreate|null
	 * @throws \Exception
	 */
	public function get(Transaction $transaction, float $amount): ?RefundCreate
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
}