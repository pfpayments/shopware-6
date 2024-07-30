<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Util\Payload;

use Psr\Log\LoggerInterface;

/**
 * Class AbstractPayload
 * 
 * @package PostFinanceCheckoutPayment\Core\Util\Payload
 */
abstract class AbstractPayload {

	/**
	 * @var \Psr\Log\LoggerInterface
	 */
	protected $logger;

	/**
	 * @param \Psr\Log\LoggerInterface $logger
	 * @internal
	 * @required
	 *
	 */
	public function setLogger(LoggerInterface $logger): void
	{
		$this->logger = $logger;
	}

	/**
	 * Fix string length string to specific length.
	 *
	 * @param string $string
	 * @param int    $maxLength
	 * @return string
	 */
	protected function fixLength(string $string, int $maxLength): string
	{
		return mb_substr($string, 0, $maxLength, 'UTF-8');
	}

	/**
	 * @param     $amount
	 * @param int $precision
	 *
	 * @return float
	 */
	public static function round(float $amount, int $precision = 2): float {
		return \round($amount, $precision);
	}

}