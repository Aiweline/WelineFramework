<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

/**
 * Immutable WLS endpoint protocol contract.
 *
 * Managed Nginx uses a plaintext HTTP/1.1 backend. Explicit pure-WLS mode
 * terminates TLS in PHP Stream SSL and negotiates HTTP/2 with HTTP/1.1
 * fallback. Native/Caddy protocol-edge sidecars and pure-WLS HTTP/3 are not
 * part of this contract.
 */
final readonly class HttpProtocolSelection
{
    public const HTTP_3 = 'h3';
    public const HTTP_2 = 'h2';
    public const HTTP_1 = 'h1';

    /** @deprecated External native/Caddy protocol-edge processes are retired. */
    public const EDGE_NATIVE = 'native';
    /** @deprecated Caddy is not a supported WLS edge. */
    public const EDGE_CADDY = 'caddy';
    public const EDGE_DISABLED = 'disabled';

    /** @var list<string> */
    public const DEFAULT_PROTOCOLS = [self::HTTP_2, self::HTTP_1];

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
            if (!\in_array($protocol, [self::HTTP_2, self::HTTP_1], true)) {
                throw new \InvalidArgumentException(
                    'Pure WLS supports only HTTP/2 and HTTP/1.1; HTTP/3 belongs to managed Nginx.'
                );
            }
        }
        if (!\in_array($preferred, $protocols, true)) {
            throw new \InvalidArgumentException('Preferred HTTP protocol must exist in the enabled protocol list.');
        }
        if ($edge !== self::EDGE_DISABLED) {
            throw new \InvalidArgumentException(
                'WLS protocol-edge sidecars are retired; use the in-process PHP HTTP/2 implementation.'
            );
        }
        if ($altSvc) {
            throw new \InvalidArgumentException('Pure WLS does not advertise HTTP/3 Alt-Svc.');
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config, bool $sslEnabled): self
    {
        $edgeConfig = \is_array($config['edge'] ?? null) ? $config['edge'] : [];
        $edgeAdapter = \strtolower(\trim((string)(
            $config['edge_adapter']
            ?? $edgeConfig['adapter']
            ?? \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_NGINX
        )));
        $pureWls = $edgeAdapter === \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_WLS;
        if (!$pureWls || !$sslEnabled) {
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
        $preferred = self::normalizeProtocol($http['preferred'] ?? self::HTTP_2);
        $edge = self::normalizeEdge($http['protocol_edge'] ?? self::EDGE_DISABLED);
        $tlsSessionResumption = (bool)($http['tls_session_resumption'] ?? true);
        $altSvc = (bool)($http['alt_svc'] ?? false);

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

        return new self(
            $protocols,
            $preferred,
            $edge,
            (bool)($data['tls_session_resumption'] ?? false),
            (bool)($data['alt_svc'] ?? false),
        );
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
        return \in_array(self::normalizeProtocol($protocol), $this->protocols, true);
    }

    public function assertCompatibleEdgeAdapter(string $edgeAdapter): void
    {
        $edgeAdapter = \strtolower(\trim($edgeAdapter));
        if ($edgeAdapter === \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_NGINX) {
            if ($this->protocols !== [self::HTTP_1]
                || $this->preferred !== self::HTTP_1
                || $this->tlsSessionResumption
                || $this->altSvc
            ) {
                throw new \InvalidArgumentException(
                    'Managed Nginx requires a plaintext WLS HTTP/1.1 backend policy.'
                );
            }
            return;
        }
        if ($edgeAdapter === \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_WLS) {
            if (!\in_array(self::HTTP_1, $this->protocols, true)) {
                throw new \InvalidArgumentException(
                    'Pure WLS must keep HTTP/1.1 enabled as the automatic fallback.'
                );
            }
            return;
        }

        throw new \InvalidArgumentException(
            'WLS edge adapter must be nginx or wls; received "' . $edgeAdapter . '".'
        );
    }

    /** @return list<string> */
    public function caddyProtocols(): array
    {
        return $this->protocols;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'protocols' => $this->protocols,
            'preferred' => $this->preferred,
            'edge' => self::EDGE_DISABLED,
            'tls_session_resumption' => $this->tlsSessionResumption,
            'alt_svc' => false,
            'http3_transport' => 'disabled',
            'tcp_alpn' => \array_map(
                static fn(string $protocol): string => $protocol === self::HTTP_2 ? 'h2' : 'http/1.1',
                $this->protocols,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function toConfig(): array
    {
        return [
            'protocols' => $this->protocols,
            'preferred' => $this->preferred,
            'protocol_edge' => self::EDGE_DISABLED,
            'protocol_edge_enabled' => false,
            'tls_session_resumption' => $this->tlsSessionResumption,
            'alt_svc' => false,
        ];
    }

    /** @return list<string> */
    private static function normalizeProtocols(mixed $value): array
    {
        if (\is_string($value)) {
            $value = \preg_split('/[\s,]+/', \trim($value), -1, \PREG_SPLIT_NO_EMPTY);
        }
        if (!\is_array($value) || $value === [] || !\array_is_list($value)) {
            throw new \InvalidArgumentException('wls.http.protocols must be a non-empty ordered list.');
        }

        $normalized = [];
        foreach ($value as $protocol) {
            if (!\is_scalar($protocol)) {
                throw new \InvalidArgumentException('wls.http.protocols may contain only strings.');
            }
            $protocol = self::normalizeProtocol($protocol);
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
            'h2', 'http2', 'http/2', 'http/2.0', '2', '2.0' => self::HTTP_2,
            'h1', 'http1', 'http1.1', 'http/1', 'http/1.1', '1', '1.1' => self::HTTP_1,
            'h3', 'http3', 'http/3', 'http/3.0', '3', '3.0' => throw new \InvalidArgumentException(
                'Pure WLS HTTP/3 is unavailable; use managed Nginx for HTTP/3.'
            ),
            default => throw new \InvalidArgumentException('Unsupported HTTP protocol "' . $protocol . '".'),
        };
    }

    private static function normalizeEdge(mixed $edge): string
    {
        if (\is_bool($edge)) {
            $edge = $edge ? self::EDGE_NATIVE : self::EDGE_DISABLED;
        }
        $edge = \strtolower(\trim((string)$edge));
        if (\in_array($edge, ['', 'off', 'disabled', 'false', '0', 'none'], true)) {
            return self::EDGE_DISABLED;
        }

        throw new \InvalidArgumentException(
            'wls.http.protocol_edge must be disabled; native and Caddy sidecars are not used.'
        );
    }
}
