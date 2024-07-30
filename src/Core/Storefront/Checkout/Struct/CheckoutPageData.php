<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Storefront\Checkout\Struct;

use Shopware\Core\Framework\Struct\Struct;

/**
 * Class CheckoutPageData
 *
 * @package PostFinanceCheckoutPayment\Storefront\Checkout\Struct
 */
class CheckoutPageData extends Struct {

	/**
	 * @var string
	 */
	protected $cartRecreateUrl;

	/**
	 * @var string
	 */
	protected $checkoutUrl;
	/**
	 * @var string
	 */
	protected $deviceJavascriptUrl;
	/**
	 * @var string
	 */
	protected $integration;
	/**
	 * @var string
	 */
	protected $javascriptUrl;
	/**
	 * @var string
	 */
	protected $paymentMethodId;
	/**
	 * @var array
	 */
	protected $possiblePaymentMethodsArray = [];
	/**
	 * @var array
	 */
	protected $transactionPossiblePaymentMethods = [];

	/**
	 * @return string
	 */
	public function getCartRecreateUrl(): string
	{
		return $this->cartRecreateUrl;
	}

	/**
	 * @param string $cartRecreateUrl
	 * @return CheckoutPageData
	 */
	public function setCartRecreateUrl(string $cartRecreateUrl): CheckoutPageData
	{
		$this->cartRecreateUrl = $cartRecreateUrl;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getCheckoutUrl(): string
	{
		return $this->checkoutUrl;
	}

	/**
	 * @param string $checkoutUrl
	 * @return CheckoutPageData
	 */
	public function setCheckoutUrl(string $checkoutUrl): CheckoutPageData
	{
		$this->checkoutUrl = $checkoutUrl;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getDeviceJavascriptUrl(): string
	{
		return $this->deviceJavascriptUrl;
	}


	/**
	 * @param int    $spaceId
	 * @param string $sessionId
	 * @return \PostFinanceCheckoutPayment\Core\Storefront\Checkout\Struct\CheckoutPageData
	 */
	public function setDeviceJavascriptUrl(int $spaceId, string $sessionId): CheckoutPageData
	{
		$this->deviceJavascriptUrl = strtr('https://checkout.postfinance.ch/s/{spaceId}/payment/device.js?sessionIdentifier={sessionId}', [
			'{spaceId}'   => $spaceId,
			'{sessionId}' => $sessionId,
		]);
		return $this;
	}

	/**
	 * @return string
	 */
	public function getJavascriptUrl(): string
	{
		return $this->javascriptUrl;
	}

	/**
	 * JavaScript URL
	 *
	 * @param string $javascriptUrl
	 * @return \PostFinanceCheckoutPayment\Core\Storefront\Checkout\Struct\CheckoutPageData
	 */
	public function setJavascriptUrl(string $javascriptUrl): CheckoutPageData
	{
		$this->javascriptUrl = $javascriptUrl;
		return $this;
	}

	/**
	 * @return array
	 */
	public function getPossiblePaymentMethodsArray(): array
	{
		return $this->possiblePaymentMethodsArray;
	}

	/**
	 * @param array $possiblePaymentMethodsArray
	 * @return \PostFinanceCheckoutPayment\Core\Storefront\Checkout\Struct\CheckoutPageData
	 */
	public function setPossiblePaymentMethodsArray(array $possiblePaymentMethodsArray): CheckoutPageData
	{
		$this->possiblePaymentMethodsArray = $possiblePaymentMethodsArray;
		return $this;
	}

	/**
	 * @return array
	 */
	public function getTransactionPossiblePaymentMethods(): array
	{
		return $this->transactionPossiblePaymentMethods;
	}

	/**
	 * @param array $transactionPossiblePaymentMethods
	 * @return \PostFinanceCheckoutPayment\Core\Storefront\Checkout\Struct\CheckoutPageData
	 */
	public function setTransactionPossiblePaymentMethods(array $transactionPossiblePaymentMethods): CheckoutPageData
	{
		$this->transactionPossiblePaymentMethods = $transactionPossiblePaymentMethods;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getIntegration(): string
	{
		return $this->integration;
	}

	/**
	 * @param string $integration
	 * @return \PostFinanceCheckoutPayment\Core\Storefront\Checkout\Struct\CheckoutPageData
	 */
	public function setIntegration(string $integration): CheckoutPageData
	{
		$this->integration = $integration;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getPaymentMethodId(): string
	{
		return $this->paymentMethodId;
	}

	/**
	 * Payment method id from Shopware database
	 *
	 * @param string $paymentMethodId
	 * @return \PostFinanceCheckoutPayment\Core\Storefront\Checkout\Struct\CheckoutPageData
	 */
	public function setPaymentMethodId(string $paymentMethodId): CheckoutPageData
	{
		$this->paymentMethodId = $paymentMethodId;
		return $this;
	}
}