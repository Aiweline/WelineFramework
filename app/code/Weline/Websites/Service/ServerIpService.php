<?php
declare(strict_types=1);

/**
 * Weline Websites - 服务器 IP 检测服务
 *
 * 获取当前服务器的公网 IP 地址（IPv4/IPv6）
 */

namespace Weline\Websites\Service;

use Weline\Framework\App\Env;
use Weline\Websites\Model\DomainConfig;

class ServerIpService
{
    private const IP_CHECK_SERVICES_V4 = [
        'https://api.ipify.org',
        'https://ipv4.icanhazip.com',
        'https://checkip.amazonaws.com',
        'https://api.ip.sb/ip',
        'https://ipinfo.io/ip',
    ];

    private const IP_CHECK_SERVICES_V6 = [
        'https://api64.ipify.org',
        'https://ipv6.icanhazip.com',
        'https://api6.ipify.org',
    ];

    private const REQUEST_TIMEOUT = 5;

    private DomainConfig $domainConfig;

    public function __construct(DomainConfig $domainConfig)
    {
        $this->domainConfig = $domainConfig;
    }

    /**
     * 获取本服务器的公网 IPv4
     */
    public function getPublicIpv4(bool $forceRefresh = false): string
    {
        if (!$forceRefresh) {
            $cached = $this->domainConfig->getServerPublicIp();
            if ($cached !== '' && $this->isValidIpv4($cached)) {
                return $cached;
            }
        }

        $ip = $this->fetchPublicIp(self::IP_CHECK_SERVICES_V4);

        if ($ip !== '' && $this->isValidIpv4($ip)) {
            $this->domainConfig->setValue(DomainConfig::CONFIG_SERVER_PUBLIC_IP, $ip);
            return $ip;
        }

        return '';
    }

    /**
     * 获取本服务器的公网 IPv6
     */
    public function getPublicIpv6(bool $forceRefresh = false): string
    {
        if (!$forceRefresh) {
            $cached = $this->domainConfig->getServerPublicIpv6();
            if ($cached !== '' && $this->isValidIpv6($cached)) {
                return $cached;
            }
        }

        $ip = $this->fetchPublicIp(self::IP_CHECK_SERVICES_V6);

        if ($ip !== '' && $this->isValidIpv6($ip)) {
            $this->domainConfig->setValue(DomainConfig::CONFIG_SERVER_PUBLIC_IPV6, $ip);
            return $ip;
        }

        return '';
    }

    /**
     * 刷新并返回所有公网 IP
     */
    public function refreshAll(): array
    {
        return [
            'ipv4' => $this->getPublicIpv4(true),
            'ipv6' => $this->getPublicIpv6(true),
        ];
    }

    /**
     * 检查给定 IP 是否是本服务器
     */
    public function isLocalServer(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }

        $serverIpv4 = $this->getPublicIpv4();
        $serverIpv6 = $this->getPublicIpv6();

        $ip = \strtolower(\trim($ip));

        if ($serverIpv4 !== '' && $ip === \strtolower($serverIpv4)) {
            return true;
        }

        if ($serverIpv6 !== '' && $this->normalizeIpv6($ip) === $this->normalizeIpv6($serverIpv6)) {
            return true;
        }

        return false;
    }

    /**
     * 从外部服务获取公网 IP
     */
    private function fetchPublicIp(array $services): string
    {
        foreach ($services as $url) {
            try {
                $ip = $this->httpGet($url);
                $ip = \trim($ip);

                if ($ip !== '' && \filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            } catch (\Throwable $e) {
                Env::log_warning('server_ip', "获取 IP 失败 ({$url}): " . $e->getMessage());
            }
        }

        return '';
    }

    /**
     * HTTP GET 请求
     */
    private function httpGet(string $url): string
    {
        $ch = \curl_init();

        \curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::REQUEST_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (WelineFramework ServerIpService)',
        ]);

        $response = \curl_exec($ch);
        $error = \curl_error($ch);
        \curl_close($ch);

        if ($error !== '') {
            throw new \RuntimeException($error);
        }

        return (string) $response;
    }

    /**
     * 验证 IPv4 地址
     */
    private function isValidIpv4(string $ip): bool
    {
        return \filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /**
     * 验证 IPv6 地址
     */
    private function isValidIpv6(string $ip): bool
    {
        return \filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }

    /**
     * 标准化 IPv6 地址（展开缩写形式）
     */
    private function normalizeIpv6(string $ip): string
    {
        $packed = \inet_pton($ip);
        if ($packed === false) {
            return $ip;
        }

        return \inet_ntop($packed) ?: $ip;
    }
}
