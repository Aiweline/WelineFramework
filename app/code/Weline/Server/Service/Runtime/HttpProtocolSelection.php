<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

/**
 * Immutable public HTTP protocol contract for one WLS instance.
 *
 * HTTP/3 is a QUIC/UDP transport and therefore cannot be selected by the TCP
 * ALPN handshake used by HTTP/2 and HTTP/1.1. A protocol edge publishes
 * Alt-Svc for normal discovery and can also be advertised through DNS
 * HTTPS/SVCB for first-connection HTTP/3.
 */
final readonly class HttpProtocolSelection
{
    public const HTTP_3 = 'h3';
    public const HTTP_2 = 'h2';
    public const HTTP_1 = 'h1';
    public const EDGE_NATIVE = 'native';
    public const EDGE_CADDY = 'caddy';
    public const EDGE_DISABLED = 'disabled';

    /** @var list<string> */
    public const DEFAULT_PROTOCOLS = [
        self::HTTP_3,
        self::HTTP_2,
        self::HTTP_1,
    ];

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
        if ($protocols === [] || !\array_is_list($protocols)) {
            throw new \InvalidArgumentException('HTTP protocol selection requires a non-empty ordered list.');
        }
        foreach ($protocols as $protocol) {
            if (!\in_array($protocol, self::DEFAULT_PROTOCOLS, true)) {
                throw new \InvalidArgumentException('Unsupported normalized HTTP protocol: ' . $protocol);
            }
        }
        if (!\in_array($preferred, $protocols, true)) {
            throw new \InvalidArgumentException('Preferred HTTP protocol must exist in the enabled protocol list.');
        }
        if (!\in_array($edge, [self::EDGE_NATIVE, self::EDGE_CADDY, self::EDGE_DISABLED], true)) {
            throw new \InvalidArgumentException('Unsupported HTTP protocol edge: ' . $edge);
        }
        if ($this->requiresProtocolEdge() && $edge === self::EDGE_DISABLED) {
            throw new \InvalidArgumentException('HTTP/2 and HTTP/3 require the WLS protocol edge.');
        }
    }

    /**
     * @param array<string, mixed> $config Flattened WLS instance config.
     */
    public static function fromConfig(array $config, bool $sslEnabled): self
    {
        if (!$sslEnabled) {
            return new self(
                [self::HTTP_1],
                self::HTTP_1,
                self::EDGE_DISABLED,
                false,
                false,
            );
        }

        $http = \is_array($config['http'] ?? null) ? $config['http'] : [];
        $protocols = self::normalizeProtocols($http['protocols'] ?? self::DEFAULT_PROTOCOLS);
        $preferred = self::normalizeProtocol($http['preferred'] ?? self::HTTP_3);
        if (!\in_array($preferred, $protocols, true)) {
            throw new \RuntimeException(
                'wls.http.preferred must be one of the enabled wls.http.protocols.'
            );
        }

        // Preserve the explicitly ordered product contract. h3 is discovered
        // through QUIC/Alt-Svc or DNS HTTPS/SVCB; h2/h1 use TLS ALPN.
        $edge = self::normalizeEdge($http['protocol_edge'] ?? 'auto', $protocols);
        $requiresEdge = \in_array(self::HTTP_3, $protocols, true)
            || \in_array(self::HTTP_2, $protocols, true);
        if ($requiresEdge && $edge === self::EDGE_DISABLED) {
            throw new \RuntimeException(
                'WLS Workers currently speak HTTP/1.1; enabling HTTP/2 or HTTP/3 requires '
                . 'wls.http.protocol_edge=auto/native (or explicit caddy compatibility mode).'
            );
        }

        return new self(
            $protocols,
            $preferred,
            $edge,
            (bool)($http['tls_session_resumption'] ?? true),
            (bool)($http['alt_svc'] ?? true),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $protocols = self::normalizeProtocols($data['protocols'] ?? self::DEFAULT_PROTOCOLS);
        $preferred = self::normalizeProtocol($data['preferred'] ?? self::HTTP_3);
        $edge = self::normalizeEdge($data['edge'] ?? self::EDGE_NATIVE, $protocols);

        return new self(
            $protocols,
            $preferred,
            $edge,
            (bool)($data['tls_session_resumption'] ?? true),
            (bool)($data['alt_svc'] ?? true),
        );
    }

    public function isProtocolEdgeEnabled(): bool
    {
        return $this->edge !== self::EDGE_DISABLED;
    }

    public function isNativeProtocolEdge(): bool
    {
        return $this->edge === self::EDGE_NATIVE;
    }

    public function isCaddyProtocolEdge(): bool
    {
        return $this->edge === self::EDGE_CADDY;
    }

    public function requiresProtocolEdge(): bool
    {
        return \in_array(self::HTTP_3, $this->protocols, true)
            || \in_array(self::HTTP_2, $this->protocols, true);
    }

    public function supports(string $protocol): bool
    {
        return \in_array(self::normalizeProtocol($protocol), $this->protocols, true);
    }

    /**
     * @return list<string>
     */
    public function caddyProtocols(): array
    {
        return $this->protocols;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'protocols' => $this->protocols,
            'preferred' => $this->preferred,
            'edge' => $this->edge,
            'tls_session_resumption' => $this->tlsSessionResumption,
            'alt_svc' => $this->altSvc,
            'http3_transport' => \in_array(self::HTTP_3, $this->protocols, true) ? 'quic_udp' : 'disabled',
            'tcp_alpn' => \array_values(\array_filter(
                $this->protocols,
                static fn (string $protocol): bool => $protocol !== self::HTTP_3,
            )),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toConfig(): array
    {
        return [
            'protocols' => $this->protocols,
            'preferred' => $this->preferred,
            'protocol_edge' => $this->edge,
            'protocol_edge_enabled' => $this->isProtocolEdgeEnabled(),
            'tls_session_resumption' => $this->tlsSessionResumption,
            'alt_svc' => $this->altSvc,
        ];
    }

    /**
     * @return list<string>
     */
    private static function normalizeProtocols(mixed $value): array
    {
        if (\is_string($value)) {
            $value = \preg_split('/[\s,]+/', \trim($value), -1, \PREG_SPLIT_NO_EMPTY);
        }
        if (!\is_array($value) || $value === []) {
            throw new \RuntimeException('wls.http.protocols must be a non-empty list.');
        }

        $normalized = [];
        foreach ($value as $protocol) {
            if (!\is_scalar($protocol)) {
                throw new \RuntimeException('wls.http.protocols may contain only strings.');
            }
            $protocol = self::normalizeProtocol((string)$protocol);
            if (!\in_array($protocol, $normalized, true)) {
                $normalized[] = $protocol;
            }
        }

        return $normalized;
    }

    private static function normalizeProtocol(mixed $protocol): string
    {
        $protocol = \strtolower(\trim((string)$protocol));

        return match ($protocol) {
            'h3', 'http3', 'http/3', 'http/3.0', '3', '3.0' => self::HTTP_3,
            'h2', 'http2', 'http/2', 'http/2.0', '2', '2.0' => self::HTTP_2,
            'h1', 'http1', 'http1.1', 'http/1', 'http/1.1', '1', '1.1' => self::HTTP_1,
            default => throw new \RuntimeException('Unsupported HTTP protocol "' . $protocol . '".'),
        };
    }

    /**
     * @param list<string> $protocols
     */
    private static function normalizeEdge(mixed $edge, array $protocols): string
    {
        if (\is_bool($edge)) {
            $edge = $edge ? self::EDGE_NATIVE : self::EDGE_DISABLED;
        }
        $edge = \strtolower(\trim((string)$edge));
        if ($edge === '' || $edge === 'auto') {
            return \in_array(self::HTTP_3, $protocols, true)
                || \in_array(self::HTTP_2, $protocols, true)
                ? self::EDGE_NATIVE
                : self::EDGE_DISABLED;
        }

        return match ($edge) {
            'native', 'wls', 'on', 'enabled', 'true', '1' => self::EDGE_NATIVE,
            'caddy' => self::EDGE_CADDY,
            'off', 'disabled', 'false', '0', 'none' => self::EDGE_DISABLED,
            default => throw new \RuntimeException(
                'wls.http.protocol_edge must be auto, native, caddy, or disabled.'
            ),
        };
    }
}
