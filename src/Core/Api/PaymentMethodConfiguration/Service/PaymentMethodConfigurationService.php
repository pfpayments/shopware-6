<?php declare(strict_types=1);

namespace WeArePlanetPayment\Core\Api\PaymentMethodConfiguration\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\{
	Content\ImportExport\DataAbstractionLayer\Serializer\Entity\MediaSerializer,
	Content\ImportExport\DataAbstractionLayer\Serializer\SerializerRegistry,
	Content\ImportExport\Struct\Config,
	Content\Media\MediaDefinition,
	Framework\Context,
	Framework\DataAbstractionLayer\Search\Criteria,
	Framework\DataAbstractionLayer\Search\Filter\EqualsFilter,
	Framework\Plugin\Util\PluginIdProvider,
	Framework\Uuid\Uuid};
use Symfony\Component\DependencyInjection\ContainerInterface;
use WeArePlanet\Sdk\{
	ApiClient,
	Model\CreationEntityState,
	Model\CriteriaOperator,
	Model\EntityQuery,
	Model\EntityQueryFilter,
	Model\EntityQueryFilterType,
	Model\PaymentMethodConfiguration,
	Model\RestLanguage};
use WeArePlanetPayment\Core\{
	Api\PaymentMethodConfiguration\Entity\PaymentMethodConfigurationEntity,
	Api\PaymentMethodConfiguration\Entity\PaymentMethodConfigurationEntityDefinition,
	Checkout\PaymentHandler\WeArePlanetPaymentHandler,
	Settings\Service\SettingsService,
	Util\LocaleCodeProvider};
use WeArePlanetPayment\WeArePlanetPayment;


/**
 * Class PaymentMethodConfigurationService
 *
 * @package WeArePlanetPayment\Core\Api\PaymentMethodConfiguration\Service
 */
class PaymentMethodConfigurationService {

	/**
	 * @var \WeArePlanetPayment\Core\Settings\Service\SettingsService
	 */
	protected $settingsService;

	/**
	 * @var \WeArePlanet\Sdk\ApiClient
	 */
	protected $apiClient;

	/**
	 * Space Id
	 *
	 * @var int
	 */
	protected $spaceId;

	/**
	 * @var \Symfony\Component\DependencyInjection\ContainerInterface
	 */
	protected $container;

	/**
	 * @var \Shopware\Core\Content\ImportExport\DataAbstractionLayer\Serializer\SerializerRegistry
	 */
	protected $serializerRegistry;

	/**
	 * @var \Psr\Log\LoggerInterface
	 */
	protected $logger;

	/**
	 * @var ?string $salesChannelId
	 */
	private $salesChannelId;

	/**
	 * @var \Shopware\Core\Content\ImportExport\DataAbstractionLayer\Serializer\Entity\MediaSerializer
	 */
	private $mediaSerializer;

	/**
	 * @var
	 */
	private $languages;

	/**
	 * @var \WeArePlanetPayment\Core\Util\LocaleCodeProvider
	 */
	private $localeCodeProvider;

	/**
	 * @var \Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface
	 */
	private $ruleRepository;

	/**
	 * @var \Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface
	 */
	private $paymentMethodRepository;

	/**
	 * PaymentMethodConfigurationService constructor.
	 *
	 * @param \WeArePlanetPayment\Core\Settings\Service\SettingsService                        $settingsService
	 * @param \Symfony\Component\DependencyInjection\ContainerInterface                                  $container
	 * @param \Shopware\Core\Content\ImportExport\DataAbstractionLayer\Serializer\Entity\MediaSerializer $mediaSerializer
	 * @param \Shopware\Core\Content\ImportExport\DataAbstractionLayer\Serializer\SerializerRegistry     $serializerRegistry
	 */
	public function __construct(
		SettingsService $settingsService,
		ContainerInterface $container,
		MediaSerializer $mediaSerializer,
		SerializerRegistry $serializerRegistry
	)
	{
		$this->container               = $container;
		$this->ruleRepository          = $this->container->get('rule.repository');
		$this->settingsService         = $settingsService;
		$this->mediaSerializer         = $mediaSerializer;
		$this->serializerRegistry      = $serializerRegistry;
		$this->localeCodeProvider      = $this->container->get(LocaleCodeProvider::class);
		$this->paymentMethodRepository = $this->container->get('payment_method.repository');
	}

	/**
	 * @param \Psr\Log\LoggerInterface $logger
	 *
	 * @internal
	 * @required
	 *
	 */
	public function setLogger(LoggerInterface $logger): void
	{
		$this->logger = $logger;
	}

	/**
	 * @return \WeArePlanet\Sdk\ApiClient
	 */
	public function getApiClient(): ApiClient
	{
		return $this->apiClient;
	}

	/**
	 * @param \WeArePlanet\Sdk\ApiClient $apiClient
	 *
	 * @return \WeArePlanetPayment\Core\Api\PaymentMethodConfiguration\Service\PaymentMethodConfigurationService
	 */
	public function setApiClient(ApiClient $apiClient): PaymentMethodConfigurationService
	{
		$this->apiClient = $apiClient;
		return $this;
	}

	/**
	 * @param \Shopware\Core\Framework\Context $context
	 *
	 * @return array
	 * @throws \WeArePlanet\Sdk\ApiException
	 * @throws \WeArePlanet\Sdk\Http\ConnectionException
	 * @throws \WeArePlanet\Sdk\VersioningException
	 */
	public function synchronize(Context $context): array
	{
		// Configuration
		$settings = $this->settingsService->getSettings($this->getSalesChannelId());
		$this->setSpaceId($settings->getSpaceId())
			 ->setApiClient($settings->getApiClient());

		$this->disablePaymentMethodConfigurations($context);
		$this->enablePaymentMethodConfigurations($context);
		$this->disableOrphanedPaymentMethods();
		return [];
	}

	/**
	 * Get sales channel id
	 *
	 * @return string|null
	 */
	public function getSalesChannelId(): ?string
	{
		return $this->salesChannelId;
	}

	/**
	 * Set sales channel id
	 *
	 * @param string|null $salesChannelId
	 *
	 * @return \WeArePlanetPayment\Core\Api\PaymentMethodConfiguration\Service\PaymentMethodConfigurationService
	 */
	public function setSalesChannelId(?string $salesChannelId = null): PaymentMethodConfigurationService
	{
		$this->salesChannelId = $salesChannelId;
		return $this;
	}

	/**
	 * @param \Shopware\Core\Framework\Context $context
	 */
	private function disablePaymentMethodConfigurations(Context $context): void
	{
		$data                          = [];
		$paymentMethodData             = [];
		$salesChannelPaymentMethodData = [];

		$criteria = (new Criteria())->addFilter(new EqualsFilter('spaceId', $this->getSpaceId()));

		/**
		 * @var $weArePlanetPMConfigurationRepository
		 */
		$weArePlanetPMConfigurationRepository = $this->container->get(PaymentMethodConfigurationEntityDefinition::ENTITY_NAME . '.repository');

		/** @var EntityRepositoryInterface $salesChannelPaymentRepository */
		$salesChannelPaymentRepository = $this->container->get('sales_channel_payment_method.repository');

		$paymentMethodConfigurationEntities = $weArePlanetPMConfigurationRepository
			->search($criteria, $context)
			->getEntities();

		if (!empty($paymentMethodConfigurationEntities)) {

			/**
			 * @var $paymentMethodConfigurationEntity \WeArePlanetPayment\Core\Api\PaymentMethodConfiguration\Entity\PaymentMethodConfigurationEntity
			 */
			foreach ($paymentMethodConfigurationEntities as $paymentMethodConfigurationEntity) {
				$data[] = [
					'id'    => $paymentMethodConfigurationEntity->getId(),
					'state' => CreationEntityState::INACTIVE,
				];

				$paymentMethodData[] = [
					'id'     => $paymentMethodConfigurationEntity->getId(),
					'active' => false,
				];

				$salesChannelPaymentMethodData[] = [
					'paymentMethodId' => $paymentMethodConfigurationEntity->getId(),
				];
			}

			try {
				$weArePlanetPMConfigurationRepository->update($data, $context);
				$this->paymentMethodRepository->update($paymentMethodData, $context);
				$salesChannelPaymentRepository->delete($salesChannelPaymentMethodData, $context);
			} catch (\Exception $exception) {
				$this->logger->critical($exception->getMessage());
			}

		}

	}

	/**
	 * Full proof method to disable any orphaned payment methods
	 *
	 */
	protected function disableOrphanedPaymentMethods(): void
	{
		try {
			$query = "UPDATE payment_method
				  	  SET active=0
				  	  WHERE handler_identifier=:handler_identifier AND id NOT IN (
				  	  	SELECT payment_method_id FROM weareplanet_payment_method_configuration
				  	  )";

			$params = [
				'handler_identifier' => WeArePlanetPaymentHandler::class,
			];

			$connection = $this->container->get(Connection::class);
			$connection->executeQuery($query, $params);
		} catch (\Exception $exception) {
			$this->logger->critical($exception->getMessage());
		}
	}

	/**
	 * @param string                           $paymentMethodId
	 * @param bool                             $active
	 * @param \Shopware\Core\Framework\Context $context
	 */
	protected function setPaymentMethodIsActive(string $paymentMethodId, bool $active, Context $context): void
	{
		$paymentMethod = [
			'id'     => $paymentMethodId,
			'active' => $active,
		];
		$this->paymentMethodRepository->update([$paymentMethod], $context);
	}

	/**
	 * Enable payment methods from WeArePlanet API
	 *
	 * @param \Shopware\Core\Framework\Context $context
	 *
	 * @throws \WeArePlanet\Sdk\ApiException
	 * @throws \WeArePlanet\Sdk\Http\ConnectionException
	 * @throws \WeArePlanet\Sdk\VersioningException
	 */
	private function enablePaymentMethodConfigurations(Context $context): void
	{
		$paymentMethodConfigurations = $this->getPaymentMethodConfigurations();
		$this->logger->debug('Updating payment methods', $paymentMethodConfigurations);

		/**
		 * @var $paymentMethodConfiguration \WeArePlanet\Sdk\Model\PaymentMethodConfiguration
		 */
		foreach ($paymentMethodConfigurations as $paymentMethodConfiguration) {

			$paymentMethodConfigurationEntity = $this->getPaymentMethodConfigurationEntity(
				$paymentMethodConfiguration->getSpaceId(),
				$paymentMethodConfiguration->getId(),
				$context
			);

			$id = is_null($paymentMethodConfigurationEntity) ? Uuid::randomHex() : $paymentMethodConfigurationEntity->getId();

			$data = [
				'id'                           => $id,
				'paymentMethodConfigurationId' => $paymentMethodConfiguration->getId(),
				'paymentMethodId'              => $id,
				'data'                         => json_decode(strval($paymentMethodConfiguration), true),
				'sortOrder'                    => $paymentMethodConfiguration->getSortOrder(),
				'spaceId'                      => $paymentMethodConfiguration->getSpaceId(),
				'state'                        => CreationEntityState::ACTIVE,
			];

			$this->upsertPaymentMethod($id, $paymentMethodConfiguration, $context);


			$this->container->get(PaymentMethodConfigurationEntityDefinition::ENTITY_NAME . '.repository')
							->upsert([$data], $context);

		}
	}

	/**
	 * Fetch active merchant payment methods from WeArePlanet API
	 *
	 * @return \WeArePlanet\Sdk\Model\PaymentMethodConfiguration[]
	 * @throws \WeArePlanet\Sdk\ApiException
	 * @throws \WeArePlanet\Sdk\Http\ConnectionException
	 * @throws \WeArePlanet\Sdk\VersioningException
	 */
	private function getPaymentMethodConfigurations(): array
	{
		$entityQueryFilter = (new EntityQueryFilter())
			->setOperator(CriteriaOperator::EQUALS)
			->setFieldName('state')
			->setType(EntityQueryFilterType::LEAF)
			->setValue(CreationEntityState::ACTIVE);

		$entityQuery = (new EntityQuery())->setFilter($entityQueryFilter);

		$paymentMethodConfigurations = $this->apiClient->getPaymentMethodConfigurationService()->search(
			$this->getSpaceId(),
			$entityQuery
		);

		usort($paymentMethodConfigurations, function (PaymentMethodConfiguration $item1, PaymentMethodConfiguration $item2) {
			return $item1->getSortOrder() <=> $item2->getSortOrder();
		});

		return $paymentMethodConfigurations;
	}

	/**
	 * @return int
	 */
	public function getSpaceId(): int
	{
		return $this->spaceId;
	}

	/**
	 * @param int $spaceId
	 *
	 * @return \WeArePlanetPayment\Core\Api\PaymentMethodConfiguration\Service\PaymentMethodConfigurationService
	 */
	public function setSpaceId(int $spaceId): PaymentMethodConfigurationService
	{
		$this->spaceId = $spaceId;
		return $this;
	}

	/**
	 * @param int                              $spaceId
	 * @param int                              $paymentMethodConfigurationId
	 * @param \Shopware\Core\Framework\Context $context
	 *
	 * @return \WeArePlanetPayment\Core\Api\PaymentMethodConfiguration\Entity\PaymentMethodConfigurationEntity|null
	 */
	protected function getPaymentMethodConfigurationEntity(
		int $spaceId,
		int $paymentMethodConfigurationId,
		Context $context
	): ?PaymentMethodConfigurationEntity
	{
		$criteria = (new Criteria())->addFilter(
			new EqualsFilter('spaceId', $spaceId),
			new EqualsFilter('paymentMethodConfigurationId', $paymentMethodConfigurationId)
		);

		return $this->container->get(PaymentMethodConfigurationEntityDefinition::ENTITY_NAME . '.repository')
							   ->search($criteria, $context)
							   ->getEntities()
							   ->first();
	}

    /**
     * @param int $spaceId
     * @param Context $context
     * @return array
     */
    public function getAllPaymentMethodConfigurations(int $spaceId, Context $context): array
    {
        $criteria = (new Criteria())->addFilter(new EqualsFilter('spaceId', $spaceId));

        $configurations = $this->container->get(PaymentMethodConfigurationEntityDefinition::ENTITY_NAME . '.repository')
            ->search($criteria, $context)
            ->getEntities();

        return $configurations->getElements();
    }

	/**
	 * Update or insert Payment Method
	 *
	 * @param string                                                      $id
	 * @param \WeArePlanet\Sdk\Model\PaymentMethodConfiguration $paymentMethodConfiguration
	 * @param \Shopware\Core\Framework\Context                            $context
	 *
	 * @throws \WeArePlanet\Sdk\ApiException
	 * @throws \WeArePlanet\Sdk\Http\ConnectionException
	 * @throws \WeArePlanet\Sdk\VersioningException
	 */
	protected function upsertPaymentMethod(
		string $id,
		PaymentMethodConfiguration $paymentMethodConfiguration,
		Context $context
	): void
	{
		/** @var PluginIdProvider $pluginIdProvider */
		$pluginIdProvider = $this->container->get(PluginIdProvider::class);
		$pluginId         = $pluginIdProvider->getPluginIdByBaseClass(
			WeArePlanetPayment::class,
			$context
		);

		$data = [
			'id'                 => $id,
			'handlerIdentifier'  => WeArePlanetPaymentHandler::class,
			'pluginId'           => $pluginId,
			'position'           => $paymentMethodConfiguration->getSortOrder() - 100,
			'afterOrderEnabled'  => true,
			'active'             => true,
			'translations'       => $this->getPaymentMethodConfigurationTranslation($paymentMethodConfiguration, $context),
		];

		$data['mediaId'] = $this->upsertMedia($id, $paymentMethodConfiguration, $context);

		$data = array_filter($data);

		$this->paymentMethodRepository->upsert([$data], $context);
	}

	/**
	 * @param \WeArePlanet\Sdk\Model\PaymentMethodConfiguration $paymentMethodConfiguration
	 * @param \Shopware\Core\Framework\Context                            $context
	 *
	 * @return array
	 * @throws \WeArePlanet\Sdk\ApiException
	 * @throws \WeArePlanet\Sdk\Http\ConnectionException
	 * @throws \WeArePlanet\Sdk\VersioningException
	 */
	protected function getPaymentMethodConfigurationTranslation(PaymentMethodConfiguration $paymentMethodConfiguration, Context $context): array
	{
		$translations = [];
		$locales      = $this->localeCodeProvider->getAvailableLocales($context);
		foreach ($locales as $locale) {
			$translations[$locale] = [
				'name'        => $this->translate($paymentMethodConfiguration->getResolvedTitle(), $locale) ?? $paymentMethodConfiguration->getName(),
				'description' => $this->translate($paymentMethodConfiguration->getResolvedDescription(), $locale) ?? '',
			];
		}
		return $translations;
	}

	/**
	 * @param array  $translatedString
	 * @param string $locale
	 *
	 * @return string|null
	 * @throws \WeArePlanet\Sdk\ApiException
	 * @throws \WeArePlanet\Sdk\Http\ConnectionException
	 * @throws \WeArePlanet\Sdk\VersioningException
	 */
	protected function translate(array $translatedString, string $locale): ?string
	{
		$translation = null;

		if (isset($translatedString[$locale])) {
			$translation = $translatedString[$locale];
		}

		if (is_null($translation)) {

			$primaryLanguage = $this->findPrimaryLanguage($locale);
			if (!is_null($primaryLanguage) && isset($translatedString[$primaryLanguage->getIetfCode()])) {
				$translation = $translatedString[$primaryLanguage->getIetfCode()];
			}

			if (is_null($translation) && isset($translatedString['en-US'])) {
				$translation = $translatedString['en-US'];
			}
		}

		return $translation;
	}

	/**
	 * Returns the primary language in the given group.
	 *
	 * @param $code
	 *
	 * @return \WeArePlanet\Sdk\Model\RestLanguage|null
	 * @throws \WeArePlanet\Sdk\ApiException
	 * @throws \WeArePlanet\Sdk\Http\ConnectionException
	 * @throws \WeArePlanet\Sdk\VersioningException
	 */
	protected function findPrimaryLanguage(string $code): ?RestLanguage
	{
		$code = substr($code, 0, 2);
		foreach ($this->getLanguages() as $language) {
			if (($language->getIso2Code() == $code) && $language->getPrimaryOfGroup()) {
				return $language;
			}
		}
		return null;
	}

	/**
	 *
	 * @return array
	 * @throws \WeArePlanet\Sdk\ApiException
	 * @throws \WeArePlanet\Sdk\Http\ConnectionException
	 * @throws \WeArePlanet\Sdk\VersioningException
	 */
	protected function getLanguages(): array
	{
		if (is_null($this->languages)) {
			$this->languages = $this->apiClient->getLanguageService()->all();
		}
		return $this->languages;
	}

	/**
	 * Upload Payment Method icons
	 *
	 * @param string                                                      $id
	 * @param \WeArePlanet\Sdk\Model\PaymentMethodConfiguration $paymentMethodConfiguration
	 * @param \Shopware\Core\Framework\Context                            $context
	 *
	 * @return string|null
	 */
	protected function upsertMedia(string $id, PaymentMethodConfiguration $paymentMethodConfiguration, Context $context): ?string
	{
		try {
			$mediaDefaultFolderRepository = $this->container->get('media_default_folder.repository');
			$mediaDefaultFolderRepository->upsert([
				[
					'id'                => $id,
					'associationFields' => [],
					'entity'            => 'payment_method_' . $paymentMethodConfiguration->getId(),
				],
			], $context);

			$mediaFolderRepository = $this->container->get('media_folder.repository');
			$mediaFolderRepository->upsert([
				[
					'id'                     => $id,
					'defaultFolderId'        => $id,
					'name'                   => $paymentMethodConfiguration->getName(),
					'useParentConfiguration' => false,
					'configuration'          => [],
				],
			], $context);

			/**
			 * @var \Shopware\Core\Content\Media\MediaDefinition
			 */
			$mediaDefinition = $this->container->get(MediaDefinition::class);
			$this->mediaSerializer->setRegistry($this->serializerRegistry);
			$data = [
				'id'            => $id,
				'title'         => $paymentMethodConfiguration->getName(),
				'url'           => $paymentMethodConfiguration->getResolvedImageUrl(),
				'mediaFolderId' => $id,
			];
			$data = $this->mediaSerializer->deserialize(new Config([], [], []), $mediaDefinition, $data);
			$this->container->get('media.repository')->upsert([$data], $context);
			return $id;
		} catch (\Exception $e) {
			$this->logger->critical($e->getMessage(), [$e->getTraceAsString()]);
			return null;
		}
	}


}
