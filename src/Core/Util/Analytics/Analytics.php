<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Util\Analytics;

use PostFinanceCheckout\Sdk\ApiClient;
use Shopware\Core\Kernel;

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

	/**
	 * @return array
	 */
	public static function getDefaultData(): array
	{
		$shopwareVersion = self::getShopwareVersion();

		return [
		  self::SHOP_SYSTEM             => 'shopware',
		  self::SHOP_SYSTEM_VERSION     => $shopwareVersion,
		  self::SHOP_SYSTEM_AND_VERSION => 'shopware-' . $shopwareVersion,
		  self::PLUGIN_SYSTEM_VERSION   => '6.2.0',
		];
	}

	/**
	 * @param \PostFinanceCheckout\Sdk\ApiClient $apiClient
	 */
	public static function addHeaders(ApiClient &$apiClient): void
	{
		$data = self::getDefaultData();
		foreach ($data as $key => $value) {
			$apiClient->addDefaultHeader($key, $value);
		}
	}

	/**
	 * Reads Shopware version and caches it for performance.
	 *
	 * @return string
	 */
	public static function getShopwareVersion(): string
	{
		static $cachedVersion = null;

		if ($cachedVersion !== null) {
			return $cachedVersion;
		}

		$basePath = dirname(__DIR__, 7);
		$installedFile = $basePath . '/vendor/composer/installed.php';

		if (is_file($installedFile)) {
			$installed = include $installedFile;
			$packages = [];

			if (isset($installed['versions'])) {
				$packages = $installed['versions'];
			} elseif (is_array($installed)) {
				foreach ($installed as $section) {
					if (isset($section['versions'])) {
						$packages = $section['versions'];
						break;
					}
				}
			}

			if (isset($packages['shopware/core']['pretty_version'])) {
				return $cachedVersion = ltrim($packages['shopware/core']['pretty_version'], 'v');
			}
		}

		$lockFile = $basePath . '/composer.lock';
		if (is_file($lockFile)) {
			$data = json_decode((string) file_get_contents($lockFile), true);
			if (!empty($data['packages'])) {
				foreach ($data['packages'] as $package) {
					if (($package['name'] ?? '') === 'shopware/core') {
						return $cachedVersion = ltrim($package['version'], 'v');
					}
				}
			}
		}

		return $cachedVersion = Kernel::SHOPWARE_FALLBACK_VERSION;
	}
}

