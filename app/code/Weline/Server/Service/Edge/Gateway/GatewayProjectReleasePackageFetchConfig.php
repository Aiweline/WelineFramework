<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

use Weline\Framework\App\Env;

/**
 * Opt-in CDN fetch settings for signed project gateway release packages.
 *
 * Defaults keep server:start offline: empty base URL and package_fetch=false.
 */
final class GatewayProjectReleasePackageFetchConfig
{
    public const DEFAULT_TIMEOUT_SEC = 120;

    /** @param list<string> $allowedHosts */
    public function __construct(
        public readonly string $baseUrl,
        public readonly bool $packageFetch,
        public readonly float $timeoutSec,
        public readonly array $allowedHosts,
    ) {
    }

    public static function fromEnv(): self
    {
        $gateway = Env::get('wls.edge.gateway', []);
        if (!\is_array($gateway)) {
            $gateway = [];
        }

        return self::fromArray($gateway);
    }

    /** @param array<string,mixed> $gateway */
    public static function fromArray(array $gateway): self
    {
        $baseUrl = \rtrim(\trim((string)($gateway['package_base_url'] ?? '')), '/');
        $timeout = (float)($gateway['package_fetch_timeout_sec'] ?? self::DEFAULT_TIMEOUT_SEC);
        if ($timeout < 1.0) {
            $timeout = (float)self::DEFAULT_TIMEOUT_SEC;
        }
        $hosts = [];
        foreach ((array)($gateway['package_fetch_hosts'] ?? []) as $host) {
            $host = \strtolower(\trim((string)$host));
            if ($host !== '' && !\in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }
        if ($hosts === [] && $baseUrl !== '') {
            $parsed = \parse_url($baseUrl);
            $fallback = \strtolower(\trim((string)($parsed['host'] ?? '')));
            if ($fallback !== '') {
                $hosts[] = $fallback;
            }
        }

        return new self(
            $baseUrl,
            ($gateway['package_fetch'] ?? false) === true,
            $timeout,
            $hosts,
        );
    }

    public function isFetchConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->allowedHosts !== [];
    }

    public function isAutoFetchEnabled(): bool
    {
        return $this->packageFetch && $this->isFetchConfigured();
    }
}
