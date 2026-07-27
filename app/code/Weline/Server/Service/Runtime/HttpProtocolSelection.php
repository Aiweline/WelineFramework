<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

/**
 * Immutable protocol contract for the private Nginx-to-WLS backend.
 *
 * Public TLS, HTTP/2, HTTP/3, ALPN, Alt-Svc and session tickets belong to
 * ManagedNginxService. WLS accepts only loopback cleartext HTTP/1.1.
 */
final readonly class HttpProtocolSelection
{
    public const HTTP_3 = 'h3';
    public const HTTP_2 = 'h2';
    public const HTTP_1 = 'h1';

    /** @deprecated Native/Caddy protocol edges are retired. */
    public const EDGE_NATIVE = 'native';
    /** @deprecated Native/Caddy protocol edges are retired. */
    public const EDGE_CADDY = 'caddy';
    public const EDGE_DISABLED = 'disabled';

    /** @var list<string> */
    public const DEFAULT_PROTOCOLS = [self::HTTP_1];

    /**
     * @param list<string> $protocols
     */
    public function __construct(
        public array $protocols,
        public string $preferred,
        public string $edge,
        public bool $tlsSessionResumption,
        public bool $altSvc,
    ) {
        if ($protocols !== [self::HTTP_1]
            || $preferred !== self::HTTP_1
            || $edge !== self::EDGE_DISABLED
            || $tlsSessionResumption
            || $altSvc
        ) {
            throw new \InvalidArgumentException(
                'Nginx-only WLS backend requires protocols=[h1], preferred=h1, '
                . 'edge=disabled, tls_session_resumption=false, and alt_svc=false.'
            );
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config, bool $sslEnabled): self
    {
        if ($sslEnabled) {
            throw new \InvalidArgumentException(
                'Nginx-only WLS backend cannot enable Worker TLS.'
            );
        }
        $http = \is_array($config['http'] ?? null) ? $config['http'] : [];
        $protocols = self::normalizeProtocols($http['protocols'] ?? [self::HTTP_1]);
        $preferred = self::normalizeProtocol($http['preferred'] ?? self::HTTP_1);
        $edge = self::normalizeEdge($http['protocol_edge'] ?? self::EDGE_DISABLED);
        $tlsSessionResumption = self::disabledBoolean($http, 'tls_session_resumption');
        $altSvc = self::disabledBoolean($http, 'alt_svc');

        return new self($protocols, $preferred, $edge, $tlsSessionResumption, $altSvc);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $protocols = self::normalizeProtocols($data['protocols'] ?? [self::HTTP_1]);
        $preferred = self::normalizeProtocol($data['preferred'] ?? self::HTTP_1);
        $edge = self::normalizeEdge($data['edge'] ?? self::EDGE_DISABLED);
        $tlsSessionResumption = self::disabledBoolean($data, 'tls_session_resumption');
        $altSvc = self::disabledBoolean($data, 'alt_svc');

        return new self($protocols, $preferred, $edge, $tlsSessionResumption, $altSvc);
    }

    public function isProtocolEdgeEnabled(): bool
    {
        return false;
    }

    public function isNativeProtocolEdge(): bool
    {
        return false;
    }

    public function isCaddyProtocolEdge(): bool
    {
        return false;
    }

    public function requiresProtocolEdge(): bool
    {
        return false;
    }

    public function supports(string $protocol): bool
    {
        return self::normalizeProtocol($protocol) === self::HTTP_1;
    }

    public function assertCompatibleEdgeAdapter(string $edgeAdapter): void
    {
        if (\strtolower(\trim($edgeAdapter)) !== 'nginx') {
            throw new \InvalidArgumentException(
                'Nginx is the only supported WLS public edge adapter.'
            );
        }
    }

    /** @return list<string> */
    public function caddyProtocols(): array
    {
        return [self::HTTP_1];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'protocols' => [self::HTTP_1],
            'preferred' => self::HTTP_1,
            'edge' => self::EDGE_DISABLED,
            'tls_session_resumption' => false,
            'alt_svc' => false,
            'http3_transport' => 'disabled',
            'tcp_alpn' => [self::HTTP_1],
        ];
    }

    /** @return array<string, mixed> */
    public function toConfig(): array
    {
        return [
            'protocols' => [self::HTTP_1],
            'preferred' => self::HTTP_1,
            'protocol_edge' => self::EDGE_DISABLED,
            'protocol_edge_enabled' => false,
            'tls_session_resumption' => false,
            'alt_svc' => false,
        ];
    }

    /** @return list<string> */
    private static function normalizeProtocols(mixed $value): array
    {
        if (\is_string($value)) {
            $value = \preg_split('/[\s,]+/', \trim($value), -1, \PREG_SPLIT_NO_EMPTY);
        }
        if (!\is_array($value) || !\array_is_list($value) || \count($value) !== 1) {
            throw new \InvalidArgumentException(
                'Nginx-only WLS backend requires wls.http.protocols=[h1].'
            );
        }

        return [self::normalizeProtocol($value[0])];
    }

    private static function normalizeProtocol(mixed $protocol): string
    {
        $protocol = \strtolower(\trim((string)$protocol));
        if (!\in_array($protocol, ['h1', 'http1', 'http1.1', 'http/1', 'http/1.1', '1', '1.1'], true)) {
            throw new \InvalidArgumentException(
                'Nginx-only WLS backend supports only HTTP/1.1.'
            );
        }

        return self::HTTP_1;
    }

    private static function normalizeEdge(mixed $edge): string
    {
        if (\is_bool($edge)) {
            if ($edge) {
                throw new \InvalidArgumentException('WLS protocol edge is retired.');
            }
            return self::EDGE_DISABLED;
        }
        $edge = \strtolower(\trim((string)$edge));
        if (!\in_array($edge, ['', 'off', 'disabled', 'false', '0', 'none'], true)) {
            throw new \InvalidArgumentException(
                'Nginx-only WLS backend requires wls.http.protocol_edge=disabled.'
            );
        }

        return self::EDGE_DISABLED;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function disabledBoolean(array $data, string $field): bool
    {
        if (!\array_key_exists($field, $data)) {
            return false;
        }
        if (!\is_bool($data[$field]) || $data[$field]) {
            throw new \InvalidArgumentException(
                'Nginx-only WLS backend requires ' . $field . '=false.'
            );
        }

        return false;
    }
}
