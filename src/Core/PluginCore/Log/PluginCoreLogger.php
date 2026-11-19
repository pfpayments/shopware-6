<?php

declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\PluginCore\Log;

use Psr\Log\LoggerInterface as PsrLoggerInterface;
use Psr\Log\LoggerTrait;
use PostFinanceCheckout\PluginCore\Log\LoggerInterface;

/**
 * Forwards plugin-core log records to the plugin's Monolog channel.
 *
 * It exists because plugin-core requires its own LoggerInterface, which
 * Monolog does not implement.
 */
class PluginCoreLogger implements LoggerInterface
{
    use LoggerTrait;

    /**
     * @param PsrLoggerInterface $delegate
     * @param array<string, mixed> $context
     */
    public function __construct(
        private readonly PsrLoggerInterface $delegate,
        private readonly array $context = [],
    ) {
    }

    /**
     * Returns a copy of this logger that adds the given context to every record.
     *
     * @param array<string, mixed> $context
     * @return self
     */
    public function withContext(array $context): self
    {
        return new self($this->delegate, $context + $this->context);
    }

    /**
     * Writes a record to the Monolog channel, adding this logger's context.
     *
     * @param mixed $level
     * @param string|\Stringable $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->delegate->log($level, $message, $context + $this->context);
    }

    /**
     * Returns the class name.
     *
     * Required by plugin-core's LoggerInterface when psr/log is not installed.
     *
     * @return string
     */
    public function __toString(): string
    {
        return self::class;
    }
}
