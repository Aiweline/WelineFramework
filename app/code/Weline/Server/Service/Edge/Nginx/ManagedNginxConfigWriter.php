<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx;

use Weline\Server\Service\SslCertificateService;

/**
 * Writes per-project nginx.conf that terminates TLS and proxies to WLS cleartext.
 *
 * Defaults target best-effort edge throughput: upstream keepalive, anonymous GET
 * micro-cache, gzip, reuseport, and access_log off.
 */
final class ManagedNginxConfigWriter
{
    public function __construct(private readonly ManagedNginxPaths $paths = new ManagedNginxPaths())
    {
    }

    /**
     * @param list<string> $serverNames
     * @param list<int> $upstreamPorts Actual loopback Worker/Dispatcher ports. Empty keeps the primary port.
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
    include       mime.types;
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
        // mime.types: copy from install tree when present, else minimal stub
        $mimeSrc = $this->paths->installRoot() . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . 'mime.types';
        $mimeDst = $this->paths->confDir() . DIRECTORY_SEPARATOR . 'mime.types';
        if (\is_file($mimeSrc)) {
            @\copy($mimeSrc, $mimeDst);
        } elseif (!\is_file($mimeDst)) {
            \file_put_contents($mimeDst, "types { text/html html htm; text/css css; application/javascript js; }\n");
        }

        $confFile = $candidate
            ? $this->candidatePath()
            : $this->paths->confFile();
        if (\file_put_contents($confFile, $conf, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write managed nginx.conf: ' . $confFile);
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

    /** @return array{conf:string,config_generation:string,config_sha256:string,candidate:bool} */
    public function refreshCandidate(): array
    {
        $active = $this->paths->confFile();
        $contents = \is_file($active) ? \file_get_contents($active) : false;
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
        $oldGenerations = \array_values(\array_unique((array)($matches[1] ?? [])));
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
        $candidate = $this->candidatePath();
        if (\file_put_contents($candidate, $candidateContents, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write managed nginx reload candidate: ' . $candidate);
        }

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
        $this->assertCandidatePath($candidate);
        if (!\is_file($candidate)) {
            throw new \RuntimeException('Managed nginx candidate config is missing.');
        }
        $active = $this->paths->confFile();
        $rollback = null;
        if (\is_file($active)) {
            $rollback = $this->rollbackPathForTransaction($transactionId);
            if (\is_file($rollback)) {
                throw new \RuntimeException('Managed nginx transaction rollback already exists.');
            }
            if (!@\rename($active, $rollback)) {
                throw new \RuntimeException('Unable to preserve the active managed nginx config.');
            }
        }
        if (!@\rename($candidate, $active)) {
            if ($rollback !== null) {
                @\rename($rollback, $active);
            }
            throw new \RuntimeException('Unable to publish the managed nginx candidate config.');
        }

        return ['conf' => $active, 'rollback' => $rollback];
    }

    public function rollbackPublished(?string $rollback): void
    {
        if ($rollback !== null && !\is_file($rollback)) {
            throw new \RuntimeException(
                'Managed nginx rollback file is missing; refusing to remove the active config.'
            );
        }
        $active = $this->paths->confFile();
        $rejected = null;
        if (\is_file($active)) {
            $rejected = $active . '.rejected.' . (string)\getmypid() . '.' . \bin2hex(\random_bytes(4));
            if (!@\rename($active, $rejected)) {
                throw new \RuntimeException('Unable to preserve the rejected managed nginx config during rollback.');
            }
        }
        if ($rollback !== null && \is_file($rollback) && !@\rename($rollback, $active)) {
            if ($rejected !== null) {
                @\rename($rejected, $active);
            }
            throw new \RuntimeException('Unable to restore the previous managed nginx config.');
        }
        if ($rejected !== null) {
            @\unlink($rejected);
        }
    }

    public function recoverInterruptedPublication(): void
    {
        $active = $this->paths->confFile();
        if (\is_file($active)) {
            return;
        }
        $lastGood = $active . '.last-good';
        if (\is_file($lastGood) && !@\copy($lastGood, $active)) {
            throw new \RuntimeException('Unable to recover the last-known-good managed nginx config.');
        }
    }
    public function rollbackPathForTransaction(string $transactionId): string
    {
        $transactionId = \strtolower(\trim($transactionId));
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $transactionId) !== 1) {
            throw new \InvalidArgumentException('Managed nginx transaction id is invalid.');
        }

        return $this->paths->confFile() . '.rollback.' . $transactionId;
    }


    public function commitPublished(?string $rollback): bool
    {
        if ($rollback === null || !\is_file($rollback)) {
            return true;
        }
        $lastGood = $this->paths->confFile() . '.last-good';
        if (!@\copy($rollback, $lastGood)) {
            return false;
        }
        if (!@\unlink($rollback)) {
            return false;
        }

        return true;
    }

    public function discardCandidate(string $candidate): void
    {
        $this->assertCandidatePath($candidate);
        if (\is_file($candidate)) {
            @\unlink($candidate);
        }
    }

    private function candidatePath(): string
    {
        return $this->paths->confFile()
            . '.candidate.' . (string)\getmypid() . '.' . \bin2hex(\random_bytes(4));
    }

    private function assertCandidatePath(string $candidate): void
    {
        $prefix = $this->paths->confFile() . '.candidate.';
        if (\dirname($candidate) !== \dirname($this->paths->confFile())
            || !\str_starts_with($candidate, $prefix)
        ) {
            throw new \InvalidArgumentException('Managed nginx candidate path is outside the isolated config scope.');
        }
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
        if ($normalized === []) {
            throw new \InvalidArgumentException('Managed nginx requires at least one upstream port.');
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
        if ($labels === []) {
            return false;
        }
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
        $certSha = \hash_file('sha256', $cert);
        $keySha = \hash_file('sha256', $key);
        if (!\is_string($certSha) || !\is_string($keySha)) {
            throw new \RuntimeException('Unable to hash managed nginx TLS material.');
        }
        $directory = $this->paths->confDir() . DIRECTORY_SEPARATOR . 'certs';
        if (!\is_dir($directory) && !@\mkdir($directory, 0700, true) && !\is_dir($directory)) {
            throw new \RuntimeException('Unable to create local managed nginx certificate directory.');
        }
        $identity = \substr(\hash('sha256', $certSha . "\0" . $keySha), 0, 32);
        $localCert = $directory . DIRECTORY_SEPARATOR . $identity . '-fullchain.pem';
        $localKey = $directory . DIRECTORY_SEPARATOR . $identity . '-privkey.pem';
        $this->stageContentAddressedFile($cert, $localCert, $certSha, 0644);
        $this->stageContentAddressedFile($key, $localKey, $keySha, 0600);

        return ['cert' => $localCert, 'key' => $localKey];
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

    private function stageContentAddressedFile(
        string $source,
        string $target,
        string $expectedSha256,
        int $permissions,
    ): void {
        if (\is_file($target)
            && \hash_equals($expectedSha256, (string)@\hash_file('sha256', $target))
        ) {
            return;
        }
        $candidate = $target . '.candidate.' . (string)\getmypid() . '.' . \bin2hex(\random_bytes(4));
        if (!@\copy($source, $candidate)
            || !\hash_equals($expectedSha256, (string)@\hash_file('sha256', $candidate))
        ) {
            @\unlink($candidate);
            throw new \RuntimeException('Unable to stage managed nginx TLS material atomically.');
        }
        @\chmod($candidate, $permissions);
        if (!@\rename($candidate, $target)) {
            @\unlink($candidate);
            if (!\is_file($target)
                || !\hash_equals($expectedSha256, (string)@\hash_file('sha256', $target))
            ) {
                throw new \RuntimeException('Unable to publish managed nginx TLS material atomically.');
            }
        }
    }
}
