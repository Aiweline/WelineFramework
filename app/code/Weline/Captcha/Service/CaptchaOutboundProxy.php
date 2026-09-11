<?php

declare(strict_types=1);

namespace Weline\Captcha\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Api\ConfigReader;

/**
 * Resolve optional outbound HTTP/SOCKS proxy for Google reCAPTCHA Enterprise assessment.
 *
 * Priority:
 * 1. SystemConfig `captcha/http/proxy` (Captcha-owned)
 * 2. Shared social-login punch `customer/social_login/http_proxy` (same tunnel merchants already set)
 * 3. Process env HTTPS_PROXY / HTTP_PROXY / ALL_PROXY
 *
 * Empty means direct connect. PHP curl does not honor env proxies unless CURLOPT_PROXY is set.
 */
final class CaptchaOutboundProxy
{
    public const CONFIG_KEY_PROXY = 'captcha/http/proxy';
    public const CONFIG_KEY_TYPE = 'captcha/http/proxy_type';
    public const SHARED_SOCIAL_PROXY_KEY = 'customer/social_login/http_proxy';
    public const SHARED_SOCIAL_PROXY_TYPE_KEY = 'customer/social_login/http_proxy_type';
    public const CAPTCHA_MODULE = 'Weline_Captcha';
    public const SOCIAL_MODULE = 'Weline_Customer';

    /**
     * @return array{proxy:string,type:string,userpwd:string}
     */
    public static function resolve(?ConfigReader $config = null): array
    {
        $fromCaptcha = self::fromCaptchaConfig($config);
        if ($fromCaptcha['proxy'] !== '') {
            return $fromCaptcha;
        }

        $fromSocial = self::fromSocialLoginConfig($config);
        if ($fromSocial['proxy'] !== '') {
            return $fromSocial;
        }

        return self::fromEnv();
    }

    /**
     * @param \CurlHandle|resource $ch
     */
    public static function apply($ch, ?ConfigReader $config = null): void
    {
        $proxy = self::resolve($config);
        if ($proxy['proxy'] === '') {
            return;
        }

        \curl_setopt($ch, \CURLOPT_PROXY, $proxy['proxy']);
        if (($proxy['type'] ?? 'http') === 'socks5') {
            \curl_setopt($ch, \CURLOPT_PROXYTYPE, \CURLPROXY_SOCKS5_HOSTNAME);
        }
        if ($proxy['userpwd'] !== '') {
            \curl_setopt($ch, \CURLOPT_PROXYUSERPWD, $proxy['userpwd']);
        }
    }

    /**
     * @return array{proxy:string,type:string,userpwd:string}
     */
    public static function fromCaptchaConfig(?ConfigReader $config = null): array
    {
        $reader = $config ?? self::configReaderOrNull();
        if ($reader === null) {
            return self::empty();
        }

        $proxy = \trim((string) $reader->get(
            self::CONFIG_KEY_PROXY,
            self::CAPTCHA_MODULE,
            ConfigReader::area_BACKEND,
            ''
        ));
        $type = \strtolower(\trim((string) $reader->get(
            self::CONFIG_KEY_TYPE,
            self::CAPTCHA_MODULE,
            ConfigReader::area_BACKEND,
            'http'
        )));

        return self::normalize($proxy, $type === '' ? 'http' : $type);
    }

    /**
     * Reuse the merchant-configured social-login outbound tunnel without a hard module dep.
     *
     * @return array{proxy:string,type:string,userpwd:string}
     */
    public static function fromSocialLoginConfig(?ConfigReader $config = null): array
    {
        $reader = $config ?? self::configReaderOrNull();
        if ($reader === null) {
            return self::empty();
        }

        $proxy = \trim((string) $reader->get(
            self::SHARED_SOCIAL_PROXY_KEY,
            self::SOCIAL_MODULE,
            ConfigReader::area_FRONTEND,
            ''
        ));
        $type = \strtolower(\trim((string) $reader->get(
            self::SHARED_SOCIAL_PROXY_TYPE_KEY,
            self::SOCIAL_MODULE,
            ConfigReader::area_FRONTEND,
            'http'
        )));

        return self::normalize($proxy, $type === '' ? 'http' : $type);
    }

    /**
     * @return array{proxy:string,type:string,userpwd:string}
     */
    public static function fromEnv(): array
    {
        foreach (['HTTPS_PROXY', 'https_proxy', 'HTTP_PROXY', 'http_proxy', 'ALL_PROXY', 'all_proxy'] as $key) {
            $raw = \trim((string) (\getenv($key) ?: ''));
            if ($raw === '') {
                continue;
            }
            $type = \str_starts_with(\strtolower($raw), 'socks') ? 'socks5' : 'http';

            return self::normalize($raw, $type);
        }

        return self::empty();
    }

    /**
     * @return array{proxy:string,type:string,userpwd:string}
     */
    public static function normalize(string $proxy, string $type = 'http'): array
    {
        $proxy = \trim($proxy);
        if ($proxy === '') {
            return self::empty();
        }

        $type = \strtolower(\trim($type));
        if (\str_starts_with(\strtolower($proxy), 'socks')) {
            $type = 'socks5';
        } elseif (!\in_array($type, ['http', 'socks5'], true)) {
            $type = 'http';
        }

        $userpwd = '';
        $parts = \parse_url($proxy);
        if (\is_array($parts) && isset($parts['user'])) {
            $user = (string) $parts['user'];
            $pass = (string) ($parts['pass'] ?? '');
            $userpwd = $user . ($pass !== '' ? ':' . $pass : '');
            $scheme = (string) ($parts['scheme'] ?? 'http');
            $host = (string) ($parts['host'] ?? '');
            $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
            if ($host !== '') {
                $proxy = $scheme . '://' . $host . $port;
            }
        }

        return [
            'proxy' => $proxy,
            'type' => $type,
            'userpwd' => $userpwd,
        ];
    }

    /**
     * @return array{proxy:string,type:string,userpwd:string}
     */
    private static function empty(): array
    {
        return ['proxy' => '', 'type' => 'http', 'userpwd' => ''];
    }

    private static function configReaderOrNull(): ?ConfigReader
    {
        try {
            if (!\class_exists(ObjectManager::class) || !\class_exists(ConfigReader::class)) {
                return null;
            }
            $reader = ObjectManager::getInstance(ConfigReader::class);

            return $reader instanceof ConfigReader ? $reader : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
