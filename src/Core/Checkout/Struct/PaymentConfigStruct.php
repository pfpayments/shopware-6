<?php

declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Checkout\Struct;

use Shopware\Core\Framework\Struct\Struct;

/**
 * This struct encapsulates all the configuration data required to initialize a WhitelabelMachineName payment integration.
 */
class PaymentConfigStruct extends Struct
{
    /**
     * @var string
     * The type of integration (e.g., 'iframe' or 'lightbox').
     */
    protected string $integration;

    /**
     * @var string
     * The absolute URL to the WhitelabelMachineName JavaScript component for transaction handling.
     */
    protected string $javascriptUrl;

    /**
     * @var string
     * The absolute URL to the WhitelabelMachineName JavaScript component for device tracking.
     */
    protected string $deviceJavascriptUrl;

    /**
     * @var array
     * A list of possible payment methods for the current transaction.
     */
    protected array $transactionPossiblePaymentMethods;

    /**
     * @var int
     * The unique ID of the WhitelabelMachineName transaction.
     */
    protected int $transactionId;

    /**
     * @var int
     * The unique ID of the WhitelabelMachineName space.
     */
    protected int $spaceId;

    /**
     * @var string|null
     * The URL to redirect to if the cart needs to be recreated (e.g., after an error).
     */
    protected ?string $cartRecreateUrl = null;

    /**
     * @var string|null
     * The URL to the checkout confirmation page.
     */
    protected ?string $checkoutUrl = null;

    /**
     * @return string
     */
    public function getIntegration(): string
    {
        return $this->integration;
    }

    /**
     * @param string $integration
     * @return self
     */
    public function setIntegration(string $integration): self
    {
        $this->integration = $integration;
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
     * @param string $javascriptUrl
     * @return self
     */
    public function setJavascriptUrl(string $javascriptUrl): self
    {
        $this->javascriptUrl = $javascriptUrl;
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
     * @param string $deviceJavascriptUrl
     * @return self
     */
    public function setDeviceJavascriptUrl(string $deviceJavascriptUrl): self
    {
        $this->deviceJavascriptUrl = $deviceJavascriptUrl;
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
     * @return self
     */
    public function setTransactionPossiblePaymentMethods(array $transactionPossiblePaymentMethods): self
    {
        $this->transactionPossiblePaymentMethods = $transactionPossiblePaymentMethods;
        return $this;
    }

    /**
     * @return int
     */
    public function getTransactionId(): int
    {
        return $this->transactionId;
    }

    /**
     * @param int $transactionId
     * @return self
     */
    public function setTransactionId(int $transactionId): self
    {
        $this->transactionId = $transactionId;
        return $this;
    }

    /**
     * @return int
     */
    public function getSpaceId(): int
    {
        return $this->spaceId;
    }

    /**
     * @param int $spaceId
     * @return self
     */
    public function setSpaceId(int $spaceId): self
    {
        $this->spaceId = $spaceId;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getCartRecreateUrl(): ?string
    {
        return $this->cartRecreateUrl;
    }

    /**
     * @param string|null $cartRecreateUrl
     * @return self
     */
    public function setCartRecreateUrl(?string $cartRecreateUrl): self
    {
        $this->cartRecreateUrl = $cartRecreateUrl;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getCheckoutUrl(): ?string
    {
        return $this->checkoutUrl;
    }

    /**
     * @param string|null $checkoutUrl
     * @return self
     */
    public function setCheckoutUrl(?string $checkoutUrl): self
    {
        $this->checkoutUrl = $checkoutUrl;
        return $this;
    }
}
