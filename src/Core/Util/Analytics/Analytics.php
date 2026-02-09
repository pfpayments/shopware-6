<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Util\Analytics;

use PostFinanceCheckout\Sdk\ApiClient;

/**
 * Class Analytics
 *
 * @package PostFinanceCheckoutPayment\Core\Util\Analytics
 */
class Analytics {

	public const SHOP_SYSTEM             = 'x-meta-shop-system';
	public const SHOP_SYSTEM_VERSION     = 'x-meta-shop-system-version';
	public const SHOP_SYSTEM_AND_VERSION = 'x-meta-shop-system-and-version';
	public const PLUGIN_SYSTEM_VERSION   = 'x-meta-plugin-version';
	public const SUBSCRIPTION_TRANSACTION = 'x-meta-subscription-transaction';

	/**
	 * @return array
	 */
	public static function getDefaultData()
	{
		return [
			self::SHOP_SYSTEM             => 'shopware',
			self::SHOP_SYSTEM_VERSION     => '6',
			self::SHOP_SYSTEM_AND_VERSION => 'shopware-6',
			self::PLUGIN_SYSTEM_VERSION   => '7.3.1',
		];
	}

	/**
	 * @param \PostFinanceCheckout\Sdk\ApiClient $apiClient
	 */
	public static function addHeaders(ApiClient &$apiClient, array $additionalHeaders = [])
	{
		$data = self::getDefaultData();
		$data = array_merge($data, $additionalHeaders);
		foreach ($data as $key => $value) {
			$apiClient->addDefaultHeader($key, $value);
		}
	}
}


