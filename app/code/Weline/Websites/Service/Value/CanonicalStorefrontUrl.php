<?php

declare(strict_types=1);

namespace Weline\Websites\Service\Value;

/**
 * Store 入口与可信请求 URL 共用的不可变规范值。
 */
final readonly class CanonicalStorefrontUrl
{
    private const DEFAULT_PORTS = [
        'http' => 80,
        'https' => 443,
    ];

    private function __construct(
        public string $scheme,
        public string $host,
        public int $port,
        public string $path,
        private bool $ipv6,
    ) {
    }

    public static function fromStoreUrl(string $url): self
    {
        return self::parse($url, false);
    }

    public static function fromRequestUrl(string $url): self
    {
        return self::parse($url, true);
    }

    public function sameOrigin(self $other): bool
    {
        return $this->scheme === $other->scheme
            && self::hostsEquivalent($this->host, $other->host)
            && $this->port === $other->port;
    }

    public function matchesRequestPath(self $request): bool
    {
        return self::matchesPathSegmentBoundary($this->path, $request->path);
    }

    /**
     * Match a complete canonical path segment boundary.
     *
     * This is public so Website and Store resolution can share one path rule
     * instead of maintaining subtly different prefix checks.
     */
    public static function matchesPathSegmentBoundary(string $basePath, string $requestPath): bool
    {
        $basePath = self::canonicalPath($basePath);
        $requestPath = self::canonicalPath($requestPath);
        if ($basePath === '/') {
            return true;
        }

        return $requestPath === $basePath
            || \str_starts_with($requestPath, $basePath . '/');
    }

    public static function canonicalPath(string $path): string
    {
        return self::normalizePath($path);
    }

    public function pathSpecificity(): int
    {
        return $this->path === '/' ? 0 : \strlen($this->path);
    }

    public function toString(): string
    {
        return $this->originString() . ($this->path === '/' ? '' : $this->path);
    }

    public function originString(): string
    {
        $authority = $this->ipv6 ? '[' . $this->host . ']' : $this->host;
        if ($this->port !== self::DEFAULT_PORTS[$this->scheme]) {
            $authority .= ':' . $this->port;
        }

        return $this->scheme . '://' . $authority;
    }

    private static function parse(string $url, bool $allowQuery): self
    {
        if ($url === '' || \trim($url) !== $url || \strlen($url) > 2048
            || \preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            throw new \InvalidArgumentException('URL contains invalid bytes or whitespace.');
        }
        if (\str_contains($url, '#') || (!$allowQuery && \str_contains($url, '?'))) {
            throw new \InvalidArgumentException('URL query or fragment is not allowed.');
        }
        if (\preg_match('~\A([A-Za-z][A-Za-z0-9+.-]*):\/\/([^/?#]*)((?:[/?#].*)?)\z~D', $url, $absolute) !== 1) {
            throw new \InvalidArgumentException('URL must be absolute.');
        }

        $scheme = \strtolower($absolute[1]);
        if (!\array_key_exists($scheme, self::DEFAULT_PORTS)) {
            throw new \InvalidArgumentException('URL scheme must be HTTP or HTTPS.');
        }

        [$host, $port, $ipv6] = self::parseAuthority($absolute[2], $scheme);
        $target = $absolute[3];
        $queryOffset = \strpos($target, '?');
        if ($queryOffset !== false) {
            $target = \substr($target, 0, $queryOffset);
        }

        $path = self::normalizePath($target);
        return new self($scheme, $host, $port, $path, $ipv6);
    }

    /** @return array{0:string,1:int,2:bool} */
    private static function parseAuthority(string $authority, string $scheme): array
    {
        if ($authority === '' || \str_contains($authority, '@')) {
            throw new \InvalidArgumentException('URL user information or empty authority is not allowed.');
        }

        $ipv6 = false;
        $portText = null;
        if (\str_starts_with($authority, '[')) {
            if (\preg_match('/\A\[([^\]]+)](?::([0-9]+))?\z/D', $authority, $match) !== 1) {
                throw new \InvalidArgumentException('Bracketed IPv6 authority is invalid.');
            }
            $hostText = $match[1];
            $portText = $match[2] ?? null;
            $packed = @\inet_pton($hostText);
            if ($packed === false || \strlen($packed) !== 16) {
                throw new \InvalidArgumentException('IPv6 host is invalid.');
            }
            $host = \strtolower((string)\inet_ntop($packed));
            $ipv6 = true;
        } else {
            if (\substr_count($authority, ':') > 1) {
                throw new \InvalidArgumentException('IPv6 hosts must use brackets.');
            }
            if (\str_contains($authority, ':')) {
                [$hostText, $portText] = \explode(':', $authority, 2);
                if ($portText === '') {
                    throw new \InvalidArgumentException('URL port is empty.');
                }
            } else {
                $hostText = $authority;
            }
            $host = self::normalizeHost($hostText);
        }

        $port = self::DEFAULT_PORTS[$scheme];
        if ($portText !== null) {
            if (\preg_match('/\A[0-9]{1,5}\z/D', $portText) !== 1) {
                throw new \InvalidArgumentException('URL port is invalid.');
            }
            $port = (int)$portText;
            if ($port < 1 || $port > 65535) {
                throw new \InvalidArgumentException('URL port is outside the valid range.');
            }
        }

        return [$host, $port, $ipv6];
    }

    private static function normalizeHost(string $host): string
    {
        $host = \strtolower($host);
        if (\str_ends_with($host, '..')) {
            throw new \InvalidArgumentException('DNS host has multiple trailing dots.');
        }
        $host = \rtrim($host, '.');
        if ($host === '' || \strlen($host) > 253 || \preg_match('/[^\x21-\x7E]/', $host) === 1
            || \str_contains($host, '%')) {
            throw new \InvalidArgumentException('URL host is invalid.');
        }

        if (\preg_match('/\A[0-9.]+\z/D', $host) === 1) {
            if (\filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new \InvalidArgumentException('IPv4 host is invalid.');
            }
            return $host;
        }

        foreach (\explode('.', $host) as $label) {
            if ($label === '' || \strlen($label) > 63
                || \preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D', $label) !== 1) {
                throw new \InvalidArgumentException('DNS host label is invalid.');
            }
        }

        return $host;
    }

    private static function hostsEquivalent(string $left, string $right): bool
    {
        if ($left === $right) {
            return true;
        }

        return self::withoutLeadingWww($left) === self::withoutLeadingWww($right);
    }

    private static function withoutLeadingWww(string $host): string
    {
        return \str_starts_with($host, 'www.') ? (string)\substr($host, 4) : $host;
    }

    private static function normalizePath(string $path): string
    {
        if ($path === '') {
            return '/';
        }
        if (!\str_starts_with($path, '/')
            || \str_contains($path, '//')
            || \preg_match('/%2f|%5c/i', $path) === 1) {
            throw new \InvalidArgumentException('URL path is not canonical.');
        }

        $normalized = '';
        $length = \strlen($path);
        for ($offset = 0; $offset < $length; $offset++) {
            $character = $path[$offset];
            $byte = \ord($character);
            if ($character === '%') {
                if ($offset + 2 >= $length
                    || \preg_match('/\A[0-9A-Fa-f]{2}\z/D', \substr($path, $offset + 1, 2)) !== 1) {
                    throw new \InvalidArgumentException('URL path contains malformed percent encoding.');
                }
                $hex = \strtoupper(\substr($path, $offset + 1, 2));
                $decoded = \chr((int)\hexdec($hex));
                $decodedByte = \ord($decoded);
                if ($decoded === '/' || $decoded === '\\' || $decodedByte <= 0x20 || $decodedByte === 0x7F) {
                    throw new \InvalidArgumentException('URL path contains an encoded separator or control byte.');
                }
                $normalized .= self::isUnreserved($decoded) ? $decoded : '%' . $hex;
                $offset += 2;
                continue;
            }
            if ($character === '\\' || $byte <= 0x20 || $byte === 0x7F) {
                throw new \InvalidArgumentException('URL path contains an invalid byte.');
            }
            if ($byte > 0x7F) {
                $normalized .= '%' . \strtoupper(\str_pad(\dechex($byte), 2, '0', STR_PAD_LEFT));
                continue;
            }
            if (!self::isPathCharacter($character)) {
                throw new \InvalidArgumentException('URL path contains an invalid character.');
            }
            $normalized .= $character;
        }

        foreach (\explode('/', $normalized) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException('URL path dot segments are not allowed.');
            }
        }

        if ($normalized !== '/') {
            $normalized = \rtrim($normalized, '/');
        }
        return $normalized === '' ? '/' : $normalized;
    }

    private static function isUnreserved(string $character): bool
    {
        return \preg_match('/\A[A-Za-z0-9._~-]\z/D', $character) === 1;
    }

    private static function isPathCharacter(string $character): bool
    {
        return self::isUnreserved($character)
            || \str_contains("!$&'()*+,;=:@/", $character);
    }
}
