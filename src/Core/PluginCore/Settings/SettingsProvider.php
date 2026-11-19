<?php

declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\PluginCore\Settings;

use PostFinanceCheckout\PluginCore\Settings\DefaultSettingsProvider;
use PostFinanceCheckout\PluginCore\Settings\IntegrationMode;
use PostFinanceCheckoutPayment\Core\Settings\Options\Integration;
use PostFinanceCheckoutPayment\Core\Settings\Service\SettingsService;
use PostFinanceCheckoutPayment\Core\Settings\Struct\Settings as ShopSettings;

/**
 * Provides plugin-core with the PostFinanceCheckout settings of one sales channel.
 *
 * Settings are read through the existing SettingsService. Settings are read once,
 * on first use, and kept for the lifetime of the object.
 */
class SettingsProvider extends DefaultSettingsProvider
{
    public const ENV_API_BASE_PATH = 'POSTFINANCECHECKOUT_API_BASE_PATH';

    /**
     * @param SettingsService $settingsService
     * @param string|null $salesChannelId
     * @param ShopSettings|null $settings
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly ?string $salesChannelId,
        private ?ShopSettings $settings = null,
    ) {
    }

    /**
     * Returns the sales channel ID.
     *
     * @return string|null
     */
    public function getSalesChannelId(): ?string
    {
        return $this->salesChannelId;
    }

    /**
     * Returns the space ID.
     *
     * @return int|null
     */
    public function getSpaceId(): ?int
    {
        $value = $this->settings()->getSpaceId();
        return $value > 0 ? $value : null;
    }

    /**
     * Returns the application user ID.
     *
     * @return int|null
     */
    public function getUserId(): ?int
    {
        $value = $this->settings()->getUserId();
        return $value > 0 ? $value : null;
    }

    /**
     * Returns the application user's authentication key.
     *
     * @return string|null
     */
    public function getApiKey(): ?string
    {
        $key = trim($this->settings()->getApplicationKey());
        return $key === '' ? null : $key;
    }

    /**
     * Returns the API base URL from the POSTFINANCECHECKOUT_API_BASE_PATH environment variable.
     *
     * @return string|null
     */
    public function getBaseUrl(): ?string
    {
        $value = getenv(self::ENV_API_BASE_PATH);
        return \is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Returns the configured integration mode.
     *
     * @return IntegrationMode
     */
    public function getIntegrationMode(): IntegrationMode
    {
        $integrationMode = match ($this->settings()->getIntegration()) {
            Integration::IFRAME => IntegrationMode::IFRAME,
            Integration::LIGHTBOX => IntegrationMode::LIGHTBOX,
            default => IntegrationMode::PAYMENT_PAGE,
        };
        return $integrationMode;
    }

    /**
     * Returns whether line item consistency correction is enabled.
     *
     * @return bool
     */
    public function getLineItemConsistencyEnabled(): bool
    {
        return $this->settings()->isLineItemConsistencyEnabled();
    }

    /**
     * Returns null, so plugin-core uses its default log level.
     *
     * The plugin has no log level setting, because the postfinancecheckout Monolog channel
     * decides what is written.
     *
     * @return string|null
     */
    public function getLogLevel(): ?string
    {
        return null;
    }

    /**
     * Returns the sales channel's settings.
     *
     * @return ShopSettings
     */
    private function settings(): ShopSettings
    {
        if (!isset($this->settings)) {
            $this->settings = $this->settingsService->getSettings($this->salesChannelId);
        }
        return $this->settings;
    }
}
