<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Settings\Command;

use Symfony\Component\{
	Console\Command\Command,
	Console\Attribute\AsCommand,
	Console\Input\InputInterface,
	Console\Input\InputArgument,
	Console\Input\InputOption,
	Console\Output\OutputInterface,
	PasswordHasher\Hasher\UserPasswordHasherInterface};
use Shopware\Core\Framework\{
	DataAbstractionLayer\EntityRepository,
	DataAbstractionLayer\EntityRepositoryInterface,
	DataAbstractionLayer\Search\Criteria,
	DataAbstractionLayer\Search\Filter\EqualsFilter,
	Uuid\Uuid,
	Context};
use PostFinanceCheckoutPayment\Core\{
	Settings\Options\Integration,
	Settings\Service\SettingsService};

/**
 * Class CreateMerchantCommand
 * @internal
 * @package PostFinanceCheckoutPayment\Core\Settings\Command
 */
#[AsCommand(name: 'postfinancecheckout:settings:create-merchant')]
class CreateMerchantCommand extends Command {

	/**
	 * @var EntityRepositoryInterface Repository for user entities
	 */
	private $userRepository;

	/**
	 * @var EntityRepositoryInterface Repository for user role entities
	 */
	private $userRoleRepository;

	/**
	 * @var EntityRepositoryInterface Repository for locale entities
	 */
	private $localeRepository;

	/**
	 * CreateMerchantUserCommand constructor.
	 * 
	 * @param EntityRepositoryInterface $userRepository
	 * @param EntityRepositoryInterface $userRoleRepository
	 * @param EntityRepositoryInterface $localeRepository
	 */
	public function __construct(
		EntityRepository $userRepository,
		EntityRepository $userRoleRepository,
		EntityRepository $localeRepository,
	) {
		parent::__construct();
		$this->userRepository = $userRepository;
		$this->userRoleRepository = $userRoleRepository;
		$this->localeRepository = $localeRepository;
	}

	/**
	 * Executes the command to create a new merchant user with a specific role.
	 *
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return int Command::SUCCESS on success, Command::FAILURE on failure
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$output->writeln('Creating PostFinanceCheckoutPayment merchant with custom role...');

		$firstName 	= $input->getOption('firstName');
		$lastName 	= $input->getOption('lastName');
		$email 		= $input->getOption('email') ?? 'merchant@merchant.com';
		$password 	= $input->getOption('password') ?? 'merchant123';

		$context = Context::createDefaultContext();

		// Check if user already exists
		$criteria = new Criteria();
		$criteria->addFilter(new EqualsFilter('email', $email));
		$existingUser = $this->userRepository->search($criteria, $context)->first();

		if ($existingUser) {
			$output->writeln('User already exists.');
			return Command::SUCCESS;
		}

		// Create role if it doesn't exist
		$roleId = $this->getOrCreateRoleId('PostFinanceCheckout viewer', $context);

		// Create user if it doesn't exist
		$this->userRepository->create([
			[
				'id' => Uuid::randomHex(),
				'username' => $email,
				'email' => $email,
				'firstName' => $firstName,
				'lastName' => $lastName,
				'password' => $password,
				'admin' => false,
				'localeId' => $this->getLocaleId($context),
				'aclRoles' => [
					[
						'id' => $roleId
					]
				],
			]
		], $context);

		$output->writeln('Merchant user created successfully.');

		return Command::SUCCESS;
	}

	/**
	 * Fetches the default locale ID.
	 *
	 * @param Context $context
	 * @return string Locale ID
	 * @throws \RuntimeException If the default locale is not found
	 */
	private function getLocaleId(Context $context): string
	{
		// Fetch the default locale id
		$criteria = new Criteria();
		$criteria->addFilter(new EqualsFilter('code', 'en-GB'));
		$localeId = $this->localeRepository->searchIds($criteria, $context)->firstId();

		if (!$localeId) {
			throw new \RuntimeException('Default locale not found');
		}

		return $localeId;
	}

	/**
	 * Fetches the role ID for a given role name or creates the role if it does not exist.
	 *
	 * @param string $roleName
	 * @param Context $context
	 * @return string Role ID
	 * @throws \RuntimeException If the role cannot be created or found
	 */
	private function getOrCreateRoleId(string $roleName, Context $context): string
	{
		$criteria = new Criteria();
		$criteria->addFilter(new EqualsFilter('name', $roleName));
		$roleId = $this->userRoleRepository->searchIds($criteria, $context)->firstId();

		if (!$roleId) {
			$roleId = Uuid::randomHex();
			$this->userRoleRepository->create([
				[
					'id' => $roleId,
					'name' => $roleName,
					'privileges' => [
						'postfinancecheckout.viewer',
						'postfinancecheckout_sales_channel:read',
						'postfinancecheckout_sales_channel_run:read',
						'postfinancecheckout_sales_channel_run_log:read',
						'language:read',
						'locale:read',
						'system_config:read'
					]
				]
			], $context);
		}

		return $roleId;
	}

	/**
	 * Configures the current command.
	 */
	protected function configure(): void
	{
		$this
			->setDescription('Creates a new merchant user with specific roles.')
			->addOption('firstName', null, InputOption::VALUE_OPTIONAL, 'First name of the merchant user', 'Merchant')
			->addOption('lastName', null, InputOption::VALUE_OPTIONAL, 'Last name of the merchant user', 'Merchant')
			->addOption('email', null, InputOption::VALUE_OPTIONAL, 'Email of the merchant user')
			->addOption('password', null, InputOption::VALUE_OPTIONAL, 'Password of the merchant user');
	}
}
