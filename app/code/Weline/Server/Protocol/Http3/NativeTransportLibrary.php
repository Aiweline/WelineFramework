<?php

declare(strict_types=1);

namespace Weline\Server\Protocol\Http3;

/**
 * Loads the immutable, control-plane verified HTTP/3 native library.
 */
final class NativeTransportLibrary
{
    public const RUNTIME_EVIDENCE_SCHEMA = 3;

    private const MANIFEST_SCHEMA = 1;
    private const MAX_MANIFEST_BYTES = 131072;
    private const MAX_DARWIN_DEPENDENCIES = 128;
    private const MAX_DARWIN_EDGES = 512;
    private const DARWIN_LOAD_COMMANDS = [
        'LC_LOAD_DYLIB',
        'LC_LOAD_WEAK_DYLIB',
        'LC_REEXPORT_DYLIB',
        'LC_LOAD_UPWARD_DYLIB',
        'LC_LAZY_LOAD_DYLIB',
    ];
    private const DARWIN_INJECTION_ENVIRONMENT = [
        'DYLD_FRAMEWORK_PATH',
        'DYLD_FALLBACK_FRAMEWORK_PATH',
        'DYLD_LIBRARY_PATH',
        'DYLD_FALLBACK_LIBRARY_PATH',
        'DYLD_INSERT_LIBRARIES',
        'DYLD_ROOT_PATH',
        'DYLD_IMAGE_SUFFIX',
        'LD_PRELOAD',
    ];

    private const CDEF = <<<'CDEF'
typedef struct wls_tls_context wls_tls_context;
typedef struct wls_h3_server wls_h3_server;
typedef struct wls_h3_datagram_router wls_h3_datagram_router;

typedef struct {
  uint32_t struct_size;
  const char *certificate_file;
  const char *private_key_file;
  const uint8_t *alpn_wire;
  size_t alpn_wire_length;
  int32_t min_tls_version;
  int32_t max_tls_version;
  uint32_t flags;
} wls_tls_context_config;

typedef struct {
  uint32_t struct_size;
  const uint8_t *current_key;
  size_t current_key_length;
  const uint8_t *previous_key;
  size_t previous_key_length;
  uint64_t epoch;
  const char *digest;
  const uint8_t *session_context;
  size_t session_context_length;
  uint32_t ticket_lifetime_seconds;
  uint32_t flags;
} wls_tls_ticket_ring;

typedef struct {
  uint32_t struct_size;
  uint32_t flags;
  uint64_t ticket_epoch;
  uint64_t handshakes_completed;
  uint64_t full_handshakes;
  uint64_t resumed_handshakes;
  uint64_t tickets_encrypted;
  uint64_t tickets_decrypted_current;
  uint64_t tickets_decrypted_previous;
  uint64_t tickets_rejected;
  uint64_t ticket_errors;
  uint32_t ticket_lifetime_seconds;
  uint32_t reserved32;
  char ticket_digest[65];
} wls_tls_context_stats;

typedef struct {
  uint32_t struct_size;
  uint8_t disable_active_migration;
  uint8_t reserved8[7];
  uint64_t max_idle_timeout_ms;
  uint64_t initial_max_data;
  uint64_t initial_max_stream_data;
  uint64_t initial_max_streams_bidi;
  uint64_t max_connections;
  uint64_t max_active_streams;
  uint64_t retry_token_lifetime_ms;
  const uint8_t *retry_secret;
  size_t retry_secret_length;
  uint32_t max_request_header_bytes;
  uint32_t max_request_body_bytes;
} wls_h3_server_config;
typedef struct {
  uint32_t struct_size;
  uint32_t slot;
  uint32_t slot_count;
  uint32_t flags;
  uint64_t owner_epoch;
  uint64_t generation;
  const char *namespace_key;
} wls_h3_linux_route_config;

typedef struct {
  uint32_t struct_size;
  uint32_t state;
  uint32_t slot;
  uint32_t slot_count;
  uint64_t owner_epoch;
  uint64_t generation;
  uint64_t listener_cookie;
  uint64_t connection_cookie;
  uint64_t active_cids;
  uint32_t program_id;
  uint32_t listen_map_id;
  uint32_t worker_map_id;
  uint32_t count_map_id;
  uint32_t owner_map_id;
  uint32_t reserved32;
  char pin_namespace[256];
} wls_h3_linux_route_status;


typedef struct {
  uint32_t struct_size;
  uint32_t flags;
  uint64_t token;
  char *peer;
  size_t peer_capacity;
  size_t peer_length;
  uint8_t *raw_request;
  size_t raw_request_capacity;
  size_t raw_request_length;
  uint64_t connection_id;
  int64_t stream_id;
  uint32_t end_stream;
  uint32_t reserved32;
} wls_h3_request;

typedef struct {
  uint32_t struct_size;
  uint32_t flags;
  uint64_t token;
  const uint8_t *raw_response;
  size_t raw_response_length;
} wls_h3_response;

typedef struct {
  uint32_t struct_size;
  uint32_t reserved32;
  uint64_t received_datagrams;
  uint64_t accepted_initials;
  uint64_t active_connections;
  uint64_t active_streams;
  uint64_t queued_requests;
  uint64_t retry_sent;
  uint64_t retry_validated;
  uint64_t rejected_initials;
  uint64_t connection_errors;
  uint64_t connection_read_errors;
  uint64_t connection_flush_errors;
  uint64_t connection_callback_errors;
  uint64_t connection_expiry_errors;
  uint64_t draining_reads;
  uint64_t closing_reads;
  uint64_t flush_skipped_draining;
  uint64_t flush_skipped_closing;
  uint64_t write_stream_not_found;
  uint64_t connection_rotation_requests;
  uint64_t connection_rotation_goaways;
  uint64_t connection_rotation_completions;
  uint64_t max_connection_request_count;
  uint64_t last_connection_error_stage;
  int64_t last_connection_error_code;
  uint64_t capacity_rejections;
  uint64_t peer_mismatch_drops;
  uint64_t routed_datagrams;
  uint64_t channel_drops;
  uint64_t channel_auth_failures;
} wls_h3_server_stats;

typedef struct {
  uint32_t struct_size;
  uint32_t worker_id;
  uint64_t generation;
  uint16_t public_port;
  uint16_t reserved16;
  uint32_t reserved32;
  const char *channel_path;
  const uint8_t *channel_key;
  size_t channel_key_length;
} wls_h3_datagram_worker_config;

typedef struct {
  uint32_t struct_size;
  uint32_t max_initial_datagram_bytes;
  uint64_t retry_token_lifetime_ms;
  const uint8_t *retry_secret;
  size_t retry_secret_length;
} wls_h3_datagram_router_config;

typedef struct {
  uint32_t struct_size;
  uint32_t worker_id;
  uint64_t generation;
  uint8_t accepting_new_connections;
  uint8_t reserved8[7];
  const char *channel_path;
  const uint8_t *channel_key;
  size_t channel_key_length;
} wls_h3_worker_endpoint;

typedef struct {
  uint32_t struct_size;
  uint32_t reserved32;
  uint64_t received_datagrams;
  uint64_t routed_datagrams;
  uint64_t ingress_drops;
  uint64_t egress_datagrams;
  uint64_t egress_drops;
  uint64_t channel_auth_failures;
  uint64_t retry_sent;
  uint64_t retry_validated;
  uint64_t rejected_initials;
  uint64_t route_epoch;
  uint64_t active_endpoints;
  uint64_t accepting_endpoints;
  uint64_t live_authorizations;
  uint64_t provisional_authorizations;
  uint64_t established_authorizations;
  uint64_t closing_authorizations;
  uint64_t pending_terminal_closes;
  uint64_t terminal_closes_cached;
  uint64_t terminal_close_sends;
  uint64_t terminal_close_resends;
  uint64_t terminal_close_drops;
  uint64_t terminal_close_rate_limited;
  uint64_t pending_egress_datagrams;
  uint64_t egress_datagrams_queued;
  uint64_t egress_queue_sends;
  uint64_t egress_queue_retries;
  uint64_t egress_queue_drops;
  uint64_t pending_ingress_datagrams;
  uint64_t ingress_datagrams_queued;
  uint64_t ingress_queue_sends;
  uint64_t ingress_queue_retries;
  uint64_t ingress_queue_drops;
} wls_h3_datagram_router_stats;

uint32_t wls_transport_abi_version(void);
const char *wls_transport_build_id(void);
const char *wls_transport_last_error(void);
int wls_tls_context_new(const wls_tls_context_config *config, wls_tls_context **out_context);
void wls_tls_context_release(wls_tls_context *context);
uint64_t wls_tls_context_capabilities(const wls_tls_context *context);
int wls_tls_context_set_ticket_ring(wls_tls_context *context, const wls_tls_ticket_ring *ticket_ring);
int wls_tls_context_get_stats(const wls_tls_context *context, wls_tls_context_stats *stats);
int wls_h3_server_new(wls_tls_context *tls_context, const wls_h3_server_config *config, wls_h3_server **out_server);
int wls_h3_server_bind(wls_h3_server *server, const char *host, uint16_t port, int reuse_port);
int wls_h3_server_bind_linux_route(wls_h3_server *server, const char *host, uint16_t port, const wls_h3_linux_route_config *config);
int wls_h3_server_activate_linux_route(wls_h3_server *server);
int wls_h3_server_get_linux_route_status(const wls_h3_server *server, wls_h3_linux_route_status *status);
int wls_h3_server_fd(const wls_h3_server *server);
int wls_h3_server_dup_fd(const wls_h3_server *server);
int wls_h3_server_wait_fd(const wls_h3_server *server);
int wls_h3_server_dup_wait_fd(const wls_h3_server *server);
uint16_t wls_h3_server_bound_port(const wls_h3_server *server);
int wls_h3_server_bind_datagram_worker(wls_h3_server *server, const wls_h3_datagram_worker_config *config);
int wls_h3_server_begin_drain(wls_h3_server *server);
int wls_h3_server_poll(wls_h3_server *server, int timeout_ms);
int wls_h3_server_next_request(wls_h3_server *server, wls_h3_request *request);
int wls_h3_server_respond(wls_h3_server *server, const wls_h3_response *response);
int wls_h3_server_close_request(wls_h3_server *server, uint64_t token, uint64_t app_error_code);
int wls_h3_server_get_stats(const wls_h3_server *server, wls_h3_server_stats *stats);
void wls_h3_server_destroy(wls_h3_server *server);
int wls_h3_datagram_router_new(const wls_h3_datagram_router_config *config, wls_h3_datagram_router **out_router);
int wls_h3_datagram_router_bind(wls_h3_datagram_router *router, const char *host, uint16_t port);
int wls_h3_datagram_router_publish_workers(wls_h3_datagram_router *router, const wls_h3_worker_endpoint *workers, size_t worker_count, uint64_t route_epoch);
uint16_t wls_h3_datagram_router_bound_port(const wls_h3_datagram_router *router);
int wls_h3_datagram_router_dup_fd(const wls_h3_datagram_router *router);
int wls_h3_datagram_router_wait_fd(const wls_h3_datagram_router *router);
int wls_h3_datagram_router_poll(wls_h3_datagram_router *router, int timeout_ms, uint32_t *processed);
int wls_h3_datagram_router_get_stats(const wls_h3_datagram_router *router, wls_h3_datagram_router_stats *stats);
void wls_h3_datagram_router_destroy(wls_h3_datagram_router *router);
CDEF;

    /** @var array{ffi:mixed,manifest:array<string,mixed>}|null */
    private static ?array $loaded = null;
    private static ?string $failure = null;
    /** @var array<string,mixed>|null */
    private static ?array $selectedManifest = null;
    private static bool $selfTestCandidateSelected = false;

    public static function reset(): void
    {
        self::$loaded = null;
        self::$failure = null;
        self::$selectedManifest = null;
        self::$selfTestCandidateSelected = false;
    }

    /**
     * Pin a long-running Master or Worker to the immutable build selected by
     * its parent control plane. Platform-active pointers may legitimately
     * advance while another instance is still serving or recovering.
     *
     * @return array<string,mixed>
     */
    public static function pinManifest(string $fingerprint, string $expectedLibrarySha256): array
    {
        $fingerprint = \strtolower(\trim($fingerprint));
        $expectedLibrarySha256 = \strtolower(\trim($expectedLibrarySha256));
        if (\preg_match('/^[a-f0-9]{32}$/D', $fingerprint) !== 1
            || \preg_match('/^[a-f0-9]{64}$/D', $expectedLibrarySha256) !== 1
        ) {
            throw new \InvalidArgumentException('Invalid native HTTP/3 fingerprint or library digest.');
        }
        $manifest = self::readManifest(self::manifestPathForFingerprint($fingerprint, $expectedLibrarySha256));
        if (!($manifest['ready'] ?? false)
            || ($manifest['fingerprint'] ?? '') !== $fingerprint
            || ($manifest['platform'] ?? '') !== \PHP_OS_FAMILY
            || ($manifest['architecture'] ?? '') !== (string)\php_uname('m')
            || !\hash_equals($expectedLibrarySha256, (string)($manifest['library_sha256'] ?? ''))
            || !self::validArtifactManifest($manifest, $fingerprint, $expectedLibrarySha256)
            || !self::hasVerifiedRuntimeEvidence($manifest)
        ) {
            throw new \RuntimeException('Pinned native HTTP/3 manifest is missing, stale or does not match the control plane.');
        }
        self::$loaded = null;
        self::$failure = null;
        self::$selectedManifest = $manifest;
        self::$selfTestCandidateSelected = false;
        return $manifest;
    }

    /**
     * Explicit control-plane-only candidate selection for the offline runtime
     * self-test. Production callers must use pinManifest().
     *
     * @return array<string,mixed>
     */
    public static function pinSelfTestCandidate(string $fingerprint, string $expectedLibrarySha256): array
    {
        $fingerprint = \strtolower(\trim($fingerprint));
        $expectedLibrarySha256 = \strtolower(\trim($expectedLibrarySha256));
        if (\preg_match('/^[a-f0-9]{32}$/D', $fingerprint) !== 1
            || \preg_match('/^[a-f0-9]{64}$/D', $expectedLibrarySha256) !== 1
        ) {
            throw new \InvalidArgumentException('Invalid native HTTP/3 self-test fingerprint or library digest.');
        }
        $manifest = self::readManifest(self::manifestPathForFingerprint($fingerprint, $expectedLibrarySha256));
        if (!self::validArtifactManifest($manifest, $fingerprint, $expectedLibrarySha256)) {
            throw new \RuntimeException('Native HTTP/3 self-test candidate is missing or does not match its immutable artifact identity.');
        }
        self::$loaded = null;
        self::$failure = null;
        self::$selectedManifest = $manifest;
        self::$selfTestCandidateSelected = true;
        return $manifest;
    }

    /**
     * Read-only production selector. It never acquires a build lock, installs
     * dependencies, compiles code, rewrites manifests or runs a network test.
     *
     * @return array{ready:bool,reason:string,manifest:array<string,mixed>}
     */
    public static function selectInstalledVerified(): array
    {
        self::reset();
        $candidate = self::readManifest(self::activeManifestPath());
        if ($candidate === []) {
            return [
                'ready' => false,
                'reason' => 'No platform-specific HTTP/3 component manifest is installed.',
                'manifest' => [],
            ];
        }

        $fingerprint = \strtolower(\trim((string)($candidate['fingerprint'] ?? '')));
        $librarySha256 = \strtolower(\trim((string)($candidate['library_sha256'] ?? '')));
        if (!($candidate['ready'] ?? false)
            || ($candidate['platform'] ?? '') !== \PHP_OS_FAMILY
            || ($candidate['architecture'] ?? '') !== (string)\php_uname('m')
            || \preg_match('/^[a-f0-9]{32}$/D', $fingerprint) !== 1
            || \preg_match('/^[a-f0-9]{64}$/D', $librarySha256) !== 1
            || !self::hasVerifiedRuntimeEvidence($candidate)
        ) {
            return [
                'ready' => false,
                'reason' => 'The installed HTTP/3 component manifest is stale or lacks current strong runtime evidence.',
                'manifest' => $candidate,
            ];
        }

        try {
            $manifest = self::pinManifest($fingerprint, $librarySha256);
            $loaded = self::load();
        } catch (\Throwable $exception) {
            self::reset();
            return ['ready' => false, 'reason' => $exception->getMessage(), 'manifest' => $candidate];
        }
        if (!($loaded['available'] ?? false)) {
            $reason = (string)($loaded['reason'] ?? 'The installed HTTP/3 component could not be loaded.');
            self::reset();
            return ['ready' => false, 'reason' => $reason, 'manifest' => $candidate];
        }

        return [
            'ready' => true,
            'reason' => 'Preinstalled HTTP/3 component hash, ABI, build identity and runtime evidence verified.',
            'manifest' => $manifest,
        ];
    }

    public static function hasPinnedManifest(): bool
    {
        return self::$selectedManifest !== null;
    }

    /**
     * @return array{available:bool,reason:string,manifest:array<string,mixed>,ffi?:mixed}
     */
    public static function load(): array
    {
        return self::loadSelected(false);
    }

    /**
     * @return array{available:bool,reason:string,manifest:array<string,mixed>,ffi?:mixed}
     */
    public static function loadSelfTestCandidate(): array
    {
        return self::loadSelected(true);
    }

    /**
     * @return array{available:bool,reason:string,manifest:array<string,mixed>,ffi?:mixed}
     */
    private static function loadSelected(bool $allowSelfTestCandidate): array
    {
        $manifest = self::$selectedManifest;
        if ($manifest === null) {
            return self::fail('native HTTP/3 library is not pinned by the control plane');
        }
        if ($allowSelfTestCandidate !== self::$selfTestCandidateSelected) {
            return self::fail($allowSelfTestCandidate
                ? 'native HTTP/3 self-test candidate was not explicitly selected'
                : 'unverified native HTTP/3 self-test candidate cannot be loaded in production');
        }
        if (!$allowSelfTestCandidate && !self::hasVerifiedRuntimeEvidence($manifest)) {
            return self::fail('pinned native HTTP/3 manifest lacks current strong runtime evidence');
        }
        if (self::$loaded !== null) {
            return [
                'available' => true,
                'reason' => 'native library already loaded',
                'manifest' => self::$loaded['manifest'],
                'ffi' => self::$loaded['ffi'],
            ];
        }
        if (self::$failure !== null) {
            return ['available' => false, 'reason' => self::$failure, 'manifest' => []];
        }
        if (!\extension_loaded('FFI') || !\class_exists(\FFI::class)) {
            return self::fail('PHP FFI is unavailable');
        }

        if (!($manifest['ready'] ?? false)) {
            return self::fail((string)($manifest['runtime_reason'] ?? 'native HTTP/3 manifest is not ready'));
        }
        $library = (string)($manifest['library'] ?? '');
        $expectedHash = (string)($manifest['library_sha256'] ?? '');
        if ($library === '' || $expectedHash === '' || !\is_file($library)) {
            return self::fail('native HTTP/3 library is missing');
        }

        $root = \realpath(self::nativeRoot());
        $real = \realpath($library);
        if (!\is_string($root) || !\is_string($real)
            || !\str_starts_with($real, $root . \DIRECTORY_SEPARATOR)
        ) {
            return self::fail('native HTTP/3 library escaped the trusted cache root');
        }
        $libraryStat = @\lstat($real);
        $libraryOwner = \is_array($libraryStat) ? (int)($libraryStat['uid'] ?? -1) : false;
        $libraryMode = \is_array($libraryStat) ? (int)($libraryStat['mode'] ?? 0) : false;
        if (\function_exists('posix_geteuid')
            && $libraryOwner !== 0
            && $libraryOwner !== \posix_geteuid()
        ) {
            return self::fail('native HTTP/3 library owner is not trusted');
        }
        if (!\is_int($libraryMode) || ($libraryMode & 0022) !== 0) {
            return self::fail('native HTTP/3 library is writable by group or other users');
        }
        if (!\hash_equals($expectedHash, (string)\hash_file('sha256', $real))) {
            return self::fail('native HTTP/3 library digest mismatch');
        }

        $dependencyPrefix = \trim((string)($manifest['dependency_prefix'] ?? ''));
        if ($dependencyPrefix !== ''
            && !(\PHP_OS_FAMILY === 'Darwin'
                && ($manifest['dependency_linkage'] ?? '') === 'private-dynamic')
        ) {
            $dependenciesRoot = \realpath(self::nativeRoot() . \DIRECTORY_SEPARATOR . 'deps');
            $realDependencyPrefix = \realpath($dependencyPrefix);
            if (!\is_string($dependenciesRoot) || !\is_string($realDependencyPrefix)
                || !\is_dir($realDependencyPrefix)
                || !\str_starts_with($realDependencyPrefix, $dependenciesRoot . \DIRECTORY_SEPARATOR)
            ) {
                return self::fail('native HTTP/3 dependency prefix escaped the trusted dependency root');
            }
            if (!\function_exists('posix_geteuid')) {
                return self::fail('native HTTP/3 dependency prefix owner cannot be verified');
            }
            $dependencyOwner = \fileowner($realDependencyPrefix);
            if (!\is_int($dependencyOwner) || $dependencyOwner !== \posix_geteuid()) {
                return self::fail('native HTTP/3 dependency prefix owner does not match the WLS user');
            }
            $dependencyMode = \fileperms($realDependencyPrefix);
            if (!\is_int($dependencyMode) || ($dependencyMode & 0022) !== 0) {
                return self::fail('native HTTP/3 dependency prefix is writable by group or other users');
            }
        }
        if (\PHP_OS_FAMILY === 'Darwin' && !self::hasVerifiedDarwinDependencies($manifest)) {
            return self::fail('native HTTP/3 Darwin dependency identity is stale or untrusted');
        }

        try {
            $ffi = \FFI::cdef(self::CDEF, $real);
            if ((int)$ffi->wls_transport_abi_version() !== NativeTransportCompiler::ABI_VERSION) {
                return self::fail('native HTTP/3 ABI version mismatch');
            }
            $nativeBuildId = $ffi->wls_transport_build_id();
            $buildId = \is_string($nativeBuildId) ? $nativeBuildId : \FFI::string($nativeBuildId);
            if ($buildId === '' || !\hash_equals((string)($manifest['build_id'] ?? ''), $buildId)) {
                return self::fail('native HTTP/3 build identity mismatch');
            }
            $libraryAfter = @\lstat($real);
            if (!\is_array($libraryStat) || !\is_array($libraryAfter)) {
                return self::fail('native HTTP/3 library identity could not be rechecked after loading');
            }
            foreach (['dev', 'ino', 'mode', 'uid', 'size', 'mtime'] as $field) {
                if ((int)($libraryStat[$field] ?? -1) !== (int)($libraryAfter[$field] ?? -2)) {
                    return self::fail('native HTTP/3 library changed while it was loading');
                }
            }
            if (!\hash_equals($expectedHash, (string)\hash_file('sha256', $real))) {
                return self::fail('native HTTP/3 library digest changed while it was loading');
            }
            if (\PHP_OS_FAMILY === 'Darwin' && !self::hasVerifiedDarwinDependencies($manifest)) {
                return self::fail('native HTTP/3 Darwin dependencies changed while the library was loading');
            }
            if (\PHP_OS_FAMILY === 'Darwin'
                && !self::hasLoadedDarwinDependencies($manifest, $real)
            ) {
                return self::fail('native HTTP/3 dyld loaded-image identity does not match the sealed dependency closure');
            }
        } catch (\Throwable $exception) {
            return self::fail($exception->getMessage());
        }

        self::$loaded = ['ffi' => $ffi, 'manifest' => $manifest];
        return [
            'available' => true,
            'reason' => 'native HTTP/3 library hash, ABI and build identity verified',
            'manifest' => $manifest,
            'ffi' => $ffi,
        ];
    }

    /** @return array<string,mixed> */
    public static function hasVerifiedRuntimeEvidence(array $manifest): bool
    {
        $evidence = \is_array($manifest['runtime_evidence'] ?? null)
            ? $manifest['runtime_evidence']
            : [];
        $expectedVerifierSha256 = self::runtimeEvidenceVerifierSha256();
        $evidenceVerifierSha256 = (string)($evidence['verifier_sha256'] ?? '');
        $expectedIntegrationSha256 = self::productionIntegrationSha256();
        $evidenceIntegrationSha256 = (string)($evidence['integration_sha256'] ?? '');
        $ticketClient = (string)($evidence['ticket_client'] ?? '');
        $ticketClientVerified = $ticketClient === 'php_ext_curl_share'
            && \extension_loaded('curl')
            && \defined('CURL_VERSION_HTTP3')
            && \defined('CURL_LOCK_DATA_SSL_SESSION')
            && (((int)(\curl_version()['features'] ?? 0) & \CURL_VERSION_HTTP3) !== 0);
        if ($ticketClient === 'external_curl_ssls_export') {
            $curl = (string)($manifest['http3_curl'] ?? '');
            $expectedCurlSha256 = (string)($manifest['http3_curl_sha256'] ?? '');
            $evidenceCurlSha256 = (string)($evidence['ticket_client_sha256'] ?? '');
            $actualCurlSha256 = \is_file($curl) ? (string)\hash_file('sha256', $curl) : '';
            $ticketClientVerified = \preg_match('/^[a-f0-9]{64}$/D', $expectedCurlSha256) === 1
                && \preg_match('/^[a-f0-9]{64}$/D', $evidenceCurlSha256) === 1
                && \preg_match('/^[a-f0-9]{64}$/D', $actualCurlSha256) === 1
                && \hash_equals($expectedCurlSha256, $evidenceCurlSha256)
                && \hash_equals($evidenceCurlSha256, $actualCurlSha256);
        }
        return (bool)($manifest['runtime_verified'] ?? false)
            && (int)($evidence['schema'] ?? 0) === self::RUNTIME_EVIDENCE_SCHEMA
            && $ticketClientVerified
            && (\PHP_OS_FAMILY !== 'Darwin' || self::hasVerifiedDarwinDependencies($manifest))
            && \preg_match('/^[a-f0-9]{64}$/D', $expectedVerifierSha256) === 1
            && \preg_match('/^[a-f0-9]{64}$/D', $evidenceVerifierSha256) === 1
            && \hash_equals($expectedVerifierSha256, $evidenceVerifierSha256)
            && \preg_match('/^[a-f0-9]{64}$/D', $expectedIntegrationSha256) === 1
            && \preg_match('/^[a-f0-9]{64}$/D', $evidenceIntegrationSha256) === 1
            && \hash_equals($expectedIntegrationSha256, $evidenceIntegrationSha256)
            && self::evidenceMatchesArtifact($manifest, $evidence)
            && (bool)($evidence['quic_loopback'] ?? false)
            && (bool)($evidence['tls_ticket_ring_cross_context'] ?? false)
            && (bool)($evidence['tls_ticket_previous_key_resumption'] ?? false)
            && (bool)($evidence['tls_session_resumption'] ?? false)
            && (bool)($evidence['early_data_disabled'] ?? false)
            && (int)($evidence['issuer_full_handshakes'] ?? 0) >= 1
            && (int)($evidence['issuer_tickets_encrypted'] ?? 0) >= 1
            && (int)($evidence['consumer_resumed_handshakes'] ?? 0) >= 1
            && (int)($evidence['consumer_tickets_decrypted_previous'] ?? 0) >= 1
            && (int)($evidence['first_http_version'] ?? 0) === 30
            && (int)($evidence['second_http_version'] ?? 0) === 30;
    }

    public static function runtimeEvidenceVerifierSha256(): string
    {
        $files = [
            __FILE__,
            __DIR__ . \DIRECTORY_SEPARATOR . 'NativeTransportSelfTest.php',
            __DIR__ . \DIRECTORY_SEPARATOR . 'Ngtcp2QuicTransportAdapter.php',
            __DIR__ . \DIRECTORY_SEPARATOR . 'NativeTransportCompiler.php',
            __DIR__ . \DIRECTORY_SEPARATOR . 'NativeBuildProcessRunner.php',
            \dirname(__DIR__, 2) . \DIRECTORY_SEPARATOR . 'Console'
                . \DIRECTORY_SEPARATOR . 'Server' . \DIRECTORY_SEPARATOR . 'Http3'
                . \DIRECTORY_SEPARATOR . 'Build.php',
            __DIR__ . \DIRECTORY_SEPARATOR . 'LinuxNativeDependencyInstaller.php',
            __DIR__ . \DIRECTORY_SEPARATOR . 'Native' . \DIRECTORY_SEPARATOR . 'wls_transport.c',
            __DIR__ . \DIRECTORY_SEPARATOR . 'Native' . \DIRECTORY_SEPARATOR . 'wls_transport_abi.h',
        ];
        if (\PHP_OS_FAMILY === 'Linux') {
            $files = [...$files,
                __DIR__ . \DIRECTORY_SEPARATOR . 'Native' . \DIRECTORY_SEPARATOR . 'wls_transport.map',
                __DIR__ . \DIRECTORY_SEPARATOR . 'Native' . \DIRECTORY_SEPARATOR . 'wls_linux_reuseport_runtime.c',
                __DIR__ . \DIRECTORY_SEPARATOR . 'Native' . \DIRECTORY_SEPARATOR . 'wls_linux_reuseport_runtime.h',
                __DIR__ . \DIRECTORY_SEPARATOR . 'Native' . \DIRECTORY_SEPARATOR . 'wls_linux_reuseport_bpf.c',
                __DIR__ . \DIRECTORY_SEPARATOR . 'Native' . \DIRECTORY_SEPARATOR . 'wls_linux_reuseport_bpf_code.h',
            ];
        }
        $context = \hash_init('sha256');
        foreach ($files as $file) {
            if (!\is_file($file)) {
                return '';
            }
            $digest = \hash_file('sha256', $file);
            if (!\is_string($digest)) {
                return '';
            }
            \hash_update($context, \basename($file) . "\0" . $digest . "\0");
        }
        $integrationSha256 = self::productionIntegrationSha256();
        if (\preg_match('/^[a-f0-9]{64}$/D', $integrationSha256) !== 1) {
            return '';
        }
        \hash_update($context, 'production-integration' . "\0" . $integrationSha256 . "\0");
        $curl = \function_exists('curl_version') ? (array)\curl_version() : [];
        $runtimeIdentity = [
            'php_version' => \PHP_VERSION,
            'php_version_id' => \PHP_VERSION_ID,
            'os_family' => \PHP_OS_FAMILY,
            'architecture' => (string)\php_uname('m'),
            'ffi_extension' => (string)(\phpversion('FFI') ?: ''),
            'openssl_extension' => (string)(\phpversion('openssl') ?: ''),
            'openssl_library' => \defined('OPENSSL_VERSION_TEXT') ? (string)\OPENSSL_VERSION_TEXT : '',
            'curl_extension' => (string)(\phpversion('curl') ?: ''),
            'curl_version' => (string)($curl['version'] ?? ''),
            'curl_version_number' => (int)($curl['version_number'] ?? 0),
            'curl_ssl_version' => (string)($curl['ssl_version'] ?? ''),
            'curl_features' => (int)($curl['features'] ?? 0),
            'curl_http3_constant' => \defined('CURL_HTTP_VERSION_3ONLY'),
            'curl_http3_feature' => \defined('CURL_VERSION_HTTP3')
                && (((int)($curl['features'] ?? 0) & \CURL_VERSION_HTTP3) !== 0),
        ];
        $encodedIdentity = \json_encode($runtimeIdentity, \JSON_UNESCAPED_SLASHES);
        if (!\is_string($encodedIdentity)) {
            return '';
        }
        \hash_update($context, 'runtime' . "\0" . $encodedIdentity . "\0");
        return \hash_final($context);
    }

    /**
     * Bind reusable native evidence to the production H3 Worker, routing,
     * Ticket and readiness integration that consumes the verified adapter.
     */
    public static function productionIntegrationSha256(): string
    {
        $serverRoot = \dirname(__DIR__, 2);
        $files = [
            __DIR__ . \DIRECTORY_SEPARATOR . 'WorkerQuicRuntime.php',
            __DIR__ . \DIRECTORY_SEPARATOR . 'Http3ResponseBatch.php',
            __DIR__ . \DIRECTORY_SEPARATOR . 'AltSvcResponsePolicy.php',
            $serverRoot . \DIRECTORY_SEPARATOR . 'Service' . \DIRECTORY_SEPARATOR . 'Runtime'
                . \DIRECTORY_SEPARATOR . 'TlsTicketRingStore.php',
            $serverRoot . \DIRECTORY_SEPARATOR . 'Service' . \DIRECTORY_SEPARATOR . 'Runtime'
                . \DIRECTORY_SEPARATOR . 'WorkerReadinessState.php',
            $serverRoot . \DIRECTORY_SEPARATOR . 'bin' . \DIRECTORY_SEPARATOR . 'worker_ssl.php',
            $serverRoot . \DIRECTORY_SEPARATOR . 'Service' . \DIRECTORY_SEPARATOR . 'ServiceOrchestrator.php',
        ];
        if (\PHP_OS_FAMILY === 'Darwin') {
            $files[] = __DIR__ . \DIRECTORY_SEPARATOR . 'DarwinDatagramRouterTransport.php';
            $files[] = __DIR__ . \DIRECTORY_SEPARATOR . 'DarwinHttp3RuntimeIdentity.php';
        }

        $context = \hash_init('sha256');
        foreach ($files as $file) {
            if (!\is_file($file)) {
                return '';
            }
            $digest = \hash_file('sha256', $file);
            if (!\is_string($digest) || \preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                return '';
            }
            $relative = \str_replace(\DIRECTORY_SEPARATOR, '/', \substr($file, \strlen($serverRoot) + 1));
            if ($relative === '' || \str_starts_with($relative, '../')) {
                return '';
            }
            \hash_update($context, $relative . "\0" . $digest . "\0");
        }
        return \hash_final($context);
    }

    public static function nativeSourceSha256(): string
    {
        $files = [
            __DIR__ . '/Native/wls_transport.c',
            __DIR__ . '/Native/wls_transport_abi.h',
        ];
        if (\PHP_OS_FAMILY === 'Linux') {
            $files = [...$files,
                __DIR__ . '/Native/wls_transport.map',
                __DIR__ . '/Native/wls_linux_reuseport_runtime.c',
                __DIR__ . '/Native/wls_linux_reuseport_runtime.h',
                __DIR__ . '/Native/wls_linux_reuseport_bpf.c',
                __DIR__ . '/Native/wls_linux_reuseport_bpf_code.h',
            ];
        }
        $payload = '';
        foreach ($files as $file) {
            if (!\is_file($file)) {
                return '';
            }
            $contents = \file_get_contents($file);
            if (!\is_string($contents)) {
                return '';
            }
            $payload .= "\0" . $contents;
        }
        return \hash('sha256', $payload);
    }

    /** @return array<string,string> */
    public static function darwinRuntimeIdentity(): array
    {
        $phpBinary = \realpath(\PHP_BINARY);
        $phpBinaryStat = \is_string($phpBinary) ? @\lstat($phpBinary) : false;
        return [
            'os_family' => \PHP_OS_FAMILY,
            'os_version' => \defined('PHP_OS_VERSION') ? (string)\PHP_OS_VERSION : (string)\php_uname('r'),
            'kernel_release' => (string)\php_uname('r'),
            'kernel_version' => (string)\php_uname('v'),
            'architecture' => (string)\php_uname('m'),
            'php_binary' => \is_string($phpBinary) ? $phpBinary : '',
            'php_binary_sha256' => \is_string($phpBinary) ? (string)\hash_file('sha256', $phpBinary) : '',
            'php_binary_owner_uid' => \is_array($phpBinaryStat) ? (string)($phpBinaryStat['uid'] ?? -1) : '-1',
            'php_binary_mode' => \is_array($phpBinaryStat)
                ? (string)(((int)($phpBinaryStat['mode'] ?? 0)) & 07777)
                : '-1',
        ];
    }

    /**
     * @param list<array<string,int|string>> $dependencies
     * @param list<string> $systemDependencies
     * @param array<string,string> $runtimeIdentity
     */
    public static function darwinDependencyFingerprint(
        array $dependencies,
        array $systemDependencies,
        array $runtimeIdentity,
    ): string {
        try {
            $payload = \json_encode([
                'schema' => 1,
                'dependencies' => \array_values($dependencies),
                'system_dependencies' => \array_values($systemDependencies),
                'runtime_identity' => $runtimeIdentity,
            ], \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '';
        }
        return \hash('sha256', $payload);
    }

    /**
     * Hash the relocatable private dependency closure. Absolute publication
     * paths and the root artifact are deliberately excluded: dependency
     * images commit to their rewritten bytes and normalized graph identity.
     *
     * @param list<array<string,mixed>> $images
     * @param list<array<string,mixed>> $edges
     * @param list<array<string,mixed>> $systemEdges
     */
    public static function darwinPrivateBundleFingerprint(
        array $images,
        array $edges,
        array $systemEdges,
    ): string {
        $normalizedImages = [];
        foreach ($images as $image) {
            if (!\is_array($image) || ($image['role'] ?? '') !== 'dependency') {
                continue;
            }
            $architectures = \is_array($image['architectures'] ?? null)
                ? \array_values(\array_map('strval', $image['architectures']))
                : [];
            \sort($architectures, \SORT_STRING);
            $normalizedImages[] = [
                'role' => 'dependency',
                'name' => \basename((string)($image['relative_path'] ?? '')),
                'sha256' => \strtolower((string)($image['sha256'] ?? '')),
                'source_sha256' => \strtolower((string)($image['source_sha256'] ?? '')),
                'size' => (int)($image['size'] ?? -1),
                'mode' => (int)($image['mode'] ?? -1),
                'architectures' => $architectures,
                'install_id' => (string)($image['install_id'] ?? ''),
                'source_install_id' => (string)($image['source_install_id'] ?? ''),
                'cdhash' => \strtolower((string)($image['cdhash'] ?? '')),
                'rpaths' => \is_array($image['rpaths'] ?? null)
                    ? \array_values(\array_map('strval', $image['rpaths']))
                    : [],
            ];
        }
        \usort($normalizedImages, static fn(array $left, array $right): int => \strcmp(
            (string)$left['name'],
            (string)$right['name'],
        ));

        $normalizedEdges = [];
        foreach ($edges as $edge) {
            if (!\is_array($edge)) {
                continue;
            }
            $loader = (string)($edge['loader_relative_path'] ?? '');
            $target = \basename((string)($edge['target_relative_path'] ?? ''));
            $normalizedLoader = $loader === '@artifact' ? '@artifact' : \basename($loader);
            $normalizedEdges[] = [
                'loader' => $normalizedLoader,
                'load_command' => (string)($edge['load_command'] ?? ''),
                'source_install_name' => (string)($edge['source_install_name'] ?? ''),
                'install_name' => $normalizedLoader === '@artifact'
                    ? '@loader_path/@bundle/' . $target
                    : '@loader_path/' . $target,
                'target' => $target,
            ];
        }
        \usort($normalizedEdges, static fn(array $left, array $right): int => \strcmp(
            \implode("\0", $left),
            \implode("\0", $right),
        ));

        $normalizedSystemEdges = [];
        foreach ($systemEdges as $edge) {
            if (!\is_array($edge)) {
                continue;
            }
            $loader = (string)($edge['loader_relative_path'] ?? '');
            $normalizedSystemEdges[] = [
                'loader' => $loader === '@artifact' ? '@artifact' : \basename($loader),
                'load_command' => (string)($edge['load_command'] ?? ''),
                'install_name' => (string)($edge['install_name'] ?? ''),
            ];
        }
        \usort($normalizedSystemEdges, static fn(array $left, array $right): int => \strcmp(
            \implode("\0", $left),
            \implode("\0", $right),
        ));

        try {
            $payload = \json_encode([
                'schema' => 1,
                'images' => $normalizedImages,
                'edges' => $normalizedEdges,
                'system_edges' => $normalizedSystemEdges,
            ], \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '';
        }
        return \hash('sha256', $payload);
    }

    public static function isDarwinSystemDependency(string $installName): bool
    {
        $installName = \trim($installName);
        if ($installName === '' || !\str_starts_with($installName, '/')) {
            return false;
        }
        foreach (\explode('/', $installName) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }
        return $installName === '/usr/lib'
            || \str_starts_with($installName, '/usr/lib/')
            || $installName === '/System/Library'
            || \str_starts_with($installName, '/System/Library/')
            || $installName === '/System/Cryptexes/'
            || \str_starts_with($installName, '/System/Cryptexes/');
    }

    private static function hasTrustedDarwinPathAncestors(string $path): bool
    {
        if (!\str_starts_with($path, '/') || \str_contains($path, "\0")) {
            return false;
        }
        $canonical = \realpath($path);
        if (!\is_string($canonical)) {
            return false;
        }

        $effectiveUser = \function_exists('posix_geteuid') ? (int)\posix_geteuid() : null;
        $verifyDirectoryChain = static function (string $directory) use ($effectiveUser): bool {
            $trimmed = \rtrim($directory, '/');
            $segments = $trimmed === '' ? [] : \explode('/', \ltrim($trimmed, '/'));
            $current = '/';
            $paths = ['/'];
            foreach ($segments as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..') {
                    return false;
                }
                $current = $current === '/' ? '/' . $segment : $current . '/' . $segment;
                $paths[] = $current;
            }
            foreach ($paths as $directoryPath) {
                $stat = @\lstat($directoryPath);
                if (!\is_array($stat)
                    || (((int)($stat['mode'] ?? 0)) & 0170000) !== 0040000
                    || \is_link($directoryPath)
                ) {
                    return false;
                }
                $owner = (int)($stat['uid'] ?? -1);
                $mode = ((int)($stat['mode'] ?? 0)) & 07777;
                if (($mode & 0022) !== 0
                    || ($effectiveUser !== null && $owner !== 0 && $owner !== $effectiveUser)
                ) {
                    return false;
                }
            }
            return true;
        };

        $rawDirectory = \is_dir($path) ? \rtrim($path, '/') : \dirname($path);
        $canonicalDirectory = \is_dir($canonical) ? \rtrim($canonical, '/') : \dirname($canonical);
        return $verifyDirectoryChain($rawDirectory === '' ? '/' : $rawDirectory)
            && $verifyDirectoryChain($canonicalDirectory === '' ? '/' : $canonicalDirectory);
    }

    /** @param array<string,mixed> $manifest */
    public static function hasVerifiedDarwinDependencies(array $manifest): bool
    {
        if (\PHP_OS_FAMILY !== 'Darwin') {
            return true;
        }
        foreach (self::DARWIN_INJECTION_ENVIRONMENT as $variable) {
            $value = \getenv($variable);
            if (\is_string($value) && \trim($value) !== '') {
                return false;
            }
        }

        $bundleSha256 = \strtolower((string)($manifest['dependency_bundle_sha256'] ?? ''));
        $dependencyFingerprint = \strtolower((string)($manifest['dependency_fingerprint'] ?? ''));
        $images = \is_array($manifest['darwin_private_images'] ?? null)
            ? \array_values($manifest['darwin_private_images'])
            : [];
        $edges = \is_array($manifest['darwin_private_edges'] ?? null)
            ? \array_values($manifest['darwin_private_edges'])
            : [];
        $systemEdges = \is_array($manifest['darwin_system_edges'] ?? null)
            ? \array_values($manifest['darwin_system_edges'])
            : [];
        $runtimeIdentity = \is_array($manifest['os_runtime_identity'] ?? null)
            ? $manifest['os_runtime_identity']
            : [];
        if (($manifest['dependency_linkage'] ?? '') !== 'private-dynamic'
            || \preg_match('/^[a-f0-9]{64}$/D', $bundleSha256) !== 1
            || !\hash_equals($bundleSha256, $dependencyFingerprint)
            || \count($images) < 2 || \count($images) > self::MAX_DARWIN_DEPENDENCIES + 1
            || $edges === [] || \count($edges) > self::MAX_DARWIN_EDGES
            || \count($systemEdges) > self::MAX_DARWIN_EDGES
            || ($manifest['dynamic_dependencies'] ?? null) !== $edges
            || $runtimeIdentity !== self::darwinRuntimeIdentity()
            || \preg_match('/^[a-f0-9]{64}$/D', (string)($runtimeIdentity['php_binary_sha256'] ?? '')) !== 1
        ) {
            return false;
        }

        $phpBinary = \realpath(\PHP_BINARY);
        $phpBinaryStat = \is_string($phpBinary) ? @\lstat($phpBinary) : false;
        $effectiveUser = \function_exists('posix_geteuid') ? (int)\posix_geteuid() : null;
        if (!\is_string($phpBinary)
            || !\hash_equals($phpBinary, (string)($runtimeIdentity['php_binary'] ?? ''))
            || !\is_array($phpBinaryStat)
            || (($phpBinaryStat['mode'] ?? 0) & 0170000) !== 0100000
            || \is_link($phpBinary) || !\is_file($phpBinary)
            || ((((int)($phpBinaryStat['mode'] ?? 0)) & 07777) & 0022) !== 0
            || !\hash_equals(
                (string)($runtimeIdentity['php_binary_sha256'] ?? ''),
                (string)\hash_file('sha256', $phpBinary),
            )
        ) {
            return false;
        }
        $phpBinaryOwner = (int)($phpBinaryStat['uid'] ?? -1);
        if ($effectiveUser !== null && $phpBinaryOwner !== 0 && $phpBinaryOwner !== $effectiveUser) {
            return false;
        }

        $artifactLibrary = \realpath((string)($manifest['library'] ?? ''));
        if (!\is_string($artifactLibrary) || !self::hasTrustedDarwinPathAncestors($artifactLibrary)) {
            return false;
        }
        $artifactDirectory = \dirname($artifactLibrary);
        $dependenciesRoot = $artifactDirectory . \DIRECTORY_SEPARATOR . 'deps';
        $dependencyPrefix = $dependenciesRoot . \DIRECTORY_SEPARATOR . $bundleSha256;
        if (!\hash_equals($dependencyPrefix, (string)($manifest['dependency_prefix'] ?? ''))
            || !self::hasTrustedDarwinPathAncestors($dependencyPrefix)
        ) {
            return false;
        }
        foreach ([$artifactDirectory, $dependenciesRoot, $dependencyPrefix] as $directory) {
            $stat = @\lstat($directory);
            $owner = \is_array($stat) ? (int)($stat['uid'] ?? -1) : -1;
            if (!\is_array($stat)
                || (($stat['mode'] ?? 0) & 0170000) !== 0040000
                || ((((int)($stat['mode'] ?? 0)) & 07777) !== 0700)
                || \is_link($directory)
                || ($effectiveUser !== null && $owner !== 0 && $owner !== $effectiveUser)
            ) {
                return false;
            }
        }

        $byRelative = [];
        $byBasename = [];
        $rootCount = 0;
        $dependencyCount = 0;
        $identityNames = '';
        foreach ($images as $image) {
            if (!\is_array($image)) {
                return false;
            }
            $role = (string)($image['role'] ?? '');
            $relative = (string)($image['relative_path'] ?? '');
            $basename = \basename($relative);
            if ($role === 'root') {
                $validRelative = \preg_match('/^libwls_transport-[a-f0-9]{32}\.dylib$/D', $relative) === 1;
                $rootCount++;
            } elseif ($role === 'dependency') {
                $validRelative = \preg_match(
                    '#^deps/' . \preg_quote($bundleSha256, '#') . '/d-[a-f0-9]{24}\.dylib$#D',
                    $relative,
                ) === 1;
                $dependencyCount++;
            } else {
                return false;
            }
            if (!$validRelative || isset($byRelative[$relative]) || isset($byBasename[$basename])) {
                return false;
            }
            $expectedPath = $artifactDirectory . \DIRECTORY_SEPARATOR
                . \str_replace('/', \DIRECTORY_SEPARATOR, $relative);
            $canonical = \realpath($expectedPath);
            $stat = @\lstat($expectedPath);
            $sha256 = \strtolower((string)($image['sha256'] ?? ''));
            $sourceSha256 = \strtolower((string)($image['source_sha256'] ?? ''));
            $cdhash = \strtolower((string)($image['cdhash'] ?? ''));
            $owner = \is_array($stat) ? (int)($stat['uid'] ?? -1) : -1;
            $mode = \is_array($stat) ? (((int)($stat['mode'] ?? 0)) & 07777) : -1;
            $architectures = \is_array($image['architectures'] ?? null)
                ? \array_values($image['architectures'])
                : [];
            if (!\is_string($canonical)
                || !\hash_equals($expectedPath, $canonical)
                || !\hash_equals($canonical, (string)($image['path'] ?? ''))
                || !self::hasTrustedDarwinPathAncestors($canonical)
                || !\is_array($stat) || (($stat['mode'] ?? 0) & 0170000) !== 0100000
                || \is_link($expectedPath) || !\is_file($canonical)
                || $mode !== 0555 || (int)($image['mode'] ?? -1) !== 0555
                || $owner !== (int)($image['owner_uid'] ?? -1)
                || ($effectiveUser !== null && $owner !== 0 && $owner !== $effectiveUser)
                || (int)($stat['size'] ?? -1) !== (int)($image['size'] ?? -2)
                || \preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1
                || \preg_match('/^[a-f0-9]{64}$/D', $sourceSha256) !== 1
                || \preg_match('/^[a-f0-9]{40}$/D', $cdhash) !== 1
                || !\hash_equals($sha256, (string)\hash_file('sha256', $canonical))
                || (string)($image['install_id'] ?? '') !== '@loader_path/' . $basename
                || !\is_string($image['source_install_id'] ?? null)
                || (string)$image['source_install_id'] === ''
                || ($image['rpaths'] ?? null) !== []
                || !\in_array((string)\php_uname('m'), $architectures, true)
            ) {
                return false;
            }
            $image['path'] = $canonical;
            $byRelative[$relative] = $image;
            $byBasename[$basename] = $relative;
            $identityNames .= "\n" . (string)$image['source_install_id'];
            if ($role === 'root' && !\hash_equals($artifactLibrary, $canonical)) {
                return false;
            }
        }
        if ($rootCount !== 1 || $dependencyCount < 1 || $dependencyCount > self::MAX_DARWIN_DEPENDENCIES) {
            return false;
        }

        $seenEdges = [];
        foreach ($edges as $edge) {
            if (!\is_array($edge)) {
                return false;
            }
            $loaderRelative = (string)($edge['loader_relative_path'] ?? '');
            $targetRelative = (string)($edge['target_relative_path'] ?? '');
            $command = (string)($edge['load_command'] ?? '');
            $sourceInstallName = (string)($edge['source_install_name'] ?? '');
            $loaderImage = $loaderRelative === '@artifact' ? null : ($byRelative[$loaderRelative] ?? null);
            $targetImage = $byRelative[$targetRelative] ?? null;
            $expectedLoader = $loaderRelative === '@artifact'
                ? '@artifact'
                : (\is_array($loaderImage) ? (string)$loaderImage['path'] : '');
            $expectedInstallName = $loaderRelative === '@artifact'
                ? '@loader_path/' . $targetRelative
                : '@loader_path/' . \basename($targetRelative);
            $edgeKey = $loaderRelative . "\0" . $command . "\0" . $sourceInstallName;
            if (($loaderRelative !== '@artifact'
                    && (!\is_array($loaderImage) || ($loaderImage['role'] ?? '') !== 'dependency'))
                || !\is_array($targetImage) || ($targetImage['role'] ?? '') !== 'dependency'
                || !\in_array($command, self::DARWIN_LOAD_COMMANDS, true)
                || $sourceInstallName === '' || \strlen($sourceInstallName) > 4096
                || !\hash_equals($expectedInstallName, (string)($edge['install_name'] ?? ''))
                || !\hash_equals($expectedLoader, (string)($edge['loader'] ?? ''))
                || ($edge['run_path_stack'] ?? null) !== []
                || !\hash_equals((string)$targetImage['path'], (string)($edge['resolution_path'] ?? ''))
                || !\hash_equals((string)$targetImage['path'], (string)($edge['path'] ?? ''))
                || !\hash_equals((string)$targetImage['sha256'], (string)($edge['sha256'] ?? ''))
                || (int)$targetImage['owner_uid'] !== (int)($edge['owner_uid'] ?? -1)
                || (int)$targetImage['mode'] !== (int)($edge['mode'] ?? -1)
                || isset($seenEdges[$edgeKey])
            ) {
                return false;
            }
            $seenEdges[$edgeKey] = true;
            $identityNames .= "\n" . $sourceInstallName;
        }

        $expectedSystemDependencies = [];
        $seenSystemEdges = [];
        foreach ($systemEdges as $edge) {
            if (!\is_array($edge)) {
                return false;
            }
            $loaderRelative = (string)($edge['loader_relative_path'] ?? '');
            $command = (string)($edge['load_command'] ?? '');
            $installName = (string)($edge['install_name'] ?? '');
            $loaderImage = $loaderRelative === '@artifact' ? null : ($byRelative[$loaderRelative] ?? null);
            $edgeKey = $loaderRelative . "\0" . $command . "\0" . $installName;
            if (($loaderRelative !== '@artifact'
                    && (!\is_array($loaderImage) || ($loaderImage['role'] ?? '') !== 'dependency'))
                || !\in_array($command, self::DARWIN_LOAD_COMMANDS, true)
                || !self::isDarwinSystemDependency($installName)
                || isset($seenSystemEdges[$edgeKey])
            ) {
                return false;
            }
            $seenSystemEdges[$edgeKey] = true;
            $expectedSystemDependencies[$installName] = true;
        }
        $expectedSystemDependencies = \array_keys($expectedSystemDependencies);
        \sort($expectedSystemDependencies, \SORT_STRING);
        if (($manifest['system_dynamic_dependencies'] ?? null) !== $expectedSystemDependencies) {
            return false;
        }
        foreach (['libngtcp2', 'libngtcp2_crypto_ossl', 'libnghttp3', 'libssl', 'libcrypto'] as $required) {
            if (!\str_contains($identityNames, $required)) {
                return false;
            }
        }

        $fingerprint = self::darwinPrivateBundleFingerprint($images, $edges, $systemEdges);
        return \preg_match('/^[a-f0-9]{64}$/D', $fingerprint) === 1
            && \hash_equals($bundleSha256, $fingerprint);
    }

    /** @param list<string> $runPathStack */
    private static function resolveDarwinDependencyPath(
        string $installName,
        string $loader,
        array $runPathStack,
    ): ?string {
        if (\str_starts_with($installName, '/')) {
            return \is_file($installName) ? $installName : null;
        }
        if (\str_starts_with($installName, '@loader_path/')) {
            $candidate = \dirname($loader) . \DIRECTORY_SEPARATOR
                . \substr($installName, \strlen('@loader_path/'));
            return \is_file($candidate) ? $candidate : null;
        }
        if (\str_starts_with($installName, '@executable_path/')) {
            $executable = \realpath(\PHP_BINARY);
            if (!\is_string($executable)) {
                return null;
            }
            $candidate = \dirname($executable) . \DIRECTORY_SEPARATOR
                . \substr($installName, \strlen('@executable_path/'));
            return \is_file($candidate) ? $candidate : null;
        }
        if (\str_starts_with($installName, '@rpath/')) {
            $suffix = \substr($installName, \strlen('@rpath/'));
            foreach ($runPathStack as $runPath) {
                $candidate = \rtrim($runPath, '/') . \DIRECTORY_SEPARATOR . $suffix;
                if (\is_file($candidate)) {
                    return $candidate;
                }
            }
            return null;
        }
        $candidate = \dirname($loader) . \DIRECTORY_SEPARATOR . $installName;
        return \is_file($candidate) ? $candidate : null;
    }

    /**
     * Verify the paths dyld actually loaded, not only the paths predicted from
     * LC_RPATH during the control-plane build. This closes the case where a
     * higher-priority same-name dylib appears after the manifest was sealed.
     *
     * @param array<string,mixed> $manifest
     */
    private static function hasLoadedDarwinDependencies(array $manifest, string $library): bool
    {
        if (\PHP_OS_FAMILY !== 'Darwin') {
            return true;
        }
        if (!self::hasVerifiedDarwinDependencies($manifest)) {
            return false;
        }

        $rootLibrary = \realpath($library);
        if (!\is_string($rootLibrary)) {
            return false;
        }
        $expectedByBasename = [];
        $registerExpected = static function (string $path) use (&$expectedByBasename): bool {
            $canonical = \realpath($path);
            if (!\is_string($canonical)) {
                return false;
            }
            $basename = \basename($canonical);
            if ($basename === '') {
                return false;
            }
            if (isset($expectedByBasename[$basename])
                && !\hash_equals($expectedByBasename[$basename], $canonical)
            ) {
                return false;
            }
            $expectedByBasename[$basename] = $canonical;
            return true;
        };
        if (!$registerExpected($rootLibrary)) {
            return false;
        }
        foreach ((array)($manifest['darwin_private_images'] ?? []) as $image) {
            if (!\is_array($image)) {
                return false;
            }
            if (($image['role'] ?? '') === 'root') {
                continue;
            }
            if (($image['role'] ?? '') !== 'dependency'
                || !$registerExpected((string)($image['path'] ?? ''))
            ) {
                return false;
            }
        }

        try {
            $dyld = \FFI::cdef(
                'uint32_t _dyld_image_count(void);'
                . ' const char *_dyld_get_image_name(uint32_t image_index);',
                '/usr/lib/libSystem.B.dylib',
            );
            $imageCount = (int)$dyld->_dyld_image_count();
            if ($imageCount < 1 || $imageCount > 4096) {
                return false;
            }
            $observed = [];
            for ($index = 0; $index < $imageCount; $index++) {
                $imageName = $dyld->_dyld_get_image_name($index);
                if (\is_string($imageName)) {
                    $loadedPath = $imageName;
                } else {
                    if (\FFI::isNull($imageName)) {
                        continue;
                    }
                    $loadedPath = \FFI::string($imageName);
                }
                $canonical = \realpath($loadedPath);
                if (!\is_string($canonical)) {
                    continue;
                }
                $loadedBasenames = \array_values(\array_unique([
                    \basename($loadedPath),
                    \basename($canonical),
                ]));
                foreach ($loadedBasenames as $basename) {
                    if (!isset($expectedByBasename[$basename])) {
                        continue;
                    }
                    if (!\hash_equals($expectedByBasename[$basename], $canonical)) {
                        return false;
                    }
                    $observed[$canonical] = true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        foreach ($expectedByBasename as $expected) {
            if (!isset($observed[$expected])) {
                return false;
            }
        }
        return true;
    }

    /** @return array{ready:bool,reason:string} */
    public static function linuxReusePortRouteReadiness(): array
    {
        if (\PHP_OS_FAMILY !== 'Linux') {
            return ['ready' => false, 'reason' => 'Linux reuseport eBPF routing is not used on this platform.'];
        }
        if (!\function_exists('posix_geteuid')) {
            return ['ready' => false, 'reason' => 'The effective Linux user cannot be verified.'];
        }

        $bpffsRoot = '/sys/fs/bpf';
        if (!\is_dir($bpffsRoot) || \is_link($bpffsRoot)) {
            return ['ready' => false, 'reason' => '/sys/fs/bpf is not an accessible bpffs directory.'];
        }
        $mountInfo = @\file('/proc/self/mountinfo', \FILE_IGNORE_NEW_LINES);
        $bpffsMounted = false;
        if (\is_array($mountInfo)) {
            foreach ($mountInfo as $line) {
                $parts = \explode(' - ', (string)$line, 2);
                $left = \preg_split('/\s+/', $parts[0] ?? '') ?: [];
                $right = \preg_split('/\s+/', $parts[1] ?? '') ?: [];
                if (($left[4] ?? '') === $bpffsRoot && ($right[0] ?? '') === 'bpf') {
                    $bpffsMounted = true;
                    break;
                }
            }
        }
        if (!$bpffsMounted) {
            return ['ready' => false, 'reason' => '/sys/fs/bpf is not mounted as bpffs.'];
        }

        $effectiveUser = (int)\posix_geteuid();
        if ($effectiveUser === 0) {
            return ['ready' => true, 'reason' => 'root can provision the owner-scoped reuseport eBPF route.'];
        }

        $processStatus = (string)@\file_get_contents('/proc/self/status');
        if (\preg_match('/^CapEff:\s*([0-9a-f]+)$/mi', $processStatus, $matches) !== 1) {
            return ['ready' => false, 'reason' => 'Linux effective capabilities cannot be read.'];
        }
        $effectiveCapabilities = (int)\hexdec($matches[1]);
        $hasCapability = static fn(int $bit): bool => ($effectiveCapabilities & (1 << $bit)) !== 0;
        $hasSysAdmin = $hasCapability(21);
        $hasBpf = $hasCapability(39);
        $hasNetAdmin = $hasCapability(12);
        if (!$hasSysAdmin && (!$hasBpf || !$hasNetAdmin)) {
            return [
                'ready' => false,
                'reason' => 'The WLS user lacks CAP_BPF plus CAP_NET_ADMIN (or CAP_SYS_ADMIN); HTTP/3 stays disabled while Direct TCP remains available.',
            ];
        }

        $userRoot = $bpffsRoot . '/weline/' . $effectiveUser;
        $owner = @\fileowner($userRoot);
        $mode = @\fileperms($userRoot);
        if (!\is_dir($userRoot)
            || !\is_int($owner) || $owner !== $effectiveUser
            || !\is_int($mode) || ($mode & 0022) !== 0
            || !\is_readable($userRoot) || !\is_writable($userRoot)
        ) {
            return [
                'ready' => false,
                'reason' => 'The owner-scoped bpffs directory is not provisioned securely for the WLS user: ' . $userRoot,
            ];
        }

        return ['ready' => true, 'reason' => 'Linux capabilities and owner-scoped bpffs namespace are ready.'];
    }

    /** @return array<string,mixed> */
    public static function capabilities(): array
    {
        if (!self::hasPinnedManifest()) {
            $selection = self::selectInstalledVerified();
            $loaded = ($selection['ready'] ?? false)
                ? self::load()
                : [
                    'available' => false,
                    'reason' => (string)($selection['reason'] ?? 'Native HTTP/3 library is unavailable.'),
                    'manifest' => (array)($selection['manifest'] ?? []),
                ];
        } else {
            $loaded = self::load();
        }
        $manifest = \is_array($loaded['manifest'] ?? null) ? $loaded['manifest'] : self::manifest();
        $available = (bool)($loaded['available'] ?? false);
        $ticketRingVerified = $available && self::hasVerifiedRuntimeEvidence($manifest);
        $linuxPicStaticDependencyBundle = \PHP_OS_FAMILY === 'Linux'
            && $available
            && (bool)($manifest['ready'] ?? false)
            && ($manifest['platform'] ?? '') === 'Linux'
            && ($manifest['architecture'] ?? '') === (string)\php_uname('m')
            && ($manifest['dependency_linkage'] ?? '') === 'pic-static';

        return [
            'native_library_available' => $available,
            'manifest_ready' => (bool)($manifest['ready'] ?? false),
            'tls_context_capabilities_function' => $available,
            'long_lived_tls_context_api_available' => $available,
            'long_lived_tls_context_activated' => false,
            'native_tls_ticket_key_ring' => $ticketRingVerified,
            'ssl_ctx_ticket_callback' => $available,
            'linux_pic_static_dependency_bundle' => $linuxPicStaticDependencyBundle,
            'ticket_key_ring_native_activation_verified' => $ticketRingVerified,
            'cross_worker_ticket_key_ring' => false,
            'ticket_key_ring_ack_activation' => false,
            'server_session_reuse_observable' => $available,
            'session_resumption_verified' => $ticketRingVerified,
            'same_worker_session_resumption_verified' => false,
            'cross_worker_session_resumption_verified' => false,
            'cross_context_session_resumption_verified' => $ticketRingVerified,
            'ticket_rotation_continuity_verified' => $ticketRingVerified,
            'early_data_disabled' => $ticketRingVerified,
            'reason' => $ticketRingVerified
                ? 'Native HTTP/3 ticket-ring activation, independent TLS-context resumption through the rotated previous key, true server reuse counters and disabled 0-RTT are verified. Cross-Worker ACK and reuse require live instance telemetry and are not inferred from this self-test.'
                : ($available
                    ? 'Native HTTP/3 exposes the SSL_CTX ticket-ring setter and true server-side reuse counters; the independent TLS-context resumption proof is pending or failed.'
                    : (string)($loaded['reason'] ?? 'Native HTTP/3 library is unavailable.')),
        ];
    }

    /** @return array<string,mixed> */
    public static function manifest(): array
    {
        return self::$selectedManifest ?? self::readManifest(self::activeManifestPath());
    }

    public static function activeManifestPath(): string
    {
        $platform = \strtolower((string)\preg_replace(
            '/[^a-zA-Z0-9_.-]+/',
            '-',
            \PHP_OS_FAMILY . '-' . (string)\php_uname('m'),
        ));
        return self::nativeRoot() . \DIRECTORY_SEPARATOR . 'active-' . $platform . '.json';
    }

    public static function legacyActiveManifestPath(): string
    {
        return self::nativeRoot() . \DIRECTORY_SEPARATOR . 'active.json';
    }

    public static function manifestPathForFingerprint(string $fingerprint, ?string $librarySha256 = null): string
    {
        $fingerprint = \strtolower(\trim($fingerprint));
        if (\preg_match('/^[a-f0-9]{32}$/D', $fingerprint) !== 1) {
            throw new \InvalidArgumentException('Invalid native HTTP/3 fingerprint.');
        }
        $path = self::nativeRoot() . \DIRECTORY_SEPARATOR . $fingerprint;
        if ($librarySha256 !== null) {
            $librarySha256 = \strtolower(\trim($librarySha256));
            if (\preg_match('/^[a-f0-9]{64}$/D', $librarySha256) !== 1) {
                throw new \InvalidArgumentException('Invalid native HTTP/3 library digest.');
            }
            $path .= \DIRECTORY_SEPARATOR . $librarySha256;
        }
        return $path . \DIRECTORY_SEPARATOR . 'manifest.json';
    }

    /** @return array<string,mixed> */
    private static function readManifest(string $path): array
    {
        $stat = @\lstat($path);
        if (!\is_array($stat)
            || (($stat['mode'] ?? 0) & 0170000) !== 0100000
            || \is_link($path)
            || (int)($stat['size'] ?? 0) <= 0
            || (int)($stat['size'] ?? 0) > self::MAX_MANIFEST_BYTES
            || (((int)($stat['mode'] ?? 0)) & 0022) !== 0
        ) {
            return [];
        }
        if (\function_exists('posix_geteuid')) {
            $owner = (int)($stat['uid'] ?? -1);
            if ($owner !== 0 && $owner !== (int)\posix_geteuid()) {
                return [];
            }
        }
        $handle = @\fopen($path, 'rb');
        if (!\is_resource($handle)) {
            return [];
        }
        $opened = @\fstat($handle);
        $contents = @\stream_get_contents($handle, self::MAX_MANIFEST_BYTES + 1);
        $read = @\fstat($handle);
        @\fclose($handle);
        $after = @\lstat($path);
        if (!\is_array($opened) || !\is_array($read)
            || !\is_string($contents) || !\is_array($after)
            || \strlen($contents) !== (int)($stat['size'] ?? -1)
            || \is_link($path)
        ) {
            return [];
        }
        foreach (['dev', 'ino', 'mode', 'uid', 'size', 'mtime'] as $field) {
            $expected = (int)($stat[$field] ?? -1);
            if ($expected !== (int)($opened[$field] ?? -2)
                || $expected !== (int)($read[$field] ?? -3)
                || $expected !== (int)($after[$field] ?? -4)
            ) {
                return [];
            }
        }
        try {
            $decoded = \json_decode($contents, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        return \is_array($decoded) && (int)($decoded['schema'] ?? 0) === self::MANIFEST_SCHEMA
            ? $decoded
            : [];
    }

    /** @param array<string,mixed> $manifest */
    private static function validArtifactManifest(
        array $manifest,
        string $fingerprint,
        string $librarySha256,
    ): bool {
        $library = (string)($manifest['library'] ?? '');
        $artifactDirectory = \dirname(self::manifestPathForFingerprint($fingerprint, $librarySha256));
        $realDirectory = \realpath($artifactDirectory);
        $realLibrary = \realpath($library);
        $directoryStat = @\lstat($artifactDirectory);
        $libraryStat = @\lstat($library);
        $dependencyFingerprint = (string)($manifest['dependency_fingerprint'] ?? '');
        $sourceSha256 = self::nativeSourceSha256();
        $effectiveUser = \function_exists('posix_geteuid') ? (int)\posix_geteuid() : null;
        $trustedOwner = static fn(array $stat): bool => $effectiveUser === null
            || (int)($stat['uid'] ?? -1) === 0
            || (int)($stat['uid'] ?? -1) === $effectiveUser;
        return ($manifest['ready'] ?? false)
            && (int)($manifest['abi_version'] ?? 0) === NativeTransportCompiler::ABI_VERSION
            && ($manifest['fingerprint'] ?? '') === $fingerprint
            && ($manifest['platform'] ?? '') === \PHP_OS_FAMILY
            && ($manifest['architecture'] ?? '') === (string)\php_uname('m')
            && \hash_equals($librarySha256, (string)($manifest['library_sha256'] ?? ''))
            && \preg_match('/^[a-f0-9]{64}$/D', $sourceSha256) === 1
            && \hash_equals($sourceSha256, (string)($manifest['source_sha256'] ?? ''))
            && \preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/D', $dependencyFingerprint) === 1
            && (\PHP_OS_FAMILY !== 'Darwin' || self::hasVerifiedDarwinDependencies($manifest))
            && (string)($manifest['build_id'] ?? '') !== ''
            && \is_string($realDirectory) && \is_string($realLibrary)
            && \dirname($realLibrary) === $realDirectory
            && \is_array($directoryStat) && (($directoryStat['mode'] ?? 0) & 0170000) === 0040000
            && (((int)($directoryStat['mode'] ?? 0)) & 0022) === 0
            && $trustedOwner($directoryStat)
            && \is_array($libraryStat) && (($libraryStat['mode'] ?? 0) & 0170000) === 0100000
            && (((int)($libraryStat['mode'] ?? 0)) & 0022) === 0
            && $trustedOwner($libraryStat)
            && \is_file($realLibrary) && !\is_link($library)
            && \hash_equals($librarySha256, (string)\hash_file('sha256', $realLibrary));
    }

    /** @param array<string,mixed> $manifest @param array<string,mixed> $evidence */
    private static function evidenceMatchesArtifact(array $manifest, array $evidence): bool
    {
        foreach ([
            'fingerprint',
            'library_sha256',
            'source_sha256',
            'build_id',
            'dependency_fingerprint',
            'dependency_linkage',
            'platform',
            'architecture',
        ] as $field) {
            $manifestValue = (string)($manifest[$field] ?? '');
            $evidenceValue = (string)($evidence[$field] ?? '');
            if ($manifestValue === '' || $evidenceValue === '' || !\hash_equals($manifestValue, $evidenceValue)) {
                return false;
            }
        }
        $versions = \json_encode($manifest['versions'] ?? [], \JSON_UNESCAPED_SLASHES);
        $versionsSha256 = \is_string($versions) ? \hash('sha256', $versions) : '';
        return (int)($manifest['abi_version'] ?? 0) > 0
            && (int)($manifest['abi_version'] ?? 0) === (int)($evidence['abi_version'] ?? 0)
            && \preg_match('/^[a-f0-9]{64}$/D', $versionsSha256) === 1
            && \hash_equals($versionsSha256, (string)($evidence['versions_sha256'] ?? ''));
    }

    private static function nativeRoot(): string
    {
        $base = \defined('BP') ? (string)\BP : \dirname(__DIR__, 6) . \DIRECTORY_SEPARATOR;
        return \rtrim($base, '\\/') . \DIRECTORY_SEPARATOR . 'var'
            . \DIRECTORY_SEPARATOR . 'server' . \DIRECTORY_SEPARATOR . 'native'
            . \DIRECTORY_SEPARATOR . 'http3';
    }

    /**
     * @return array{available:false,reason:string,manifest:array<string,mixed>}
     */
    private static function fail(string $reason): array
    {
        self::$failure = $reason !== '' ? $reason : 'native HTTP/3 load failed';
        return ['available' => false, 'reason' => self::$failure, 'manifest' => []];
    }
}
