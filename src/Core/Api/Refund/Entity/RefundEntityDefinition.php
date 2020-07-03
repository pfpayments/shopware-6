<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Api\Refund\Entity;

use Shopware\Core\{
	Framework\DataAbstractionLayer\EntityDefinition,
	Framework\DataAbstractionLayer\Field\CreatedAtField,
	Framework\DataAbstractionLayer\Field\Flag\PrimaryKey,
	Framework\DataAbstractionLayer\Field\Flag\Required,
	Framework\DataAbstractionLayer\Field\IdField,
	Framework\DataAbstractionLayer\Field\IntField,
	Framework\DataAbstractionLayer\Field\JsonField,
	Framework\DataAbstractionLayer\Field\ManyToOneAssociationField,
	Framework\DataAbstractionLayer\Field\StringField,
	Framework\DataAbstractionLayer\Field\UpdatedAtField,
	Framework\DataAbstractionLayer\FieldCollection};
use PostFinanceCheckoutPayment\Core\Api\Transaction\Entity\TransactionEntityDefinition;

/**
 * Class RefundEntityDefinition
 *
 * @package PostFinanceCheckoutPayment\Core\Api\Refund\Entity
 */
class RefundEntityDefinition extends EntityDefinition {

	public const ENTITY_NAME = 'postfinancecheckout_refund';

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
			(new IntField('refund_id', 'refundId'))->addFlags(new Required()),
			(new IntField('space_id', 'spaceId'))->addFlags(new Required()),
			(new StringField('state', 'state'))->addFlags(new Required()),
			(new IntField('transaction_id', 'transactionId'))->addFlags(new Required()),
			new ManyToOneAssociationField('transaction', 'transaction_id', TransactionEntityDefinition::class, 'transaction_id'),
			new CreatedAtField(),
			new UpdatedAtField(),
		]);
	}

	/**
	 * @return string
	 */
	public function getCollectionClass(): string
	{
		return RefundEntityCollection::class;
	}

	/**
	 * @return string
	 */
	public function getEntityClass(): string
	{
		return RefundEntity::class;
	}

}