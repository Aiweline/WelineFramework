<?php
declare(strict_types=1);

namespace Weline\Server\Protocol\Http3;

/**
 * Contract for the WLS HTTP/3 data-plane adapter.
 *
 * Implementations must prove that UDP accept, QUIC TLS 1.3 handshake,
 * HTTP/3 stream multiplexing and WorkerPolicyKernel dispatch are all wired
 * before WLS advertises h3 to clients. The capability probe consumes this
 * readonly descriptor; request hot paths must not instantiate probe objects.
 */
interface QuicTransportAdapterInterface
{
    /**
     * Stop admitting new HTTP/3 streams and begin graceful connection drain.
     */
    public function beginDrain(): void;

    /**
     * @return array{
     *     available:bool,
     *     adapter:string,
     *     reason:string,
     *     capabilities:array<string,bool>,
     *     missing:list<string>
     * }
     */
    public function readiness(): array;
}
