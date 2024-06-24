<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment;

use Shopware\Core\{
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

use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\DelegatingLoader;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\DirectoryLoader;
use Symfony\Component\DependencyInjection\Loader\GlobFileLoader;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

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

		$locator = new FileLocator('Resources/config');

		$resolver = new LoaderResolver([
			new YamlFileLoader($container, $locator),
			new GlobFileLoader($container, $locator),
			new DirectoryLoader($container, $locator),
		]);

		$configLoader = new DelegatingLoader($resolver);

		$confDir = \rtrim($this->getPath(), '/') . '/Resources/config';

		$configLoader->load($confDir . '/{packages}/*.yaml', 'glob');
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
}
