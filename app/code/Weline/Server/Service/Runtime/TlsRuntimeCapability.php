<?php
declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

/**
 * Immutable, serializable TLS/HTTP reuse capability description.
 *
 * It intentionally separates connection reuse and multiplexing from verified
 * cross-connection TLS session resumption.
 */
final readonly class TlsRuntimeCapability
{
    /**
     * @param array<string, mixed> $stream
     * @param array<string, mixed> $eventBuffer
     * @param array<string, mixed> $http3Quic
     */
    public function __construct(
        public bool $tls13Server,
        public string $handshakeAvoidance,
        public array $stream,
        public array $eventBuffer,
        public bool $http2Multiplex,
        public array $http3Quic,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tls_1_3_server' => $this->tls13Server,
            'handshake_avoidance' => $this->handshakeAvoidance,
            'stream' => $this->stream,
            'event_buffer' => $this->eventBuffer,
            'http2_multiplex' => $this->http2Multiplex,
            'http3_quic' => $this->http3Quic,
        ];
    }
}
