<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Util;

use Psr\Log\LoggerInterface;
use Shopware\Core\{
	Framework\Context,
	Framework\DataAbstractionLayer\EntityRepositoryInterface,
	Framework\DataAbstractionLayer\Search\Criteria,
	System\Language\LanguageCollection};

/**
 * Class LocaleCodeProvider
 *
 * @package PostFinanceCheckoutPayment\Core\Util
 */
class LocaleCodeProvider {

	/**
	 * @var \Psr\Log\LoggerInterface
	 */
	protected $logger;

	/**
	 * @var EntityRepositoryInterface
	 */
	private $languageRepository;

	/**
	 * LocaleCodeProvider constructor.
	 *
	 * @param \Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface $languageRepository
	 */
	public function __construct(EntityRepositoryInterface $languageRepository)
	{
		$this->languageRepository = $languageRepository;
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
	 * @return string
	 */
	public function getLocaleCodeFromContext(Context $context): string
	{
		$defaultLocale = 'en-GB';
		$languageId    = $context->getLanguageId();
		$criteria      = (new Criteria([$languageId]))->addAssociation('locale');
		/** @var \Shopware\Core\System\Language\LanguageCollection $languageCollection */
		$languageCollection = $this->languageRepository->search($criteria, $context)->getEntities();

		$language = $languageCollection->get($languageId);
		if (is_null($language)) {
			return $defaultLocale;
		}

		return $language->getLocale() ? $language->getLocale()->getCode() : $defaultLocale;
	}

	/**
	 * Get available languages
	 *
	 * @param \Shopware\Core\Framework\Context $context
	 * @return \Shopware\Core\System\Language\LanguageCollection
	 */
	public function getAvailableLanguages(Context $context): LanguageCollection
	{
		return $this->languageRepository->search((new Criteria())->addAssociations([
			'locale',
		]), $context)->getEntities();
	}

	/**
	 * Get available translations
	 *
	 * @param array                            $translation
	 * @param \Shopware\Core\Framework\Context $context
	 *
	 * @return array
	 */
	public function getAvailableTranslations(array $translation, Context $context): array
	{
		$availableLanguages = $this->getAvailableLanguages($context);

		$locales = $locales = array_map(
			function ($language) {
				/**
				 * @var \Shopware\Core\System\Language\LanguageEntity $language
				 */
				return $language->getLocale()->getCode();
			},
			$availableLanguages->jsonSerialize()
		);

		foreach ($translation as $key => $value) {
			if (!in_array($key, $locales)) {
				$translation[$key] = null;
			}
		}

		$translation = array_filter($translation);
		return $translation;
	}
}