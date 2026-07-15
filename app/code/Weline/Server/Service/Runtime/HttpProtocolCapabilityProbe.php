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
        $streamAlpn = $this->streamAcceptsAlpnOption();
        $udpSocket = $this->probeUdpSocketRuntime();
        $quicTransportAdapter = $this->probeWlsQuicTransportAdapter();
        $http2AdapterSelfTest = $this->http2AdapterSelfTest();
        $http2Enabled = $streamAlpn && (bool)($http2AdapterSelfTest['ok'] ?? false);
        $http3Readiness = $this->buildHttp3Readiness($curl, $ffiRuntime, $nghttp3, $ngtcp2, $udpSocket, $quicTransportAdapter);
        $wlsAdapters = $this->buildWlsAdapterSnapshot($http2Enabled, $http2AdapterSelfTest, $http3Readiness, $quicTransportAdapter);
        $defaultPolicy = $this->buildDefaultPolicy($curl, $wlsAdapters);

        return [
            'default_policy' => $defaultPolicy,
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
                // PHP streams do not expose a stable selected-ALPN accessor in
                // this Worker path, so the Worker must only advertise h2 after a
                // real h2 adapter is installed and self-tested.
                'stream_selected_alpn_visible' => false,
            ],
            'curl_client' => [
                'version' => $curl['version'] ?? null,
                'ssl_version' => $curl['ssl_version'] ?? null,
                'http2_constant' => \defined('CURL_HTTP_VERSION_2_0'),
                'http3_constant' => \defined('CURL_HTTP_VERSION_3'),
                'http2_feature' => $this->curlFeatureEnabled($curl, 'CURL_VERSION_HTTP2'),
                'http3_feature' => $this->curlFeatureEnabled($curl, 'CURL_VERSION_HTTP3'),
            ],
            'native_libraries' => [
                'nghttp2' => $nghttp2,
                'nghttp3' => $nghttp3,
                'ngtcp2' => $ngtcp2,
            ],
            'udp' => $udpSocket,
            'http3_readiness' => $http3Readiness,
            'wls_adapters' => $wlsAdapters,
        ];
    }

    /**
     * @param array<string,mixed> $curl
     * @param array<string,mixed> $wlsAdapters
     * @return array{target_preferred:string,effective_preferred:string,fallback:list<string>,negotiation_order:list<string>,http3_when_available:bool,selection_rule:string}
     */
    private function buildDefaultPolicy(array $curl, array $wlsAdapters): array
    {
        $effective = $this->selectEffectiveHttpVersion($curl, $wlsAdapters);

        return [
            'target_preferred' => 'http/3',
            'effective_preferred' => 'http/' . $effective,
            'fallback' => ['http/2', 'http/1.1'],
            'negotiation_order' => ['http/3', 'http/2', 'http/1.1'],
            'http3_when_available' => true,
            'selection_rule' => 'prefer HTTP/3 only when both the benchmark/client runtime and WLS QUIC adapter support it; otherwise HTTP/2, then HTTP/1.1',
        ];
    }

    /**
     * @param array<string,mixed> $curl
     * @param array<string,mixed> $wlsAdapters
     */
    private function selectEffectiveHttpVersion(array $curl, array $wlsAdapters): string
    {
        $curlHttp3 = \defined('CURL_HTTP_VERSION_3') && $this->curlFeatureEnabled($curl, 'CURL_VERSION_HTTP3');
        if ($curlHttp3 && (bool)($wlsAdapters['http3']['enabled'] ?? false)) {
            return '3';
        }

        $curlHttp2 = \defined('CURL_HTTP_VERSION_2_0') && $this->curlFeatureEnabled($curl, 'CURL_VERSION_HTTP2');
        if ($curlHttp2 && (bool)($wlsAdapters['http2']['enabled'] ?? false)) {
            return '2';
        }

        return '1.1';
    }

    /**
     * @param array<string,mixed> $http2AdapterSelfTest
     * @param array<string,mixed> $http3Readiness
     * @param array{available:bool,adapter:string,reason:string,capabilities:array<string,bool>,missing:list<string>} $quicTransportAdapter
     * @return array<string,mixed>
     */
    private function buildWlsAdapterSnapshot(bool $http2Enabled, array $http2AdapterSelfTest, array $http3Readiness, array $quicTransportAdapter): array
    {
        return [
            'http1' => [
                'enabled' => true,
                'transport' => 'stream',
                'notes' => 'Current WorkerPolicyKernel and WlsRequest path accepts HTTP/1.0 and HTTP/1.1 text requests.',
            ],
            'http2' => [
                'enabled' => $http2Enabled,
                'foundation' => [
                    'frame_codec' => \class_exists(\Weline\Server\Protocol\Http2\FrameCodec::class),
                    'hpack_decoder' => \class_exists(\Weline\Server\Protocol\Http2\HpackDecoder::class),
                    'hpack_huffman' => true,
                    'connection_adapter' => \class_exists(\Weline\Server\Protocol\Http2\ConnectionAdapter::class),
                    'response_writer' => \class_exists(\Weline\Server\Protocol\Http2\ConnectionAdapter::class),
                    'adapter_self_test' => (bool)($http2AdapterSelfTest['ok'] ?? false),
                ],
                'reason' => $http2Enabled
                    ? 'HTTP/2 ALPN and Worker connection adapter self-test passed; WLS can negotiate h2 and bridge requests through the unified policy pipeline.'
                    : (string)($http2AdapterSelfTest['reason'] ?? 'HTTP/2 adapter or ALPN capability is unavailable.'),
                'requires' => ['alpn_h2', 'hpack_huffman', 'worker_alpn_dispatch'],
            ],
            'http3' => [
                'enabled' => (bool)($http3Readiness['ready'] ?? false),
                'transport' => 'quic/udp',
                'adapter' => $quicTransportAdapter['adapter'],
                'adapter_reason' => $quicTransportAdapter['reason'],
                'adapter_capabilities' => $quicTransportAdapter['capabilities'],
                'foundation' => $http3Readiness['checks'],
                'reason' => (bool)($http3Readiness['ready'] ?? false)
                    ? 'HTTP/3 QUIC/UDP adapter and protocol prerequisites are ready; WLS may advertise h3 when the runtime selects it.'
                    : 'HTTP/3 requires a WLS QUIC/UDP transport adapter; current readiness: ' . $http3Readiness['summary'],
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
        $adapterClass = \Weline\Server\Protocol\Http3\UnavailableQuicTransportAdapter::class;

        $interfaceFile = \dirname(__DIR__, 2) . '/Protocol/Http3/QuicTransportAdapterInterface.php';
        $adapterFile = \dirname(__DIR__, 2) . '/Protocol/Http3/UnavailableQuicTransportAdapter.php';
        if (!\interface_exists($interface, false) && \is_file($interfaceFile)) {
            require_once $interfaceFile;
        }
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
     * @param array<string,mixed> $udpSocket
     * @param array{available:bool,adapter:string,reason:string,capabilities:array<string,bool>,missing:list<string>} $quicTransportAdapter
     * @return array{ready:bool,summary:string,checks:array<string,bool>,missing:list<string>,install_hints:list<string>}
     */
    private function buildHttp3Readiness(array $curl, array $ffiRuntime, array $nghttp3, array $ngtcp2, array $udpSocket, array $quicTransportAdapter): array
    {
        $checks = [
            'udp_socket_runtime' => (bool)($udpSocket['available'] ?? false),
            'ffi_runtime' => (bool)($ffiRuntime['available'] ?? false),
            'ngtcp2_library' => (bool)($ngtcp2['available'] ?? false),
            'ngtcp2_ffi_loadable' => (bool)($ngtcp2['ffi_loadable'] ?? false),
            'nghttp3_library' => (bool)($nghttp3['available'] ?? false),
            'nghttp3_ffi_loadable' => (bool)($nghttp3['ffi_loadable'] ?? false),
            'curl_http3_client' => \defined('CURL_HTTP_VERSION_3') && $this->curlFeatureEnabled($curl, 'CURL_VERSION_HTTP3'),
            'wls_quic_transport_adapter' => (bool)($quicTransportAdapter['available'] ?? false),
        ];
        $missing = [];
        foreach ($checks as $name => $ok) {
            if (!$ok) {
                $missing[] = $name;
            }
        }

        $installHints = match (\PHP_OS_FAMILY) {
            'Darwin' => ['brew install ngtcp2 nghttp3 curl-openssl', 'ensure PHP curl is linked with HTTP/3-capable libcurl'],
            'Windows' => ['install verified ARM64-compatible ngtcp2/nghttp3/curl DLLs before enabling QUIC', 'keep Dispatcher TCP fallback on Windows until a QUIC adapter is verified'],
            default => ['install distro packages for ngtcp2/nghttp3 and HTTP/3-capable curl', 'enable or ship a WLS QUIC/UDP transport adapter'],
        };

        return [
            'ready' => $missing === [],
            'summary' => $missing === [] ? 'all HTTP/3 prerequisites are present' : ('missing ' . \implode(',', $missing)),
            'checks' => $checks,
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
            || !\class_exists(\Weline\Server\Protocol\Http2\ConnectionAdapter::class)) {
            return ['ok' => false, 'reason' => 'HTTP/2 frame codec or connection adapter class is missing'];
        }

        try {
            $adapterClass = \Weline\Server\Protocol\Http2\ConnectionAdapter::class;
            $frameClass = \Weline\Server\Protocol\Http2\FrameCodec::class;
            $adapter = new $adapterClass();
            $clientBytes = $frameClass::CLIENT_CONNECTION_PREFACE
                . $frameClass::encode($frameClass::TYPE_SETTINGS, 0, 0)
                . $frameClass::encode($frameClass::TYPE_WINDOW_UPDATE, 0, 0, \pack('N', 65535))
                . $frameClass::encode(
                    $frameClass::TYPE_HEADERS,
                    $frameClass::FLAG_END_HEADERS | $frameClass::FLAG_END_STREAM,
                    1,
                    "\x82\x87\x41\x0fself-test.local\x84"
                );
            $received = $adapter->receive($clientBytes);
            if (($received['status'] ?? '') !== 'ok' || empty($received['requests'][0]['raw_request'])) {
                return ['ok' => false, 'reason' => 'HTTP/2 adapter did not emit a bridged request during self-test'];
            }
            $response = $adapter->encodeResponse(1, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nOK");
            if ($response === '') {
                return ['ok' => false, 'reason' => 'HTTP/2 adapter did not encode a response during self-test'];
            }
            return ['ok' => true, 'reason' => 'HTTP/2 adapter self-test passed'];
        } catch (\Throwable $exception) {
            return ['ok' => false, 'reason' => $exception->getMessage()];
        }
    }

    private function streamAcceptsAlpnOption(): bool
    {
        try {
            $context = \stream_context_create(['ssl' => ['alpn_protocols' => "h2,http/1.1"]]);
            $options = \stream_context_get_options($context);
            return (($options['ssl']['alpn_protocols'] ?? null) === "h2,http/1.1");
        } catch (\Throwable) {
            return false;
        }
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
