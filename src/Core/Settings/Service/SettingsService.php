<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Settings\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use PostFinanceCheckoutPayment\Core\Settings\Struct\Settings;


/**
 * Class SettingsService
 *
 * @package PostFinanceCheckoutPayment\Core\Settings\Service
 */
class SettingsService {

	/**
	 * Prefix to PostFinanceCheckout configs
	 */
	public const SYSTEM_CONFIG_DOMAIN                       = 'PostFinanceCheckoutPayment.config.';
	public const CONFIG_APPLICATION_KEY                     = 'applicationKey';
	public const CONFIG_EMAIL_ENABLED                       = 'emailEnabled';
	public const CONFIG_INTEGRATION                         = 'integration';
	public const CONFIG_LINE_ITEM_CONSISTENCY_ENABLED       = 'lineItemConsistencyEnabled';
	public const CONFIG_SPACE_ID                            = 'spaceId';
	public const CONFIG_SPACE_VIEW_ID                       = 'spaceViewId';
	public const CONFIG_STOREFRONT_INVOICE_DOWNLOAD_ENABLED = 'storefrontInvoiceDownloadEnabled';
	public const CONFIG_USER_ID                             = 'userId';
	public const CONFIG_STOREFRONT_WEBHOOKS_UPDATE_ENABLED  = 'storefrontWebhooksUpdateEnabled';
	public const CONFIG_STOREFRONT_PAYMENTS_UPDATE_ENABLED  = 'storefrontPaymentsUpdateEnabled';

	/**
	 * List of config properties whose values allowed to be empty without triggering a warning in logger.
	 * 
	 * This list is derived from testing of all config properties. The plugin fails only when either spaceId, userId, applicationKey and/or integration is empty.
	 * On top of that, spaceId, userId, applicationKey are marked as "required" input fields in admin interface.
	 * 
	 * It is worth considering updating this list whenever a new config is introduced in settings.
	 * If new config is optional, left empty by design and not required for transactions to work, this list should be updated to avoid false-positive warnings.
	 * 
	 * @var array
	 */
	private const ALLOWED_EMPTY_CONFIGS = [
		// Options
		self::CONFIG_SPACE_VIEW_ID,
		self::CONFIG_LINE_ITEM_CONSISTENCY_ENABLED,
		self::CONFIG_EMAIL_ENABLED,
		
		// Storefront Options
		self::CONFIG_STOREFRONT_INVOICE_DOWNLOAD_ENABLED,

		// Advanced Options
		self::CONFIG_STOREFRONT_WEBHOOKS_UPDATE_ENABLED,
		self::CONFIG_STOREFRONT_PAYMENTS_UPDATE_ENABLED
	];

	/**
	 * @var \Shopware\Core\System\SystemConfig\SystemConfigService
	 */
	private $systemConfigService;

	/**
	 * @var \Psr\Log\LoggerInterface
	 */
	private $logger;

	/**
	 * SettingsService constructor.
	 *
	 * @param \Shopware\Core\System\SystemConfig\SystemConfigService $systemConfigService
	 */
	public function __construct(SystemConfigService $systemConfigService)
	{
		$this->systemConfigService = $systemConfigService;
	}

	/**
	 * @param \Psr\Log\LoggerInterface $logger
	 * @internal
	 * @required
	 *
	 */
	public function setLogger(LoggerInterface $logger): void
	{
		$this->logger = $logger;
	}

	/**
	 * Update setting
	 *
	 * @param array       $settings
	 * @param string|null $salesChannelId
	 */
	public function updateSettings(array $settings, ?string $salesChannelId = null): void
	{
		foreach ($settings as $key => $value) {
			$this->systemConfigService->set(
				self::SYSTEM_CONFIG_DOMAIN . $key,
				$value,
				$salesChannelId
			);
		}
	}

	/**
	 * Get valid settings
	 *
	 * @param string|null $salesChannelId
	 * @return \PostFinanceCheckoutPayment\Core\Settings\Struct\Settings|null
	 */
	public function getValidSettings(?string $salesChannelId = null): ?Settings
	{
		$settings = $this->getSettings($salesChannelId);

		if (empty($settings->getSpaceId())) {
			$this->logger->critical('Empty spaceId setting');
			return null;
		}

		if (empty($settings->getUserId())) {
			$this->logger->critical('Empty userId setting');
			return null;
		}

		if (empty($settings->getIntegration())) {
			$this->logger->critical('Empty integration setting');
			return null;
		}

		if (empty($settings->getApplicationKey())) {
			$this->logger->critical('Empty applicationKey setting');
			return null;
		}

		return $settings;
	}

	/**
	 * Get settings
	 *
	 * @param string|null $salesChannelId
	 * @return \PostFinanceCheckoutPayment\Core\Settings\Struct\Settings
	 */
	public function getSettings(?string $salesChannelId = null): Settings
	{
		$values = $this->systemConfigService->getDomain(
			self::SYSTEM_CONFIG_DOMAIN,
			$salesChannelId,
			true
		);

		$propertyValuePairs = [];

		/** @var string $key */
		foreach ($values as $key => $value) {
			$property = (string) \mb_substr($key, \mb_strlen(self::SYSTEM_CONFIG_DOMAIN));
			if ($property === '') {
				continue;
			}
			// Space view id is only numeric setting which can be 0. If it is, rest of the loop is skipped.
			if ($property === self::CONFIG_SPACE_VIEW_ID && $value === 0) {
				$propertyValuePairs[$property] = $value;
				continue;
			}
			// Check if $value is empty and is not in the list of configs which are allowed to be empty
			if (empty($value) && !in_array($property, self::ALLOWED_EMPTY_CONFIGS, true)) {
				$this->logger->warning(strtr('Empty value :value for settings :property.', [':property' => $property, ':value' => $value]));
			}
			$propertyValuePairs[$property] = $value;
		}

		return (new Settings())->assign($propertyValuePairs);
	}
}