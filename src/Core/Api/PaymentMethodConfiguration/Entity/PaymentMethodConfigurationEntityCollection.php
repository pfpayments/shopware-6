<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Api\PaymentMethodConfiguration\Entity;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * Class PaymentMethodConfigurationEntityCollection
 *
 * @package PostFinanceCheckoutPayment\Core\Api\PaymentMethodConfiguration\Entity
 *
 * @method void              add(PaymentMethodConfigurationEntity $entity)
 * @method void              set(string $key, PaymentMethodConfigurationEntity $entity)
 * @method PaymentMethodConfigurationEntity[]    getIterator()
 * @method PaymentMethodConfigurationEntity[]    getElements()
 * @method PaymentMethodConfigurationEntity|null get(string $key)
 * @method PaymentMethodConfigurationEntity|null first()
 * @method PaymentMethodConfigurationEntity|null last()
 */
class PaymentMethodConfigurationEntityCollection extends EntityCollection {

	/**
	 * @return string
	 */
	protected function getExpectedClass(): string
	{
		return PaymentMethodConfigurationEntity::class;
	}
}