<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Util\Payload\CustomProducts;

use Shopware\Core\{
	Checkout\Cart\Tax\Struct\CalculatedTaxCollection,
	Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity
};
use PostFinanceCheckout\Sdk\{
	Model\LineItemAttributeCreate,
	Model\TaxCreate
};
use PostFinanceCheckoutPayment\Core\Util\Exception\InvalidPayloadException;

trait CustomProductsLineItems {

	/**
	 * Get Custom Product attributes
	 *
	 * @param \Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity $shopLineItem
	 *
	 * @return array
	 */
	public function getCustomProductLineItemAttribute(OrderLineItemEntity $shopLineItem)
	{
		$customProductsOptions = $this->transaction->getOrder()
												   ->getLineItems()
												   ->filterByType(CustomProductsLineItemTypes::LINE_ITEM_TYPE_CUSTOMIZED_PRODUCTS_OPTION)
												   ->filterByProperty('parentId', $shopLineItem->getId());
		$productAttributes     = [];
		foreach ($customProductsOptions as $option) {
			$label = $option->getLabel();
			$value = $this->extractValueFromPayload($option);

			if ($value === null) {
				continue;
			}

			$lineItemAttributeCreate = (new LineItemAttributeCreate())
				->setLabel($this->fixLength($label, 512))
				->setValue($this->fixLength($value, 512));

			if ($lineItemAttributeCreate->valid()) {
				$key                     = $this->fixLength('option_' . md5($label), 40);
				$productAttributes[$key] = $lineItemAttributeCreate;
			} else {
				$this->logger->critical('LineItemAttributeCreate payload invalid:', $lineItemAttributeCreate->listInvalidProperties());
				throw new InvalidPayloadException('LineItemAttributeCreate payload invalid:' . json_encode($lineItemAttributeCreate->listInvalidProperties()));
			}
		}
		return $productAttributes;
	}

	/**
	 * @param \Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection $calculatedTaxes
	 * @param string                                                          $title
	 * @param                                                                 $amount
	 *
	 * @return array
	 */
	public function getCustomProductTaxes(CalculatedTaxCollection $calculatedTaxes, string $title, $amount)
	{
		$taxes = [];
		$sumOfTaxes = $this->getSumOfTaxes($calculatedTaxes);

		foreach ($calculatedTaxes as $calculatedTax) {
			$taxRate = ($calculatedTax->getTax() * 100) / ($amount - $sumOfTaxes);
			$taxRate = (float) number_format($taxRate, 8, '.', '');
			$tax = (new TaxCreate())
				->setRate($taxRate)
				->setTitle($this->fixLength($title . ' : ' . $calculatedTax->getTaxRate(), 40));

			if (!$tax->valid()) {
				$this->logger->critical('Tax payload invalid:', $tax->listInvalidProperties());
				throw new InvalidPayloadException('Tax payload invalid:' . json_encode($tax->listInvalidProperties()));
			}

			$taxes [] = $tax;
		}

		return $taxes;
	}

	/**
	 * Extract Custom Product Attribute value
	 *
	 * @param \Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity $option
	 *
	 * @return string|null
	 */
	protected function extractValueFromPayload(OrderLineItemEntity $option): ?string
	{
		$payload = $option->getPayload() ?? [];

		$type = $payload['type'] ?? null;

		$value = $payload['value'] ?? 'on';

		if (!$type) {
			return null;
		}

		if ($type === CustomProductsLineItemTypes::PRODUCT_OPTION_TYPE_DATETIME) {
			return $value ? \date('d.m.Y', \strtotime($value)) : null;
		}

		if ($type === CustomProductsLineItemTypes::PRODUCT_OPTION_TYPE_TIMESTAMP) {
			return $value ? \date('H:i', \strtotime($value)) : null;
		}

		if ($type === CustomProductsLineItemTypes::PRODUCT_OPTION_TYPE_IMAGE_UPLOAD || $type === CustomProductsLineItemTypes::PRODUCT_OPTION_TYPE_FILE_UPLOAD) {
			return \implode(', ', \array_column($option->getPayload()['media'] ?? [], 'filename'));
		}

		return $value;
	}

	/**
	 * @param CalculatedTaxCollection $calculatedTaxes
	 * @return float
	 */
	private function getSumOfTaxes(CalculatedTaxCollection $calculatedTaxes): float
	{
		$sumOfTaxes = 0;
		foreach ($calculatedTaxes as $calculatedTax) {
			$sumOfTaxes += $calculatedTax->getTax();
		}

		return $sumOfTaxes;
	}
}