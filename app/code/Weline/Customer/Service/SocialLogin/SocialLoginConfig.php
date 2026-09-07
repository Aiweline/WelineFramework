<?php

declare(strict_types=1);

namespace Weline\Customer\Service\SocialLogin;

use Weline\SystemConfig\Api\ConfigReader;

/**
 * Storefront social-login OAuth credentials and activation from SystemConfig.
 */
class SocialLoginConfig
{
    public const MODULE = 'Weline_Customer';
    public const AREA = ConfigReader::area_FRONTEND;

    public function __construct(private readonly ConfigReader $config)
    {
    }

    public function clientId(string $provider): string
    {
        return $this->string($provider . '/client_id');
    }

    public function clientSecret(string $provider): string
    {
        return $this->string($provider . '/client_secret');
    }

    public function isConfigured(string $provider): bool
    {
        return $this->clientId($provider) !== '' && $this->clientSecret($provider) !== '';
    }

    /**
     * Merchant "activate storefront entry" switch (ignored unless credentials exist).
     */
    public function isEnabled(string $provider): bool
    {
        return $this->bool($provider . '/enabled');
    }

    /**
     * Storefront may expose the provider only when configured and activated.
     */
    public function isActive(string $provider): bool
    {
        return $this->isConfigured($provider) && $this->isEnabled($provider);
    }

    /**
     * Auto prompt (Google One Tap / Facebook JS popup) when provider is active.
     * Defaults to enabled; merchant may turn off per provider.
     */
    public function isQuickPromptEnabled(string $provider): bool
    {
        if (!$this->isActive($provider)) {
            return false;
        }
        $raw = $this->config->get(
            'customer/social_login/' . strtolower(trim($provider)) . '/quick_prompt',
            self::MODULE,
            self::AREA,
            '1'
        );

        return $this->boolFromRaw($raw, true);
    }

    /**
     * Optional outbound proxy for provider token/userinfo HTTP (shared by all providers).
     */
    public function httpProxy(): string
    {
        return trim((string) $this->config->get(
            SocialLoginOutboundProxy::CONFIG_KEY_PROXY,
            self::MODULE,
            self::AREA,
            ''
        ));
    }

    /**
     * Proxy type: http|socks5.
     */
    public function httpProxyType(): string
    {
        $type = strtolower(trim((string) $this->config->get(
            SocialLoginOutboundProxy::CONFIG_KEY_TYPE,
            self::MODULE,
            self::AREA,
            'http'
        )));

        return in_array($type, ['http', 'socks5'], true) ? $type : 'http';
    }

    private function string(string $key): string
    {
        return trim((string) $this->config->get(
            'customer/social_login/' . $key,
            self::MODULE,
            self::AREA,
            ''
        ));
    }

    private function bool(string $key): bool
    {
        return $this->boolFromRaw(
            $this->config->get(
                'customer/social_login/' . $key,
                self::MODULE,
                self::AREA,
                '0'
            ),
            false
        );
    }

    private function boolFromRaw(mixed $raw, bool $default): bool
    {
        if ($raw === null || $raw === '') {
            return $default;
        }
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_int($raw) || is_float($raw)) {
            return (int) $raw === 1;
        }
        $normalized = strtolower(trim((string) $raw));

        return in_array($normalized, ['1', 'true', 'on', 'yes'], true);
    }
}
