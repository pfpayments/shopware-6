<?php declare(strict_types=1);


namespace WeArePlanetPayment\Core\Api\WebHooks\Command;

use Symfony\Component\{
	Console\Command\Command,
	Console\Input\InputInterface,
	Console\Output\OutputInterface};
use WeArePlanetPayment\Core\Api\WebHooks\Service\WebHooksService;

/**
 * Class WebHooksCommand
 *
 * @package WeArePlanetPayment\Core\Api\WebHooks\Command
 */
class WebHooksCommand extends Command {

	/**
	 * @var string
	 */
	protected static $defaultName = 'weareplanet:webhooks:install';

	/**
	 * @var \WeArePlanetPayment\Core\Api\WebHooks\Service\WebHooksService
	 */
	protected $webHooksService;

	/**
	 * WebHooksCommand constructor.
	 *
	 * @param \WeArePlanetPayment\Core\Api\WebHooks\Service\WebHooksService $webHooksService
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
	 * @throws \WeArePlanet\Sdk\ApiException
	 * @throws \WeArePlanet\Sdk\Http\ConnectionException
	 * @throws \WeArePlanet\Sdk\VersioningException
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$output->writeln('Install WeArePlanetPayment webhooks...');
		$this->webHooksService->install();
		return 0;
	}

	/**
	 * Configures the current command.
	 */
	protected function configure()
	{
		$this->setDescription('Install WeArePlanetPayment webhooks.')
			 ->setHelp('This command installs WeArePlanetPayment webhooks.');
	}

}