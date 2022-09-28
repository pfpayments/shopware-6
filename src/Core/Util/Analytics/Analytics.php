<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Util\Analytics;

use Composer\InstalledVersions;
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

	/**
	 * @return array
	 */
	public static function getDefaultData()
	{
		$shop_version = InstalledVersions::getVersion('shopware/core');
		[$major_version, $minor_version, $rest] = explode('.', $shop_version, 3);
		return [
			self::SHOP_SYSTEM             => 'shopware',
			self::SHOP_SYSTEM_VERSION     => $shop_version,
			self::SHOP_SYSTEM_AND_VERSION => 'shopware-' . $major_version . '.' . $minor_version,
		];
	}

	/**
	 * @param \PostFinanceCheckout\Sdk\ApiClient $apiClient
	 */
	public static function addHeaders(ApiClient &$apiClient)
	{
		$data = self::getDefaultData();
		foreach ($data as $key => $value) {
			$apiClient->addDefaultHeader($key, $value);
		}
	}
}


