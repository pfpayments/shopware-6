<?php declare(strict_types=1);


namespace PostFinanceCheckoutPayment\Core\Api\PaymentMethodConfiguration\Command;

use Shopware\Core\Framework\Context;
use Symfony\Component\{
	Console\Command\Command,
	Console\Input\InputInterface,
	Console\Output\OutputInterface};
use PostFinanceCheckoutPayment\Core\Api\PaymentMethodConfiguration\Service\PaymentMethodConfigurationService;

/**
 * Class PaymentMethodConfigurationCommand
 *
 * @package PostFinanceCheckoutPayment\Core\Api\PaymentMethodConfiguration\Command
 */
class PaymentMethodConfigurationCommand extends Command {

	/**
	 * @var string
	 */
	protected static $defaultName = 'postfinancecheckout:payment-method:configuration';

	/**
	 * @var \PostFinanceCheckoutPayment\Core\Api\PaymentMethodConfiguration\Service\PaymentMethodConfigurationService
	 */
	protected $paymentMethodConfigurationService;

	/**
	 * PaymentMethodConfigurationCommand constructor.
	 *
	 * @param \PostFinanceCheckoutPayment\Core\Api\PaymentMethodConfiguration\Service\PaymentMethodConfigurationService $paymentMethodConfigurationService
	 */
	public function __construct(PaymentMethodConfigurationService $paymentMethodConfigurationService)
	{
		parent::__construct(self::$defaultName);
		$this->paymentMethodConfigurationService = $paymentMethodConfigurationService;
	}

	/**
	 * @param \Symfony\Component\Console\Input\InputInterface   $input
	 * @param \Symfony\Component\Console\Output\OutputInterface $output
	 * @return int
	 * @throws \PostFinanceCheckout\Sdk\ApiException
	 * @throws \PostFinanceCheckout\Sdk\Http\ConnectionException
	 * @throws \PostFinanceCheckout\Sdk\VersioningException
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$output->writeln('Fetch PostFinanceCheckoutPayment space available payment methods...');
		$this->paymentMethodConfigurationService->synchronize(Context::createDefaultContext());
		return 0;
	}

	/**
	 * Configures the current command.
	 */
	protected function configure()
	{
		$this->setDescription('Fetches PostFinanceCheckoutPayment space available payment methods.')
			 ->setHelp('This command fetches PostFinanceCheckoutPayment space available payment methods.');
	}

}