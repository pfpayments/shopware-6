<?php

namespace PostFinanceCheckoutPayment\Core\Api\WebHooks\Struct;

use Shopware\Core\Framework\Struct\Struct;

/**
 * Class Entity
 *
 * @package PostFinanceCheckoutPayment\Core\Api\WebHooks\Struct
 */
class Entity extends Struct {

	/**
	 * @var int
	 */
	protected $id;

	/**
	 * @var string
	 */
	protected $name;

	/**
	 * @var array
	 */
	protected $states;

	/**
	 * @var bool
	 */
	protected $notifyEveryChange = false;

	/**
	 * @return int
	 */
	public function getId(): int
	{
		return $this->id;
	}

	/**
	 * @param int $id
	 * @return \PostFinanceCheckoutPayment\Core\Api\WebHooks\Struct\Entity
	 */
	public function setId(int $id): Entity
	{
		$this->id = $id;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getName(): string
	{
		return $this->name;
	}

	/**
	 * @param string $name
	 * @return \PostFinanceCheckoutPayment\Core\Api\WebHooks\Struct\Entity
	 */
	public function setName(string $name): Entity
	{
		$this->name = $name;
		return $this;
	}

	/**
	 * @return array
	 */
	public function getStates(): array
	{
		return $this->states;
	}

	/**
	 * @param array $states
	 * @return \PostFinanceCheckoutPayment\Core\Api\WebHooks\Struct\Entity
	 */
	public function setStates(array $states): Entity
	{
		$this->states = $states;
		return $this;
	}

	/**
	 * @return bool
	 */
	public function isNotifyEveryChange(): bool
	{
		return $this->notifyEveryChange;
	}

	/**
	 * @param bool $notifyEveryChange
	 * @return \PostFinanceCheckoutPayment\Core\Api\WebHooks\Struct\Entity
	 */
	public function setNotifyEveryChange(bool $notifyEveryChange): Entity
	{
		$this->notifyEveryChange = $notifyEveryChange;
		return $this;
	}


}
