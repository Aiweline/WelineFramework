<?php
declare(strict_types=1);

namespace Weline\Server\Protocol\Http3;

/**
 * Explicit negative adapter used until the real QUIC/UDP Worker transport lands.
 *
 * Keeping this as code, rather than a hard-coded boolean inside the doctor,
 * makes HTTP/3 enablement depend on a replaceable data-plane contract and keeps
 * current diagnostics honest: h3 must never be advertised over the TCP TLS
 * Worker by accident.
 */
final class UnavailableQuicTransportAdapter implements QuicTransportAdapterInterface
{
    /**
     * @return array{
     *     available:bool,
     *     adapter:string,
     *     reason:string,
     *     capabilities:array<string,bool>,
     *     missing:list<string>
     * }
     */
    public function readiness(): array
    {
        return [
            'available' => false,
            'adapter' => self::class,
            'reason' => 'No WLS QUIC/UDP worker transport adapter is installed; HTTP/3 must not be advertised until UDP accept, QUIC TLS 1.3 handshake, stream multiplexing, and WorkerPolicyKernel dispatch are implemented and self-tested.',
            'capabilities' => [
                'udp_accept_loop' => false,
                'quic_tls13_handshake' => false,
                'http3_stream_multiplexing' => false,
                'worker_policy_dispatch' => false,
                'h3_alt_svc_advertising' => false,
            ],
            'missing' => [
                'udp_quic_listener',
                'tls1.3_quic_stack',
                'http3_stream_multiplexing',
                'worker_quic_dispatch',
            ],
        ];
    }
}
