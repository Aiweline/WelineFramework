<?php

declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\App\Env;

/** Immutable health-endpoint access settings loaded once during Worker boot. */
final class WorkerHealthAccessPolicy
{
    /** @var array<string, self> */
    private static array $instances = [];

    private function __construct(
        public readonly bool $allowRemote,
        private readonly string $cookieSecret,
    ) {
    }

    public static function boot(string $instanceName): self
    {
        $instanceName = \trim($instanceName) !== '' ? \trim($instanceName) : 'default';
        $wls = Env::get('wls', []);
        $wls = \is_array($wls) ? $wls : [];
        $servers = \is_array($wls['servers'] ?? null) ? $wls['servers'] : [];
        $instance = \is_array($servers[$instanceName] ?? null) ? $servers[$instanceName] : [];

        return self::$instances[$instanceName] = new self(
            allowRemote: (bool)($instance['health_allow_remote'] ?? $wls['health_allow_remote'] ?? false),
            cookieSecret: (string)($instance['health_cookie_secret'] ?? $wls['health_cookie_secret'] ?? ''),
        );
    }

    public static function instance(string $instanceName): self
    {
        $instanceName = \trim($instanceName) !== '' ? \trim($instanceName) : 'default';
        return self::$instances[$instanceName] ?? self::boot($instanceName);
    }

    public static function reset(): void
    {
        self::$instances = [];
    }

    public function cookieValid(string $cookieValue, ?int $timestamp = null): bool
    {
        if ($this->cookieSecret === '' || $cookieValue === '') {
            return false;
        }
        $slot = \intdiv($timestamp ?? \time(), 3600);
        foreach ([$slot, $slot - 1] as $candidate) {
            $expected = \hash_hmac('sha256', 'wls_health_' . $candidate, $this->cookieSecret);
            if (\hash_equals($expected, $cookieValue)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, string> $headers Lowercase header map. */
    public function allowsClient(string $clientIp, array $headers = []): bool
    {
        if ($this->allowRemote || $this->isLocalOrPrivateIp($clientIp)) {
            return true;
        }
        $cookie = (string)($headers['cookie'] ?? '');
        if ($cookie === '' || \preg_match('/(?:^|;\s*)wls_health_allow=([^;\s]+)/', $cookie, $match) !== 1) {
            return false;
        }

        return $this->cookieValid(\trim((string)$match[1], '"'));
    }

    private function isLocalOrPrivateIp(string $ip): bool
    {
        $ip = \trim($ip, " []\t\r\n");
        if ($ip === '::1' || \str_starts_with($ip, '127.')) {
            return true;
        }
        if (\filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) !== false) {
            $long = \ip2long($ip);
            if ($long === false) {
                return false;
            }
            $long = (int)\sprintf('%u', $long);
            return ($long >= 0x0A000000 && $long <= 0x0AFFFFFF)
                || ($long >= 0xAC100000 && $long <= 0xAC1FFFFF)
                || ($long >= 0xC0A80000 && $long <= 0xC0A8FFFF);
        }
        if (\filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6) !== false) {
            $packed = \inet_pton($ip);
            if (!\is_string($packed) || \strlen($packed) !== 16) {
                return false;
            }
            $first = \ord($packed[0]);
            $second = \ord($packed[1]);
            return ($first & 0xFE) === 0xFC || ($first === 0xFE && ($second & 0xC0) === 0x80);
        }

        return false;
    }
}
