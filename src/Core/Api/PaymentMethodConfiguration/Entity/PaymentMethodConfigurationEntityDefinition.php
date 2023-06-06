<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Api\PaymentMethodConfiguration\Entity;

use Shopware\Core\{
	Checkout\Payment\PaymentMethodDefinition,
	Framework\DataAbstractionLayer\EntityDefinition,
	Framework\DataAbstractionLayer\Field\CreatedAtField,
	Framework\DataAbstractionLayer\Field\FkField,
	Framework\DataAbstractionLayer\Field\Flag\PrimaryKey,
	Framework\DataAbstractionLayer\Field\Flag\Required,
	Framework\DataAbstractionLayer\Field\IdField,
	Framework\DataAbstractionLayer\Field\IntField,
	Framework\DataAbstractionLayer\Field\JsonField,
	Framework\DataAbstractionLayer\Field\OneToOneAssociationField,
	Framework\DataAbstractionLayer\Field\StringField,
	Framework\DataAbstractionLayer\Field\UpdatedAtField,
	Framework\DataAbstractionLayer\FieldCollection};

/**
 * Class PaymentMethodConfigurationEntityDefinition
 *
 * @package PostFinanceCheckoutPayment\Core\Api\PaymentMethodConfiguration\Entity
 */
class PaymentMethodConfigurationEntityDefinition extends EntityDefinition {

	public const ENTITY_NAME = 'postfinancecheckout_payment_method_configuration';

	/**
	 * @return string
	 */
	public function getEntityName(): string
	{
		return self::ENTITY_NAME;
	}

	/**
	 * @return \Shopware\Core\Framework\DataAbstractionLayer\FieldCollection
	 */
	protected function defineFields(): FieldCollection
	{
		return new FieldCollection([
			(new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
			(new JsonField('data', 'data'))->addFlags(new Required()),
			(new IntField('payment_method_configuration_id', 'paymentMethodConfigurationId'))->addFlags(new Required()),
			(new FkField('payment_method_id', 'paymentMethodId', PaymentMethodDefinition::class))->addFlags(new Required()),
			(new IntField('sort_order', 'sortOrder'))->addFlags(new Required()),
			(new IntField('space_id', 'spaceId'))->addFlags(new Required()),
			(new StringField('state', 'state'))->addFlags(new Required()),
			new OneToOneAssociationField('paymentMethod', 'payment_method_id', 'id', PaymentMethodDefinition::class, true),
			new CreatedAtField(),
			new UpdatedAtField(),
		]);
	}

	/**
	 * @return string
	 */
	public function getCollectionClass(): string
	{
		return PaymentMethodConfigurationEntityCollection::class;
	}

	/**
	 * @return string
	 */
	public function getEntityClass(): string
	{
		return PaymentMethodConfigurationEntity::class;
	}

}