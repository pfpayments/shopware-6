<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Util\Payload;

/**
 * Class PayloadLimits
 *
 * Maximum field lengths accepted by the PostFinanceCheckout SDK payload models.
 * The SDK setters throw an \InvalidArgumentException when a value exceeds them,
 * so every value coming from the shop (product labels, promotion names, shipping
 * method names, ...) has to be truncated before it is handed over to the SDK.
 *
 * @package PostFinanceCheckoutPayment\Core\Util\Payload
 */
final class PayloadLimits {

	public const LINE_ITEM_NAME = 150;

	public const LINE_ITEM_SKU = 200;

	public const LINE_ITEM_UNIQUE_ID = 200;

	public const LINE_ITEM_ATTRIBUTE_LABEL = 512;

	public const LINE_ITEM_ATTRIBUTE_VALUE = 512;

	public const TAX_TITLE = 40;

	/**
	 * Truncate a string to the given length, multibyte safe.
	 *
	 * @param string|null $string
	 * @param int         $maxLength
	 * @return string
	 */
	public static function fixLength(?string $string, int $maxLength): string
	{
		return \mb_substr((string) $string, 0, $maxLength, 'UTF-8');
	}

}
