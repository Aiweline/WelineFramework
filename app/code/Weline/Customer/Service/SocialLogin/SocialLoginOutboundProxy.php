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
        $fromConfig = self::discardUnreachableLoopback(self::fromConfig($config));
        if ($fromConfig['proxy'] !== '') {
            return $fromConfig;
        }

        return self::discardUnreachableLoopback(self::fromEnv());
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
     * @param array{proxy:string,type:string,userpwd:string} $resolved
     * @return array{proxy:string,type:string,userpwd:string}
     */
    public static function discardUnreachableLoopback(array $resolved): array
    {
        $proxy = trim((string) ($resolved['proxy'] ?? ''));
        if ($proxy === '' || !self::isLoopbackProxy($proxy)) {
            return $resolved;
        }
        if (self::isProxyListening($proxy)) {
            return $resolved;
        }

        return self::empty();
    }

    public static function isLoopbackProxy(string $proxy): bool
    {
        $host = strtolower((string) (parse_url($proxy, PHP_URL_HOST) ?? ''));

        return in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
    }

    public static function isProxyListening(string $proxy): bool
    {
        $parts = parse_url($proxy);
        if (!is_array($parts)) {
            return false;
        }
        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        $port = (int) ($parts['port'] ?? 0);
        if ($port <= 0) {
            $port = str_starts_with($scheme, 'socks') ? 1080 : 80;
        }
        $fp = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 0.2);
        if ($fp === false) {
            return false;
        }
        fclose($fp);

        return true;
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
