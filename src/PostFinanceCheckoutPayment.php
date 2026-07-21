<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment;

use Shopware\Core\{
  	Framework\Feature,
	Framework\Plugin,
	Framework\Plugin\Context\ActivateContext,
	Framework\Plugin\Context\DeactivateContext,
	Framework\Plugin\Context\UninstallContext,
	Framework\Plugin\Context\UpdateContext
};
use PostFinanceCheckoutPayment\Core\{
	Api\WebHooks\Service\WebHooksService,
	Util\Traits\PostFinanceCheckoutPaymentPluginTrait
};

use Shopware\Core\Framework\Plugin\Util\AssetService;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\DelegatingLoader;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\DirectoryLoader;
use Symfony\Component\DependencyInjection\Loader\GlobFileLoader;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;

// expect the vendor folder on Shopware store releases
if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
	require_once dirname(__DIR__) . '/vendor/autoload.php';
}

/**
 * Class PostFinanceCheckoutPayment
 *
 * @package PostFinanceCheckoutPayment
 */
class PostFinanceCheckoutPayment extends Plugin {

	use PostFinanceCheckoutPaymentPluginTrait;

	private const POSTFINANCECHECKOUT_SALES_CHANNEL_PRIVILEGE_READ = 'postfinancecheckout_sales_channel:read';
	private const POSTFINANCECHECKOUT_SALES_CHANNEL_PRIVILEGE_UPDATE = 'postfinancecheckout_sales_channel:update';
	private const POSTFINANCECHECKOUT_SALES_CHANNEL_PRIVILEGE_CREATE = 'postfinancecheckout_sales_channel:create';
	private const POSTFINANCECHECKOUT_SALES_CHANNEL_PRIVILEGE_DELETE = 'postfinancecheckout_sales_channel:delete';
	private const POSTFINANCECHECKOUT_SALES_CHANNEL_PRIVILEGE_RUN_READ = 'postfinancecheckout_sales_channel_run:read';
	private const POSTFINANCECHECKOUT_SALES_CHANNEL_PRIVILEGE_RUN_UPDATE = 'postfinancecheckout_sales_channel_run:update';
	private const POSTFINANCECHECKOUT_SALES_CHANNEL_PRIVILEGE_RUN_CREATE = 'postfinancecheckout_sales_channel_run:create';
	private const POSTFINANCECHECKOUT_SALES_CHANNEL_PRIVILEGE_RUN_DELETE = 'postfinancecheckout_sales_channel_run:delete';
	private const POSTFINANCECHECKOUT_SALES_CHANNEL_PRIVILEGE_RUN_LOG_READ = 'postfinancecheckout_sales_channel_run_log:read';

	/**
	 * @param \Shopware\Core\Framework\Plugin\Context\UninstallContext $uninstallContext
	 * @return void
	 */
	public function uninstall(UninstallContext $uninstallContext): void
	{
		parent::uninstall($uninstallContext);
		$this->disablePaymentMethods($uninstallContext->getContext());
		$this->removeConfiguration($uninstallContext->getContext());
		$this->deleteUserData($uninstallContext);
	}

	/**
	 * @param \Shopware\Core\Framework\Plugin\Context\UpdateContext $updateContext
	 * @return void
	 */
	public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);

        // Force-publish assets on every update. This clears the old hashed-filename files
        // (from bin/build-administration builds) and replaces them with the stable-filename
        // files produced by shopware-cli, preventing the plugin from disappearing from the
        // settings menu after an upgrade.
        $this->publishAssets(force: true);
    }

	/**
	 * @param \Shopware\Core\Framework\Plugin\Context\ActivateContext $activateContext
	 * @return void
	 */
	public function activate(ActivateContext $activateContext): void
	{
		parent::activate($activateContext);
		$this->enablePaymentMethods($activateContext->getContext());
	}

	/**
	 * @param \Shopware\Core\Framework\Plugin\Context\DeactivateContext $deactivateContext
	 * @return void
	 */
	public function deactivate(DeactivateContext $deactivateContext): void
	{
		parent::deactivate($deactivateContext);
		$this->disablePaymentMethods($deactivateContext->getContext());
	}

	public function build(ContainerBuilder $container): void
	{
		parent::build($container);

		$confDir = \rtrim($this->getPath(), '/') . '/Resources/config';
		$locator = new FileLocator($confDir);

		$resolver = new LoaderResolver([
		  new YamlFileLoader($container, $locator),
		  new XmlFileLoader($container, $locator),
		  new GlobFileLoader($container, $locator),
		  new DirectoryLoader($container, $locator),
		]);

		$configLoader = new DelegatingLoader($resolver);

		$configLoader->load($confDir . '/{packages}/*.yaml', 'glob');

		$configLoader->load('services/core/checkout.xml');
	}

	public function enrichPrivileges(): array
	{
		return [
			'sales_channel.viewer' => [
				self::POSTFINANCECHECKOUT_SALES_CHANNEL_PRIVILEGE_READ,
				self::POSTFINANCECHECKOUT_SALES_CHANNEL_PRIVILEGE_RUN_READ,
				self::POSTFINANCECHECKOUT_SALES_CHANNEL_PRIVILEGE_RUN_UPDATE,
				self::POSTFINANCECHECKOUT_SALES_CHANNEL_PRIVILEGE_RUN_CREATE,
				self::POSTFINANCECHECKOUT_SALES_CHANNEL_PRIVILEGE_RUN_LOG_READ,
				'sales_channel_payment_method:read',
			],
			'sales_channel.editor' => [
				self::POSTFINANCECHECKOUT_SALES_CHANNEL_PRIVILEGE_UPDATE,
				self::POSTFINANCECHECKOUT_SALES_CHANNEL_PRIVILEGE_RUN_DELETE,
				'payment_method:update',
			],
			'sales_channel.creator' => [
				self::POSTFINANCECHECKOUT_SALES_CHANNEL_PRIVILEGE_CREATE,
				'payment_method:create',
				'shipping_method:create',
				'delivery_time:create',
			],
			'sales_channel.deleter' => [
				self::POSTFINANCECHECKOUT_SALES_CHANNEL_PRIVILEGE_DELETE,
			],
		];
	}

    /**
     * {@inheritdoc}
     */
    public function executeComposerCommands(): bool
    {
        // The plugin needs the SDK to be installed via composer.
        return true;
    }

    private function publishAssets(bool $force = false): void
    {
        if (!$this->container->has(AssetService::class)) {
            return;
        }

        /** @var AssetService $assetService */
        $assetService = $this->container->get(AssetService::class);
        $assetService->copyAssets($this, $force);
    }
}
