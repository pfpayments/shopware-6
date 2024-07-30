<?php declare(strict_types=1);


namespace PostFinanceCheckoutPayment\Core\Api\PaymentMethodConfiguration\Command;

use Shopware\Core\Framework\Context;
use Symfony\Component\{
	Console\Command\Command,
	Console\Input\InputInterface,
	Console\Output\OutputInterface};
use PostFinanceCheckoutPayment\Core\Util\PaymentMethodUtil;

/**
 * Class PaymentMethodDefaultCommand
 *
 * @package PostFinanceCheckoutPayment\Core\Api\PaymentMethodConfiguration\Command
 */
class PaymentMethodDefaultCommand extends Command {

	/**
	 * @var string
	 */
	protected static $defaultName = 'postfinancecheckout:payment-method:default';

	/**
	 * @var \PostFinanceCheckoutPayment\Core\Util\PaymentMethodUtil
	 */
	protected $paymentMethodUtil;

	/**
	 * PaymentMethodDefaultCommand constructor.
	 *
	 * @param \PostFinanceCheckoutPayment\Core\Util\PaymentMethodUtil $paymentMethodUtil
	 */
	public function __construct(PaymentMethodUtil $paymentMethodUtil)
	{
		parent::__construct(self::$defaultName);
		$this->paymentMethodUtil = $paymentMethodUtil;
	}

	/**
	 * @param \Symfony\Component\Console\Input\InputInterface   $input
	 * @param \Symfony\Component\Console\Output\OutputInterface $output
	 * @return int
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$output->writeln('Set PostFinanceCheckoutPayment as default payment method...');
		$context = Context::createDefaultContext();
		$this->paymentMethodUtil->setPostFinanceCheckoutAsDefaultPaymentMethod($context);
		$this->paymentMethodUtil->disableSystemPaymentMethods($context);
		return 0;
	}

	/**
	 * Configures the current command.
	 */
	protected function configure()
	{
		$this->setDescription('Sets PostFinanceCheckoutPayment as default payment method.')
			 ->setHelp('This command updates PostFinanceCheckoutPayment as default payment method for all SalesChannels.');
	}

}