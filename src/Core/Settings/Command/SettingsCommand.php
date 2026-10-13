<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Settings\Command;

use Symfony\Component\{
	Console\Command\Command,
    Console\Attribute\AsCommand,
	Console\Input\InputInterface,
	Console\Input\InputOption,
	Console\Output\OutputInterface};
use PostFinanceCheckoutPayment\Core\{
	Settings\Options\Integration,
	Settings\Service\SettingsService};

/**
 * Class SettingsCommand
 * @internal
 * @package PostFinanceCheckoutPayment\Core\Settings\Command
 */
#[AsCommand(name: 'postfinancecheckout:settings:install')]
class SettingsCommand extends Command {

	/**
	 * Every setting this command can write, in the order they are declared in configure().
	 */
	private const SETTINGS = [
		SettingsService::CONFIG_APPLICATION_KEY,
		SettingsService::CONFIG_SPACE_ID,
		SettingsService::CONFIG_USER_ID,
		SettingsService::CONFIG_EMAIL_ENABLED,
		SettingsService::CONFIG_INTEGRATION,
		SettingsService::CONFIG_LINE_ITEM_CONSISTENCY_ENABLED,
		SettingsService::CONFIG_KEEP_FAILED_PAYMENTS_ORDER_OPEN,
		SettingsService::CONFIG_STOREFRONT_INVOICE_DOWNLOAD_ENABLED,
		SettingsService::CONFIG_SPACE_VIEW_ID,
		SettingsService::CONFIG_STOREFRONT_WEBHOOKS_UPDATE_ENABLED,
		SettingsService::CONFIG_STOREFRONT_PAYMENTS_UPDATE_ENABLED,
	];

	/**
	 * Settings stored as a boolean. Passed on the command line they arrive as a string, and
	 * "false" or "off" would be stored as a truthy value without normalising them first.
	 */
	private const BOOLEAN_SETTINGS = [
		SettingsService::CONFIG_EMAIL_ENABLED,
		SettingsService::CONFIG_LINE_ITEM_CONSISTENCY_ENABLED,
		SettingsService::CONFIG_KEEP_FAILED_PAYMENTS_ORDER_OPEN,
		SettingsService::CONFIG_STOREFRONT_INVOICE_DOWNLOAD_ENABLED,
		SettingsService::CONFIG_STOREFRONT_WEBHOOKS_UPDATE_ENABLED,
		SettingsService::CONFIG_STOREFRONT_PAYMENTS_UPDATE_ENABLED,
	];

	/**
	 * @var \PostFinanceCheckoutPayment\Core\Settings\Service\SettingsService
	 */
	protected $settingsService;

	/**
	 * SettingsCommand constructor.
	 * @param \PostFinanceCheckoutPayment\Core\Settings\Service\SettingsService $settingsService
	 */
	public function __construct(SettingsService $settingsService)
	{
		parent::__construct();
		$this->settingsService = $settingsService;
	}

	/**
	 * @param \Symfony\Component\Console\Input\InputInterface   $input
	 * @param \Symfony\Component\Console\Output\OutputInterface $output
	 * @return int
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$output->writeln('Set PostFinanceCheckoutPayment settings...');

		// Only settings that were actually passed are written. Storing the default of every
		// option would record it as a merchant choice: it makes the defaults of the
		// administration UI unreachable, because those only apply while the key is absent
		// from the configuration, and it lets a second run overwrite saved values.
		$settings = [];
		foreach (self::SETTINGS as $setting) {
			if (!$input->hasParameterOption(['--' . $setting, '-' . $setting])) {
				continue;
			}

			$value = $input->getOption($setting);
			if (in_array($setting, self::BOOLEAN_SETTINGS, true)) {
				$value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
			}

			$settings[$setting] = $value;
		}

		if (empty($settings)) {
			$output->writeln('No settings were passed, nothing to update.');
			return Command::SUCCESS;
		}

		$this->settingsService->updateSettings($settings);
		return Command::SUCCESS;
	}

	/**
	 * Configures the current command.
	 */
	protected function configure()
	{
		$this->setDescription('Sets PostFinanceCheckoutPayment settings.')
			 ->setHelp('This command updates PostFinanceCheckoutPayment settings for all SalesChannels.')
			 ->addOption(
				 SettingsService::CONFIG_APPLICATION_KEY,
				 SettingsService::CONFIG_APPLICATION_KEY,
				 InputOption::VALUE_REQUIRED,
				 SettingsService::CONFIG_APPLICATION_KEY
			 )
			 ->addOption(
				 SettingsService::CONFIG_SPACE_ID,
				 SettingsService::CONFIG_SPACE_ID,
				 InputOption::VALUE_REQUIRED,
				 SettingsService::CONFIG_SPACE_ID
			 )
			 ->addOption(
				 SettingsService::CONFIG_USER_ID,
				 SettingsService::CONFIG_USER_ID,
				 InputOption::VALUE_REQUIRED,
				 SettingsService::CONFIG_USER_ID
			 )
			 ->addOption(
				 SettingsService::CONFIG_EMAIL_ENABLED,
				 SettingsService::CONFIG_EMAIL_ENABLED,
				 InputOption::VALUE_OPTIONAL,
				 SettingsService::CONFIG_EMAIL_ENABLED,
				 true
			 )
			 ->addOption(
				 SettingsService::CONFIG_INTEGRATION,
				 SettingsService::CONFIG_INTEGRATION,
				 InputOption::VALUE_OPTIONAL,
				 SettingsService::CONFIG_INTEGRATION,
				 Integration::PAYMENT_PAGE
			 )
			 ->addOption(
				 SettingsService::CONFIG_LINE_ITEM_CONSISTENCY_ENABLED,
				 SettingsService::CONFIG_LINE_ITEM_CONSISTENCY_ENABLED,
				 InputOption::VALUE_OPTIONAL,
				 SettingsService::CONFIG_LINE_ITEM_CONSISTENCY_ENABLED,
				 true
			 )
			 ->addOption(
				 SettingsService::CONFIG_KEEP_FAILED_PAYMENTS_ORDER_OPEN,
				 SettingsService::CONFIG_KEEP_FAILED_PAYMENTS_ORDER_OPEN,
				 InputOption::VALUE_OPTIONAL,
				 SettingsService::CONFIG_KEEP_FAILED_PAYMENTS_ORDER_OPEN,
				 // Keeping a failed payment's order open is off by default, as in the administration UI.
				 false
			 )
			 ->addOption(
				 SettingsService::CONFIG_STOREFRONT_INVOICE_DOWNLOAD_ENABLED,
				 SettingsService::CONFIG_STOREFRONT_INVOICE_DOWNLOAD_ENABLED,
				 InputOption::VALUE_OPTIONAL,
				 SettingsService::CONFIG_STOREFRONT_INVOICE_DOWNLOAD_ENABLED,
				 true
			 )
			 ->addOption(
				 SettingsService::CONFIG_SPACE_VIEW_ID,
				 SettingsService::CONFIG_SPACE_VIEW_ID,
				 InputOption::VALUE_OPTIONAL,
				 SettingsService::CONFIG_SPACE_VIEW_ID,
				 ''
			 )
			->addOption(
				SettingsService::CONFIG_STOREFRONT_WEBHOOKS_UPDATE_ENABLED,
				SettingsService::CONFIG_STOREFRONT_WEBHOOKS_UPDATE_ENABLED,
				InputOption::VALUE_OPTIONAL,
				SettingsService::CONFIG_STOREFRONT_WEBHOOKS_UPDATE_ENABLED,
				true
			)
			->addOption(
				SettingsService::CONFIG_STOREFRONT_PAYMENTS_UPDATE_ENABLED,
				SettingsService::CONFIG_STOREFRONT_PAYMENTS_UPDATE_ENABLED,
				InputOption::VALUE_OPTIONAL,
				SettingsService::CONFIG_STOREFRONT_PAYMENTS_UPDATE_ENABLED,
				true
			);
	}
}
