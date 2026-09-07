<?php

declare(strict_types=1);

namespace Weline\Mail\Service;

use Weline\SystemConfig\Api\ConfigReader;

/**
 * Frontend mailbox register feature flags.
 * Storefront login always uses Customer account login (no dedicated mailbox login UI).
 */
final class MailFrontendFeatureConfig
{
    public const MODULE = 'Weline_Mail';
    public const KEY_REGISTER_ENABLED = 'mail/frontend_register/enabled';
    public const KEY_AUX_VERIFY = 'mail/frontend_register/aux_email_verify';

    public function __construct(private readonly ConfigReader $config)
    {
    }

    public function isRegisterEnabled(): bool
    {
        return $this->bool(self::KEY_REGISTER_ENABLED, false);
    }

    public function isAuxEmailVerifyEnabled(): bool
    {
        return $this->bool(self::KEY_AUX_VERIFY, true);
    }

    private function bool(string $key, bool $default): bool
    {
        try {
            $raw = $this->config->get($key, self::MODULE, ConfigReader::area_FRONTEND, null);
            if ($raw === null || $raw === '') {
                return $default;
            }
            $parsed = filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

            return $parsed ?? $default;
        } catch (\Throwable) {
            return $default;
        }
    }
}
