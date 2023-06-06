<?php declare(strict_types=1);


namespace PostFinanceCheckoutPayment\Core\Api\OrderDeliveryState\Command;

use Shopware\Core\Framework\Context;
use Symfony\Component\{
	Console\Command\Command,
	Console\Input\InputInterface,
	Console\Output\OutputInterface};
use PostFinanceCheckoutPayment\Core\Api\OrderDeliveryState\Service\OrderDeliveryStateService;

/**
 * Class OrderDeliveryStateCommand
 *
 * @package PostFinanceCheckoutPayment\Core\Api\OrderDeliveryState\Command
 */
class OrderDeliveryStateCommand extends Command {

	/**
	 * @var string
	 */
	protected static $defaultName = 'postfinancecheckout:order-delivery-states:install';

	/**
	 * @var \PostFinanceCheckoutPayment\Core\Api\OrderDeliveryState\Service\OrderDeliveryStateService
	 */
	protected $orderDeliveryStateService;

	/**
	 * OrderDeliveryStateCommand constructor.
	 *
	 * @param \PostFinanceCheckoutPayment\Core\Api\OrderDeliveryState\Service\OrderDeliveryStateService $orderDeliveryStateService
	 */
	public function __construct(OrderDeliveryStateService $orderDeliveryStateService)
	{
		parent::__construct(self::$defaultName);
		$this->orderDeliveryStateService = $orderDeliveryStateService;
	}

	/**
	 * @param \Symfony\Component\Console\Input\InputInterface   $input
	 * @param \Symfony\Component\Console\Output\OutputInterface $output
	 * @return int
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$output->writeln('Install PostFinanceCheckoutPayment extra delivery states...');
		$this->orderDeliveryStateService->install(Context::createDefaultContext());
		return 0;
	}

	/**
	 * Configures the current command.
	 */
	protected function configure()
	{
		$this->setDescription('Installs PostFinanceCheckoutPayment extra delivery states.')
			 ->setHelp('This command installs PostFinanceCheckoutPayment extra delivery states.');
	}

}