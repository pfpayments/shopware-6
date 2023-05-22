<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Api\Transaction\Entity;

use Shopware\Core\{Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition,
    Checkout\Order\OrderDefinition,
    Checkout\Payment\PaymentMethodDefinition,
    Framework\DataAbstractionLayer\EntityDefinition,
    Framework\DataAbstractionLayer\Field\BoolField,
    Framework\DataAbstractionLayer\Field\CreatedAtField,
    Framework\DataAbstractionLayer\Field\FkField,
    Framework\DataAbstractionLayer\Field\Flag\ApiAware,
    Framework\DataAbstractionLayer\Field\Flag\CascadeDelete,
    Framework\DataAbstractionLayer\Field\Flag\PrimaryKey,
    Framework\DataAbstractionLayer\Field\Flag\Required,
    Framework\DataAbstractionLayer\Field\IdField,
    Framework\DataAbstractionLayer\Field\IntField,
    Framework\DataAbstractionLayer\Field\JsonField,
    Framework\DataAbstractionLayer\Field\OneToManyAssociationField,
    Framework\DataAbstractionLayer\Field\OneToOneAssociationField,
    Framework\DataAbstractionLayer\Field\ReferenceVersionField,
    Framework\DataAbstractionLayer\Field\StringField,
    Framework\DataAbstractionLayer\Field\UpdatedAtField,
    Framework\DataAbstractionLayer\FieldCollection,
    System\SalesChannel\SalesChannelDefinition};
use PostFinanceCheckoutPayment\Core\Api\Refund\Entity\RefundEntityDefinition;

/**
 * Class TransactionEntityDefinition
 *
 * @package PostFinanceCheckoutPayment\Core\Api\Transaction\Entity
 */
class TransactionEntityDefinition extends EntityDefinition {

	public const ENTITY_NAME = 'postfinancecheckout_transaction';

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
			new BoolField('confirmation_email_sent', 'confirmationEmailSent'),
			new StringField('erp_merchant_id', 'erpMerchantId'),
			(new JsonField('data', 'data'))->addFlags(new Required()),
			(new FkField('payment_method_id', 'paymentMethodId', PaymentMethodDefinition::class))->addFlags(new Required()),
			(new FkField('order_id', 'orderId', OrderDefinition::class))->addFlags(new Required()),
			(new FkField('order_transaction_id', 'orderTransactionId', OrderTransactionDefinition::class))->addFlags(new Required()),
			(new IntField('space_id', 'spaceId'))->addFlags(new Required()),
			(new StringField('state', 'state'))->addFlags(new Required()),
			(new FkField('sales_channel_id', 'salesChannelId', SalesChannelDefinition::class))->addFlags(new Required()),
			(new IntField('transaction_id', 'transactionId'))->addFlags(new Required()),
			new OneToOneAssociationField('paymentMethod', 'payment_method_id', 'id', PaymentMethodDefinition::class, true),
			new OneToOneAssociationField('order', 'order_id', 'id', OrderDefinition::class, true),
			new OneToOneAssociationField('orderTransaction', 'order_transaction_id', 'id', OrderTransactionDefinition::class, true),
			(new OneToManyAssociationField('refunds', RefundEntityDefinition::class, 'transaction_id', 'transaction_id'))->addFlags(new CascadeDelete()),
			new OneToOneAssociationField('salesChannel', 'sales_channel_id', 'id', SalesChannelDefinition::class, true),
            (new ReferenceVersionField(OrderDefinition::class))->addFlags(new ApiAware(), new Required()),
            new CreatedAtField(),
			new UpdatedAtField(),
		]);
	}

	/**
	 * @return string
	 */
	public function getCollectionClass(): string
	{
		return TransactionEntityCollection::class;
	}

	/**
	 * @return string
	 */
	public function getEntityClass(): string
	{
		return TransactionEntity::class;
	}

}
