<?php

declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\PluginCore;

use Symfony\Contracts\Service\ResetInterface;
use PostFinanceCheckout\PluginCore\Sdk\ClientMetadataProviderInterface;
use PostFinanceCheckout\PluginCore\SharedKernel\CacheInterface;
use PostFinanceCheckoutPayment\Core\PluginCore\Log\PluginCoreLogger;
use PostFinanceCheckoutPayment\Core\PluginCore\Settings\SettingsProvider;
use PostFinanceCheckoutPayment\Core\Settings\Service\SettingsService;

/**
 * Creates the plugin-core services for a sales channel and reuses them within a request.
 *
 * Code that calls PostFinanceCheckout portal gets its services from here. Shopware stores
 * PostFinanceCheckout per sales channel, while plugin-core's SdkProvider reads credentials
 * when it is created. A separate service set per channel keeps
 * one channel's credentials from being used for another.
 */
class PluginCoreFactory implements ResetInterface
{
    /** @var array<string, PluginCoreServices> */
    private array $bundles = [];

    /**
     * @param SettingsService $settingsService
     * @param PluginCoreLogger $logger
     * @param ClientMetadataProviderInterface|null $clientMetadataProvider
     * @param CacheInterface|null $cache
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly PluginCoreLogger $logger,
        private readonly ?ClientMetadataProviderInterface $clientMetadataProvider = null,
        private readonly ?CacheInterface $cache = null,
    ) {
    }

    /**
     * Returns the plugin-core services for the given sales channel.
     *
     * In case of empty string the services for the global configuration
     * are returned.
     *
     * @param string|null $salesChannelId
     * @return PluginCoreServices
     */
    public function forSalesChannel(?string $salesChannelId): PluginCoreServices
    {
        $salesChannelId = $this->normalize($salesChannelId);
        $key = $salesChannelId ?? '';

        if (!isset($this->bundles[$key])) {
            $this->bundles[$key] = new PluginCoreServices(
                new SettingsProvider($this->settingsService, $salesChannelId),
                $this->logger->withContext(['salesChannelId' => $salesChannelId ?? 'default']),
                $this->clientMetadataProvider,
                $this->cache,
            );
        }

        return $this->bundles[$key];
    }

    /**
     * Removes all stored service sets, so the next call reads the settings again.
     *
     * Symfony calls this automatically, therefore we call it manually only when
     * credentials change within one request, for example a settings save followed
     * by a connection check.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->bundles = [];
    }

    /**
     * Lowercases and trims the sales channel ID, and turns an empty ID into null.
     *
     * @param string|null $salesChannelId
     * @return string|null
     */
    private function normalize(?string $salesChannelId): ?string
    {
        if ($salesChannelId === null) {
            return null;
        }
        $salesChannelId = strtolower(trim($salesChannelId));
        return $salesChannelId === '' ? null : $salesChannelId;
    }
}
