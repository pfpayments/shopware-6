<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Util;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\{
	Framework\Context,
	Framework\DataAbstractionLayer\Search\Criteria,
	Framework\DataAbstractionLayer\Search\Filter\EqualsFilter,
	Framework\DataAbstractionLayer\Search\Filter\NotFilter,
	Framework\DataAbstractionLayer\Search\Sorting\FieldSorting,
	System\SalesChannel\SalesChannelCollection,};
use PostFinanceCheckoutPayment\Core\Checkout\PaymentHandler\PostFinanceCheckoutPaymentHandler;

/**
 * Class PaymentMethodUtil
 *
 * @package PostFinanceCheckoutPayment\Core\Util
 */
class PaymentMethodUtil {

	/**
	 * @var \Psr\Log\LoggerInterface
	 */
	protected $logger;

	/**
	 * @var \Psr\Container\ContainerInterface
	 */
	protected $container;

	/**
	 * @var \Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface
	 */
	private $paymentRepository;

	/**
	 * @var \Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface
	 */
	private $salesChannelRepository;

	/**
	 * @var \Shopware\Core\System\SalesChannel\Aggregate\SalesChannelPaymentMethod\SalesChannelPaymentMethodDefinition
	 */
	private $salesChannelPaymentMethodRepository;

	/**
	 * PaymentMethodUtil constructor.
	 *
	 * @param \Psr\Container\ContainerInterface $container
	 */
	public function __construct(ContainerInterface $container)
	{
		$this->container                           = $container;
		$this->paymentRepository                   = $this->container->get('payment_method.repository');
		$this->salesChannelRepository              = $this->container->get('sales_channel.repository');
		$this->salesChannelPaymentMethodRepository = $this->container->get('sales_channel_payment_method.repository');
	}

	/**
	 * @param \Psr\Log\LoggerInterface $logger
	 * @internal
	 * @required
	 *
	 */
	public function setLogger(LoggerInterface $logger): void
	{
		$this->logger = $logger;
	}

	/**
	 * @param \Shopware\Core\Framework\Context $context
	 * @param string|null                      $salesChannelId
	 */
	public function setPostFinanceCheckoutAsDefaultPaymentMethod(Context $context, ?string $salesChannelId = null): void
	{
		$paymentMethodIds = $this->getPostFinanceCheckoutPaymentMethodIds($context);
		if (empty($paymentMethodIds)) {
			return;
		}

		$salesChannelsToChange = $this->getSalesChannelsToChange($context, $salesChannelId);
		$updateData            = [];

		foreach ($salesChannelsToChange as $salesChannel) {
			foreach ($paymentMethodIds as $paymentMethodId) {
				$salesChannelUpdateData = [
					'id'              => $salesChannel->getId(),
					'paymentMethodId' => $paymentMethodId,
				];

				$paymentMethodCollection = $salesChannel->getPaymentMethods();
				if (is_null($paymentMethodCollection) || is_null($paymentMethodCollection->get($paymentMethodId))) {
					$salesChannelUpdateData['paymentMethods'][] = [
						'id' => $paymentMethodId,
					];
				}

				$updateData[] = $salesChannelUpdateData;
			}
		}

		$this->salesChannelRepository->update($updateData, $context);
	}

	/**
	 * @param \Shopware\Core\Framework\Context $context
	 * @return array
	 */
	public function getPostFinanceCheckoutPaymentMethodIds(Context $context): array
	{
		$criteria = (new Criteria())
			->addFilter(new EqualsFilter('handlerIdentifier', PostFinanceCheckoutPaymentHandler::class))
			->addSorting(new FieldSorting('position'));

		return $this->paymentRepository->searchIds($criteria, $context)->getIds();
	}

	/**
	 * @param \Shopware\Core\Framework\Context $context
	 * @param string|null                      $salesChannelId
	 * @return \Shopware\Core\System\SalesChannel\SalesChannelCollection
	 */
	private function getSalesChannelsToChange(Context $context, ?string $salesChannelId = null): SalesChannelCollection
	{
		$criteria = is_null($salesChannelId) ? new Criteria() : new Criteria([$salesChannelId]);
		$criteria->addAssociation('paymentMethods');

		return $this->salesChannelRepository->search($criteria, $context)->getEntities();
	}

	/**
	 * Disable System Payment Methods
	 *
	 * @param \Shopware\Core\Framework\Context $context
	 *
	 */
	public function disableSystemPaymentMethods(Context $context): void
	{
		$paymentMethodIds = $this->getSystemPaymentMethodIds($context);
		$this->setPaymentMethodIsActive($paymentMethodIds, false, $context);
		$this->disableSalesChannelPaymentMethods($paymentMethodIds, $context);
	}

	/**
	 * @param \Shopware\Core\Framework\Context $context
	 * @return string[]
	 */
	protected function getSystemPaymentMethodIds(Context $context): array
	{
		$criteria = (new Criteria())
			->addFilter(new NotFilter(
				NotFilter::CONNECTION_AND,
				[
					new EqualsFilter('handlerIdentifier', PostFinanceCheckoutPaymentHandler::class),
				]
			));

		return $this->paymentRepository->searchIds($criteria, $context)->getIds();
	}

	/**
	 * @param array                            $paymentMethodIds
	 * @param bool                             $active
	 * @param \Shopware\Core\Framework\Context $context
	 *
	 */
	protected function setPaymentMethodIsActive(array $paymentMethodIds, bool $active, Context $context): void
	{
		$data = [];

		foreach ($paymentMethodIds as $paymentMethodId) {
			$data[] = [
				'id'     => $paymentMethodId,
				'active' => $active,
			];
		}

		$this->paymentRepository->update($data, $context);
	}

	/**
	 * @param array                            $paymentMethodIds
	 * @param \Shopware\Core\Framework\Context $context
	 *
	 */
	protected function disableSalesChannelPaymentMethods(array $paymentMethodIds, Context $context)
	{
		$data = [];

		$salesChannels = $this->getSalesChannelsToChange($context);

		foreach ($salesChannels as $salesChannel) {
			foreach ($paymentMethodIds as $paymentMethodId) {
				$data[] = [
					'paymentMethodId' => $paymentMethodId,
					'salesChannelId'  => $salesChannel->getId(),
				];
			}
		}
		$this->salesChannelPaymentMethodRepository->delete($data, $context);
	}

}