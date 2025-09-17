<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Checkout\Subscription\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Shopware\Commercial\Subscription\Checkout\Order\Generation\GenerateSubscriptionOrder;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Adapter\Console\ShopwareStyle;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'postfinancecheckout:subscription:generate',
    description: 'Manually dispatches a message to generate a recurring subscription order.',
)]
class GenerateSubscriptionOrderCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly ?EntityRepository $subscriptionRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('subscriptionIdentifier', InputArgument::REQUIRED, 'The ID or Number of the subscription to process.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new ShopwareStyle($input, $output);

        if ($this->subscriptionRepository === null || !class_exists(\Shopware\Commercial\Subscription\Entity\Subscription\SubscriptionEntity::class)) {
            $io->error('Subscription functionality is not available in this Shopware instance. Please ensure the Subscription plugin is installed and enabled.');
            return self::FAILURE;
        }

        $identifier = $input->getArgument('subscriptionIdentifier');

        if (!is_string($identifier)) {
            $io->error('Invalid Subscription ID provided.');
            return self::FAILURE;
        }
        $subscriptionId = $this->findSubscriptionId($identifier, $io);

        if ($subscriptionId === null) {
            // Error message is already printed in findSubscriptionId
            return self::FAILURE;
        }

        $io->text(sprintf('Forcing next schedule for subscription ID: %s', $subscriptionId));
        $this->forceNextSchedule($subscriptionId);

        $io->title('Subscription Order Generation');
        $io->text(sprintf('Dispatching GenerateSubscriptionOrder message for subscription ID: %s', $subscriptionId));

        $this->bus->dispatch(new GenerateSubscriptionOrder($subscriptionId));

        $io->success('Message dispatched successfully!');
        $io->note('Ensure a message consumer is running to process the queue: "bin/console messenger:consume async"');

        return self::SUCCESS;
    }

    /**
     * Set the next schedule date to the current time, so it will be processed immediately.
     *
     * @param string $subscriptionId
     * @return void
     */
    private function forceNextSchedule(string $subscriptionId): void
    {
        $context = Context::createDefaultContext();
        $this->subscriptionRepository->update([
            [
                'id' => $subscriptionId,
                'nextSchedule' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]
        ], $context);
    }

    private function findSubscriptionId(string $identifier, ShopwareStyle $io): ?string
    {
        $context = Context::createDefaultContext();
        if (Uuid::isValid($identifier)) {
            // Check if a subscription with this ID actually exists
            $result = $this->subscriptionRepository->searchIds(new Criteria([$identifier]), $context);
            if ($result->firstId()) {
                return $identifier;
            }
            $io->error(sprintf('No subscription found with ID "%s".', $identifier));
            return null;
        }

        // If not a UUID, assume it's a subscription number
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('subscriptionNumber', $identifier));
        $result = $this->subscriptionRepository->searchIds($criteria, $context);

        if ($result->firstId() === null) {
            $io->error(sprintf('No subscription found with number "%s".', $identifier));
            return null;
        }

        return $result->firstId();
    }
}
