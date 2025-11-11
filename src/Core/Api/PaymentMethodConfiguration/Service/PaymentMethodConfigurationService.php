<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Api\PaymentMethodConfiguration\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\{
	Content\ImportExport\DataAbstractionLayer\Serializer\Entity\MediaSerializer,
	Content\ImportExport\DataAbstractionLayer\Serializer\SerializerRegistry,
	Content\ImportExport\Struct\Config,
	Content\Media\MediaDefinition,
	Framework\Context,
	Framework\DataAbstractionLayer\EntityRepository,
	Framework\DataAbstractionLayer\Search\Criteria,
	Framework\DataAbstractionLayer\Search\EntitySearchResult,
	Framework\DataAbstractionLayer\Search\Filter\EqualsFilter,
	Framework\Plugin\Util\PluginIdProvider,
	Framework\Uuid\Uuid};
use Symfony\Component\DependencyInjection\ContainerInterface;
use PostFinanceCheckout\Sdk\{
	ApiClient,
	Model\CreationEntityState,
	Model\CriteriaOperator,
	Model\EntityQuery,
	Model\EntityQueryFilter,
	Model\EntityQueryFilterType,
	Model\PaymentMethodConfiguration,
	Model\RestLanguage};
use PostFinanceCheckoutPayment\Core\{
	Api\PaymentMethodConfiguration\Entity\PaymentMethodConfigurationEntity,
	Api\PaymentMethodConfiguration\Entity\PaymentMethodConfigurationEntityDefinition,
	Checkout\PaymentHandler\PostFinanceCheckoutPaymentHandler,
	Settings\Service\SettingsService,
	Util\LocaleCodeProvider};
use PostFinanceCheckoutPayment\PostFinanceCheckoutPayment;

/**
 * Class PaymentMethodConfigurationService
 *
 * @package PostFinanceCheckoutPayment\Core\Api\PaymentMethodConfiguration\Service
 */
class PaymentMethodConfigurationService {

	/**
	 * @var \PostFinanceCheckoutPayment\Core\Settings\Service\SettingsService
	 */
	protected $settingsService;

	/**
	 * @var \PostFinanceCheckout\Sdk\ApiClient
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
	 * @var \PostFinanceCheckoutPayment\Core\Util\LocaleCodeProvider
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
	 * @var \Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface
	 */
	private $salesChannelPaymentRepository;

	/**
	 * @var \Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface
	 */
	private $mediaRepository;

	/**
	 * @var \Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface
	 */
	private $mediaFolderRepository;

	/**
	 * @var \Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface
	 */
	private $mediaDefaultFolderRepository;

	/**
	 * @var \Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface
	 */
	private $postFinanceCheckoutPaymentMethodConfigurationRepository;

	/**
	 * PaymentMethodConfigurationService constructor.
	 *
	 * @param \PostFinanceCheckoutPayment\Core\Settings\Service\SettingsService                        $settingsService
	 * @param \Symfony\Component\DependencyInjection\ContainerInterface                                  $container
	 * @param \Shopware\Core\Content\ImportExport\DataAbstractionLayer\Serializer\Entity\MediaSerializer $mediaSerializer
	 * @param \Shopware\Core\Content\ImportExport\DataAbstractionLayer\Serializer\SerializerRegistry     $serializerRegistry
	 * @param \Shopware\Core\Framework\DataAbstractionLayer\EntityRepository                             $salesChannelPaymentRepository
	 * @param \Shopware\Core\Framework\DataAbstractionLayer\EntityRepository                             $paymentMethodRepository
	 * @param \Shopware\Core\Framework\DataAbstractionLayer\EntityRepository                             $mediaRepository
	 * @param \Shopware\Core\Framework\DataAbstractionLayer\EntityRepository                             $mediaFolderRepository
	 * @param \Shopware\Core\Framework\DataAbstractionLayer\EntityRepository                             $mediaDefaultFolderRepository
	 * @param \Shopware\Core\Framework\DataAbstractionLayer\EntityRepository                             $ruleRepository
	 * @param \Shopware\Core\Framework\DataAbstractionLayer\EntityRepository                             $postFinanceCheckoutPaymentMethodConfigurationRepository
	 */
	public function __construct(
		SettingsService $settingsService,
		ContainerInterface $container,
		MediaSerializer $mediaSerializer,
		SerializerRegistry $serializerRegistry,
		EntityRepository $salesChannelPaymentRepository,
		EntityRepository $paymentMethodRepository,
		EntityRepository $mediaRepository,
		EntityRepository $mediaFolderRepository,
		EntityRepository $mediaDefaultFolderRepository,
		EntityRepository $ruleRepository,
		EntityRepository $postFinanceCheckoutPaymentMethodConfigurationRepository,
	) {
		$this->settingsService         = $settingsService;
		$this->container               = $container;
		$this->mediaSerializer         = $mediaSerializer;
		$this->serializerRegistry      = $serializerRegistry;
		$this->salesChannelPaymentRepository = $salesChannelPaymentRepository;
		$this->paymentMethodRepository = $paymentMethodRepository;
		$this->mediaRepository         = $mediaRepository;
		$this->mediaFolderRepository   = $mediaFolderRepository;
		$this->mediaDefaultFolderRepository = $mediaDefaultFolderRepository;
		$this->ruleRepository          = $ruleRepository;
		$this->postFinanceCheckoutPaymentMethodConfigurationRepository = $postFinanceCheckoutPaymentMethodConfigurationRepository;
		$this->localeCodeProvider      = $this->container->get(LocaleCodeProvider::class);
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
	 * @return \PostFinanceCheckout\Sdk\ApiClient
	 */
	public function getApiClient(): ApiClient
	{
		return $this->apiClient;
	}

	/**
	 * @param \PostFinanceCheckout\Sdk\ApiClient $apiClient
	 *
	 * @return \PostFinanceCheckoutPayment\Core\Api\PaymentMethodConfiguration\Service\PaymentMethodConfigurationService
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
	 * @throws \PostFinanceCheckout\Sdk\ApiException
	 * @throws \PostFinanceCheckout\Sdk\Http\ConnectionException
	 * @throws \PostFinanceCheckout\Sdk\VersioningException
	 */
	public function synchronize(Context $context): array
	{
		// Configuration
		$settings = $this->settingsService->getSettings($this->getSalesChannelId());
		$this->setSpaceId($settings->getSpaceId())->setApiClient($settings->getApiClient());

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
	 * @return \PostFinanceCheckoutPayment\Core\Api\PaymentMethodConfiguration\Service\PaymentMethodConfigurationService
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

		$criteria = (new Criteria())->addFilter(new EqualsFilter('state', 'ACTIVE'))
		  ->addFilter(new EqualsFilter('spaceId', $this->getSpaceId()));

		/**
		 * @var $postFinanceCheckoutPMConfigurationRepository
		 */
		$postFinanceCheckoutPMConfigurationRepository = $this->container->get(PaymentMethodConfigurationEntityDefinition::ENTITY_NAME . '.repository');

		$paymentMethodConfigurationEntities = $postFinanceCheckoutPMConfigurationRepository
			->search($criteria, $context)
			->getEntities();

		if (!empty($paymentMethodConfigurationEntities)) {

			/**
			 * @var $paymentMethodConfigurationEntity \PostFinanceCheckoutPayment\Core\Api\PaymentMethodConfiguration\Entity\PaymentMethodConfigurationEntity
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
			}

			try {
				$this->postFinanceCheckoutPaymentMethodConfigurationRepository->update($data, $context);
				$this->paymentMethodRepository->update($paymentMethodData, $context);
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
				  	  	SELECT payment_method_id FROM postfinancecheckout_payment_method_configuration
				  	  )";

			$params = [
				'handler_identifier' => PostFinanceCheckoutPaymentHandler::class,
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
	 * Enable payment methods from PostFinanceCheckout API
	 *
	 * @param \Shopware\Core\Framework\Context $context
	 *
	 * @throws \PostFinanceCheckout\Sdk\ApiException
	 * @throws \PostFinanceCheckout\Sdk\Http\ConnectionException
	 * @throws \PostFinanceCheckout\Sdk\VersioningException
	 */
	private function enablePaymentMethodConfigurations(Context $context): void
	{
		$paymentMethodConfigurations = $this->getPaymentMethodConfigurations();
		$this->logger->debug('Updating payment methods', $paymentMethodConfigurations);

		foreach ($paymentMethodConfigurations as $paymentMethodConfiguration) {
			$entity = $this->getPaymentMethodConfigurationEntity(
			  $paymentMethodConfiguration->getSpaceId(),
			  $paymentMethodConfiguration->getId(),
			  $context
			);

			$configId = $entity ? $entity->getId() : Uuid::randomHex();
			$technicalName = $paymentMethodConfiguration->getName();

			$paymentMethodId = $this->getOrCreatePaymentMethodId(
			  $technicalName,
			  PostFinanceCheckoutPaymentHandler::class,
			  $context
			);

			$data = [
			  'id'                           => $configId,
			  'paymentMethodConfigurationId' => $paymentMethodConfiguration->getId(),
			  'paymentMethodId'              => $paymentMethodId,
			  'data'                         => json_decode(strval($paymentMethodConfiguration), true),
			  'sortOrder'                    => $paymentMethodConfiguration->getSortOrder(),
			  'spaceId'                      => $paymentMethodConfiguration->getSpaceId(),
			  'state'                        => CreationEntityState::ACTIVE,
			];

			try {
				$this->upsertPaymentMethod($paymentMethodId, $paymentMethodConfiguration, $context);
				$this->container
				  ->get(PaymentMethodConfigurationEntityDefinition::ENTITY_NAME . '.repository')
				  ->upsert([$data], $context);
			} catch (\Exception $e) {
				$this->logger->error($e->getMessage(), [$e->getTraceAsString()]);
			}
		}
	}

	private function getOrCreatePaymentMethodId(string $technicalName, string $handlerIdentifier, Context $context): string
	{
		$criteria = new Criteria();
		$criteria->addFilter(new EqualsFilter('technicalName', $technicalName));
		$criteria->setLimit(1);

		$existing = $this->paymentMethodRepository->search($criteria, $context)->first();
		if ($existing !== null) {
			return $existing->getId();
		}

		$paymentMethodId = Uuid::randomHex();

		$this->paymentMethodRepository->upsert([[
		  'id' => $paymentMethodId,
		  'handlerIdentifier' => $handlerIdentifier,
		  'technicalName' => $technicalName,
		  'name' => $technicalName,
		  'active' => false,
		]], $context);

		return $paymentMethodId;
	}

	/**
	 * Fetch active merchant payment methods from PostFinanceCheckout API
	 *
	 * @return \PostFinanceCheckout\Sdk\Model\PaymentMethodConfiguration[]
	 * @throws \PostFinanceCheckout\Sdk\ApiException
	 * @throws \PostFinanceCheckout\Sdk\Http\ConnectionException
	 * @throws \PostFinanceCheckout\Sdk\VersioningException
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
	 * @return \PostFinanceCheckoutPayment\Core\Api\PaymentMethodConfiguration\Service\PaymentMethodConfigurationService
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
	 * @return \PostFinanceCheckoutPayment\Core\Api\PaymentMethodConfiguration\Entity\PaymentMethodConfigurationEntity|null
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

		return $this->postFinanceCheckoutPaymentMethodConfigurationRepository
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

		$configurations = $this->postFinanceCheckoutPaymentMethodConfigurationRepository
			->search($criteria, $context)
			->getEntities();

		return $configurations->getElements();
	}

	/**
	 * @param \PostFinanceCheckout\Sdk\Model\PaymentMethodConfiguration $paymentMethodConfiguration
	 * @param \Shopware\Core\Framework\Context                            $context
	 *
	 * @return array
	 * @throws \PostFinanceCheckout\Sdk\ApiException
	 * @throws \PostFinanceCheckout\Sdk\Http\ConnectionException
	 * @throws \PostFinanceCheckout\Sdk\VersioningException
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
	 * @throws \PostFinanceCheckout\Sdk\ApiException
	 * @throws \PostFinanceCheckout\Sdk\Http\ConnectionException
	 * @throws \PostFinanceCheckout\Sdk\VersioningException
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
	 * @return \PostFinanceCheckout\Sdk\Model\RestLanguage|null
	 * @throws \PostFinanceCheckout\Sdk\ApiException
	 * @throws \PostFinanceCheckout\Sdk\Http\ConnectionException
	 * @throws \PostFinanceCheckout\Sdk\VersioningException
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
	 * @throws \PostFinanceCheckout\Sdk\ApiException
	 * @throws \PostFinanceCheckout\Sdk\Http\ConnectionException
	 * @throws \PostFinanceCheckout\Sdk\VersioningException
	 */
	protected function getLanguages(): array
	{
		if (is_null($this->languages)) {
			$this->languages = $this->apiClient->getLanguageService()->all();
		}
		return $this->languages;
	}

	/**
	 * Update or insert Payment Method
	 */
	protected function upsertPaymentMethod(
	  string $id,
	  PaymentMethodConfiguration $paymentMethodConfiguration,
	  Context $context
	): void {
		/** @var PluginIdProvider $pluginIdProvider */
		$pluginIdProvider = $this->container->get(PluginIdProvider::class);
		$pluginId = $pluginIdProvider->getPluginIdByBaseClass(
		  PostFinanceCheckoutPayment::class,
		  $context
		);

		$data = [
		  'id'                => $id,
		  'handlerIdentifier' => PostFinanceCheckoutPaymentHandler::class,
		  'pluginId'          => $pluginId,
		  'position'          => $paymentMethodConfiguration->getSortOrder() - 100,
		  'afterOrderEnabled' => true,
		  'active'            => true,
		  'translations'      => $this->getPaymentMethodConfigurationTranslation($paymentMethodConfiguration, $context),
		  'technicalName'     => $paymentMethodConfiguration->getName(),
		];

		$mediaId = $this->upsertMedia($id, $paymentMethodConfiguration, $context);
		if ($mediaId) {
			$data['mediaId'] = $mediaId;
		}

		try {
			$this->paymentMethodRepository->upsert([$data], $context);
		} catch (\Exception $e) {
			$this->logger->error($e->getMessage(), [$e->getTraceAsString()]);
		}
	}

	/**
	 * Upload or update Payment Method icons
	 */
	protected function upsertMedia(string $id, PaymentMethodConfiguration $paymentMethodConfiguration, Context $context): ?string
	{
		try {
			$folderKey = 'payment_method_' . $paymentMethodConfiguration->getId();

			// Check existing default folder
			$criteria = new Criteria();
			$criteria->addFilter(new EqualsFilter('entity', $folderKey));
			$existingFolder = $this->mediaDefaultFolderRepository->search($criteria, $context);

			$folderId = $id;
			if ($existingFolder->count() > 0) {
				$folderId = $existingFolder->first()->getId();
			}

			// Ensure default folder
			$this->mediaDefaultFolderRepository->upsert([
			  [
				'id'                => $folderId,
				'associationFields' => [],
				'entity'            => $folderKey,
			  ],
			], $context);

			// Ensure media folder
			$this->mediaFolderRepository->upsert([
			  [
				'id'                     => $folderId,
				'defaultFolderId'        => $folderId,
				'name'                   => $paymentMethodConfiguration->getName(),
				'useParentConfiguration' => false,
				'configuration'          => [],
			  ],
			], $context);

			// Media insert/update

			// detect if collision, return existing id
			$existingId = $this->checkMediaAlreadyExists($paymentMethodConfiguration->getResolvedImageUrl(), $context);
			if ($existingId) {
				return $existingId;
			}

			$mediaDefinition = $this->container->get(MediaDefinition::class);
			$this->mediaSerializer->setRegistry($this->serializerRegistry);

			$data = [
			  'id'            => $id,
			  'title'         => $paymentMethodConfiguration->getName(),
			  'url'           => $paymentMethodConfiguration->getResolvedImageUrl(),
			  'mediaFolderId' => $folderId,
			];

			$data = $this->mediaSerializer->deserialize(new Config([], [], []), $mediaDefinition, $data);
			$this->mediaRepository->upsert([$data], $context);

			return $id;
		} catch (\Exception $e) {
			$this->logger->critical($e->getMessage(), [$e->getTraceAsString()]);
			return null;
		}
	}

	private function checkMediaAlreadyExists($paymentMethodUrl, $context) {
		// detect if collision, return existing id
		if (preg_match('#/([^/]+)\.[^/.]+$#', $paymentMethodUrl, $matches)) {
			$filename = $matches[1];
		}
		$criteria = new Criteria();
		$criteria->addFilter(new EqualsFilter('fileName', $filename));

		$existing = $this->mediaRepository->search($criteria, $context)->first();

		if ($existing) {
			return $existing->getId();
		}
		return false;
	}

	/**
	 * Retrieves media default folder for a given payment method configuration.
	 *
	 * @param PaymentMethodConfiguration $paymentMethodConfiguration The payment method configuration to check.
	 * @param Context $context The current context.
	 *
	 * @return EntitySearchResult The search result for the media default folder.
	 */
	private function getMediaDefaultFolderForPaymentMethod(PaymentMethodConfiguration $paymentMethodConfiguration, Context $context): ?EntitySearchResult
	{
		$criteria = new Criteria();
		$criteria->addFilter(new EqualsFilter('entity', 'payment_method_' . $paymentMethodConfiguration->getId()));
		return $this->mediaDefaultFolderRepository->search($criteria, $context);
	}

}
