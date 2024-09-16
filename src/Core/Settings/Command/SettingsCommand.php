<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Settings\Command;

use Symfony\Component\{
	Console\Command\Command,
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
class SettingsCommand extends Command {

	/**
	 * @var string
	 */
	protected static $defaultName = 'postfinancecheckout:settings:install';

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
		parent::__construct(self::$defaultName);
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
		$this->settingsService->updateSettings([
			SettingsService::CONFIG_APPLICATION_KEY                     => $input->getOption(SettingsService::CONFIG_APPLICATION_KEY),
			SettingsService::CONFIG_EMAIL_ENABLED                       => $input->getOption(SettingsService::CONFIG_EMAIL_ENABLED),
			SettingsService::CONFIG_INTEGRATION                         => $input->getOption(SettingsService::CONFIG_INTEGRATION),
			SettingsService::CONFIG_IS_SHOWCASE                         => $input->getOption(SettingsService::CONFIG_IS_SHOWCASE),
			SettingsService::CONFIG_LINE_ITEM_CONSISTENCY_ENABLED       => $input->getOption(SettingsService::CONFIG_LINE_ITEM_CONSISTENCY_ENABLED),
			SettingsService::CONFIG_SPACE_ID                            => $input->getOption(SettingsService::CONFIG_SPACE_ID),
			SettingsService::CONFIG_SPACE_VIEW_ID                       => $input->getOption(SettingsService::CONFIG_SPACE_VIEW_ID),
			SettingsService::CONFIG_STOREFRONT_INVOICE_DOWNLOAD_ENABLED => $input->getOption(SettingsService::CONFIG_STOREFRONT_INVOICE_DOWNLOAD_ENABLED),
			SettingsService::CONFIG_USER_ID                             => $input->getOption(SettingsService::CONFIG_USER_ID),
			SettingsService::CONFIG_STOREFRONT_WEBHOOKS_UPDATE_ENABLED  => $input->getOption(SettingsService::CONFIG_STOREFRONT_WEBHOOKS_UPDATE_ENABLED),
			SettingsService::CONFIG_STOREFRONT_PAYMENTS_UPDATE_ENABLED  => $input->getOption(SettingsService::CONFIG_STOREFRONT_PAYMENTS_UPDATE_ENABLED),
		]);
		return 0;
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
				 Integration::IFRAME
			 )
			 ->addOption(
				 SettingsService::CONFIG_IS_SHOWCASE,
				 SettingsService::CONFIG_IS_SHOWCASE,
				 InputOption::VALUE_OPTIONAL,
				 SettingsService::CONFIG_IS_SHOWCASE,
				 true
			 )
			 ->addOption(
				 SettingsService::CONFIG_LINE_ITEM_CONSISTENCY_ENABLED,
				 SettingsService::CONFIG_LINE_ITEM_CONSISTENCY_ENABLED,
				 InputOption::VALUE_OPTIONAL,
				 SettingsService::CONFIG_LINE_ITEM_CONSISTENCY_ENABLED,
				 true
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
			)			->addOption(
				SettingsService::CONFIG_STOREFRONT_PAYMENTS_UPDATE_ENABLED,
				SettingsService::CONFIG_STOREFRONT_PAYMENTS_UPDATE_ENABLED,
				InputOption::VALUE_OPTIONAL,
				SettingsService::CONFIG_STOREFRONT_PAYMENTS_UPDATE_ENABLED,
				true
			);
	}
}