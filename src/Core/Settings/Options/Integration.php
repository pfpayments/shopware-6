<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Settings\Options;

/**
 * Class Integration
 *
 * @package PostFinanceCheckoutPayment\Core\Settings\Options
 */
class Integration {

	/**
	 * Possible values of this enum
	 */
	public const CHARGE_FLOW            = 'charge_flow';
	public const DIRECT_CARD_PROCESSING = 'direct_card_processing';
	public const IFRAME                 = 'iframe';
	public const LIGHTBOX               = 'lightbox';
	public const MOBILE_WEB             = 'mobile_web_view';
	public const PAYMENT_LINK           = 'payment_link';
	public const PAYMENT_PAGE           = 'payment_page';
	public const TERMINAL               = 'terminal';


	/**
	 * Gets allowable values of the enum
	 * @return string[]
	 */
	public static function getAllowableEnumValues(): array
	{
		return [
			self::CHARGE_FLOW,
			self::DIRECT_CARD_PROCESSING,
			self::IFRAME,
			self::LIGHTBOX,
			self::MOBILE_WEB,
			self::PAYMENT_LINK,
			self::PAYMENT_PAGE,
			self::TERMINAL,
		];
	}
}