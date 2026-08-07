<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx;

use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;
use Weline\Server\Service\Edge\Nginx\Runtime\NginxConfigPublication;
use Weline\Server\Service\SslCertificateService;

/**
 * Writes per-project nginx.conf that terminates TLS and proxies to WLS cleartext.
 *
 * Defaults target best-effort edge throughput: upstream keepalive, anonymous GET
 * micro-cache, gzip, reuseport, and access_log off.
 */
final class ManagedNginxConfigWriter
{
    private const MAX_CONFIG_BYTES = 16 * 1024 * 1024;
    private const MAX_MIME_TYPES_BYTES = 4 * 1024 * 1024;
    private const MAX_TLS_MATERIAL_BYTES = 1024 * 1024;
    private const WRITER_LOCK_WAIT_SECONDS = 30.0;
    private const LEGACY_LIFECYCLE_LOCK_WAIT_SECONDS = 90.0;
    private const FALLBACK_MIME_TYPES = "types { text/html html htm; text/css css; application/javascript js; }\n";

    private readonly ManagedNginxPaths $paths;
    private readonly NginxConfigPublication $publication;

    public function __construct(
        ?ManagedNginxPaths $paths = null,
        ?NginxConfigPublication $publication = null,
    ) {
        $this->paths = $paths ?? new ManagedNginxPaths();
        $this->publication = $publication ?? new NginxConfigPublication(
            $this->paths->confFile(),
            'managed nginx',
        );
    }

    /**
     * @param list<string> $serverNames
     * @param list<int> $upstreamPorts Actual loopback Worker/Dispatcher ports. Empty keeps the primary port.
     * @phpstan-param array<array-key,mixed> $upstreamPorts
     * @return array{conf:string,http:int,https:int,upstream:string,upstreams:list<string>,config_generation:string,config_sha256:string,candidate:bool}
     */
    public function write(
        int $upstreamPort,
        string $upstreamHost = '127.0.0.1',
        array $serverNames = [],
        bool $http2Enabled = false,
        bool $gzipSupported = true,
        bool $candidate = false,
        bool $http3Enabled = false,
        array $upstreamPorts = [],
    ): array
    {
        $this->paths->ensureRuntimeDirectories();
        $ports = (new ManagedNginxPortAllocator($this->paths))->allocate();
        $upstreamHost = $this->normalizeLoopbackUpstreamHost($upstreamHost);
        $upstreamPorts = $this->normalizeUpstreamPorts($upstreamPort, $upstreamPorts, $ports);
        $names = $this->resolveServerNames($serverNames);
        $ssl = $this->resolveSslMaterial($names);
        $sslCertificateSha256 = $ssl !== null
            ? $this->certificateFingerprint($ssl['cert'])
            : null;
        if ($ssl !== null && $sslCertificateSha256 === null) {
            throw new \RuntimeException('Unable to fingerprint managed nginx TLS certificate.');
        }
        $upstreams = \array_map(
            fn(int $port): string => $this->formatUpstreamEndpoint($upstreamHost, $port),
            $upstreamPorts,
        );
        $upstream = \implode(', ', $upstreams);
        $upstreamServers = \implode(
            "\n",
            \array_map(static fn(string $endpoint): string => '        server ' . $endpoint . ';', $upstreams),
        );
        $configGeneration = \bin2hex(\random_bytes(16));

        $nameList = \implode(' ', $names);
        $isWindows = \PHP_OS_FAMILY === 'Windows';
        // Darwin QUIC needs a stable per-Worker UDP socket, while Darwin TCP
        // reuseport can pin same-host validation traffic to one Worker.
        $tcpReuse = \in_array(\PHP_OS_FAMILY, ['Linux', 'BSD'], true) ? ' reuseport' : '';
        $quicReuse = \in_array(\PHP_OS_FAMILY, ['Darwin', 'Linux', 'BSD'], true) ? ' reuseport' : '';
        $sslBlock = '';
        if ($ssl !== null) {
            $cert = $this->nginxQuotedPath($ssl['cert']);
            $key = $this->nginxQuotedPath($ssl['key']);
            $http2Line = $http2Enabled ? "\n    http2 on;" : '';
            $http3Configured = $http3Enabled && !$isWindows;
            $http3Block = $http3Configured
                ? "\n    listen {$ports['https']} quic{$quicReuse};"
                    . "\n    http3 on;"
                    . "\n    quic_retry on;"
                : '';
            $sslBlock = <<<NGINX

    listen {$ports['https']} ssl{$tcpReuse};{$http2Line}{$http3Block}
    ssl_certificate     {$cert};
    ssl_certificate_key {$key};
    ssl_protocols       TLSv1.3;
    ssl_session_cache   shared:WLS_SSL:50m;
    ssl_session_timeout 1d;
    ssl_early_data      off;
    ssl_session_tickets on;
    ssl_buffer_size     4k;
NGINX;
        } else {
            $http3Configured = false;
        }

        $cacheDir = $this->nginxQuotedPath($this->paths->cacheDir());
        $edgeCache = $this->paths->edgeCacheEnabled();
        $ttl = $this->paths->edgeCacheTtlSec();
        $cacheMaxMb = $this->paths->edgeCacheMaxSizeMb();
        $keysZoneMb = $this->paths->edgeCacheKeysZoneMb();
        $gzipOn = $this->paths->gzipEnabled() && $gzipSupported;
        $gzipLevel = $this->paths->gzipCompLevel();
        $upstreamKeepalive = $this->paths->upstreamKeepalive();
        $upstreamKeepaliveTimeoutSec = $this->paths->upstreamKeepaliveTimeoutSec();
        $workerConnections = $this->paths->workerConnections();
        $mimeTypes = $this->stageMimeTypesDependency();
        $quotedMimeTypes = $this->nginxQuotedPath($mimeTypes);

        $tempBlock = '';
        if ($isWindows) {
            $tempRoot = $this->paths->tempDir();
            $clientBodyTemp = $this->nginxQuotedPath($tempRoot . DIRECTORY_SEPARATOR . 'client_body_temp');
            $proxyTemp = $this->nginxQuotedPath($tempRoot . DIRECTORY_SEPARATOR . 'proxy_temp');
            $fastcgiTemp = $this->nginxQuotedPath($tempRoot . DIRECTORY_SEPARATOR . 'fastcgi_temp');
            $uwsgiTemp = $this->nginxQuotedPath($tempRoot . DIRECTORY_SEPARATOR . 'uwsgi_temp');
            $scgiTemp = $this->nginxQuotedPath($tempRoot . DIRECTORY_SEPARATOR . 'scgi_temp');
            $tempBlock = <<<NGINX

    client_body_temp_path {$clientBodyTemp};
    proxy_temp_path       {$proxyTemp};
    fastcgi_temp_path     {$fastcgiTemp};
    uwsgi_temp_path       {$uwsgiTemp};
    scgi_temp_path        {$scgiTemp};
NGINX;
        }

        $cacheHttpBlock = '';
        $cacheLocationBlock = '';
        $gzipBlock = '';
        if ($gzipOn) {
            $gzipBlock = <<<NGINX

    gzip on;
    gzip_comp_level {$gzipLevel};
    gzip_min_length 256;
    gzip_proxied any;
    gzip_types text/plain text/css text/xml text/javascript application/javascript application/json application/xml image/svg+xml;
    gzip_vary on;
NGINX;
        }

        if ($edgeCache) {
            $cacheHttpBlock = <<<NGINX

    # 匿名 GET/HEAD 边缘微缓存；Cookie、Authorization 或 Upgrade 请求一律回源。
    proxy_cache_path {$cacheDir} levels=1:2 keys_zone=wls_edge:{$keysZoneMb}m max_size={$cacheMaxMb}m inactive=30m use_temp_path=off;
    map "\$http_cookie|\$http_authorization|\$http_upgrade" \$wls_edge_bypass {
        default 1;
        "||"    0;
    }
NGINX;
            $cacheLocationBlock = <<<NGINX

            proxy_cache wls_edge;
            proxy_cache_key "\$scheme\$request_method\$host\$request_uri";
            proxy_cache_methods GET HEAD;
            proxy_cache_valid 200 {$ttl}s;
            proxy_cache_valid 301 302 {$ttl}s;
            proxy_cache_lock on;
            proxy_cache_lock_timeout 5s;
            proxy_cache_use_stale error timeout updating http_500 http_502 http_503 http_504;
            proxy_cache_background_update on;
            proxy_cache_revalidate on;
            proxy_cache_bypass \$wls_edge_bypass;
            proxy_no_cache \$wls_edge_bypass;
            add_header X-Wls-Edge-Cache \$upstream_cache_status always;
NGINX;
        }
        $authorityMapBlock = <<<NGINX

    # HTTP/3 exposes :authority through \$host even when \$http_host is empty.
    map \$http_host \$wls_upstream_authority {
        default \$http_host;
        ""      "\$host:\$server_port";
    }

    # Local health benchmarks propagate an explicit client close so fresh-edge
    # samples also exercise a fresh WLS connection; all other traffic keeps
    # the upstream keepalive pool.
    map \$http_connection \$wls_probe_upstream_connection {
        default "";
        close close;
    }

    # Only an exact WebSocket Upgrade can switch a business connection into a
    # tunnel. Every other value is stripped and keeps the upstream pool reusable.
    map \$http_upgrade \$wls_business_upstream_upgrade {
        default   "";
        websocket websocket;
    }
    map \$http_upgrade \$wls_business_upstream_connection {
        default   "";
        websocket upgrade;
    }
NGINX;
        $protocolMapBlock = '';
        $serverProtocolHeaders = '';
        if ($http3Configured) {
            $protocolMapBlock = <<<NGINX

    map \$scheme \$wls_alt_svc {
        default "";
        https 'h3=":{$ports['https']}"; ma=86400';
    }
NGINX;
            $serverProtocolHeaders = "\n        add_header Alt-Svc \$wls_alt_svc always;";
        }
        $locationProtocolHeaders = "\n            add_header X-Wls-Nginx-Config {$configGeneration} always;";
        if ($http3Configured) {
            $locationProtocolHeaders .= "\n            add_header Alt-Svc \$wls_alt_svc always;";
        }

        $httpsPortSuffix = (int)$ports['https'] === 443 ? '' : ':' . (int)$ports['https'];
        $httpRedirectLocation = $ssl !== null
            ? "\n            if (\$scheme = http) { return 308 https://\$host{$httpsPortSuffix}\$request_uri; }"
            : '';

        $workerProcesses = $isWindows ? '1' : 'auto';
        // Windows nginx ignores/limits high rlimit; keep a conservative value.
        $rlimit = $isWindows ? \min(8192, $workerConnections) : $workerConnections;
        $eventsExtra = "    multi_accept        on;\n    use                 epoll;\n";
        if ($isWindows) {
            $eventsExtra = "    multi_accept        on;\n";
        }

        $conf = <<<NGINX
worker_processes  {$workerProcesses};
worker_rlimit_nofile  {$rlimit};
worker_shutdown_timeout 10s;
error_log  logs/error.log  warn;
pid        run/nginx.pid;

events {
    worker_connections  {$workerConnections};
{$eventsExtra}}

http {
    include       {$quotedMimeTypes};
    default_type  application/octet-stream;
    access_log    off;
    sendfile      on;
    server_tokens off;
    tcp_nopush    on;
    tcp_nodelay   on;
    keepalive_timeout  65;
    keepalive_requests 100000;
    reset_timedout_connection on;
    open_file_cache max=20000 inactive=60s;
    open_file_cache_valid 30s;
    open_file_cache_min_uses 2;
    open_file_cache_errors on;
{$tempBlock}{$gzipBlock}
{$cacheHttpBlock}{$authorityMapBlock}{$protocolMapBlock}

    upstream wls_backend {
{$upstreamServers}
        keepalive {$upstreamKeepalive};
        keepalive_requests 1000;
        keepalive_timeout {$upstreamKeepaliveTimeoutSec}s;
    }

    server {
        listen {$ports['http']}{$tcpReuse};
{$sslBlock}
        server_name {$nameList};
        add_header X-Wls-Nginx-Config {$configGeneration} always;

{$serverProtocolHeaders}
        location = /_wls/nginx/tls-session-probe {
            allow 127.0.0.1;
            allow ::1;
            deny all;
            add_header X-Wls-Nginx-Config {$configGeneration} always;
            add_header X-Wls-Nginx-Worker-Pid \$pid always;
            add_header X-Wls-Nginx-Tls-Session-Reused \$ssl_session_reused always;
            add_header X-Wls-Nginx-Tls-Protocol \$ssl_protocol always;
            add_header Cache-Control "no-store" always;
            empty_gif;
        }

        location ^~ /.well-known/acme-challenge/ {
            proxy_pass http://wls_backend;
            proxy_http_version 1.1;
            proxy_set_header Connection "";
            proxy_set_header Host \$wls_upstream_authority;
            proxy_set_header X-Forwarded-Port \$server_port;
            proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
            proxy_set_header X-Forwarded-Proto \$scheme;
        }

        # 内部探测不走边缘缓存
        location ^~ /_wls/ {
            proxy_pass http://wls_backend;
            allow 127.0.0.1;
            allow ::1;
            deny all;
            proxy_http_version 1.1;
            proxy_set_header Connection \$wls_probe_upstream_connection;
            proxy_set_header Host \$wls_upstream_authority;
            proxy_set_header X-Forwarded-Port \$server_port;
            proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
            proxy_set_header X-Forwarded-Proto \$scheme;
            proxy_set_header X-Real-IP \$remote_addr;
        }

        # Framework SSE is a long-lived subscription. Keep transport buffering,
        # caching and compression disabled without changing ordinary requests.
        location = /api/framework/stream {
            proxy_pass http://wls_backend;
            proxy_http_version 1.1;
{$httpRedirectLocation}
            proxy_set_header Upgrade \$wls_business_upstream_upgrade;
            proxy_set_header Connection \$wls_business_upstream_connection;
            proxy_set_header Host \$wls_upstream_authority;
            proxy_set_header X-Forwarded-Port \$server_port;
            proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
            proxy_set_header X-Forwarded-Proto \$scheme;
            proxy_set_header X-Real-IP \$remote_addr;
            proxy_buffering off;
            proxy_cache off;
            proxy_no_cache 1;
            gzip off;
            proxy_read_timeout 300s;
            proxy_send_timeout 300s;
        }

        location / {
            proxy_pass http://wls_backend;
            proxy_http_version 1.1;
{$httpRedirectLocation}
            proxy_set_header Upgrade \$wls_business_upstream_upgrade;
            proxy_set_header Connection \$wls_business_upstream_connection;
            proxy_set_header Host \$wls_upstream_authority;
            proxy_set_header X-Forwarded-Port \$server_port;
            proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
            proxy_set_header X-Forwarded-Proto \$scheme;
            proxy_set_header X-Real-IP \$remote_addr;
            proxy_buffering on;
            proxy_buffer_size 64k;
            proxy_buffers 32 64k;
            proxy_busy_buffers_size 128k;
            proxy_max_temp_file_size 0;
{$locationProtocolHeaders}{$cacheLocationBlock}
        }
    }
}
NGINX;

        // macOS/FreeBSD: epoll is unavailable — rewrite to kqueue.
        // Windows: events.use already omitted above.
        if (\PHP_OS_FAMILY === 'Darwin' || \PHP_OS_FAMILY === 'BSD') {
            $conf = \str_replace("    use                 epoll;\n", "    use                 kqueue;\n", $conf);
        }

        $configSha256 = \hash('sha256', $conf);
        if ($candidate) {
            $confFile = $this->publication->stageCandidate($conf);
        } else {
            $confFile = $this->publishLegacyConfig($conf);
        }

        return [
            'conf' => $confFile,
            'http' => $ports['http'],
            'https' => $ports['https'],
            'upstream' => $upstream,
            'upstreams' => $upstreams,
            'ssl' => $ssl !== null,
            'ssl_certificate_sha256' => $sslCertificateSha256,
            'server_names' => $names,
            'edge_cache' => $edgeCache,
            'edge_cache_ttl_sec' => $ttl,
            'edge_cache_max_size_mb' => $cacheMaxMb,
            'gzip' => $gzipOn,
            'upstream_keepalive' => $upstreamKeepalive,
            'upstream_keepalive_timeout_sec' => $upstreamKeepaliveTimeoutSec,
            'http2_enabled' => $ssl !== null && $http2Enabled,
            'http3_enabled' => $http3Configured,
            'alt_svc_enabled' => $http3Configured,
            'http1_fallback' => $ssl !== null,
            'tls_protocols' => $ssl !== null ? ['TLSv1.3'] : [],
            'tls_session_cache_shared' => $ssl !== null,
            'tls_session_tickets' => $ssl !== null,
            'config_sha256' => $configSha256,
            'config_generation' => $configGeneration,
            'candidate' => $candidate,
        ];
    }

    /**
     * Compatibility-only publication for callers that still request an
     * active config directly. Production lifecycle paths always request a
     * candidate while already holding this same lock, so they never enter
     * this method or recursively acquire the lifecycle lock.
     */
    private function publishLegacyConfig(string $contents): string
    {
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $this->paths->lifecycleLockFile(),
            function () use ($contents): string {
                $this->cleanupLegacyPublicationRecoveryBackups();
                $this->publication->recoverInterruptedPublication();
                $candidate = $this->publication->stageCandidate($contents);
                $rollback = null;
                $published = false;
                try {
                    $publication = $this->publication->publishCandidate(
                        $candidate,
                        \bin2hex(\random_bytes(16)),
                    );
                    $candidate = '';
                    $published = true;
                    $rollback = \is_string($publication['rollback'] ?? null)
                        ? $publication['rollback']
                        : null;
                    $this->cleanupPublishedLegacyRecoveryBackups($contents);
                    if (!$this->publication->commitPublished($rollback)) {
                        throw new \RuntimeException(
                            'Unable to commit the managed Nginx legacy config transaction.',
                        );
                    }
                    $rollback = null;
                    $published = false;
                    // Committing a replacement may itself retain one Windows
                    // ReplaceFile backup for the reusable last-good target.
                    // Close that artifact only after the new active and LKG
                    // both pass their target-specific validation.
                    $this->cleanupPublishedLegacyRecoveryBackups($contents);
                    return $this->paths->confFile();
                } catch (\Throwable $throwable) {
                    if ($candidate !== ''
                        && (\file_exists($candidate) || \is_link($candidate))
                    ) {
                        try {
                            $this->publication->discardCandidate($candidate);
                        } catch (\Throwable) {
                        }
                    }
                    if ($published
                        && ($rollback === null
                            || \file_exists($rollback)
                            || \is_link($rollback))
                    ) {
                        try {
                            $this->publication->rollbackPublished($rollback);
                        } catch (\Throwable $rollbackFailure) {
                            throw new \RuntimeException(
                                'Managed Nginx legacy publication failed and rollback did not complete: '
                                    . $rollbackFailure->getMessage(),
                                0,
                                $throwable,
                            );
                        }
                    }
                    throw $throwable;
                }
            },
            waitTimeoutSeconds: self::LEGACY_LIFECYCLE_LOCK_WAIT_SECONDS,
        );
    }

    private function cleanupLegacyPublicationRecoveryBackups(): void
    {
        $processManager = new ManagedNginxProcessManager($this->paths);
        $this->publication->cleanupAtomicWriteRecoveryBackups(
            static function (
                string $path,
                string $contents,
                string $kind,
            ) use ($processManager): void {
                if ($contents === '') {
                    throw new \RuntimeException(
                        'Managed Nginx ' . $kind . ' recovery target is empty.',
                    );
                }
                $result = $processManager->testConfig($path);
                if ($result['code'] !== 0) {
                    throw new \RuntimeException(
                        'Managed Nginx ' . $kind
                            . ' recovery target failed nginx -t: '
                            . \substr(\trim($result['output']), 0, 1024),
                    );
                }
            },
        );
    }

    private function cleanupPublishedLegacyRecoveryBackups(string $contents): void
    {
        $expectedDigest = \hash('sha256', $contents);
        $processManager = new ManagedNginxProcessManager($this->paths);
        $this->publication->cleanupAtomicWriteRecoveryBackups(
            function (
                string $path,
                string $current,
                string $kind,
            ) use ($expectedDigest, $processManager): void {
                if ($kind === 'active config') {
                    if (!\hash_equals($expectedDigest, \hash('sha256', $current))) {
                        throw new \RuntimeException(
                            'Managed Nginx active config changed after legacy publication.',
                        );
                    }
                    return;
                }
                $result = $processManager->testConfig($path);
                if ($result['code'] !== 0) {
                    throw new \RuntimeException(
                        'Managed Nginx ' . $kind
                            . ' recovery target failed nginx -t: '
                            . \substr(\trim($result['output']), 0, 1024),
                    );
                }
            },
        );
        $active = GatewayProjectStateFilesystem::read(
            $this->paths->confFile(),
            self::MAX_CONFIG_BYTES,
            'Managed Nginx committed legacy active config',
        );
        if (!\hash_equals($expectedDigest, \hash('sha256', $active))) {
            throw new \RuntimeException(
                'Managed Nginx legacy active config failed committed readback.',
            );
        }
    }

    /** @return array{conf:string,config_generation:string,config_sha256:string,candidate:bool} */
    public function refreshCandidate(): array
    {
        $active = $this->paths->confFile();
        $contents = \is_file($active)
            ? GatewayProjectStateFilesystem::read(
                $active,
                self::MAX_CONFIG_BYTES,
                'Managed Nginx active config',
            )
            : false;
        if (!\is_string($contents) || $contents === '') {
            throw new \RuntimeException('Managed nginx.conf is unavailable for a verified reload.');
        }
        $generation = \bin2hex(\random_bytes(16));
        $matches = [];
        $markerCount = \preg_match_all(
            '/add_header X-Wls-Nginx-Config ([a-f0-9]{32}) always;/',
            $contents,
            $matches,
        );
        $oldGenerations = \array_values(\array_unique($matches[1]));
        if ($markerCount !== 3 || \count($oldGenerations) !== 1) {
            throw new \RuntimeException('Managed nginx.conf lacks one consistent WLS config generation.');
        }
        $candidateContents = \str_replace(
            'add_header X-Wls-Nginx-Config ' . $oldGenerations[0] . ' always;',
            'add_header X-Wls-Nginx-Config ' . $generation . ' always;',
            $contents,
            $count,
        );
        if ($count !== 3) {
            throw new \RuntimeException('Managed nginx.conf generation replacement was incomplete.');
        }
        $candidate = $this->publication->stageCandidate($candidateContents);

        return [
            'conf' => $candidate,
            'config_generation' => $generation,
            'config_sha256' => \hash('sha256', $candidateContents),
            'candidate' => true,
        ];
    }

    /** @return array{conf:string,rollback:string|null} */
    public function publishCandidate(string $candidate, string $transactionId): array
    {
        return $this->publication->publishCandidate($candidate, $transactionId);
    }

    public function rollbackPublished(?string $rollback): void
    {
        $this->publication->rollbackPublished($rollback);
    }

    public function recoverInterruptedPublication(): void
    {
        $this->publication->recoverInterruptedPublication();
    }

    /** @param \Closure(string,string,string):void $validateConfig */
    public function cleanupAtomicWriteRecoveryBackups(\Closure $validateConfig): void
    {
        $this->publication->cleanupAtomicWriteRecoveryBackups($validateConfig);
    }

    public function cleanupResolvedRollbackTemporaries(string $rollback): void
    {
        $this->publication->cleanupResolvedRollbackTemporaries($rollback);
    }

    public function rollbackPathForTransaction(string $transactionId): string
    {
        return $this->publication->rollbackPathForTransaction($transactionId);
    }


    public function commitPublished(?string $rollback): bool
    {
        return $this->publication->commitPublished($rollback);
    }

    public function discardCandidate(string $candidate): void
    {
        $this->publication->discardCandidate($candidate);
    }

    private function normalizeLoopbackUpstreamHost(string $host): string
    {
        $normalized = \strtolower(\trim($host));
        if (\str_starts_with($normalized, '[') && \str_ends_with($normalized, ']')) {
            $normalized = \substr($normalized, 1, -1);
        }
        if ($normalized === 'localhost' || $normalized === '::1') {
            return $normalized;
        }
        if (\filter_var($normalized, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && \str_starts_with($normalized, '127.')
        ) {
            return $normalized;
        }

        throw new \InvalidArgumentException(
            'Managed nginx upstream must be an explicit loopback WLS backend address.'
        );
    }

    private function formatUpstreamEndpoint(string $host, int $port): string
    {
        return \str_contains($host, ':')
            ? '[' . $host . ']:' . $port
            : $host . ':' . $port;
    }

    /**
     * @param list<int> $upstreamPorts
     * @phpstan-param array<array-key,mixed> $upstreamPorts
     * @param array{http:int,https:int} $publicPorts
     * @return list<int>
     */
    private function normalizeUpstreamPorts(int $primaryPort, array $upstreamPorts, array $publicPorts): array
    {
        $candidates = $upstreamPorts !== [] ? $upstreamPorts : [$primaryPort];
        if (!\array_is_list($candidates)) {
            throw new \InvalidArgumentException('Managed nginx upstream ports must be a list.');
        }

        $normalized = [];
        foreach ($candidates as $candidate) {
            if (!\is_int($candidate) && !(\is_string($candidate) && \ctype_digit($candidate))) {
                throw new \InvalidArgumentException('Managed nginx upstream ports must contain only integers.');
            }
            $port = (int)$candidate;
            if ($port < 1 || $port > 65535) {
                throw new \InvalidArgumentException('Managed nginx upstream port must be between 1 and 65535.');
            }
            if ($port === (int)$publicPorts['http'] || $port === (int)$publicPorts['https']) {
                throw new \InvalidArgumentException(
                    'Managed nginx upstream port must not equal either managed public listen port.'
                );
            }
            $normalized[$port] = $port;
        }
        return \array_values($normalized);
    }

    /**
     * @param list<string> $serverNames
     * @return list<string>
     */
    private function resolveServerNames(array $serverNames): array
    {
        $cfgNames = $this->paths->config()['server_names'] ?? [];
        $merged = [];
        foreach (\array_merge($serverNames, \is_array($cfgNames) ? $cfgNames : []) as $name) {
            $name = \strtolower(\trim((string)$name));
            if ($name !== '') {
                if (!$this->isSafeServerName($name)) {
                    throw new \InvalidArgumentException('Managed nginx server_name contains an unsafe host.');
                }
                $merged[$name] = $name;
            }
        }
        if ($merged === []) {
            return ['_'];
        }
        return \array_values($merged);
    }
    private function isSafeServerName(string $name): bool
    {
        if ($name === '_' || $name === 'localhost') {
            return true;
        }
        $ipCandidate = \trim($name, '[]');
        if (\filter_var($ipCandidate, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        if (\strlen($name) > 253) {
            return false;
        }
        if (\str_starts_with($name, '*.')) {
            $name = \substr($name, 2);
        } elseif (\str_starts_with($name, '.')) {
            $name = \substr($name, 1);
        }
        $labels = \explode('.', $name);
        foreach ($labels as $label) {
            if ($label === ''
                || \strlen($label) > 63
                || \preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $label) !== 1
            ) {
                return false;
            }
        }

        return true;
    }

    private function stageMimeTypesDependency(): string
    {
        return $this->withWriterLock(function (): string {
            $source = $this->paths->installRoot() . DIRECTORY_SEPARATOR
                . 'conf' . DIRECTORY_SEPARATOR . 'mime.types';
            $sourceBefore = @\lstat($source);
            if (\is_array($sourceBefore)) {
                $this->assertCanonicalSourcePath(
                    $source,
                    'Managed Nginx MIME types source',
                );
                $contents = GatewayProjectStateFilesystem::read(
                    $source,
                    self::MAX_MIME_TYPES_BYTES,
                    'Managed Nginx MIME types source',
                );
            } else {
                if (\file_exists($source) || \is_link($source)) {
                    throw new \RuntimeException(
                        'Managed Nginx MIME types source path is indeterminate or unsafe.',
                    );
                }
                $contents = self::FALLBACK_MIME_TYPES;
            }
            $digest = \hash('sha256', $contents);
            $target = $this->paths->confDir() . DIRECTORY_SEPARATOR
                . 'mime.' . $digest . '.types';
            $this->stageContentAddressedContents(
                $target,
                $contents,
                self::MAX_MIME_TYPES_BYTES,
                0444,
                'Managed Nginx content-addressed MIME types',
            );

            $sourceAfter = @\lstat($source);
            if (\is_array($sourceBefore)) {
                $this->assertCanonicalSourcePath(
                    $source,
                    'Managed Nginx MIME types source readback',
                );
                $verifiedSource = GatewayProjectStateFilesystem::read(
                    $source,
                    self::MAX_MIME_TYPES_BYTES,
                    'Managed Nginx MIME types source readback',
                );
                if (!\hash_equals(
                    \hash('sha256', $contents),
                    \hash('sha256', $verifiedSource),
                )) {
                    throw new \RuntimeException(
                        'Managed Nginx MIME types source changed while staging.',
                    );
                }
            } elseif (\is_array($sourceAfter) || \file_exists($source)) {
                throw new \RuntimeException(
                    'Managed Nginx MIME types source appeared while staging the fallback.',
                );
            }

            return $target;
        });
    }


    /**
     * @param list<string> $serverNames
     * @return array{cert:string,key:string}|null
     */
    private function resolveSslMaterial(array $serverNames): ?array
    {
        $sslRoot = $this->paths->projectRoot() . DIRECTORY_SEPARATOR . 'app'
            . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'ssl';
        foreach ($serverNames as $name) {
            if ($name === '_' || $name === '') {
                continue;
            }
            $segment = SslCertificateService::certificateStorageSegmentForFilesystem($name);
            $dir = $sslRoot . DIRECTORY_SEPARATOR . $segment;
            $cert = $dir . DIRECTORY_SEPARATOR . 'fullchain.pem';
            $key = $dir . DIRECTORY_SEPARATOR . 'privkey.pem';
            if (\is_file($cert) && \is_file($key)) {
                return $this->localizeSslMaterial($cert, $key);
            }
        }
        $localFallbackAllowed = \array_filter(
            $serverNames,
            static fn(mixed $name): bool => !\in_array(
                \strtolower(\trim((string)$name)),
                ['', '_', 'localhost', '127.0.0.1', '::1'],
                true,
            ),
        ) === [];
        if ($localFallbackAllowed) {
            $local = $sslRoot . DIRECTORY_SEPARATOR . 'localhost';
            $cert = $local . DIRECTORY_SEPARATOR . 'fullchain.pem';
            $key = $local . DIRECTORY_SEPARATOR . 'privkey.pem';
            if (\is_file($cert) && \is_file($key)) {
                return $this->localizeSslMaterial($cert, $key);
            }
        }
        return null;
    }

    /** @return array{cert:string,key:string} */
    private function localizeSslMaterial(string $cert, string $key): array
    {
        if (\PHP_OS_FAMILY !== 'Windows') {
            return ['cert' => $cert, 'key' => $key];
        }
        return $this->stageLocalizedSslMaterial($cert, $key);
    }

    /** @return array{cert:string,key:string} */
    private function stageLocalizedSslMaterial(string $cert, string $key): array
    {
        return $this->withWriterLock(function () use ($cert, $key): array {
            $this->assertCanonicalSourcePath(
                $cert,
                'Managed Nginx TLS certificate source',
            );
            $this->assertCanonicalSourcePath(
                $key,
                'Managed Nginx TLS private key source',
            );
            $certContents = GatewayProjectStateFilesystem::read(
                $cert,
                self::MAX_TLS_MATERIAL_BYTES,
                'Managed Nginx TLS certificate source',
            );
            $keyContents = GatewayProjectStateFilesystem::read(
                $key,
                self::MAX_TLS_MATERIAL_BYTES,
                'Managed Nginx TLS private key source',
            );
            $certSha = \hash('sha256', $certContents);
            $keySha = \hash('sha256', $keyContents);
            $directory = $this->paths->confDir() . DIRECTORY_SEPARATOR . 'certs';
            $this->ensureContentAddressedDirectory($directory);
            $identity = \substr(\hash('sha256', $certSha . "\0" . $keySha), 0, 32);
            $localCert = $directory . DIRECTORY_SEPARATOR . $identity . '-fullchain.pem';
            $localKey = $directory . DIRECTORY_SEPARATOR . $identity . '-privkey.pem';
            $this->stageContentAddressedContents(
                $localCert,
                $certContents,
                self::MAX_TLS_MATERIAL_BYTES,
                0444,
                'Managed Nginx content-addressed TLS certificate',
            );
            $this->stageContentAddressedContents(
                $localKey,
                $keyContents,
                self::MAX_TLS_MATERIAL_BYTES,
                0400,
                'Managed Nginx content-addressed TLS private key',
            );
            $this->assertCanonicalSourcePath(
                $cert,
                'Managed Nginx TLS certificate source readback',
            );
            $this->assertCanonicalSourcePath(
                $key,
                'Managed Nginx TLS private key source readback',
            );
            $certReadback = GatewayProjectStateFilesystem::read(
                $cert,
                self::MAX_TLS_MATERIAL_BYTES,
                'Managed Nginx TLS certificate source readback',
            );
            $keyReadback = GatewayProjectStateFilesystem::read(
                $key,
                self::MAX_TLS_MATERIAL_BYTES,
                'Managed Nginx TLS private key source readback',
            );
            if (!\hash_equals($certSha, \hash('sha256', $certReadback))
                || !\hash_equals($keySha, \hash('sha256', $keyReadback))
            ) {
                throw new \RuntimeException(
                    'Managed Nginx TLS source changed while staging content-addressed material.',
                );
            }

            return ['cert' => $localCert, 'key' => $localKey];
        });
    }

    /**
     * MIME/TLS snapshots have reusable names, unlike config candidates. Keep
     * every reader and writer of those names in one bounded namespace lock.
     * This lock is deliberately distinct from the managed lifecycle lock.
     *
     * @template TResult
     * @param \Closure():TResult $callback
     * @return TResult
     */
    private function withWriterLock(\Closure $callback): mixed
    {
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $this->paths->confDir() . DIRECTORY_SEPARATOR
                . '.managed-nginx-writer.lock',
            $callback,
            waitTimeoutSeconds: self::WRITER_LOCK_WAIT_SECONDS,
        );
    }

    private function assertCanonicalSourcePath(string $path, string $label): void
    {
        $directory = \dirname($path);
        $directoryStatus = @\lstat($directory);
        $resolvedDirectory = @\realpath($directory);
        $resolvedPath = @\realpath($path);
        if (!\is_array($directoryStatus)
            || \is_link($directory)
            || ((((int)$directoryStatus['mode']) & 0170000) !== 0040000)
            || !\is_string($resolvedDirectory)
            || !\is_string($resolvedPath)
            || !$this->sameFilesystemPath($resolvedDirectory, $directory)
            || !$this->sameFilesystemPath($resolvedPath, $path)
        ) {
            throw new \RuntimeException($label . ' path traverses an unsafe link.');
        }
    }

    private function ensureContentAddressedDirectory(string $directory): void
    {
        $parent = \dirname($directory);
        $parentStatus = @\lstat($parent);
        $resolvedParent = @\realpath($parent);
        if (!\is_array($parentStatus)
            || \is_link($parent)
            || ((((int)$parentStatus['mode']) & 0170000) !== 0040000)
            || !\is_string($resolvedParent)
            || !$this->sameFilesystemPath($resolvedParent, $parent)
        ) {
            throw new \RuntimeException(
                'Managed Nginx content-addressed directory parent is unsafe.',
            );
        }

        $status = @\lstat($directory);
        if (!\is_array($status)) {
            if (\file_exists($directory)
                || \is_link($directory)
                || !@\mkdir($directory, 0700)
            ) {
                throw new \RuntimeException(
                    'Unable to create the managed Nginx content-addressed directory.',
                );
            }
            $status = @\lstat($directory);
        }
        $resolved = @\realpath($directory);
        if (!\is_array($status)
            || \is_link($directory)
            || ((((int)$status['mode']) & 0170000) !== 0040000)
            || !\is_string($resolved)
            || !$this->sameFilesystemPath($resolved, $directory)
            || (\PHP_OS_FAMILY !== 'Windows'
                && (((int)$status['mode']) & 0777) !== 0700)
        ) {
            throw new \RuntimeException(
                'Managed Nginx content-addressed directory is unsafe.',
            );
        }
    }

    /**
     * Caller must hold the writer namespace lock. A content-addressed leaf is
     * immutable: an existing different generation is a collision, never an
     * overwrite target.
     */
    private function stageContentAddressedContents(
        string $target,
        string $contents,
        int $maximumBytes,
        int $permissions,
        string $label,
    ): void {
        $length = \strlen($contents);
        if ($length < 1 || $length > $maximumBytes) {
            throw new \RuntimeException($label . ' has an invalid size.');
        }
        $expectedDigest = \hash('sha256', $contents);
        $existing = GatewayProjectStateFilesystem::readOptional(
            $target,
            $maximumBytes,
            $label,
        );
        if ($existing === null) {
            GatewayProjectStateFilesystem::atomicWrite(
                $target,
                $contents,
                $permissions,
            );
        } elseif (\strlen($existing) !== $length
            || !\hash_equals($expectedDigest, \hash('sha256', $existing))
            || !\hash_equals($contents, $existing)
        ) {
            throw new \RuntimeException(
                $label . ' content-addressed target collides with different content.',
            );
        }

        $this->assertContentAddressedTarget(
            $target,
            $contents,
            $maximumBytes,
            $permissions,
            $label,
        );
        GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
            $target,
            $maximumBytes,
            $label,
            function (string $current) use ($contents, $expectedDigest, $label): void {
                if (\strlen($current) !== \strlen($contents)
                    || !\hash_equals($expectedDigest, \hash('sha256', $current))
                    || !\hash_equals($contents, $current)
                ) {
                    throw new \RuntimeException(
                        $label . ' recovery target failed exact validation.',
                    );
                }
            },
        );
        $this->assertContentAddressedTarget(
            $target,
            $contents,
            $maximumBytes,
            $permissions,
            $label . ' final readback',
        );
    }

    private function assertContentAddressedTarget(
        string $target,
        string $expected,
        int $maximumBytes,
        int $permissions,
        string $label,
    ): void {
        $current = GatewayProjectStateFilesystem::read(
            $target,
            $maximumBytes,
            $label,
        );
        $status = @\lstat($target);
        if (\strlen($current) !== \strlen($expected)
            || !\hash_equals(\hash('sha256', $expected), \hash('sha256', $current))
            || !\hash_equals($expected, $current)
            || !\is_array($status)
            || (\PHP_OS_FAMILY !== 'Windows'
                && (((int)$status['mode']) & 0777) !== $permissions)
        ) {
            throw new \RuntimeException($label . ' failed immutable readback.');
        }
    }

    private function sameFilesystemPath(string $left, string $right): bool
    {
        $left = \str_replace('\\', '/', $left);
        $right = \str_replace('\\', '/', $right);
        $left = $left === '/' ? $left : \rtrim($left, '/');
        $right = $right === '/' ? $right : \rtrim($right, '/');
        if (\PHP_OS_FAMILY === 'Windows') {
            $left = \strtolower($left);
            $right = \strtolower($right);
        }

        return $left !== '' && \hash_equals($left, $right);
    }

    private function nginxQuotedPath(string $path): string
    {
        $normalized = \str_replace('\\', '/', $path);
        $escaped = \str_replace(['$', '"'], ['\\$', '\\"'], $normalized);

        return '"' . $escaped . '"';
    }

    private function certificateFingerprint(string $path): ?string
    {
        $certificate = @\openssl_x509_read('file://' . $path);
        if ($certificate === false) {
            return null;
        }
        $fingerprint = @\openssl_x509_fingerprint($certificate, 'sha256');
        if (!\is_string($fingerprint)
            || \preg_match('/\A[a-f0-9]{64}\z/D', \strtolower($fingerprint)) !== 1
        ) {
            return null;
        }

        return \strtolower($fingerprint);
    }

}
