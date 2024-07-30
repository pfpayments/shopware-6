<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Util;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\{
	Defaults,
	Framework\Adapter\Translation\Translator,
	Framework\Context,
	Framework\DataAbstractionLayer\EntityRepositoryInterface,
	Framework\DataAbstractionLayer\Search\Criteria,
	System\Language\LanguageCollection,
	System\Language\LanguageEntity};


/**
 * Class LocaleCodeProvider
 *
 * @package PostFinanceCheckoutPayment\Core\Util
 */
class LocaleCodeProvider {

	public const LOCALE_GREAT_BRITAIN_ENGLISH = 'en-GB';
	public const LOCALE_GERMANY_GERMAN = 'de-DE';
	public const LOCALE_FRANCE_FRENCH = 'fr-FR';
	public const LOCALE_ITALY_ITALIAN = 'it-IT';

	/**
	 * @var \Psr\Log\LoggerInterface
	 */
	protected $logger;
	/**
	 * @var ContainerInterface
	 */
	protected $container;
	/**
	 * @var \Shopware\Core\Framework\Adapter\Translation\Translator
	 */
	protected $translator;
	/**
	 * @var EntityRepositoryInterface
	 */
	private $languageRepository;

	/**
	 * LocaleCodeProvider constructor.
	 *
	 * @param \Psr\Container\ContainerInterface                       $container
	 * @param \Shopware\Core\Framework\Adapter\Translation\Translator $translator
	 */
	public function __construct(ContainerInterface $container, Translator $translator)
	{
		$this->container          = $container;
		$this->translator         = $translator;
		$this->languageRepository = $this->container->get('language.repository');
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
	 * @param \Shopware\Core\Framework\Context $context
	 *
	 * @return string
	 */
	public function getLocaleCodeFromContext(Context $context): string
	{
		$defaultLocale = self::LOCALE_GREAT_BRITAIN_ENGLISH;
		$languageId    = $context->getLanguageId();
		/** @var \Shopware\Core\System\Language\LanguageCollection $languageCollection */
		$languageCollection = $this->languageRepository->search(
			(new Criteria([$languageId]))->addAssociation('locale'),
			$context
		)->getEntities();

		$language = $languageCollection->get($languageId);
		if (is_null($language)) {
			return $defaultLocale;
		}

		return $language->getLocale() ? $language->getLocale()->getCode() : $defaultLocale;
	}


	/**
	 * @param \Shopware\Core\Framework\Context $context
	 *
	 * @return string
	 */
	public function getDefaultLocaleCode(Context $context): string
	{
		$defaultLocale = self::LOCALE_GREAT_BRITAIN_ENGLISH;
		$languageId    = Defaults::LANGUAGE_SYSTEM;
		/** @var \Shopware\Core\System\Language\LanguageCollection $languageCollection */
		$languageCollection = $this->languageRepository->search(
			(new Criteria([$languageId]))->addAssociation('locale'),
			$context
		)->getEntities();

		$language = $languageCollection->get($languageId);
		if (is_null($language)) {
			return $defaultLocale;
		}

		return $language->getLocale() ? $language->getLocale()->getCode() : $defaultLocale;
	}

	/**
	 * Get available translations
	 *
	 * @param string                           $snippet
	 * @param string                           $fallback
	 * @param \Shopware\Core\Framework\Context $context
	 *
	 * @return array
	 */
	public function getAvailableTranslations(string $snippet, string $fallback, Context $context): array
	{
		$locales      = $this->getAvailableLocales($context);
		$translations = [];

		foreach ($locales as $locale) {
			$translation = $this->translator->trans($snippet, [], null, $locale);
			$pattern     = '/^postfinancecheckout\./';

			// there is a bug/lack of documentation on Shopware translations, sometimes the translation does not work

			if (preg_match($pattern, $translation)) { // string not translated
				$translation = $this->translator->trans($snippet, [], 'storefront', $locale);
			}

			if (preg_match($pattern, $translation)) { // string not translated
				$translation = $fallback;
			}

			$translations[$locale]['name'] = $translation;
		}

		return $translations;
	}

	/**
	 * Get all locales available
	 *
	 * @param \Shopware\Core\Framework\Context $context
	 *
	 * @return array
	 */
	public function getAvailableLocales(Context $context): array
	{
		$availableLanguages = $this->getAvailableLanguages($context);
		$locales            = array_map(function (LanguageEntity $language) {
			return $language->getLocale()->getCode();
		},
			$availableLanguages->jsonSerialize()
		);
		$locales[]          = $this->getDefaultLocaleCode($context);
		$locales[]          = self::LOCALE_GERMANY_GERMAN;
		$locales[]          = self::LOCALE_GREAT_BRITAIN_ENGLISH;
		$locales[]          = self::LOCALE_FRANCE_FRENCH;
		$locales[]          = self::LOCALE_ITALY_ITALIAN;
		$locales            = array_unique($locales);
		return $locales;
	}

	/**
	 * Get available languages
	 *
	 * @param \Shopware\Core\Framework\Context $context
	 *
	 * @return \Shopware\Core\System\Language\LanguageCollection
	 */
	public function getAvailableLanguages(Context $context): LanguageCollection
	{
		return $this->languageRepository->search((new Criteria())->addAssociations([
			'locale',
		]), $context)->getEntities();
	}
}
