<?php

declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Util\Payload\CustomFields;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\{
    Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity,
    Checkout\Order\OrderEntity,
    Framework\DataAbstractionLayer\Search\Criteria,
    Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter,
    Framework\Uuid\Uuid,
    System\SalesChannel\SalesChannelContext,
};
use PostFinanceCheckout\Sdk\{
    Model\LineItemAttributeCreate,
};
use PostFinanceCheckoutPayment\Core\{
    Util\Exception\InvalidPayloadException,
    Util\LocaleCodeProvider
};

/**
 * Class ProductCustomFieldAttributeService
 *
 * Converts allow-listed product custom fields into line item attributes.
 *
 * @package WalleePayment\Core\Util\Payload\CustomFields
 */
class ProductCustomFieldAttributeService
{
    private const DEFAULT_LANG = 'en-GB';

    /**
     * @var \Psr\Container\ContainerInterface
     */
    private $container;

    /**
     * @var \Psr\Log\LoggerInterface|null
     */
    private $logger = null;

    /**
     * @var \Shopware\Core\System\SalesChannel\SalesChannelContext
     */
    private $salesChannelContext;

    /**
     * @var \WalleePayment\Core\Util\LocaleCodeProvider
     */
    private $localeCodeProvider;

    /**
     * @var \WalleePayment\Core\Checkout\Order\OrderEntity|null
     */
    private $order = null;

    /**
     * @var array
     */
    private $allowFields;

    /**
     * ProductCustomFieldAttributeService constructor.
     *
     * @param \Psr\Container\ContainerInterface $container
     * @param \Psr\Log\LoggerInterface|null $logger
     * @param \Shopware\Core\System\SalesChannel\SalesChannelContext $salesChannelContext
     * @param \WalleePayment\Core\Util\LocaleCodeProvider $localeCodeProvider
     * @param \Shopware\Core\Checkout\Order\OrderEntity|null $order
     * @param array $allowFields
     */
    public function __construct(
        ContainerInterface $container,
        ?LoggerInterface $logger = null,
        SalesChannelContext $salesChannelContext,
        LocaleCodeProvider $localeCodeProvider,
        ?OrderEntity $order = null,
        array $allowFields
    ) {
        $this->container = $container;
        $this->logger = $logger;
        $this->salesChannelContext = $salesChannelContext;
        $this->localeCodeProvider = $localeCodeProvider;
        $this->order = $order;
        $this->allowFields = $allowFields;
    }

    /**
     * @param \Psr\Log\LoggerInterface $logger
     *
     * @internal
     * @required
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     *
     * @return array<string, array<string, \PostFinanceCheckout\Sdk\Model\LineItemAttributeCreate>>
     *
     * @throws \PostFinanceCheckoutPayment\Core\Util\Exception\InvalidPayloadException
     */
    public function buildProductCustomFieldAttributes(): array
    {
        $this->allowFields = array_values(array_filter($this->allowFields));

        if (empty($this->allowFields)) {
            return [];
        }

        $allowed = array_flip($this->allowFields);
        $customFields = [];

        foreach ($this->order->getLineItems() as $lineItem) {
            $lineItemFields = array_intersect_key($this->getCustomFields($lineItem), $allowed);

            if (!empty($lineItemFields)) {
                $customFields[$lineItem->getId()] = $lineItemFields;
            }
        }

        if (empty($customFields)) {
            return [];
        }

        $definitions = $this->loadDefinitions();
        $entityLabels = $this->resolveReferences($customFields, $definitions);

        $attributes = [];

        foreach ($customFields as $lineItemId => $fields) {
            foreach ($fields as $name => $rawValue) {
                $definition = $definitions[(string) $name] ?? [];
                $displayValue = $this->toDisplayValue($rawValue, $definition, $entityLabels);
                $value = $this->stringifyValue($displayValue);

                if ($value === null || $value === '') {
                    continue;
                }

                $key = mb_substr('customField_' . md5($name), 0, 40, 'UTF-8');

                $attributes[$lineItemId][$key] = $this->createAttribute(
                    $definition['label'] ?? $name,
                    $value,
                    $name,
                    $lineItemId
                );
            }
        }
        return $attributes;
    }

    /**
     * Custom fields are retrieved from the line item product. Live product is used as a fallback.
     *
     * @param OrderLineItemEntity $lineItem
     *
     * @return array<string, mixed>
     */
    private function getCustomFields(OrderLineItemEntity $lineItem): array
    {
        $customFields = [];
        $product = $lineItem->getProduct();

        if ($product !== null) {
            $customFields = $product->getTranslation('customFields') ?? $product->getCustomFields() ?? [];
        }

        if (!is_array($customFields)) {
            $customFields = [];
        }

        $payload = $lineItem->getPayload();

        if (is_array($payload) && !empty($payload['customFields']) && is_array($payload['customFields'])) {
            $customFields = array_merge($customFields, $payload['customFields']);
        }

        return $customFields;
    }

    /**
     * Loads custom field definitions for the entire order. Fetches translated
     * labels, option lists for select fields, and referenced entity names for
     * entity and media fields.
     *
     * @return array<string, array>
     */
    private function loadDefinitions(): array
    {
        $definitions = [];
        $customFields = [];
        try {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsAnyFilter('name', $this->allowFields));
            $customFields = $this->container->get('custom_field.repository')
                ->search($criteria, $this->salesChannelContext->getContext())
                ->getEntities();
        } catch (\Throwable $e) {
            $this->logger->warning(
                "Could not load product custom field definitions: " .
                $e->getMessage()
            );
            return [];
        }

        foreach ($customFields as $customField) {
            $config = is_array($customField->getConfig()) ? $customField->getConfig() : [];
            $label = $config['label'] ?? null;

            if (is_array($label)) {
                $label = $label[$this->getLanguage()] ?? $label[self::DEFAULT_LANG] ?? null;
            }
            $definitions[$customField->getName()] = [
                'label' => (is_string($label) && trim($label) !== '') ? trim($label) : $customField->getName(),
                'options' => is_array($config['options'] ?? null) ? $config['options'] : [],
                'entity' => $this->resolveEntityName($config),
            ];
        }

        return $definitions;
    }

    /**
     * Fetchs entity field names from config.
     *
     * @param array $config
     *
     * @return string|null
     */
    private function resolveEntityName(array $config): ?string
    {
        if (is_string($config['entity'] ?? null) && $config['entity'] !== '') {
            return $config['entity'];
        }

        $type = (string) ($config['customFieldType'] ?? '');
        $component = (string) ($config['componentName'] ?? '');

        return ($type === 'media' || strpos($component, 'media') !== false) ? 'media' : null;
    }

    /**
     * Collects every referenced ID in the order and resolves them with one query
     * per entity type.
     *
     * @param array<string, array> $customFields
     * @param array<string, array> $definitions
     *
     * @return array<string, array>
     */
    private function resolveReferences(array $customFields, array $definitions): array
    {
        $idsByEntity = [];

        foreach ($customFields as $fields) {
            foreach ($fields as $name => $value) {
                $entityName = $definitions[(string) $name]['entity'] ?? null;

                if ($entityName !== null) {
                    $idsByEntity[$entityName] = array_merge($idsByEntity[$entityName] ?? [], $this->toIds($value));
                }
            }
        }

        $labels = [];
        foreach ($idsByEntity as $entityName => $ids) {
            $ids = array_values(array_unique($ids));
            if ($ids !== []) {
                $labels[$entityName] = $this->loadEntityLabels((string) $entityName, $ids);
            }
        }
        return $labels;
    }

    /**
     * Loads entity labels.
     *
     * @param string $entityName
     * @param array $ids
     *
     * @return array<string, string>
     */

    private function loadEntityLabels(string $entityName, array $ids): array
    {
        $labels = [];
        $repositoryId = $entityName . '.repository';
        if (!$this->container->has($repositoryId)) {
            $this->logger->warning("No repository for custom field entity " . $entityName . ".");
            return [];
        }

        try {
            $entities = $this->container->get($repositoryId)
                ->search(new Criteria($ids), $this->salesChannelContext->getContext())
                ->getEntities();

            foreach ($entities as $entity) {
                $label = $this->extractEntityLabel($entity);
                if ($label !== null) {
                    $labels[$entity->getId()] = $label;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                "Could not resolve names for custom field entity " . $entityName
                . ", raw IDs will be transmitted: " . $e->getMessage()
            );
        }
        return $labels;
    }

    /**
     * Since DAL has no universal "readable name", the usual accessors are
     * tried in order. Anything without one keeps its raw ID.
     *
     * @param mixed $entity
     *
     * @return string|null
     */
    private function extractEntityLabel($entity): ?string
    {
        if (!is_object($entity)) {
            return null;
        }

        if (method_exists($entity, 'getTranslation')) {
            $name = $entity->getTranslation('name');

            if (is_string($name) && trim($name) !== '') {
                return trim($name);
            }
        }

        foreach (['getName', 'getTitle', 'getLabel', 'getFileName', 'getProductNumber'] as $accessor) {

            if (!method_exists($entity, $accessor)) {
                continue;
            }

            $value = $entity->{$accessor}();

            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            if ($accessor === 'getFileName' && method_exists($entity, 'getFileExtension')) {
                $extension = $entity->getFileExtension();

                if (is_string($extension) && $extension !== '') {
                    return trim($value) . '.' . $extension;
                }
            }

            return trim($value);
        }
        return null;
    }

    /**
     * Replaces the stored keys or IDs with something readable, before the value is
     * turned into text.
     *
     * @param mixed $value
     * @param array $definition
     * @param array<string, array<> $entityLabels
     *
     * @return mixed
     */
    private function toDisplayValue($value, array $definition, array $entityLabels)
    {
        if (!empty($definition['entity'])) {
            return $this->applyEntityLabels($value, $entityLabels[$definition['entity']] ?? []);
        }

        if (!empty($definition['options'])) {
            return $this->applyOptionLabels($value, $definition['options']);
        }

        return $value;
    }

    /**
     * @param mixed $value
     * @param array<string, string> $labels
     *
     * @return mixed
     */
    public function applyEntityLabels($value, array $labels)
    {
        if (is_array($value)) {
            return array_map(function ($item) use ($labels) {
                return $this->applyEntityLabels($item, $labels);
            }, $value);
        }

        return (is_string($value) && isset($labels[$value])) ? $labels[$value] : $value;
    }

    /**
     * Selects store the option key ("ch"), not the label ("Switzerland"). Both are
     * emitted: the label for humans, the key for anything parsing the payload.
     *
     * @param mixed $value
     * @param array $options
     *
     * @return mixed
     */
    public function applyOptionLabels($value, array $options)
    {
        if ($options === []) {
            return $value;
        }

        $language = $this->getLanguage();

        if (is_array($value)) {
            return array_map(function ($item) use ($options, $language) {
                return $this->applyOptionLabels($item, $options);
            }, $value);
        }

        if (!is_scalar($value) || is_bool($value)) {
            return $value;
        }

        $value = (string) $value;

        foreach ($options as $option) {
            if (!is_array($option) || (string) ($option['value'] ?? null) !== $value) {
                continue;
            }

            $label = $option['label'] ?? null;

            if (is_array($label)) {
                $label = $label[$language] ?? $label[self::DEFAULT_LANG] ?? (reset($label) ?: null);
            }

            if (is_string($label) && trim($label) !== '' && trim($label) !== $value) {
                return sprintf('%s (%s)', trim($label), $value);
            }

            break;
        }

        return $value;
    }

    /**
     * Renders a custom field value as text.
     *
     * @param mixed $value
     * 
     * @return string|null
     */
    public function stringifyValue($value): ?string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DATE_ATOM);
        }

        if (is_object($value)) {
            $decoded = json_decode((string) json_encode($value), true);
            $value   = is_array($decoded) ? $decoded : null;
        }

        if (!is_array($value)) {
            return null;
        }

        unset($value['extensions']);

        if (count($value) === 1 && is_array($value['elements'] ?? null)) {
            $value = $value['elements'];
        }

        if (is_numeric($value['gross'] ?? null)) {
            return number_format((float) $value['gross'], 2, '.', '');
        }

        if ($value === array_values($value)) {
            $parts = [];

            foreach ($value as $item) {
                $part = $this->stringifyValue($item);

                if ($part !== null && $part !== '') {
                    $parts[] = $part;
                }
            }

            return $parts === [] ? null : implode(', ', $parts);
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? null : $encoded;
    }

    /**
     * @param mixed $value
     *
     * @return array
     */
    private function toIds($value): array
    {
        $ids = [];
        foreach (is_array($value) ? $value : [$value] as $item) {
            if (is_string($item) && Uuid::isValid(trim($item))) {
                $ids[] = trim($item);
            }
        }
        return $ids;
    }

    /**
     *
     * @param mixed $label
     * @param mixed $value
     * @param mixed $fieldName
     * @param mixed $lineItemId
     *
     * @return LineItemAttributeCreate
     *
     * @throws \PostFinanceCheckoutPayment\Core\Util\Exception\InvalidPayloadException
     */
    private function createAttribute(string $label, string $value, string $fieldName, string $lineItemId): LineItemAttributeCreate
    {
        $attribute = (new LineItemAttributeCreate())
            ->setLabel(mb_substr($label, 0, 512, 'UTF-8'))
            ->setValue(mb_substr($value, 0, 512, 'UTF-8'));

        if (!$attribute->valid()) {
            $this->logger->critical('LineItemAttributeCreate payload invalid:', $attribute->listInvalidProperties());

            throw new InvalidPayloadException('LineItemAttributeCreate payload invalid:' . json_encode($attribute->listInvalidProperties()));
        }

        return $attribute;
    }

    /**
     *
     * @return string
     */
    private function getLanguage(): string
    {
        $language = $this->localeCodeProvider->getLocaleCodeFromContext($this->salesChannelContext->getContext());
        return (is_string($language) && $language !== '') ? $language : self::DEFAULT_LANG;
    }
}