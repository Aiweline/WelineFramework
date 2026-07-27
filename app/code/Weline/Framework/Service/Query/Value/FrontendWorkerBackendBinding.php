<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Query\Value;

/**
 * Credential-free server-side binding for an attested backend Worker session.
 *
 * The raw PHP Session ID is never persisted here. Only its SHA-256 fingerprint
 * is retained so logout/login/session regeneration immediately revokes the
 * Worker binding during server-side revalidation.
 */
final readonly class FrontendWorkerBackendBinding
{
    public const VERSION = 'v1';
    private const DIGEST_DOMAIN = "weline-frontend-worker-backend-binding-v1\0";
    private const FINGERPRINT_PATTERN = '/^[a-f0-9]{64}$/D';

    public function __construct(
        public int $backendUserId,
        public string $sessionFingerprint,
        public string $authorityHost,
        public int $issuedAt,
        public int $expiresAt,
    ) {
        if ($backendUserId <= 0) {
            throw new \InvalidArgumentException('Worker backend binding user ID is invalid.');
        }
        if (\preg_match(self::FINGERPRINT_PATTERN, $sessionFingerprint) !== 1) {
            throw new \InvalidArgumentException('Worker backend binding Session fingerprint is invalid.');
        }
        $canonicalHost = self::canonicalAuthorityHost($authorityHost);
        if (!\hash_equals($canonicalHost, $authorityHost)) {
            throw new \InvalidArgumentException('Worker backend binding authority_host is not canonical.');
        }
        if ($issuedAt < 1 || $expiresAt <= $issuedAt) {
            throw new \InvalidArgumentException('Worker backend binding time window is invalid.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        self::assertExactKeys($data, [
            'authority_host',
            'backend_user_id',
            'binding_version',
            'expires_at',
            'issued_at',
            'session_fingerprint',
        ]);
        if ($data['binding_version'] !== self::VERSION
            || !\is_int($data['backend_user_id'])
            || !\is_string($data['session_fingerprint'])
            || !\is_string($data['authority_host'])
            || !\is_int($data['issued_at'])
            || !\is_int($data['expires_at'])) {
            throw new \InvalidArgumentException('Worker backend binding structure is invalid.');
        }

        return new self(
            $data['backend_user_id'],
            $data['session_fingerprint'],
            $data['authority_host'],
            $data['issued_at'],
            $data['expires_at'],
        );
    }

    /**
     * @return array{binding_version:string,backend_user_id:int,session_fingerprint:string,authority_host:string,issued_at:int,expires_at:int}
     */
    public function toArray(): array
    {
        return [
            'binding_version' => self::VERSION,
            'backend_user_id' => $this->backendUserId,
            'session_fingerprint' => $this->sessionFingerprint,
            'authority_host' => $this->authorityHost,
            'issued_at' => $this->issuedAt,
            'expires_at' => $this->expiresAt,
        ];
    }

    public function digest(): string
    {
        $json = \json_encode(
            $this->toArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        return \hash('sha256', self::DIGEST_DOMAIN . $json);
    }

    public static function canonicalAuthorityHost(string $host): string
    {
        $authority = \strtolower(\trim($host));
        if ($authority === ''
            || \strlen($authority) > 261
            || \preg_match('/[\x00-\x20\x7F\/\\\\@?#]/', $authority) === 1) {
            throw new \InvalidArgumentException('Worker backend binding authority_host is invalid.');
        }

        if (\str_starts_with($authority, '[')) {
            if (\preg_match('/^\[([0-9a-f:.]+)\](?::([0-9]{1,5}))?$/D', $authority, $matches) !== 1
                || \filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new \InvalidArgumentException('Worker backend binding authority_host is invalid.');
            }
            $port = self::canonicalPort($matches[2] ?? '');
            return '[' . $matches[1] . ']' . ($port === '' ? '' : ':' . $port);
        }

        if (\substr_count($authority, ':') > 1
            || \preg_match('/^([^:]+)(?::([0-9]{1,5}))?$/D', $authority, $matches) !== 1) {
            throw new \InvalidArgumentException('Worker backend binding authority_host is invalid.');
        }
        $hostname = \rtrim($matches[1], '.');
        if (!self::isValidHostname($hostname)) {
            throw new \InvalidArgumentException('Worker backend binding authority_host is invalid.');
        }
        $port = self::canonicalPort($matches[2] ?? '');
        return $hostname . ($port === '' ? '' : ':' . $port);
    }

    /** @param array<string, mixed> $data @param list<string> $expected */
    private static function assertExactKeys(array $data, array $expected): void
    {
        if (\array_is_list($data)) {
            throw new \InvalidArgumentException('Worker backend binding must be an object map.');
        }
        $actual = \array_keys($data);
        \sort($actual, SORT_STRING);
        \sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \InvalidArgumentException('Worker backend binding fields are incomplete or unknown.');
        }
    }

    private static function isValidHostname(string $hostname): bool
    {
        if ($hostname === '' || \strlen($hostname) > 253) {
            return false;
        }
        if (\filter_var($hostname, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return true;
        }
        if (\preg_match('/^[0-9.]+$/D', $hostname) === 1) {
            return false;
        }
        foreach (\explode('.', $hostname) as $label) {
            if (\preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $label) !== 1) {
                return false;
            }
        }
        return true;
    }

    private static function canonicalPort(string $port): string
    {
        if ($port === '') {
            return '';
        }
        $number = (int)$port;
        if ($number < 1 || $number > 65535) {
            throw new \InvalidArgumentException('Worker backend binding authority port is invalid.');
        }
        return (string)$number;
    }
}
