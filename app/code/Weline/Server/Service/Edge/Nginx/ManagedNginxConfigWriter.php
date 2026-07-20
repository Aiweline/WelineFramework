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
     * @return array{conf:string,http:int,https:int,upstream:string}
     */
    public function write(int $upstreamPort, string $upstreamHost = '127.0.0.1', array $serverNames = []): array
    {
        $this->paths->ensureRuntimeDirectories();
        $ports = (new ManagedNginxPortAllocator($this->paths))->allocate();
        $names = $this->resolveServerNames($serverNames);
        $ssl = $this->resolveSslMaterial($names);
        $upstream = $upstreamHost . ':' . $upstreamPort;

        $nameList = \implode(' ', $names);
        $isWindows = \PHP_OS_FAMILY === 'Windows';
        // Official Windows nginx builds do not support reuseport; keep listen simple.
        $reuse = $isWindows ? '' : ' reuseport';
        $sslBlock = '';
        if ($ssl !== null) {
            $cert = $this->nginxPath($ssl['cert']);
            $key = $this->nginxPath($ssl['key']);
            $http2Line = $isWindows ? '' : "\n    http2 on;";
            $sslBlock = <<<NGINX

    listen {$ports['https']} ssl{$reuse};{$http2Line}
    ssl_certificate     {$cert};
    ssl_certificate_key {$key};
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_session_cache   shared:WLS_SSL:50m;
    ssl_session_timeout 1d;
    ssl_buffer_size     4k;
NGINX;
        }

        $cacheDir = $this->nginxPath($this->paths->cacheDir());
        $edgeCache = $this->paths->edgeCacheEnabled();
        $ttl = $this->paths->edgeCacheTtlSec();
        $cacheMaxMb = $this->paths->edgeCacheMaxSizeMb();
        $keysZoneMb = $this->paths->edgeCacheKeysZoneMb();
        $gzipOn = $this->paths->gzipEnabled();
        $gzipLevel = $this->paths->gzipCompLevel();
        $upstreamKeepalive = $this->paths->upstreamKeepalive();
        $workerConnections = $this->paths->workerConnections();

        $tempBlock = '';
        if ($isWindows) {
            $tempRoot = $this->nginxPath($this->paths->tempDir());
            $tempBlock = <<<NGINX

    client_body_temp_path {$tempRoot}/client_body_temp;
    proxy_temp_path       {$tempRoot}/proxy_temp;
    fastcgi_temp_path     {$tempRoot}/fastcgi_temp;
    uwsgi_temp_path       {$tempRoot}/uwsgi_temp;
    scgi_temp_path        {$tempRoot}/scgi_temp;
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

    # 匿名 GET 边缘微缓存（有 Cookie 跳过）：热页由 Nginx 直接吐出，避免反复回源拷贝
    proxy_cache_path {$cacheDir} levels=1:2 keys_zone=wls_edge:{$keysZoneMb}m max_size={$cacheMaxMb}m inactive=30m use_temp_path=off;
    map \$http_cookie \$wls_edge_bypass {
        default 1;
        ""      0;
    }
NGINX;
            $cacheLocationBlock = <<<NGINX

            proxy_cache wls_edge;
            proxy_cache_key "\$scheme\$request_method\$host\$request_uri";
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
{$cacheHttpBlock}

    upstream wls_backend {
        server {$upstream};
        keepalive {$upstreamKeepalive};
        keepalive_requests 100000;
        keepalive_timeout 60s;
    }

    server {
        listen {$ports['http']}{$reuse};
{$sslBlock}
        server_name {$nameList};

        location ^~ /.well-known/acme-challenge/ {
            proxy_pass http://wls_backend;
            proxy_http_version 1.1;
            proxy_set_header Connection "";
            proxy_set_header Host \$host;
            proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
            proxy_set_header X-Forwarded-Proto \$scheme;
        }

        # 内部探测不走边缘缓存
        location ^~ /_wls/ {
            proxy_pass http://wls_backend;
            proxy_http_version 1.1;
            proxy_set_header Connection "";
            proxy_set_header Host \$host;
            proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
            proxy_set_header X-Forwarded-Proto \$scheme;
            proxy_set_header X-Real-IP \$remote_addr;
        }

        location / {
            proxy_pass http://wls_backend;
            proxy_http_version 1.1;
            proxy_set_header Connection "";
            proxy_set_header Host \$host;
            proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
            proxy_set_header X-Forwarded-Proto \$scheme;
            proxy_set_header X-Real-IP \$remote_addr;
            proxy_buffering on;
            proxy_buffer_size 64k;
            proxy_buffers 32 64k;
            proxy_busy_buffers_size 128k;
            proxy_max_temp_file_size 0;
{$cacheLocationBlock}
        }
    }
}
NGINX;

        // macOS/FreeBSD: epoll is unavailable — rewrite to kqueue.
        // Windows: events.use already omitted above.
        if (\PHP_OS_FAMILY === 'Darwin' || \PHP_OS_FAMILY === 'BSD') {
            $conf = \str_replace("    use                 epoll;\n", "    use                 kqueue;\n", $conf);
        }

        // mime.types: copy from install tree when present, else minimal stub
        $mimeSrc = $this->paths->installRoot() . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . 'mime.types';
        $mimeDst = $this->paths->confDir() . DIRECTORY_SEPARATOR . 'mime.types';
        if (\is_file($mimeSrc)) {
            @\copy($mimeSrc, $mimeDst);
        } elseif (!\is_file($mimeDst)) {
            \file_put_contents($mimeDst, "types { text/html html htm; text/css css; application/javascript js; }\n");
        }

        $confFile = $this->paths->confFile();
        if (\file_put_contents($confFile, $conf) === false) {
            throw new \RuntimeException('Unable to write managed nginx.conf: ' . $confFile);
        }

        return [
            'conf' => $confFile,
            'http' => $ports['http'],
            'https' => $ports['https'],
            'upstream' => $upstream,
            'ssl' => $ssl !== null,
            'server_names' => $names,
            'edge_cache' => $edgeCache,
            'edge_cache_ttl_sec' => $ttl,
            'edge_cache_max_size_mb' => $cacheMaxMb,
            'gzip' => $gzipOn,
            'upstream_keepalive' => $upstreamKeepalive,
        ];
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
                $merged[$name] = $name;
            }
        }
        if ($merged === []) {
            return ['_'];
        }
        return \array_values($merged);
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
                return ['cert' => $cert, 'key' => $key];
            }
        }
        // localhost fallback for local managed HTTPS listen
        $local = $sslRoot . DIRECTORY_SEPARATOR . 'localhost';
        $cert = $local . DIRECTORY_SEPARATOR . 'fullchain.pem';
        $key = $local . DIRECTORY_SEPARATOR . 'privkey.pem';
        if (\is_file($cert) && \is_file($key)) {
            return ['cert' => $cert, 'key' => $key];
        }
        return null;
    }

    private function nginxPath(string $path): string
    {
        return \str_replace('\\', '/', $path);
    }
}
