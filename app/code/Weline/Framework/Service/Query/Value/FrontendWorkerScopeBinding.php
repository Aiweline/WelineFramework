<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Query\Value;

use Weline\Framework\Runtime\ScopeIdentity;

/**
 * Immutable server-side binding between a verified storefront Scope Token and
 * a frontend worker session/ticket. The original token and signing metadata
 * must never be stored here.
 */
final readonly class FrontendWorkerScopeBinding
{
    public const VERSION = 'v2';
    public const CLOCK_SKEW_SECONDS = 60;
    private const DIGEST_DOMAIN = "weline-frontend-worker-scope-binding-v2\0";
    private const FINGERPRINT_PATTERN = '/^[a-f0-9]{64}$/D';

    public function __construct(
        public ScopeIdentity $scope,
        public string $authorityHost,
        public string $tokenFingerprint,
        public int $tokenIssuedAt,
        public int $tokenExpiresAt,
        public bool $authoritativeAtIssue = false,
    ) {
        if ($scope->scopeKind !== ScopeIdentity::KIND_CHANNEL) {
            throw new \InvalidArgumentException('Worker Scope 绑定必须使用完整 Channel Scope');
        }

        $canonicalHost = self::canonicalAuthorityHost($authorityHost);
        if (!hash_equals($canonicalHost, $authorityHost)) {
            throw new \InvalidArgumentException('Worker Scope 绑定的 authority_host 必须是规范值');
        }
        if (preg_match(self::FINGERPRINT_PATTERN, $tokenFingerprint) !== 1) {
            throw new \InvalidArgumentException('Worker Scope 绑定必须携带完整 SHA-256 Token 指纹');
        }
        if ($tokenIssuedAt < 1 || $tokenExpiresAt <= $tokenIssuedAt) {
            throw new \InvalidArgumentException('Worker Scope 绑定的 Token 时间窗口无效');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        self::assertExactKeys(
            $data,
            [
                'authority_host',
                'authoritative_at_issue',
                'binding_version',
                'scope',
                'token_expires_at',
                'token_fingerprint',
                'token_issued_at',
            ]
        );
        if ($data['binding_version'] !== self::VERSION
            || !is_array($data['scope'])
            || array_is_list($data['scope'])
            || !is_string($data['authority_host'])
            || !is_string($data['token_fingerprint'])
            || !is_int($data['token_issued_at'])
            || !is_int($data['token_expires_at'])
            || !is_bool($data['authoritative_at_issue'])) {
            throw new \InvalidArgumentException('Worker Scope 绑定结构无效');
        }

        self::assertExactKeys(
            $data['scope'],
            [
                'channel_code',
                'context_version',
                'scope_kind',
                'store_code',
                'store_mode',
                'website_code',
                'website_id',
            ]
        );
        $scope = ScopeIdentity::fromArray($data['scope']);
        if ($scope->toArray() !== $data['scope']) {
            throw new \InvalidArgumentException('Worker Scope 绑定中的 Scope 不是规范结构');
        }

        return new self(
            $scope,
            $data['authority_host'],
            $data['token_fingerprint'],
            $data['token_issued_at'],
            $data['token_expires_at'],
            $data['authoritative_at_issue'],
        );
    }

    /**
     * @return array{
     *     binding_version:string,
     *     scope:array{scope_kind:string,website_id:?int,website_code:?string,store_code:?string,channel_code:?string,store_mode:?string,context_version:string},
     *     authority_host:string,
     *     authoritative_at_issue:bool,
     *     token_fingerprint:string,
     *     token_issued_at:int,
     *     token_expires_at:int
     * }
     */
    public function toArray(): array
    {
        return [
            'binding_version' => self::VERSION,
            'scope' => $this->scope->toArray(),
            'authority_host' => $this->authorityHost,
            'authoritative_at_issue' => $this->authoritativeAtIssue,
            'token_fingerprint' => $this->tokenFingerprint,
            'token_issued_at' => $this->tokenIssuedAt,
            'token_expires_at' => $this->tokenExpiresAt,
        ];
    }

    public function digest(): string
    {
        $json = json_encode(
            $this->toArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        return hash('sha256', self::DIGEST_DOMAIN . $json);
    }

    /**
     * Public metadata intentionally omits the Token fingerprint. It is used
     * only as a server-side proof input during the one-time bootstrap exchange.
     *
     * @return array{
     *     binding_version:string,
     *     scope:array{scope_kind:string,website_id:?int,website_code:?string,store_code:?string,channel_code:?string,store_mode:?string,context_version:string},
     *     authority_host:string,
     *     authoritative_at_issue:bool,
     *     token_issued_at:int,
     *     token_expires_at:int,
     *     binding_digest:string
     * }
     */
    public function publicMeta(): array
    {
        return [
            'binding_version' => self::VERSION,
            'scope' => $this->scope->toArray(),
            'authority_host' => $this->authorityHost,
            'authoritative_at_issue' => $this->authoritativeAtIssue,
            'token_issued_at' => $this->tokenIssuedAt,
            'token_expires_at' => $this->tokenExpiresAt,
            'binding_digest' => $this->digest(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $expected
     */
    private static function assertExactKeys(array $data, array $expected): void
    {
        if (array_is_list($data)) {
            throw new \InvalidArgumentException('Worker Scope 绑定必须是对象结构');
        }
        $actual = array_keys($data);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \InvalidArgumentException('Worker Scope 绑定字段不完整或包含未知字段');
        }
    }

    private static function canonicalAuthorityHost(string $host): string
    {
        $authority = strtolower(trim($host));
        if ($authority === ''
            || strlen($authority) > 261
            || preg_match('/[\x00-\x20\x7F\/\\\\@?#]/', $authority) === 1) {
            throw new \InvalidArgumentException('Worker Scope 绑定的 authority_host 无效');
        }

        if (str_starts_with($authority, '[')) {
            if (preg_match('/^\[([0-9a-f:.]+)\](?::([0-9]{1,5}))?$/D', $authority, $matches) !== 1
                || filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new \InvalidArgumentException('Worker Scope 绑定的 authority_host 无效');
            }
            $port = self::canonicalPort($matches[2] ?? '');
            return '[' . $matches[1] . ']' . ($port === '' ? '' : ':' . $port);
        }

        if (substr_count($authority, ':') > 1
            || preg_match('/^([^:]+)(?::([0-9]{1,5}))?$/D', $authority, $matches) !== 1) {
            throw new \InvalidArgumentException('Worker Scope 绑定的 authority_host 无效');
        }
        $hostname = rtrim($matches[1], '.');
        if (!self::isValidHostname($hostname)) {
            throw new \InvalidArgumentException('Worker Scope 绑定的 authority_host 无效');
        }
        $port = self::canonicalPort($matches[2] ?? '');
        return $hostname . ($port === '' ? '' : ':' . $port);
    }

    private static function isValidHostname(string $hostname): bool
    {
        if ($hostname === '' || strlen($hostname) > 253) {
            return false;
        }
        if (filter_var($hostname, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return true;
        }
        if (preg_match('/^[0-9.]+$/D', $hostname) === 1) {
            return false;
        }
        foreach (explode('.', $hostname) as $label) {
            if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $label) !== 1) {
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
            throw new \InvalidArgumentException('Worker Scope 绑定的 authority_host 端口无效');
        }
        return (string)$number;
    }
}
