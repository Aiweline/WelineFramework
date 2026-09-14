<?php

declare(strict_types=1);

namespace Weline\Customer\Service\SocialLogin;

/**
 * OAuth start/callback failed after state was read; keep storefront locale for the error redirect.
 */
final class SocialLoginOAuthFailedException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $localePrefix = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getLocalePrefix(): string
    {
        return $this->localePrefix;
    }
}
