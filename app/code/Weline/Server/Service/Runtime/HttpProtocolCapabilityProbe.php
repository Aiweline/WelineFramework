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

        return [
            'default_policy' => [
                'preferred' => 'http/2',
                'fallback' => ['http/1.1'],
                'http3_when_available' => true,
            ],
            'php' => [
                'version' => \PHP_VERSION,
                'os_family' => \PHP_OS_FAMILY,
                'architecture' => (string)\php_uname('m'),
                'openssl_loaded' => \extension_loaded('openssl'),
                'ffi_loaded' => \extension_loaded('FFI'),
                'ffi_runtime' => $ffiRuntime['available'],
                'ffi_reason' => $ffiRuntime['reason'],
                'stream_alpn_option' => $this->streamAcceptsAlpnOption(),
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
            'wls_adapters' => [
                'http1' => [
                    'enabled' => true,
                    'transport' => 'stream',
                    'notes' => 'Current WorkerPolicyKernel and WlsRequest path accepts HTTP/1.0 and HTTP/1.1 text requests.',
                ],
                'http2' => [
                    'enabled' => false,
                    'foundation' => [
                        'frame_codec' => \class_exists(\Weline\Server\Protocol\Http2\FrameCodec::class),
                        'hpack_decoder' => \class_exists(\Weline\Server\Protocol\Http2\HpackDecoder::class),
                        'hpack_huffman' => false,
                    ],
                    'reason' => 'HTTP/2 connection adapter is not active yet; advertising h2 would make clients send binary frames to the HTTP/1.1 parser.',
                    'requires' => ['alpn_h2', 'hpack_huffman', 'h2_connection_adapter', 'h2_response_writer'],
                ],
                'http3' => [
                    'enabled' => false,
                    'reason' => 'HTTP/3 requires a QUIC/UDP transport adapter; it cannot be served by the current TCP TLS stream Worker.',
                    'requires' => ['udp_quic_listener', 'ngtcp2_or_equivalent', 'nghttp3_or_equivalent'],
                ],
            ],
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
