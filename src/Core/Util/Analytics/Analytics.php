<?php declare(strict_types=1);

namespace WeArePlanetPayment\Core\Util\Analytics;

use WeArePlanet\Sdk\ApiClient;

/**
 * Class Analytics
 *
 * @package WeArePlanetPayment\Core\Util\Analytics
 */
class Analytics {

	public const SHOP_SYSTEM             = 'x-meta-shop-system';
	public const SHOP_SYSTEM_VERSION     = 'x-meta-shop-system-version';
	public const SHOP_SYSTEM_AND_VERSION = 'x-meta-shop-system-and-version';

	/**
	 * @return array
	 */
	public static function getDefaultData()
	{
		return [
			self::SHOP_SYSTEM             => 'shopware',
			self::SHOP_SYSTEM_VERSION     => '6',
			self::SHOP_SYSTEM_AND_VERSION => 'shopware-6',
		];
	}

	/**
	 * @param \WeArePlanet\Sdk\ApiClient $apiClient
	 */
	public static function addHeaders(ApiClient &$apiClient)
	{
		$data = self::getDefaultData();
		foreach ($data as $key => $value) {
			$apiClient->addDefaultHeader($key, $value);
		}
	}
}


