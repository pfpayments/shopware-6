<?php declare(strict_types=1);


namespace PostFinanceCheckoutPayment\Core\Api\WebHooks\Command;

use Symfony\Component\{
	Console\Command\Command,
	Console\Input\InputInterface,
	Console\Output\OutputInterface};
use PostFinanceCheckoutPayment\Core\Api\WebHooks\Service\WebHooksService;

/**
 * Class WebHooksCommand
 *
 * @package PostFinanceCheckoutPayment\Core\Api\WebHooks\Command
 */
class WebHooksCommand extends Command {

	/**
	 * @var string
	 */
	protected static $defaultName = 'postfinancecheckout:webhooks:install';

	/**
	 * @var \PostFinanceCheckoutPayment\Core\Api\WebHooks\Service\WebHooksService
	 */
	protected $webHooksService;

	/**
	 * WebHooksCommand constructor.
	 *
	 * @param \PostFinanceCheckoutPayment\Core\Api\WebHooks\Service\WebHooksService $webHooksService
	 */
	public function __construct(WebHooksService $webHooksService)
	{
		parent::__construct(self::$defaultName);
		$this->webHooksService = $webHooksService;
	}

	/**
	 * @param \Symfony\Component\Console\Input\InputInterface   $input
	 * @param \Symfony\Component\Console\Output\OutputInterface $output
	 *
	 * @return int
	 * @throws \PostFinanceCheckout\Sdk\ApiException
	 * @throws \PostFinanceCheckout\Sdk\Http\ConnectionException
	 * @throws \PostFinanceCheckout\Sdk\VersioningException
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$output->writeln('Install PostFinanceCheckoutPayment webhooks...');
		$this->webHooksService->install();
		return 0;
	}

	/**
	 * Configures the current command.
	 */
	protected function configure()
	{
		$this->setDescription('Install PostFinanceCheckoutPayment webhooks.')
			 ->setHelp('This command installs PostFinanceCheckoutPayment webhooks.');
	}

}