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
	 * Per-request cache of resolved locale codes, keyed by language ID.
	 *
	 * Resolving a locale is a DAL query. A single checkout request asks for it repeatedly (payload
	 * fingerprinting, transaction create/update, payment page locale), and the answer cannot change
	 * within a request. Cleared between requests through the kernel.reset tag.
	 *
	 * @var array<string, string>
	 */
	private array $localeCodeCache = [];

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

		if (isset($this->localeCodeCache[$languageId])) {
			return $this->localeCodeCache[$languageId];
		}

		/** @var \Shopware\Core\System\Language\LanguageCollection $languageCollection */
		$languageCollection = $this->languageRepository->search(
			(new Criteria([$languageId]))->addAssociation('locale'),
			$context
		)->getEntities();

		$language = $languageCollection->get($languageId);
		if (is_null($language)) {
			return $this->localeCodeCache[$languageId] = $defaultLocale;
		}

		return $this->localeCodeCache[$languageId] = ($language->getLocale() ? $language->getLocale()->getCode() : $defaultLocale);
	}

	/**
	 * Drops the per-request locale cache.
	 *
	 * Invoked by Symfony between requests via the kernel.reset tag.
	 */
	public function reset(): void
	{
		$this->localeCodeCache = [];
	}

	/**
	 *  Maps a locale code to a PostFinanceCheckout-supported payment page locale by matching the language prefix.
	 *  E.g. de-CH -> de-DE, fr-CH -> fr-FR, en-US -> en-GB, it-CH -> it-IT.
	 *
	 * @param string $localeCode
	 * @return string
	 */
	public function mapToPaymentPageLocale(string $localeCode): string
	{
		$supportedLocales = [
			'de' => self::LOCALE_GERMANY_GERMAN,
			'fr' => self::LOCALE_FRANCE_FRENCH,
			'it' => self::LOCALE_ITALY_ITALIAN,
			'en' => self::LOCALE_GREAT_BRITAIN_ENGLISH,
		];

		$languagePrefix = substr($localeCode, 0, 2);

		return $supportedLocales[$languagePrefix] ?? self::LOCALE_GREAT_BRITAIN_ENGLISH;
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
