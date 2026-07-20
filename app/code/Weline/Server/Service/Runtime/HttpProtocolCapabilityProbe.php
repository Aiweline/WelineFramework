<?php
declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

/**
 * Reports what the current PHP/WLS runtime can honestly negotiate and serve.
 *
 * This is deliberately stricter than client capability: cURL may be able to
 * request HTTP/2 or HTTP/3 while the WLS Worker data plane can still only serve
 * HTTP/1.1. Start/benchmark/doctor code should gate protocol advertising on the
 * WLS adapter booleans, not on cURL alone.
 */
final class HttpProtocolCapabilityProbe
{
    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $curl = \function_exists('curl_version') ? (array)\curl_version() : [];
        $ffiRuntime = $this->probeFfiRuntime();
        $nghttp2 = $this->probeNativeLibrary('nghttp2', 'nghttp2_strerror');
        $nghttp3 = $this->probeNativeLibrary('nghttp3', 'nghttp3_strerror');
        $ngtcp2 = $this->probeNativeLibrary('ngtcp2', 'ngtcp2_strerror');
        $ngtcp2CryptoOssl = $this->probeNativeLibrary('ngtcp2_crypto_ossl', 'ngtcp2_crypto_ossl_configure_server_session');
        $tlsAlpn = (new TlsAlpnRuntimeProbe())->snapshot();
        $streamAlpn = $this->streamAcceptsAlpnOption();
        $udpSocket = $this->probeUdpSocketRuntime();
        $quicTransportAdapter = $this->probeWlsQuicTransportAdapter();
        $http2AdapterSelfTest = $this->http2AdapterSelfTest();
        $http2Configured = $streamAlpn && (bool)($tlsAlpn['configured'] ?? false);
        $http3Readiness = $this->buildHttp3Readiness(
            $curl, $ffiRuntime, $nghttp3, $ngtcp2, $ngtcp2CryptoOssl, $udpSocket, $quicTransportAdapter
        );
        $wlsAdapters = $this->buildWlsAdapterSnapshot(
            $http2Configured,
            $tlsAlpn,
            $http2AdapterSelfTest,
            $http3Readiness,
            $quicTransportAdapter
        );
        $tlsSessionReuse = (new TlsSessionResumptionCapabilityProbe())->snapshot($wlsAdapters);
        $defaultPolicy = $this->buildDefaultPolicy($curl, $wlsAdapters, $tlsSessionReuse, $tlsAlpn);

        return [
            'default_policy' => $defaultPolicy,
            'tls_alpn' => $tlsAlpn,
            'php' => [
                'version' => \PHP_VERSION,
                'os_family' => \PHP_OS_FAMILY,
                'architecture' => (string)\php_uname('m'),
                'openssl_loaded' => \extension_loaded('openssl'),
                'ffi_loaded' => \extension_loaded('FFI'),
                'ffi_runtime' => $ffiRuntime['available'],
                'ffi_reason' => $ffiRuntime['reason'],
                'stream_alpn_option' => $streamAlpn,
                'udp_socket_runtime' => (bool)($udpSocket['available'] ?? false),
                'udp_socket_reason' => (string)($udpSocket['reason'] ?? ''),
                'stream_selected_alpn_visible' => false,
            ],
            'tls_session_reuse' => $tlsSessionReuse,
            'curl_client' => [
                'version' => $curl['version'] ?? null,
                'ssl_version' => $curl['ssl_version'] ?? null,
                'http2_constant' => \defined('CURL_HTTP_VERSION_2_0'),
                'http3_constant' => \defined('CURL_HTTP_VERSION_3'),
                'http3_only_constant' => \defined('CURL_HTTP_VERSION_3ONLY'),
                'http2_feature' => $this->curlFeatureEnabled($curl, 'CURL_VERSION_HTTP2'),
                'http3_feature' => $this->curlFeatureEnabled($curl, 'CURL_VERSION_HTTP3'),
            ],
            'native_libraries' => [
                'nghttp2' => $nghttp2,
                'nghttp3' => $nghttp3,
                'ngtcp2' => $ngtcp2,
                'ngtcp2_crypto_ossl' => $ngtcp2CryptoOssl,
            ],
            'udp' => $udpSocket,
            'http3_readiness' => $http3Readiness,
            'wls_adapters' => $wlsAdapters,
        ];
    }

    /**
     * @param array<string,mixed> $curl
     * @param array<string,mixed> $wlsAdapters
     * @return array<string,mixed>
     */
    private function buildDefaultPolicy(
        array $curl,
        array $wlsAdapters,
        array $tlsResumption,
        array $tlsAlpn
    ): array
    {
        $effective = $this->selectEffectiveHttpVersion($curl, $wlsAdapters);
        $http2Ready = (bool)($wlsAdapters['http2']['enabled'] ?? false);
        $http3Ready = (bool)($wlsAdapters['http3']['enabled'] ?? false);
        $http3ClientReady = \defined('CURL_HTTP_VERSION_3ONLY')
            && $this->curlFeatureEnabled($curl, 'CURL_VERSION_HTTP3');
        $http3AutoReady = $http3Ready && $http3ClientReady;
        $streamTls = \is_array($tlsResumption['stream'] ?? null) ? $tlsResumption['stream'] : [];
        $sharedContextSessionReuseVerified = (bool)($streamTls['shared_ssl_context'] ?? false)
            && (bool)($streamTls['stream_context_ticket_callback_supported'] ?? false)
            && (bool)($streamTls['server_session_reuse_observable'] ?? false)
            && (bool)($streamTls['session_resumption_verified'] ?? false);
        $externalStatefulSessionReuseVerified = (bool)($streamTls['external_stateful_session_api_available'] ?? false)
            && (bool)($streamTls['external_cache_configured'] ?? false)
            && (bool)($streamTls['external_cache_runtime_verified'] ?? false)
            && (bool)($streamTls['server_session_reuse_observable'] ?? false)
            && (bool)($streamTls['session_resumption_verified'] ?? false);
        $sessionReuseVerified = $sharedContextSessionReuseVerified
            || $externalStatefulSessionReuseVerified;
        $sessionReuseSupported = $sharedContextSessionReuseVerified
            || (bool)($streamTls['external_stateful_session_api_available'] ?? false);
        $http3Capabilities = \is_array($wlsAdapters['http3']['adapter_capabilities'] ?? null)
            ? $wlsAdapters['http3']['adapter_capabilities']
            : [];
        $http3TicketRingSupported = $http3Ready
            && (bool)($http3Capabilities['native_tls_ticket_key_ring'] ?? false)
            && (bool)($http3Capabilities['ssl_ctx_ticket_callback'] ?? false);
        $http3SessionResumptionVerified = $http3TicketRingSupported
            && (bool)($http3Capabilities['tls_session_resumption_verified'] ?? false);
        $http3CrossWorkerSupported = $http3TicketRingSupported
            && (bool)($http3Capabilities['cross_worker_ticket_key_ring'] ?? false);
        $http3CrossWorkerVerified = $http3CrossWorkerSupported
            && $http3SessionResumptionVerified
            && (bool)($http3Capabilities['tls_cross_worker_session_resumption_verified'] ?? false);

        return [
            'target_preferred' => 'http/2',
            'effective_preferred' => 'http/' . $effective,
            'fallback' => $http3AutoReady ? ['http/2', 'http/1.1'] : ['http/1.1'],
            'negotiation_order' => $http3AutoReady
                ? ['http/3', 'http/2', 'http/1.1']
                : ['http/2', 'http/1.1'],
            'http3_when_available' => true,
            'http3_runtime_ready' => $http3Ready,
            'http3_client_ready' => $http3ClientReady,
            'http3_selection' => $http3AutoReady
                ? 'automatically prefer the verified HTTP/3 QUIC/UDP data plane, then fall back to HTTP/2 and HTTP/1.1'
                : ($http3Ready
                    ? 'advertise Alt-Svc, but keep HTTP/2 as the effective client default until this cURL build can issue strict HTTP/3 requests'
                    : 'do not advertise HTTP/3 until the WLS QUIC/UDP adapter is runtime-ready'),
            'tls13_server' => (bool)($tlsResumption['tls_1_3_server'] ?? false),
            'tls13_server_supported' => (bool)($tlsResumption['tls_1_3_server'] ?? false),
            'tls13_runtime_verified' => (bool)($tlsAlpn['tls13_runtime_verified'] ?? false),
            'alpn_http2' => $http2Ready,
            'tls_session_reuse' => [
                'supported' => $sessionReuseSupported,
                'verified' => $sessionReuseVerified,
                'enabled' => (bool)($streamTls['external_cache_configured'] ?? false),
                'active_verified' => $externalStatefulSessionReuseVerified,
                'transport' => 'tcp',
                'data_plane' => 'php_stream_ssl',
                'external_stateful_session_api_min_php' => (string)($streamTls['external_stateful_session_api_min_php'] ?? '8.6.0'),
                'external_stateful_session_api_available' => (bool)($streamTls['external_stateful_session_api_available'] ?? false),
                'external_cache_api_disables_stateless_tickets' => (bool)($streamTls['external_cache_api_disables_stateless_tickets'] ?? false),
                'external_cache_mode' => (string)($streamTls['external_cache_mode'] ?? 'off'),
                'external_cache_config_valid' => (bool)($streamTls['external_cache_config_valid'] ?? true),
                'external_cache_configured' => (bool)($streamTls['external_cache_configured'] ?? false),
                'external_cache_worker_eligible' => (bool)($streamTls['external_cache_worker_eligible'] ?? false),
                'external_cache_dedicated_ram_store' => (bool)($streamTls['external_cache_dedicated_ram_store'] ?? false),
                'external_cache_callback_connects_or_retries' => (bool)($streamTls['external_cache_callback_connects_or_retries'] ?? false),
                'external_cache_runtime_mechanism_verified' => (bool)($streamTls['external_cache_runtime_mechanism_verified'] ?? false),
                'external_cache_runtime_verified' => (bool)($streamTls['external_cache_runtime_verified'] ?? false),
                'durable_evidence_verified' => (bool)($streamTls['external_cache_durable_evidence_verified'] ?? false),
                'active_config_matches_evidence' => (bool)($streamTls['external_cache_active_config_matches_evidence'] ?? false),
                'current_scope_evaluated' => (bool)($streamTls['external_cache_current_scope_evaluated'] ?? false),
                'current_scope_matches_evidence' => (bool)($streamTls['external_cache_current_scope_matches_evidence'] ?? false),
                'runtime_prerelease' => (bool)($streamTls['external_cache_runtime_prerelease'] ?? true),
                'php_release_channel' => (string)($streamTls['external_cache_php_release_channel'] ?? 'unknown'),
                'tls13_stateful_ticket_expected' => (bool)($streamTls['tls13_stateful_ticket_expected'] ?? false),
                'stateless_ticket_key_ring' => (bool)($streamTls['stateless_ticket_key_ring'] ?? false),
                'session_ticket_configured' => (bool)($streamTls['session_ticket_configured'] ?? false),
                'session_id_context_configured_by_worker' => (bool)($streamTls['session_id_context_configured_by_worker'] ?? false),
                'server_session_reuse_observable' => (bool)($streamTls['server_session_reuse_observable'] ?? false),
                'same_worker_session_resumption_verified' => (bool)($streamTls['same_worker_session_resumption_verified'] ?? false),
                'cross_worker_session_resumption_verified' => (bool)($streamTls['cross_worker_session_resumption_verified'] ?? false),
                'reload_continuity_verified' => (bool)($streamTls['reload_continuity_verified'] ?? false),
                'sidecar_recovery_verified' => (bool)($streamTls['sidecar_recovery_verified'] ?? false),
                'performance_baseline_verified' => (bool)($streamTls['performance_baseline_verified'] ?? false),
                'resumption_tls_p95_ms' => \is_numeric($streamTls['resumption_tls_p95_ms'] ?? null)
                    ? (float)$streamTls['resumption_tls_p95_ms']
                    : null,
                'diagnostic_resumption_tls_p95_limit_ms' => \is_numeric(
                    $streamTls['diagnostic_resumption_tls_p95_limit_ms'] ?? null
                ) ? (float)$streamTls['diagnostic_resumption_tls_p95_limit_ms'] : null,
                'production_resumption_tls_p95_limit_ms' => (float)(
                    $streamTls['production_resumption_tls_p95_limit_ms'] ?? 50.0
                ),
                'resumption_latency_gate_verified' => (bool)($streamTls['resumption_latency_gate_verified'] ?? false),
                'production_platform_matrix_verified' => (bool)($streamTls['production_platform_matrix_verified'] ?? false),
                'production_ready' => (bool)($streamTls['production_ready'] ?? false),
                'cross_worker_ticket_key_ring' => false,
                'cross_worker_ticket_reuse_verified' => false,
                'evidence' => \is_array($streamTls['external_cache_evidence'] ?? null)
                    ? $streamTls['external_cache_evidence']
                    : [],
                'reason' => (string)($streamTls['reason'] ?? 'TLS 1.3 and ALPN HTTP/2 are available, but PHP stream SSL has not verified reusable cross-connection sessions.'),
            ],
            'http3_tls_session_resumption' => [
                'supported' => $http3TicketRingSupported,
                'verified' => $http3SessionResumptionVerified,
                'transport' => 'quic/udp',
                'data_plane' => 'native_http3_quic',
                'ticket_model' => 'stateless_shared_key_ring',
                'stateless_ticket_key_ring' => $http3TicketRingSupported,
                'cross_context_verified' => (bool)($http3Capabilities['tls_cross_context_session_resumption_verified'] ?? false),
                'rotation_continuity_verified' => (bool)($http3Capabilities['tls_ticket_rotation_continuity_verified'] ?? false),
                'server_session_reuse_observable' => (bool)($http3Capabilities['tls_server_session_reuse_observable'] ?? false),
                'early_data_disabled' => (bool)($http3Capabilities['tls_early_data_disabled'] ?? false),
                'reason' => $http3SessionResumptionVerified
                    ? 'Native HTTP/3 verified a full handshake plus Ticket issuance, independent SSL_CTX resumption through the rotated previous key, and true server-side reuse counters; 0-RTT remains disabled.'
                    : 'HTTP/3 TLS resumption requires the verified native ticket-ring callback and two-handshake server-side proof.',
            ],
            'cross_worker_session_ticket' => [
                'supported' => $http3CrossWorkerSupported,
                'verified' => $http3CrossWorkerVerified,
                'transport' => 'http3_quic_only',
                'ticket_model' => 'stateless_shared_key_ring',
                'requires' => 'native HTTP/3 SSL_CTX ticket key ring shared by all Workers',
                'reason' => $http3CrossWorkerVerified
                    ? 'Native HTTP/3 uses the same acknowledged ticket-ring epoch/digest in every Worker and has verified cross-context server-side session reuse; this does not apply to TCP HTTP/2 or HTTP/1.1.'
                    : 'Cross-Worker Ticket reuse is pending until a live instance proves every READY Worker acknowledged the same epoch/digest and resumed handshakes reached more than one Worker; it must not be inferred from the native cross-context self-test, Keep-Alive, HTTP/2 multiplexing, or Alt-Svc.',
            ],
            'tls_session_resumption' => $tlsResumption,
            'selection_rule' => 'prefer verified HTTP/3 automatically when both HTTPS client and WLS QUIC data plane are ready; otherwise use HTTP/2 as the default TCP protocol and fall back to HTTP/1.1; report session resumption per data plane because native HTTP/3 proof does not enable PHP Stream TCP resumption',
        ];
    }

    /**
     * @param array<string,mixed> $curl
     * @param array<string,mixed> $wlsAdapters
     */
    private function selectEffectiveHttpVersion(array $curl, array $wlsAdapters): string
    {
        $curlHttp3 = \defined('CURL_HTTP_VERSION_3ONLY')
            && $this->curlFeatureEnabled($curl, 'CURL_VERSION_HTTP3');
        if ($curlHttp3
            && (bool)($wlsAdapters['http3']['enabled'] ?? false)
            && (bool)($wlsAdapters['http3']['runtime_verified'] ?? false)
        ) {
            return '3';
        }

        $curlHttp2 = \defined('CURL_HTTP_VERSION_2_0') && $this->curlFeatureEnabled($curl, 'CURL_VERSION_HTTP2');
        if ($curlHttp2 && (bool)($wlsAdapters['http2']['enabled'] ?? false)) {
            return '2';
        }

        return '1.1';
    }

    /**
     * @param array<string,mixed> $tlsAlpn
     * @param array<string,mixed> $http2AdapterSelfTest
     * @param array<string,mixed> $http3Readiness
     * @param array{available:bool,adapter:string,reason:string,capabilities:array<string,bool>,missing:list<string>} $quicTransportAdapter
     * @return array<string,mixed>
     */
    private function buildWlsAdapterSnapshot(
        bool $http2Configured,
        array $tlsAlpn,
        array $http2AdapterSelfTest,
        array $http3Readiness,
        array $quicTransportAdapter
    ): array
    {
        $http2Implemented = \class_exists(\Weline\Server\Protocol\Http2\FrameCodec::class)
            && \class_exists(\Weline\Server\Protocol\Http2\HpackDecoder::class)
            && \class_exists(\Weline\Server\Protocol\Http2\ConnectionAdapter::class)
            && \class_exists(\Weline\Server\Protocol\Http2\MultiplexScheduler::class);
        $runtimeVerified = $http2Configured
            && (bool)($tlsAlpn['runtime_verified'] ?? false)
            && $http2Implemented
            && (bool)($http2AdapterSelfTest['runtime_verified'] ?? false);
        $multiplexingVerified = $runtimeVerified
            && (bool)($http2AdapterSelfTest['multiplexing_verified'] ?? false);
        $maxConcurrentStreams = $multiplexingVerified
            ? (int)($http2AdapterSelfTest['max_concurrent_streams'] ?? 0)
            : 0;
        $http2Enabled = $multiplexingVerified && $maxConcurrentStreams > 1;
        $http3RuntimeVerified = (bool)($http3Readiness['ready'] ?? false)
            && (bool)($quicTransportAdapter['capabilities']['runtime_self_test'] ?? false)
            && (bool)($quicTransportAdapter['capabilities']['worker_policy_dispatch'] ?? false)
            && (bool)($quicTransportAdapter['capabilities']['h3_alt_svc_advertising'] ?? false);

        return [
            'http1' => [
                'implemented' => true,
                'configured' => true,
                'runtime_verified' => true,
                'enabled' => true,
                'transport' => 'stream',
                'notes' => 'Current WorkerPolicyKernel and WlsRequest path accepts HTTP/1.0 and HTTP/1.1 text requests.',
            ],
            'http2' => [
                'implemented' => $http2Implemented,
                'configured' => $http2Configured,
                'runtime_verified' => $runtimeVerified,
                'enabled' => $http2Enabled,
                'completeness' => $http2Enabled ? 'request_response' : 'unavailable',
                'streaming_sse' => false,
                'streaming_reason' => 'HTTP/2 SSE DATA-frame streaming is not implemented; ordinary multiplexed request/response traffic remains available.',
                'multiplexing_verified' => $multiplexingVerified,
                'max_concurrent_streams' => $maxConcurrentStreams,
                'foundation' => [
                    'frame_codec' => \class_exists(\Weline\Server\Protocol\Http2\FrameCodec::class),
                    'hpack_decoder' => \class_exists(\Weline\Server\Protocol\Http2\HpackDecoder::class),
                    'hpack_huffman' => true,
                    'connection_adapter' => \class_exists(\Weline\Server\Protocol\Http2\ConnectionAdapter::class),
                    'multiplex_scheduler' => \class_exists(\Weline\Server\Protocol\Http2\MultiplexScheduler::class),
                    'flow_control' => (bool)($http2AdapterSelfTest['checks']['flow_control'] ?? false),
                    'rst_stream' => (bool)($http2AdapterSelfTest['checks']['rst_stream'] ?? false),
                    'goaway' => (bool)($http2AdapterSelfTest['checks']['goaway'] ?? false),
                    'adapter_self_test' => (bool)($http2AdapterSelfTest['ok'] ?? false),
                ],
                'reason' => $http2Enabled
                    ? (string)($http2AdapterSelfTest['reason'] ?? 'HTTP/2 multiplex runtime verified')
                    : 'HTTP/2 is not advertised until live loopback ALPN, multiplex scheduler, stream correlation and flow-control self-tests all pass: '
                        . (string)($http2AdapterSelfTest['reason'] ?? 'runtime verification unavailable'),
                'requires' => ['alpn_h2', 'hpack_huffman', 'worker_stream_scheduler', 'flow_control', 'runtime_self_test'],
            ],
            'http3' => [
                'implemented' => (bool)($quicTransportAdapter['available'] ?? false),
                'configured' => (bool)($http3Readiness['ready'] ?? false),
                'runtime_verified' => $http3RuntimeVerified,
                'enabled' => $http3RuntimeVerified,
                'transport' => 'quic/udp',
                'adapter' => $quicTransportAdapter['adapter'],
                'adapter_reason' => $quicTransportAdapter['reason'],
                'adapter_capabilities' => $quicTransportAdapter['capabilities'],
                'foundation' => $http3Readiness['checks'],
                'client_probe' => $http3Readiness['client_checks'] ?? [],
                'reason' => $http3RuntimeVerified
                    ? 'HTTP/3 native transport, real QUIC request/response, TLS ticket issuance and independent-context resumption self-tests are verified.'
                    : 'HTTP/3 requires a WLS QUIC/UDP transport adapter; current server readiness: ' . $http3Readiness['summary'],
                'requires' => ['udp_quic_listener', 'tls1.3_quic_stack', 'ngtcp2_or_equivalent', 'nghttp3_or_equivalent', 'worker_quic_dispatch'],
                'missing' => $http3Readiness['missing'],
                'install_hints' => $http3Readiness['install_hints'],
            ],
        ];
    }


    /** @return array{available:bool,reason:string} */
    private function probeUdpSocketRuntime(): array
    {
        $uri = 'udp://127.0.0.1:0';
        $errno = 0;
        $errstr = '';
        $socket = @\stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND);
        if (\is_resource($socket)) {
            @\fclose($socket);
            return ['available' => true, 'reason' => 'UDP bind probe succeeded on loopback'];
        }

        return [
            'available' => false,
            'reason' => $errstr !== '' ? ($errstr . ' (errno=' . $errno . ')') : 'UDP bind probe failed',
        ];
    }

    /**
     * @return array{available:bool,adapter:string,reason:string,capabilities:array<string,bool>,missing:list<string>}
     */
    private function probeWlsQuicTransportAdapter(): array
    {
        $interface = \Weline\Server\Protocol\Http3\QuicTransportAdapterInterface::class;
        $nativeAdapterClass = \Weline\Server\Protocol\Http3\Ngtcp2QuicTransportAdapter::class;
        $fallbackAdapterClass = \Weline\Server\Protocol\Http3\UnavailableQuicTransportAdapter::class;

        $interfaceFile = \dirname(__DIR__, 2) . '/Protocol/Http3/QuicTransportAdapterInterface.php';
        $nativeAdapterFile = \dirname(__DIR__, 2) . '/Protocol/Http3/Ngtcp2QuicTransportAdapter.php';
        $fallbackAdapterFile = \dirname(__DIR__, 2) . '/Protocol/Http3/UnavailableQuicTransportAdapter.php';
        if (!\interface_exists($interface, false) && \is_file($interfaceFile)) {
            require_once $interfaceFile;
        }
        $adapterClass = \is_file($nativeAdapterFile) ? $nativeAdapterClass : $fallbackAdapterClass;
        $adapterFile = $adapterClass === $nativeAdapterClass ? $nativeAdapterFile : $fallbackAdapterFile;
        if (!\class_exists($adapterClass, false) && \is_file($adapterFile)) {
            require_once $adapterFile;
        }

        if (!\interface_exists($interface, false) || !\class_exists($adapterClass, false)) {
            return [
                'available' => false,
                'adapter' => $adapterClass,
                'reason' => 'WLS HTTP/3 QUIC adapter contract is not autoloadable.',
                'capabilities' => [],
                'missing' => ['wls_quic_transport_adapter'],
            ];
        }

        $adapter = new $adapterClass();
        if (!$adapter instanceof \Weline\Server\Protocol\Http3\QuicTransportAdapterInterface) {
            return [
                'available' => false,
                'adapter' => $adapterClass,
                'reason' => 'WLS HTTP/3 adapter does not implement QuicTransportAdapterInterface.',
                'capabilities' => [],
                'missing' => ['wls_quic_transport_adapter'],
            ];
        }

        $readiness = $adapter->readiness();
        $capabilities = \is_array($readiness['capabilities'] ?? null) ? $readiness['capabilities'] : [];
        $missing = \is_array($readiness['missing'] ?? null) ? $readiness['missing'] : [];

        $nativeCapabilities = \class_exists(\Weline\Server\Protocol\Http3\NativeTransportLibrary::class)
            && \method_exists(\Weline\Server\Protocol\Http3\NativeTransportLibrary::class, 'capabilities')
            ? \Weline\Server\Protocol\Http3\NativeTransportLibrary::capabilities()
            : [];
        $capabilities['native_tls_ticket_key_ring'] = (bool)($nativeCapabilities['native_tls_ticket_key_ring'] ?? false);
        $capabilities['ssl_ctx_ticket_callback'] = (bool)($nativeCapabilities['ssl_ctx_ticket_callback'] ?? false);
        $capabilities['linux_pic_static_dependency_bundle'] = (bool)($nativeCapabilities['linux_pic_static_dependency_bundle'] ?? false);
        $capabilities['tls_ticket_ring_native_activation_verified'] = (bool)($nativeCapabilities['ticket_key_ring_native_activation_verified'] ?? false);
        $capabilities['cross_worker_ticket_key_ring'] = (bool)($nativeCapabilities['cross_worker_ticket_key_ring'] ?? false);
        $capabilities['tls_session_resumption_verified'] = (bool)($nativeCapabilities['session_resumption_verified'] ?? false);
        $capabilities['tls_cross_worker_session_resumption_verified'] = (bool)($nativeCapabilities['cross_worker_session_resumption_verified'] ?? false);
        $capabilities['tls_cross_context_session_resumption_verified'] = (bool)($nativeCapabilities['cross_context_session_resumption_verified'] ?? false);
        $capabilities['tls_ticket_rotation_continuity_verified'] = (bool)($nativeCapabilities['ticket_rotation_continuity_verified'] ?? false);
        $capabilities['tls_ticket_ring_ack_activation'] = (bool)($nativeCapabilities['ticket_key_ring_ack_activation'] ?? false);
        $capabilities['tls_server_session_reuse_observable'] = (bool)($nativeCapabilities['server_session_reuse_observable'] ?? false);
        $capabilities['tls_early_data_disabled'] = (bool)($nativeCapabilities['early_data_disabled'] ?? false);

        return [
            'available' => (bool)($readiness['available'] ?? false),
            'adapter' => (string)($readiness['adapter'] ?? $adapterClass),
            'reason' => (string)($readiness['reason'] ?? 'WLS HTTP/3 adapter readiness did not provide a reason.'),
            'capabilities' => \array_map(static fn($value): bool => (bool)$value, $capabilities),
            'missing' => \array_values(\array_map(static fn($value): string => (string)$value, $missing)),
        ];
    }

    /**
     * @param array<string,mixed> $curl
     * @param array<string,mixed> $ffiRuntime
     * @param array<string,mixed> $nghttp3
     * @param array<string,mixed> $ngtcp2
     * @param array<string,mixed> $ngtcp2CryptoOssl
     * @param array<string,mixed> $udpSocket
     * @param array{available:bool,adapter:string,reason:string,capabilities:array<string,bool>,missing:list<string>} $quicTransportAdapter
     * @return array{ready:bool,summary:string,checks:array<string,bool>,missing:list<string>,install_hints:list<string>}
     */
    private function buildHttp3Readiness(array $curl, array $ffiRuntime, array $nghttp3, array $ngtcp2, array $ngtcp2CryptoOssl, array $udpSocket, array $quicTransportAdapter): array
    {
        $serverChecks = [
            'udp_socket_runtime' => (bool)($udpSocket['available'] ?? false),
            'ffi_runtime' => (bool)($ffiRuntime['available'] ?? false),
        ];
        if (\PHP_OS_FAMILY === 'Linux') {
            $serverChecks['wls_private_pic_static_bundle'] = (bool)(
                $quicTransportAdapter['capabilities']['linux_pic_static_dependency_bundle'] ?? false
            );
        } else {
            $serverChecks['ngtcp2_library'] = (bool)($ngtcp2['available'] ?? false);
            $serverChecks['ngtcp2_ffi_loadable'] = (bool)($ngtcp2['ffi_loadable'] ?? false);
            $serverChecks['ngtcp2_crypto_ossl_library'] = (bool)($ngtcp2CryptoOssl['available'] ?? false);
            $serverChecks['ngtcp2_crypto_ossl_ffi_loadable'] = (bool)($ngtcp2CryptoOssl['ffi_loadable'] ?? false);
            $serverChecks['nghttp3_library'] = (bool)($nghttp3['available'] ?? false);
            $serverChecks['nghttp3_ffi_loadable'] = (bool)($nghttp3['ffi_loadable'] ?? false);
        }
        $serverChecks['wls_quic_transport_adapter'] = (bool)($quicTransportAdapter['available'] ?? false);
        $serverChecks['runtime_quic_loopback_self_test'] = (bool)(
            $quicTransportAdapter['capabilities']['runtime_self_test'] ?? false
        );
        $serverChecks['worker_policy_dispatch'] = (bool)(
            $quicTransportAdapter['capabilities']['worker_policy_dispatch'] ?? false
        );
        $clientChecks = [
            'curl_http3_client' => \defined('CURL_HTTP_VERSION_3') && $this->curlFeatureEnabled($curl, 'CURL_VERSION_HTTP3'),
        ];
        $missing = [];
        foreach ($serverChecks as $name => $ok) {
            if (!$ok) {
                $missing[] = $name;
            }
        }

        $installHints = match (\PHP_OS_FAMILY) {
            'Darwin' => ['brew install ngtcp2 nghttp3', 'enable or ship a WLS QUIC/UDP transport adapter'],
            'Windows' => ['install verified architecture-compatible ngtcp2/nghttp3 DLLs', 'add a WLS UDP/DCID Dispatcher adapter before advertising h3'],
            default => ['build the WLS private PIC-static ngtcp2/nghttp3 dependency bundle', 'enable or ship a WLS QUIC/UDP transport adapter'],
        };

        return [
            'ready' => $missing === [],
            'summary' => $missing === [] ? 'all HTTP/3 server prerequisites are present' : ('missing ' . \implode(',', $missing)),
            'checks' => $serverChecks,
            'client_checks' => $clientChecks,
            'missing' => \array_values(\array_unique(\array_merge($missing, $quicTransportAdapter['missing'] ?? []))),
            'install_hints' => $installHints,
        ];
    }

    /** @return array{available:bool,reason:string} */
    private function probeFfiRuntime(): array
    {
        if (!\extension_loaded('FFI') || !\class_exists('FFI')) {
            return ['available' => false, 'reason' => 'FFI extension is not loaded'];
        }

        try {
            $ffi = \FFI::cdef('int abs(int);', $this->libcName());
            $ffi->abs(-1);
            return ['available' => true, 'reason' => 'FFI::cdef runtime probe succeeded'];
        } catch (\Throwable $exception) {
            return ['available' => false, 'reason' => $exception->getMessage()];
        }
    }

    /** @return array{available:bool,path:?string,ffi_loadable:bool,reason:string} */
    private function probeNativeLibrary(string $name, string $symbol): array
    {
        $path = $this->findNativeLibrary($name);
        if ($path === null) {
            return ['available' => false, 'path' => null, 'ffi_loadable' => false, 'reason' => 'library not found'];
        }
        if (!\extension_loaded('FFI') || !\class_exists('FFI')) {
            return ['available' => true, 'path' => $path, 'ffi_loadable' => false, 'reason' => 'library exists; FFI unavailable'];
        }

        try {
            \FFI::cdef('const char *' . $symbol . '(int);', $path);
            return ['available' => true, 'path' => $path, 'ffi_loadable' => true, 'reason' => 'library exists and FFI loaded symbol table'];
        } catch (\Throwable $exception) {
            return ['available' => true, 'path' => $path, 'ffi_loadable' => false, 'reason' => $exception->getMessage()];
        }
    }

    /** @return array{ok:bool,reason:string} */
    private function http2AdapterSelfTest(): array
    {
        if (!\class_exists(\Weline\Server\Protocol\Http2\FrameCodec::class)
            || !\class_exists(\Weline\Server\Protocol\Http2\ConnectionAdapter::class)
            || !\class_exists(\Weline\Server\Protocol\Http2\MultiplexScheduler::class)
        ) {
            return [
                'ok' => false,
                'runtime_verified' => false,
                'multiplexing_verified' => false,
                'max_concurrent_streams' => 0,
                'checks' => [],
                'reason' => 'HTTP/2 runtime classes are missing',
            ];
        }

        try {
            $adapterClass = \Weline\Server\Protocol\Http2\ConnectionAdapter::class;
            $frameClass = \Weline\Server\Protocol\Http2\FrameCodec::class;
            $schedulerClass = \Weline\Server\Protocol\Http2\MultiplexScheduler::class;
            $headerBlock = "\x83\x87\x41\x0fself-test.local\x84";
            $settingsPayload = \pack('nN', $frameClass::SETTINGS_MAX_CONCURRENT_STREAMS, 32);

            $adapter = new $adapterClass();
            $received = $adapter->receive(
                $frameClass::CLIENT_CONNECTION_PREFACE
                . $frameClass::encode($frameClass::TYPE_SETTINGS, 0, 0, $settingsPayload)
                . $frameClass::encode($frameClass::TYPE_HEADERS, $frameClass::FLAG_END_HEADERS, 1, $headerBlock)
                . $frameClass::encode($frameClass::TYPE_HEADERS, $frameClass::FLAG_END_HEADERS, 3, $headerBlock)
                . $frameClass::encode($frameClass::TYPE_DATA, $frameClass::FLAG_END_STREAM, 3, 'B')
                . $frameClass::encode($frameClass::TYPE_DATA, $frameClass::FLAG_END_STREAM, 1, 'A')
            );
            $streamIds = \array_map(
                static fn (array $request): int => (int)($request['stream_id'] ?? 0),
                (array)($received['requests'] ?? [])
            );
            $interleavedStreams = ($received['status'] ?? '') === 'ok'
                && $streamIds === [3, 1]
                && \str_ends_with((string)($received['requests'][0]['raw_request'] ?? ''), 'B')
                && \str_ends_with((string)($received['requests'][1]['raw_request'] ?? ''), 'A');
            $peerSettingsHonored = (int)($adapter->diagnostics()['peer_max_concurrent_streams'] ?? -1) === 32;

            $responseThree = $adapter->encodeResponse(3, "HTTP/1.1 200 OK\r\nContent-Length: 1\r\n\r\n3");
            $responseOne = $adapter->encodeResponse(1, "HTTP/1.1 200 OK\r\nContent-Length: 1\r\n\r\n1");
            $correlatedResponses = $responseThree !== '' && $responseOne !== ''
                && ($adapter->diagnostics()['active_streams'] ?? -1) === 0;

            $scheduler = $schedulerClass::selfTest();
            $schedulerVerified = (bool)($scheduler['ok'] ?? false);

            $headerAdapter = new $adapterClass();
            $headerAdapter->receive(
                $frameClass::CLIENT_CONNECTION_PREFACE
                . $frameClass::encode($frameClass::TYPE_SETTINGS, 0, 0)
                . $frameClass::encode(
                    $frameClass::TYPE_HEADERS,
                    $frameClass::FLAG_END_HEADERS | $frameClass::FLAG_END_STREAM,
                    1,
                    $headerBlock
                )
            );
            $headerBytes = $headerAdapter->encodeSimpleResponse(
                1,
                200,
                ['x-wls-self-test' => \str_repeat('h', 20000)],
                ''
            );
            $headerTypes = [];
            $headerEndStream = false;
            $headerEndHeaders = false;
            while ($headerBytes !== '') {
                $decodedHeaderFrame = $frameClass::decodeOne($headerBytes);
                if (($decodedHeaderFrame['status'] ?? '') !== 'frame') {
                    $headerTypes = [];
                    break;
                }
                $headerTypes[] = (int)($decodedHeaderFrame['type'] ?? -1);
                if (\count($headerTypes) === 1) {
                    $headerEndStream = (((int)($decodedHeaderFrame['flags'] ?? 0)) & $frameClass::FLAG_END_STREAM)
                        === $frameClass::FLAG_END_STREAM;
                }
                $headerEndHeaders = (((int)($decodedHeaderFrame['flags'] ?? 0)) & $frameClass::FLAG_END_HEADERS)
                    === $frameClass::FLAG_END_HEADERS;
                $headerBytes = \substr($headerBytes, (int)($decodedHeaderFrame['consumed'] ?? 0));
            }
            $headerFragmentation = $headerTypes === [$frameClass::TYPE_HEADERS, $frameClass::TYPE_CONTINUATION]
                && $headerEndStream
                && $headerEndHeaders;

            $streamZeroAdapter = new $adapterClass();
            $streamZero = $streamZeroAdapter->receive(
                $frameClass::CLIENT_CONNECTION_PREFACE
                . $frameClass::encode($frameClass::TYPE_SETTINGS, 0, 0)
                . $frameClass::encode($frameClass::TYPE_DATA, $frameClass::FLAG_END_STREAM, 0, 'invalid')
            );
            $dataStreamZeroRejected = ($streamZero['status'] ?? '') === 'error'
                && (int)($streamZero['error_code'] ?? -1) === $frameClass::ERROR_PROTOCOL_ERROR;

            $flowAdapter = new $adapterClass();
            $flowSettings = \pack('nN', $frameClass::SETTINGS_INITIAL_WINDOW_SIZE, 100000);
            $flowAdapter->receive(
                $frameClass::CLIENT_CONNECTION_PREFACE
                . $frameClass::encode($frameClass::TYPE_SETTINGS, 0, 0, $flowSettings)
                . $frameClass::encode(
                    $frameClass::TYPE_HEADERS,
                    $frameClass::FLAG_END_HEADERS | $frameClass::FLAG_END_STREAM,
                    1,
                    $headerBlock
                )
            );
            $flowAdapter->encodeResponse(
                1,
                "HTTP/1.1 200 OK\r\nContent-Length: 70000\r\n\r\n" . \str_repeat('x', 70000)
            );
            $blocked = $flowAdapter->hasPendingResponseData()
                && (int)($flowAdapter->diagnostics()['connection_send_window'] ?? -1) === 0;
            $unblocked = $flowAdapter->receive($frameClass::windowUpdate(0, 4465));
            $flowControl = $blocked
                && (string)($unblocked['write'] ?? '') !== ''
                && !$flowAdapter->hasPendingResponseData();

            // Regression: one large peer window can exceed the adapter's bounded
            // DATA generation budget. The transport must be able to pull further
            // batches without waiting for another WINDOW_UPDATE.
            $largeFlowBodyBytes = 600000;
            $largeFlowAdapter = new $adapterClass();
            $largeFlowSettings = \pack(
                'nN',
                $frameClass::SETTINGS_INITIAL_WINDOW_SIZE,
                $largeFlowBodyBytes
            );
            $largeFlowAdapter->receive(
                $frameClass::CLIENT_CONNECTION_PREFACE
                . $frameClass::encode($frameClass::TYPE_SETTINGS, 0, 0, $largeFlowSettings)
                . $frameClass::encode(
                    $frameClass::TYPE_HEADERS,
                    $frameClass::FLAG_END_HEADERS | $frameClass::FLAG_END_STREAM,
                    1,
                    $headerBlock
                )
            );
            $largeFlowAdapter->encodeResponse(
                1,
                "HTTP/1.1 200 OK\r\nContent-Length: {$largeFlowBodyBytes}\r\n\r\n"
                . \str_repeat('f', $largeFlowBodyBytes)
            );
            $largeFlowWindow = $largeFlowAdapter->receive(
                $frameClass::windowUpdate(0, $largeFlowBodyBytes)
            );
            $largeFlowDrainBatches = 0;
            while ($largeFlowAdapter->hasPendingResponseData() && $largeFlowDrainBatches < 8) {
                $largeFlowBatch = $largeFlowAdapter->drainPendingResponseData();
                if ($largeFlowBatch === '') {
                    break;
                }
                $largeFlowDrainBatches++;
            }
            $largeFlowControl = (string)($largeFlowWindow['write'] ?? '') !== ''
                && $largeFlowDrainBatches >= 2
                && !$largeFlowAdapter->hasPendingResponseData()
                && (int)($largeFlowAdapter->diagnostics()['active_streams'] ?? -1) === 0;

            $streamFlowAdapter = new $adapterClass();
            $streamFlowSettings = \pack('nN', $frameClass::SETTINGS_INITIAL_WINDOW_SIZE, 1);
            $streamFlowAdapter->receive(
                $frameClass::CLIENT_CONNECTION_PREFACE
                . $frameClass::encode($frameClass::TYPE_SETTINGS, 0, 0, $streamFlowSettings)
                . $frameClass::encode(
                    $frameClass::TYPE_HEADERS,
                    $frameClass::FLAG_END_HEADERS | $frameClass::FLAG_END_STREAM,
                    1,
                    $headerBlock
                )
            );
            $streamFlowAdapter->encodeResponse(1, "HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\nFLOW");
            $streamBlocked = $streamFlowAdapter->hasPendingResponseData();
            $streamFlowAdapter->receive($frameClass::windowUpdate(1, 3));
            $streamFlow = $streamBlocked && !$streamFlowAdapter->hasPendingResponseData();

            $resetAdapter = new $adapterClass();
            $resetAdapter->receive(
                $frameClass::CLIENT_CONNECTION_PREFACE
                . $frameClass::encode($frameClass::TYPE_SETTINGS, 0, 0, $streamFlowSettings)
                . $frameClass::encode(
                    $frameClass::TYPE_HEADERS,
                    $frameClass::FLAG_END_HEADERS | $frameClass::FLAG_END_STREAM,
                    1,
                    $headerBlock
                )
            );
            $resetAdapter->encodeResponse(1, "HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\nRST!");
            $resetResult = $resetAdapter->receive($frameClass::rstStream(1));
            $rstStream = \in_array(1, (array)($resetResult['reset_streams'] ?? []), true)
                && !$resetAdapter->hasPendingResponseData();

            $goawayAdapter = new $adapterClass();
            $goawayResult = $goawayAdapter->receive(
                $frameClass::CLIENT_CONNECTION_PREFACE
                . $frameClass::encode($frameClass::TYPE_SETTINGS, 0, 0)
                . $frameClass::goaway(0)
            );
            $goaway = (bool)($goawayResult['peer_goaway'] ?? false)
                && (bool)($goawayAdapter->diagnostics()['peer_goaway'] ?? false);

            $checks = [
                'interleaved_streams' => $interleavedStreams,
                'response_correlation' => $correlatedResponses,
                'scheduler_identity' => $schedulerVerified,
                'peer_settings' => $peerSettingsHonored,
                'header_fragmentation' => $headerFragmentation,
                'data_stream_zero' => $dataStreamZeroRejected,
                'flow_control' => $flowControl && $streamFlow && $largeFlowControl,
                'bounded_response_drain' => $largeFlowControl,
                'rst_stream' => $rstStream,
                'goaway' => $goaway,
            ];
            $ok = !\in_array(false, $checks, true);

            return [
                'ok' => $ok,
                'runtime_verified' => $ok,
                'multiplexing_verified' => $ok,
                'max_concurrent_streams' => $ok ? $adapterClass::MAX_CONCURRENT_STREAMS : 0,
                'checks' => $checks,
                'reason' => $ok
                    ? 'HTTP/2 runtime self-test passed: interleaved streams, response correlation, peer SETTINGS, header fragmentation, bounded flow-control drain, RST_STREAM and GOAWAY'
                    : 'HTTP/2 runtime self-test failed: ' . \implode(',', \array_keys(\array_filter($checks, static fn (bool $value): bool => !$value))),
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'runtime_verified' => false,
                'multiplexing_verified' => false,
                'max_concurrent_streams' => 0,
                'checks' => [],
                'reason' => $exception->getMessage(),
            ];
        }
    }

    private function streamAcceptsAlpnOption(): bool
    {
        return (new TlsAlpnRuntimeProbe())->configured();
    }

    /** @param array<string, mixed> $curl */
    private function curlFeatureEnabled(array $curl, string $constant): bool
    {
        if (!\defined($constant)) {
            return false;
        }
        $features = (int)($curl['features'] ?? 0);
        return ($features & (int)\constant($constant)) !== 0;
    }

    private function findNativeLibrary(string $name): ?string
    {
        $names = match (\PHP_OS_FAMILY) {
            'Darwin' => ['lib' . $name . '.dylib'],
            'Windows' => [$name . '.dll', 'lib' . $name . '.dll'],
            default => ['lib' . $name . '.so', 'lib' . $name . '.so.0', 'lib' . $name . '.so.1', 'lib' . $name . '.so.14', 'lib' . $name . '.so.16'],
        };

        foreach ($this->librarySearchDirectories() as $directory) {
            foreach ($names as $candidate) {
                $path = \rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $candidate;
                if (\is_file($path)) {
                    return $path;
                }
            }
        }

        foreach ($names as $candidate) {
            if ($candidate !== '' && @\file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function librarySearchDirectories(): array
    {
        $directories = [];
        foreach (['DYLD_LIBRARY_PATH', 'LD_LIBRARY_PATH', 'PATH'] as $env) {
            $value = (string)\getenv($env);
            if ($value === '') {
                continue;
            }
            foreach (\explode(PATH_SEPARATOR, $value) as $directory) {
                if ($directory !== '') {
                    $directories[] = $directory;
                }
            }
        }

        foreach ([
            '/opt/homebrew/lib',
            '/opt/homebrew/opt/libnghttp2/lib',
            '/opt/homebrew/opt/libnghttp3/lib',
            '/opt/homebrew/opt/libngtcp2/lib',
            '/usr/local/lib',
            '/usr/lib',
            'C:\\Program Files\\Weline\\bin',
            'C:\\Windows\\System32',
        ] as $directory) {
            $directories[] = $directory;
        }

        return \array_values(\array_unique(\array_filter($directories, static fn (string $dir): bool => $dir !== '')));
    }

    private function libcName(): string
    {
        return match (\PHP_OS_FAMILY) {
            'Darwin' => 'libc.dylib',
            'Windows' => 'ucrtbase.dll',
            default => 'libc.so.6',
        };
    }
}
