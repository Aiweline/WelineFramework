<?php

declare(strict_types=1);

namespace Weline\Server\Protocol\Http3;

/**
 * Stable Darwin QUIC datagram router.
 *
 * One exact-bound public UDP socket owns every HTTP/3 datagram. Authenticated
 * generation-fenced Unix datagram channels route each connection to its
 * selected Worker without Darwin SO_REUSEPORT packet stealing.
 */
final class DarwinDatagramRouterTransport
{
    private const OK = 0;
    private const AGAIN = 1;

    private mixed $ffi = null;
    private mixed $router = null;
    private mixed $selectStream = null;
    private int $boundPort = 0;

    /** @param array{max_initial_datagram_bytes?:int,retry_token_lifetime_ms?:int} $options */
    public function open(
        string $host,
        int $port,
        string $retrySecret,
        array $options = [],
    ): void {
        if ($this->router !== null) {
            throw new \LogicException('Darwin HTTP/3 datagram router is already open.');
        }
        if (\PHP_OS_FAMILY !== 'Darwin' || $port < 0 || $port > 65535 || \strlen($retrySecret) !== 32) {
            throw new \InvalidArgumentException('Invalid Darwin HTTP/3 datagram router configuration.');
        }
        $loaded = NativeTransportLibrary::load();
        if (!($loaded['available'] ?? false)) {
            throw new \RuntimeException((string)($loaded['reason'] ?? 'native transport unavailable'));
        }
        $this->ffi = $loaded['ffi'];
        $retry = $this->ffi->new('uint8_t[32]');
        \FFI::memcpy($retry, $retrySecret, 32);

        $config = $this->ffi->new('wls_h3_datagram_router_config');
        $config->struct_size = \FFI::sizeof($config);
        $config->max_initial_datagram_bytes = \max(
            1200,
            \min(1452, (int)($options['max_initial_datagram_bytes'] ?? 1452)),
        );
        $config->retry_token_lifetime_ms = \max(
            1000,
            (int)($options['retry_token_lifetime_ms'] ?? 10000),
        );
        $config->retry_secret = \FFI::addr($retry[0]);
        $config->retry_secret_length = 32;
        $routerOut = $this->ffi->new('wls_h3_datagram_router *[1]');
        $this->assertResult(
            (int)$this->ffi->wls_h3_datagram_router_new(\FFI::addr($config), $routerOut),
            'create Darwin HTTP/3 datagram router',
        );
        $this->router = $routerOut[0];

        $hostBuffer = $this->nativeString($host);
        $this->assertResult(
            (int)$this->ffi->wls_h3_datagram_router_bind($this->router, $hostBuffer, $port),
            'bind Darwin HTTP/3 datagram router',
        );
        $this->boundPort = (int)$this->ffi->wls_h3_datagram_router_bound_port($this->router);
        if ($this->boundPort <= 0) {
            throw new \RuntimeException('Darwin HTTP/3 datagram router returned an invalid port.');
        }
        $selectFd = (int)$this->ffi->wls_h3_datagram_router_wait_fd($this->router);
        if ($selectFd < 0) {
            throw new \RuntimeException('Darwin HTTP/3 datagram router did not expose a selectable descriptor.');
        }
        $this->selectStream = @\fopen('php://fd/' . $selectFd, 'r');
        if (!\is_resource($this->selectStream)) {
            throw new \RuntimeException('PHP could not wrap the Darwin HTTP/3 datagram router descriptor.');
        }
        @\stream_set_blocking($this->selectStream, false);
    }

    /**
     * @param list<array{worker_id:int,generation:int,accepting_new_connections:bool,channel_path:string,channel_key:string}> $workers
     */
    public function publishWorkers(array $workers, int $routeEpoch): void
    {
        if ($this->router === null || $routeEpoch <= 0 || \count($workers) > 64) {
            throw new \InvalidArgumentException('Invalid Darwin HTTP/3 route snapshot.');
        }
        $count = \count($workers);
        $endpoints = $this->ffi->new('wls_h3_worker_endpoint[' . \max(1, $count) . ']');
        $buffers = [];
        foreach (\array_values($workers) as $index => $worker) {
            $path = \trim((string)($worker['channel_path'] ?? ''));
            $key = (string)($worker['channel_key'] ?? '');
            if ((int)($worker['worker_id'] ?? 0) <= 0
                || (int)($worker['generation'] ?? 0) <= 0
                || $path === ''
                || \strlen($key) !== 32
            ) {
                throw new \InvalidArgumentException('Invalid Darwin HTTP/3 Worker endpoint.');
            }
            $pathBuffer = $this->nativeString($path);
            $keyBuffer = $this->ffi->new('uint8_t[32]');
            \FFI::memcpy($keyBuffer, $key, 32);
            $buffers[] = $pathBuffer;
            $buffers[] = $keyBuffer;
            $endpoints[$index]->struct_size = \FFI::sizeof($endpoints[$index]);
            $endpoints[$index]->worker_id = (int)$worker['worker_id'];
            $endpoints[$index]->generation = (int)$worker['generation'];
            $endpoints[$index]->accepting_new_connections =
                (bool)($worker['accepting_new_connections'] ?? false) ? 1 : 0;
            $endpoints[$index]->channel_path = \FFI::addr($pathBuffer[0]);
            $endpoints[$index]->channel_key = \FFI::addr($keyBuffer[0]);
            $endpoints[$index]->channel_key_length = 32;
        }
        $this->assertResult(
            (int)$this->ffi->wls_h3_datagram_router_publish_workers(
                $this->router,
                $count > 0 ? \FFI::addr($endpoints[0]) : null,
                $count,
                $routeEpoch,
            ),
            'publish Darwin HTTP/3 Worker routes',
        );
        unset($buffers);
    }

    public function poll(int $timeoutMs = 0): int
    {
        if ($this->router === null) {
            return 0;
        }
        $processed = $this->ffi->new('uint32_t');
        $result = (int)$this->ffi->wls_h3_datagram_router_poll(
            $this->router,
            \max(0, $timeoutMs),
            \FFI::addr($processed),
        );
        if ($result === self::AGAIN) {
            return 0;
        }
        $this->assertResult($result, 'poll Darwin HTTP/3 datagram router');
        return \max(0, (int)$processed->cdata);
    }

    /** @return array<string,int> */
    public function stats(): array
    {
        if ($this->router === null) {
            return [];
        }
        $stats = $this->ffi->new('wls_h3_datagram_router_stats');
        $stats->struct_size = \FFI::sizeof($stats);
        $this->assertResult(
            (int)$this->ffi->wls_h3_datagram_router_get_stats($this->router, \FFI::addr($stats)),
            'read Darwin HTTP/3 datagram router stats',
        );
        return [
            'received_datagrams' => (int)$stats->received_datagrams,
            'routed_datagrams' => (int)$stats->routed_datagrams,
            'ingress_drops' => (int)$stats->ingress_drops,
            'pending_ingress_datagrams' => (int)$stats->pending_ingress_datagrams,
            'ingress_datagrams_queued' => (int)$stats->ingress_datagrams_queued,
            'ingress_queue_sends' => (int)$stats->ingress_queue_sends,
            'ingress_queue_retries' => (int)$stats->ingress_queue_retries,
            'ingress_queue_drops' => (int)$stats->ingress_queue_drops,
            'egress_datagrams' => (int)$stats->egress_datagrams,
            'egress_drops' => (int)$stats->egress_drops,
            'channel_auth_failures' => (int)$stats->channel_auth_failures,
            'retry_sent' => (int)$stats->retry_sent,
            'retry_validated' => (int)$stats->retry_validated,
            'rejected_initials' => (int)$stats->rejected_initials,
            'route_epoch' => (int)$stats->route_epoch,
            'active_endpoints' => (int)$stats->active_endpoints,
            'accepting_endpoints' => (int)$stats->accepting_endpoints,
            'live_authorizations' => (int)$stats->live_authorizations,
            'provisional_authorizations' => (int)$stats->provisional_authorizations,
            'established_authorizations' => (int)$stats->established_authorizations,
            'closing_authorizations' => (int)$stats->closing_authorizations,
            'pending_terminal_closes' => (int)$stats->pending_terminal_closes,
            'terminal_closes_cached' => (int)$stats->terminal_closes_cached,
            'terminal_close_sends' => (int)$stats->terminal_close_sends,
            'terminal_close_resends' => (int)$stats->terminal_close_resends,
            'terminal_close_drops' => (int)$stats->terminal_close_drops,
            'terminal_close_rate_limited' => (int)$stats->terminal_close_rate_limited,
            'pending_egress_datagrams' => (int)$stats->pending_egress_datagrams,
            'egress_datagrams_queued' => (int)$stats->egress_datagrams_queued,
            'egress_queue_sends' => (int)$stats->egress_queue_sends,
            'egress_queue_retries' => (int)$stats->egress_queue_retries,
            'egress_queue_drops' => (int)$stats->egress_queue_drops,
        ];
    }

    public function boundPort(): int
    {
        return $this->boundPort;
    }

    public function selectStream(): mixed
    {
        return $this->selectStream;
    }

    public function close(): void
    {
        if (\is_resource($this->selectStream)) {
            @\fclose($this->selectStream);
        }
        $this->selectStream = null;
        if ($this->router !== null && $this->ffi !== null) {
            $this->ffi->wls_h3_datagram_router_destroy($this->router);
        }
        $this->router = null;
        $this->ffi = null;
        $this->boundPort = 0;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function nativeString(string $value): mixed
    {
        $buffer = $this->ffi->new('char[' . (\strlen($value) + 1) . ']');
        \FFI::memcpy($buffer, $value, \strlen($value));
        $buffer[\strlen($value)] = "\0";
        return $buffer;
    }

    private function assertResult(int $result, string $operation, bool $allowPositive = false): void
    {
        if ($result === self::OK || ($allowPositive && $result > 0)) {
            return;
        }
        $reason = '';
        if ($this->ffi !== null) {
            try {
                $nativeReason = $this->ffi->wls_transport_last_error();
                $reason = \is_string($nativeReason) ? $nativeReason : \FFI::string($nativeReason);
            } catch (\Throwable) {
            }
        }
        throw new \RuntimeException($operation . ' failed'
            . ($reason !== '' ? ': ' . $reason : ' (code=' . $result . ')'));
    }
}
