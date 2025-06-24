<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Checkout\Cart;

use Shopware\Core\Checkout\Cart\AbstractCartPersister;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use PostFinanceCheckoutPayment\Core\Checkout\PaymentHandler\PostFinanceCheckoutPaymentHandler;

class CustomCartPersister extends AbstractCartPersister
{
	private AbstractCartPersister $inner;

	public function __construct(AbstractCartPersister $inner)
	{
		$this->inner = $inner;
	}

	public function delete(string $token, SalesChannelContext $context): void
	{
		if (!$context->getContext()->hasState('do-cart-delete') && $this->isWhiteLabelPayment($context)) {
			return;
		}

		$this->inner->delete($token, $context);
	}

	public function load(string $token, SalesChannelContext $context): Cart
	{
		return $this->inner->load($token, $context);
	}

	public function save(Cart $cart, SalesChannelContext $context): void
	{
		$this->inner->save($cart, $context);
	}

	public function replace(string $oldToken, string $newToken, SalesChannelContext $context): void
	{
		$this->inner->replace($oldToken, $newToken, $context);
	}

	public function getDecorated(): AbstractCartPersister
	{
		return $this->inner;
	}

	private function isWhiteLabelPayment(SalesChannelContext $context): bool
	{
		$paymentMethod = $context->getPaymentMethod();

		if (!$paymentMethod instanceof PaymentMethodEntity) {
			return false;
		}

		return $paymentMethod->getHandlerIdentifier() === PostFinanceCheckoutPaymentHandler::class;
	}
}
