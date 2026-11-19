<?php

declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\PluginCore\Exception;

/**
 * Thrown when a sales channel is missing its PostFinanceCheckout space ID, user ID or application key.
 *
 * It is thrown when the credentials are first needed, not when the services are created.
 * The reason is that code which does not call the portal keeps working for channels
 * without PostFinanceCheckout configured.
 */
class PluginCoreNotConfiguredException extends \RuntimeException
{
    /**
     * Creates the exception for the given sales channel.
     *
     * @param string|null $salesChannelId
     * @param \Throwable $previous
     * @return self
     */
    public static function forSalesChannel(?string $salesChannelId, \Throwable $previous): self
    {
        return new self(
            sprintf(
                'PostFinanceCheckout is not configured for sales channel "%s": %s',
                $salesChannelId ?? 'default (all sales channels)',
                $previous->getMessage(),
            ),
            0,
            $previous,
        );
    }
}
