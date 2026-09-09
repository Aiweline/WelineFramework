<?php

declare(strict_types=1);

namespace Weline\Websites\Service\Value;

/**
 * L2 path-resolution match key: scheme/host/port/path only.
 * Query and fragment never participate; callers must pass the locale-stripped
 * trusted storefront URL used by ScopeResolver.
 */
final readonly class ScopePathMatchKey
{
    public function __construct(
        public string $scheme,
        public string $host,
        public int $port,
        public string $path,
    ) {
    }

    public static function fromTrustedUrl(string $trustedRequestUrl): self
    {
        $trimmed = \trim($trustedRequestUrl);
        $hashPos = \strpos($trimmed, '#');
        if ($hashPos !== false) {
            $trimmed = \substr($trimmed, 0, $hashPos);
        }
        $queryPos = \strpos($trimmed, '?');
        if ($queryPos !== false) {
            $trimmed = \substr($trimmed, 0, $queryPos);
        }
        $url = CanonicalStorefrontUrl::fromRequestUrl($trimmed);

        return new self($url->scheme, $url->host, $url->port, $url->path);
    }

    /**
     * Stable cache identity. $registryVersion ties the entry to websites-registry
     * / parser-sites generation so stale workers miss after website/store/channel changes.
     */
    public function cacheIdentity(string $registryVersion): string
    {
        return \sha1(\implode("\n", [
            $this->scheme,
            $this->host,
            (string)$this->port,
            $this->path,
            $registryVersion,
        ]));
    }

    public function toArray(): array
    {
        return [
            'scheme' => $this->scheme,
            'host' => $this->host,
            'port' => $this->port,
            'path' => $this->path,
        ];
    }
}
