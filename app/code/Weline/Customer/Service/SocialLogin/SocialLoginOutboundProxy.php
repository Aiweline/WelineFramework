<?php

declare(strict_types=1);

namespace Weline\Customer\Service\SocialLogin;

use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Api\ConfigReader;

/**
 * Resolve optional outbound HTTP/SOCKS proxy for social-login token/userinfo calls.
 *
 * Priority: SystemConfig `customer/social_login/http_proxy` → process env
 * (HTTPS_PROXY / HTTP_PROXY / ALL_PROXY). Empty means direct connect.
 */
final class SocialLoginOutboundProxy
{
    public const CONFIG_KEY_PROXY = 'customer/social_login/http_proxy';
    public const CONFIG_KEY_TYPE = 'customer/social_login/http_proxy_type';

    /**
     * @return array{proxy:string,type:string,userpwd:string}
     */
    public static function resolve(?ConfigReader $config = null): array
    {
        $fromConfig = self::fromConfig($config);
        if ($fromConfig['proxy'] !== '') {
            return $fromConfig;
        }

        return self::fromEnv();
    }

    /**
     * @return array{proxy:string,type:string,userpwd:string}
     */
    public static function fromConfig(?ConfigReader $config = null): array
    {
        $reader = $config ?? self::configReaderOrNull();
        if ($reader === null) {
            return self::empty();
        }

        $proxy = trim((string) $reader->get(
            self::CONFIG_KEY_PROXY,
            SocialLoginConfig::MODULE,
            SocialLoginConfig::AREA,
            ''
        ));
        $type = strtolower(trim((string) $reader->get(
            self::CONFIG_KEY_TYPE,
            SocialLoginConfig::MODULE,
            SocialLoginConfig::AREA,
            'http'
        )));
        if ($type === '') {
            $type = 'http';
        }

        return self::normalize($proxy, $type);
    }

    /**
     * @return array{proxy:string,type:string,userpwd:string}
     */
    public static function fromEnv(): array
    {
        foreach (['HTTPS_PROXY', 'https_proxy', 'HTTP_PROXY', 'http_proxy', 'ALL_PROXY', 'all_proxy'] as $key) {
            $raw = trim((string) (getenv($key) ?: ''));
            if ($raw === '') {
                continue;
            }
            $type = str_starts_with(strtolower($raw), 'socks') ? 'socks5' : 'http';

            return self::normalize($raw, $type);
        }

        return self::empty();
    }

    /**
     * @return array{proxy:string,type:string,userpwd:string}
     */
    public static function normalize(string $proxy, string $type = 'http'): array
    {
        $proxy = trim($proxy);
        if ($proxy === '') {
            return self::empty();
        }

        $type = strtolower(trim($type));
        if (str_starts_with(strtolower($proxy), 'socks')) {
            $type = 'socks5';
        } elseif (!in_array($type, ['http', 'socks5'], true)) {
            $type = 'http';
        }

        $userpwd = '';
        $parts = parse_url($proxy);
        if (is_array($parts) && isset($parts['user'])) {
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
            if (!class_exists(ObjectManager::class) || !class_exists(ConfigReader::class)) {
                return null;
            }
            $reader = ObjectManager::getInstance(ConfigReader::class);

            return $reader instanceof ConfigReader ? $reader : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
