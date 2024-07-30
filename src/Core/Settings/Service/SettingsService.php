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
	public const CONFIG_IS_SHOWCASE                         = 'isShowcase';
	public const CONFIG_LINE_ITEM_CONSISTENCY_ENABLED       = 'lineItemConsistencyEnabled';
	public const CONFIG_SPACE_ID                            = 'spaceId';
	public const CONFIG_SPACE_VIEW_ID                       = 'spaceViewId';
	public const CONFIG_STOREFRONT_INVOICE_DOWNLOAD_ENABLED = 'storefrontInvoiceDownloadEnabled';
	public const CONFIG_USER_ID                             = 'userId';
	public const CONFIG_STOREFRONT_WEBHOOKS_UPDATE_ENABLED  = 'storefrontWebhooksUpdateEnabled';
	public const CONFIG_STOREFRONT_PAYMENTS_UPDATE_ENABLED  = 'storefrontPaymentsUpdateEnabled';

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
			if (!is_numeric($value) && empty($value)) {
				$this->logger->warning(strtr('Empty value :value for settings :property.', [':property' => $property, ':value' => $value]));
			}
			$propertyValuePairs[$property] = $value;
		}

		return (new Settings())->assign($propertyValuePairs);
	}
}