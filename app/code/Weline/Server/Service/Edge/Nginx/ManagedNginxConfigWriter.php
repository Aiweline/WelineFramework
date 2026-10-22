<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx;

use Weline\Framework\App\Env;
use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;
use Weline\Server\Service\Edge\Gateway\ProjectCertificateGenerationStore;
use Weline\Server\Service\Edge\Nginx\Runtime\NginxConfigPublication;
use Weline\Server\Service\SslCertificateService;

/**
 * Writes per-project nginx.conf that terminates TLS and proxies to WLS cleartext.
 *
 * Defaults target best-effort edge throughput: upstream keepalive, URL-tree FPC
 * try_files under generated/html/live, static-extension disk try_files (pub →
 * app/code → vendor → BP, then @wls_static_origin), gzip, reuseport, and
 * access_log off. Business HTML is not proxy_cached (disk or @wls origin).
 * Non-HTML surfaces (BinQuery / REST / admin area prefixes / SSE) proxy_pass
 * directly — they never publish index.html.br and must not pay try_files.
 * Nginx-synthesized upstream failure (refused, premature close, timeout) is
 * rewritten to 503; an application status of 502 is left unchanged.
 */
final class ManagedNginxConfigWriter
{
    private const MAX_CONFIG_BYTES = 16 * 1024 * 1024;
    private const MAX_MIME_TYPES_BYTES = 4 * 1024 * 1024;
    private const MAX_TLS_MATERIAL_BYTES = 1024 * 1024;
    private const WRITER_LOCK_WAIT_SECONDS = 30.0;
    private const LEGACY_LIFECYCLE_LOCK_WAIT_SECONDS = 90.0;
    private const FALLBACK_MIME_TYPES = "types { text/html html htm; text/css css; application/javascript js; }\n";
    /** Business HTML/API/static upstream waits. Keep below SSE's dedicated 300s. */
    private const BUSINESS_PROXY_CONNECT_TIMEOUT_SEC = 5;
    private const BUSINESS_PROXY_READ_TIMEOUT_SEC = 90;
    private const BUSINESS_PROXY_SEND_TIMEOUT_SEC = 90;
    /**
     * Edge HTML TTL must be a propagation bound, not the origin FPC policy
     * lifetime. Without a CDN purge token a one-year HTML cache pins stale
     * pages for a year; 300s self-heals after a missed purge.
     */
    private const FPC_HTML_DISK_CDN_MAX_AGE_DEFAULT = 300;
    private const FPC_HTML_DISK_CDN_MAX_AGE_LIMIT = 86400;
    /** Default browser Cache-Control max-age when Env key is unset. */
    private const FPC_HTML_DISK_BROWSER_MAX_AGE_DEFAULT = 60;

    private readonly ManagedNginxPaths $paths;
    private readonly NginxConfigPublication $publication;

    public function __construct(
        ?ManagedNginxPaths $paths = null,
        ?NginxConfigPublication $publication = null,
    ) {
        $this->paths = $paths ?? new ManagedNginxPaths();
        // Publication requires a real, non-linked conf directory. Create runtime
        // layout before constructing the default publication (status/doctor/fromEnv
        // all hit this path before write() can call ensureRuntimeDirectories).
        if ($publication === null) {
            $this->paths->ensureRuntimeDirectories();
        }
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
    /**
     * @param list<string>|null $nginxTrustedProxyCidrs Override for tests; null resolves from security rules + accept_gate
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
        ?array $certificateGeneration = null,
        ?array $edgeCacheState = null,
        bool $realIpSupported = false,
        ?array $nginxTrustedProxyCidrs = null,
    ): array
    {
        $this->paths->ensureRuntimeDirectories();
        $hostCacheState = $edgeCacheState === null
            ? ManagedNginxHostCacheState::read($this->paths->confFile())
            : ManagedNginxHostCacheState::fromArray($edgeCacheState);
        $ports = (new ManagedNginxPortAllocator($this->paths))->allocate();
        $upstreamHost = $this->normalizeLoopbackUpstreamHost($upstreamHost);
        $upstreamPorts = $this->normalizeUpstreamPorts($upstreamPort, $upstreamPorts, $ports);
        $names = $this->resolveServerNames($serverNames);
        [$names, $apexRedirectNames, $wwwCanonicalHost] = $this->peelWwwCanonicalRedirectHosts($names);
        $ssl = $this->resolveSslMaterial(
            \array_values(\array_unique([...$names, ...$apexRedirectNames])),
            $certificateGeneration,
        );
        $sslCertificateSha256 = $ssl !== null
            ? $this->certificateFingerprint($ssl['cert'])
            : null;
        if ($ssl !== null && $sslCertificateSha256 === null) {
            throw new \RuntimeException('Unable to fingerprint managed nginx TLS certificate.');
        }
        // Hosts whose leaf/SAN is not covered by the primary edge certificate
        // (e.g. DNS-only mail.*) must get dedicated server{} blocks. Putting them
        // on the primary server_name list would present the www cert → browser
        // "Not Secure" while host_guard may already allow the Host.
        [$names, $distinctHostSsl] = $this->peelDistinctCertificateHosts($names, $ssl);
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

        // Apex redirect servers must not repeat the primary reuseport listen
        // options — nginx rejects duplicate reuseport for the same address:port.
        $apexRedirectBlock = $this->buildWwwCanonicalRedirectServerBlock(
            $apexRedirectNames,
            $wwwCanonicalHost,
            $ports,
            $ssl,
            $http2Enabled,
            false,
        );
        $distinctHostBlocks = $this->buildDistinctCertificateHostServerBlocks(
            $distinctHostSsl,
            $ports,
            $http2Enabled,
        );
        $distinctHostNames = \array_keys($distinctHostSsl);

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
        $edgeLimit = \class_exists(\Weline\Server\Service\Capacity\HostCapacityTier::class)
            ? \Weline\Server\Service\Capacity\HostCapacityTier::nginxEdgeLimitFragments()
            : [
                'enabled' => false,
                'http_zone_block' => '',
                'location_directives' => '',
            ];
        $edgeLimitHttpZone = (string)($edgeLimit['http_zone_block'] ?? '');
        $edgeLimitLocation = (string)($edgeLimit['location_directives'] ?? '');
        $mimeTypes = $this->stageMimeTypesDependency();
        $quotedMimeTypes = $this->nginxQuotedPath($mimeTypes);
        $realIpHttpBlock = $realIpSupported
            ? $this->buildRealIpHttpBlock(
                $nginxTrustedProxyCidrs ?? $this->resolveNginxTrustedProxyCidrs(),
            )
            : '';

        // Linux + Windows: pin body/proxy temp under temp/ (project-owned).
        // Omitting these on Linux lets nginx create prefix-root client_body_temp
        // as a different uid (historically nobody) → POST query-bin Permission denied.
        $tempRoot = $this->paths->nginxBodyTempRoot();
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

        $cacheHttpBlock = '';
        $staticCacheLocationBlock = '';
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

        // Business HTML: URL-tree disk try_files (no HTML proxy_cache). Static
        // extensions keep a separate edge zone. Bypass disk only for preview /
        // Authorization / Upgrade — Session cookies still HIT anonymous HTML.
        //
        // 注意：nginx 不拼接相邻 token。`root "/a/b"/$var;` 是语法错误
        // （nginx: [emerg] unexpected "/"），必须把变量放进同一对引号内，
        // 即 `root "/a/b/$var";`。故这里直接产出「完整带引号的 root 值」。
        $quotedFpcHtmlRoot = '"' . \str_replace(
            ['\\', '"'],
            ['/', '\\"'],
            $this->paths->projectRoot()
            . DIRECTORY_SEPARATOR . 'generated'
            . DIRECTORY_SEPARATOR . 'html'
            . DIRECTORY_SEPARATOR . 'live'
        ) . '/$wls_fpc_html_host"';
        $maintenanceEdgeDiskBypassSnippet = $this->buildMaintenanceEdgeDiskBypassSnippet();
        $fpcHtmlDiskHitHeaders = $this->buildFpcHtmlDiskHitHeaderBlock();
        try {
            (new FpcPierceNginxMapWriter($this->paths))->publish();
        } catch (\Throwable) {
            // Conf write continues; nginx -t will fail closed if pierce map missing.
        }
        $queryAllowlistWriter = new FpcQueryAllowlistNginxMapWriter($this->paths);
        try {
            $queryAllowlistWriter->publish();
        } catch (\Throwable) {
            // Conf write continues; retry empty map below.
        }
        if (!\is_file($queryAllowlistWriter->mapFilePath())) {
            try {
                $queryAllowlistWriter->publish([
                    'schema_version' => FpcQueryAllowlistNginxMapWriter::SNAPSHOT_SCHEMA,
                    'declarations' => [],
                ]);
            } catch (\Throwable) {
                // nginx -t fails closed if include is still missing.
            }
        }
        try {
            $sitemapEdgeWriter = new SitemapEdgeNginxMapWriter($this->paths);
            if (!\is_file($sitemapEdgeWriter->mapFilePath())
                || !\is_file($sitemapEdgeWriter->locationsFilePath())
            ) {
                $sitemapEdgeWriter->publishDefaults();
            }
        } catch (\Throwable) {
            // Conf write continues; nginx -t will fail closed if sitemap edge map missing.
        }
        $quotedFpcPierceMap = $this->nginxQuotedPath(
            $this->paths->confDir() . DIRECTORY_SEPARATOR . FpcPierceNginxMapWriter::MAP_BASENAME
        );
        $quotedFpcQueryAllowlistMap = $this->nginxQuotedPath(
            $this->paths->confDir() . DIRECTORY_SEPARATOR . FpcQueryAllowlistNginxMapWriter::MAP_BASENAME
        );
        $quotedSitemapEdgeMap = $this->nginxQuotedPath(
            $this->paths->confDir() . DIRECTORY_SEPARATOR . SitemapEdgeNginxMapWriter::MAP_BASENAME
        );
        $quotedSitemapEdgeLocations = $this->nginxQuotedPath(
            $this->paths->confDir() . DIRECTORY_SEPARATOR . SitemapEdgeNginxMapWriter::LOCATIONS_BASENAME
        );
        // Satellite vhosts (multi-project gateway): ops drop files under conf.d/;
        // main conf is regenerated on start/reload — only this include keeps them live.
        $quotedConfDGlob = $this->nginxQuotedPath(
            $this->paths->confDir() . DIRECTORY_SEPARATOR . 'conf.d' . DIRECTORY_SEPARATOR . '*.conf'
        );
        $fpcHtmlDiskMapBlock = <<<NGINX

    include {$quotedFpcPierceMap};
    include {$quotedFpcQueryAllowlistMap};
    include {$quotedSitemapEdgeMap};
    # 静态长缓存只给内容寻址 URL：/static/{12位hex}/... 或主题世代目录 /static/{code}/v{n}/{build}/...。
    map \$uri \$wls_static_versioned {
        default 0;
        "~^/static/[0-9a-f]{12}(?:/|\$)" 1;
        "~^/static/[^/]+/v[0-9]+/[0-9a-f]{12}(?:/|\$)" 1;
        "~^/static/tu-[A-Za-z0-9-]+(?:/|\$)" 1;
        "~*/[0-9a-f]{32,64}\.(?:css|js|mjs|map|json|svg|png|jpe?g|gif|webp|avif|ico|woff2?|ttf|otf|eot|txt|xml|wasm|mp4|webm|mp3|ogg|wav)\$" 1;
    }
    map \$args \$wls_static_arg_versioned {
        default 0;
        "~*(?:^|&)(?:v|weline_asset|_weline_dev)=" 1;
    }
    map "\$wls_static_versioned\$wls_static_arg_versioned" \$wls_static_cache_control {
        default "public, max-age=0, must-revalidate";
        "~1" "public, max-age=31536000, immutable";
    }
    map \$http_authorization \$wls_fpc_html_bypass_auth {
        default 0;
        "~." 1;
    }
    map \$http_upgrade \$wls_fpc_html_bypass_upgrade {
        default 0;
        "~." 1;
    }
    # Product live-preview path may miss edge (token validated on origin).
    map \$request_uri \$wls_fpc_html_bypass_preview_path {
        default 0;
        "~*^/~preview/" 1;
    }
    # Deploy webhook MARKER (~wh~{random}): pierce stamp required (same as diag).
    map \$request_uri \$wls_fpc_html_webhook_path {
        default 0;
        "~*^/~wh~" 1;
    }
    map "\$wls_fpc_pierce_ok\$wls_fpc_html_webhook_path" \$wls_fpc_html_webhook_bypass {
        default 0;
        "11" 1;
    }
    # Diagnostic / editor query bypass only with a valid install pierce stamp.
    # i18n_inline = storefront dictionary point-translate (I18nInlineEditFpcBypassProvider).
    map \$request_uri \$wls_fpc_html_diag_query {
        default 0;
        "~*[?&](preview|visual_editor|editor_mode|workspace_preview|debug_hooks|no_cache|nocache|_wb_nc|wls_tpl_perf|i18n_inline)=" 1;
    }
    map "\$wls_fpc_pierce_ok\$wls_fpc_html_diag_query" \$wls_fpc_html_diag_bypass {
        default 0;
        "11" 1;
    }
    # Cookie session after first jump (query may drop on in-site links).
    map \$http_cookie \$wls_fpc_html_i18n_inline_cookie {
        default 0;
        "~*(?:^|;\\s*)w_i18n_inline_edit(?:_w\\d+)?=" 1;
    }
    map "\$wls_fpc_pierce_ok\$wls_fpc_html_i18n_inline_cookie" \$wls_fpc_html_i18n_inline_cookie_bypass {
        default 0;
        "11" 1;
    }
    # Install pierce alone MUST bypass html-disk (UC-FPC-Pierce); wrong secret → pierce_ok=0.
    # Allowlisted query is NOT OR'd here — it drives \$wls_fpc_html_disk_try instead
    # (noise-only query stays path-only HIT; allowlisted → origin/~q).
    map "\$wls_fpc_pierce_ok\$wls_fpc_html_bypass_auth\$wls_fpc_html_bypass_upgrade\$wls_fpc_html_bypass_preview_path\$wls_fpc_html_webhook_bypass\$wls_fpc_html_diag_bypass\$wls_fpc_html_i18n_inline_cookie_bypass" \$wls_fpc_html_disk_bypass {
        default 0;
        "~1" 1;
    }
    map \$http_host \$wls_fpc_html_host {
        default \$http_host;
        ""      \$host;
    }
    map \$http_accept_encoding \$wls_fpc_accepts_br {
        default 0;
        "~*(^|[, ])br(\$|[;,\\s])" 1;
    }
    # Single gate for html-disk try_files (nginx multi-if is unreliable — last if wins).
    # HTTPS + no disk-bypass + no allowlisted Extra query + accepts br → path-only try_files.
    # Allowlisted query (\$wls_fpc_html_query_allowlist_bypass=1) → 418/origin (~q leaf).
    # Noise-only query keeps allowlist=0 → path-only HIT (UC-FPC-Scan).
    # HTTP → 418 → @wls_html_origin (owns http→https 308).
    map "\$scheme:\$wls_fpc_html_disk_bypass:\$wls_fpc_html_query_allowlist_bypass:\$wls_fpc_accepts_br" \$wls_fpc_html_disk_try {
        default 0;
        "https:0:0:1" 1;
    }
    # Compressible static leaves eligible for precomputed \$uri.br disk HIT.
    # MIME is NOT mapped here: nginx default_type does not expand variables
    # (would emit literal "\$wls_static_br_mime"). Per-extension locations set
    # literal default_type instead.
    map \$uri \$wls_static_br_eligible {
        default 0;
        "~*\\.(?:css|js|mjs|map|svg|json|xml|txt)\$" 1;
    }
NGINX;

        if ($edgeCache) {
            $cacheHttpBlock = <<<NGINX

    # Static-extension edge zone only (CSS/JS/…). Business HTML uses generated/html
    # try_files — do not proxy_cache HTML (avoids dual-path MISS poison).
    proxy_cache_path {$cacheDir} levels=1:2 keys_zone=wls_edge:{$keysZoneMb}m max_size={$cacheMaxMb}m inactive=30m use_temp_path=off;
NGINX;
            if ($this->paths->dynamicEdgeCacheEnabled()) {
                $cacheHttpBlock .= $hostCacheState->nginxMap();
            }
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
        $emitNginxConfigHeader = false;
        try {
            $emitNginxConfigHeader = \Weline\Framework\Http\ResponseObservabilityPolicy::identityHeadersEnabled();
        } catch (\Throwable) {
        }
        // Origin mode (fpm|wls): same identity gate — deploy=dev / verbose only; production lean.
        $originModeHeaderValue = $this->paths->originMode();
        $locationProtocolHeaders = $emitNginxConfigHeader
            ? "\n            add_header X-Wls-Nginx-Config {$configGeneration} always;"
                . "\n            add_header X-Wls-Origin-Mode {$originModeHeaderValue} always;"
            : '';
        $serverNginxConfigHeader = $emitNginxConfigHeader
            ? "        add_header X-Wls-Nginx-Config {$configGeneration} always;\n"
                . "        add_header X-Wls-Origin-Mode {$originModeHeaderValue} always;\n"
            : '';
        if ($http3Configured) {
            $locationProtocolHeaders .= "\n            add_header Alt-Svc \$wls_alt_svc always;";
        }

        $httpsPortSuffix = (int)$ports['https'] === 443 ? '' : ':' . (int)$ports['https'];
        $httpRedirectLocation = $ssl !== null
            ? "\n            if (\$scheme = http) { return 308 https://\$host{$httpsPortSuffix}\$request_uri; }"
            : '';
        $businessProxyConnectTimeoutSec = self::BUSINESS_PROXY_CONNECT_TIMEOUT_SEC;
        $businessProxyReadTimeoutSec = self::BUSINESS_PROXY_READ_TIMEOUT_SEC;
        $businessProxySendTimeoutSec = self::BUSINESS_PROXY_SEND_TIMEOUT_SEC;
        $nonHtmlOriginLocationBlock = $this->buildNonHtmlOriginProxyLocations(
            $httpRedirectLocation,
            $edgeLimitLocation,
            $businessProxyConnectTimeoutSec,
            $businessProxyReadTimeoutSec,
            $businessProxySendTimeoutSec,
            includeSseStream: true,
            upstreamHostHeader: '$wls_upstream_authority',
            emptyUpstreamConnection: false,
        );

        // Static disk layers (priority via ^~ then regex):
        // 1) /pub/media  2) /static → pub/static  3) extension → FPC static disk
        // 4) @wls_static_origin (+ optional proxy_cache). Do NOT try_files app/code.
        $fpmOrigin = $this->paths->isFpmOrigin();
        $quotedProjectRoot = $this->nginxQuotedPath($this->paths->projectRoot());
        $quotedPubRoot = $this->nginxQuotedPath($this->paths->pubRoot());
        // Trailing slash must stay inside quotes: alias "/path/media"/; fails nginx -t.
        $quotedMediaAlias = $this->nginxQuotedPath(\rtrim($this->paths->mediaRoot(), '/\\') . '/');
        $quotedAcmeRoot = $this->nginxQuotedPath($this->paths->acmeChallengeRoot());
        $quotedFastcgiParams = $this->nginxQuotedPath($this->resolveFastcgiParamsPath());
        if ($fpmOrigin) {
            $this->paths->ensureRuntimeDirectories();
        }
        // Literal default_type per extension — nginx does not expand vars in default_type.
        // Cache-Control only: `expires` also emits Cache-Control max-age and would duplicate.
        $staticBrHitHeaders = <<<NGINX
            access_log off;
            add_header Cache-Control \$wls_static_cache_control always;
            add_header X-Wls-Static-Disk "1" always;
            error_page 418 = @wls_static_pub_plain;
            if (\$wls_fpc_accepts_br = 0) {
                return 418;
            }
            try_files \$uri.br =418;
            charset utf-8;
            gzip off;
            gzip_vary off;
            add_header Content-Encoding br always;
            add_header Vary "Accept-Encoding" always;
            add_header X-Weline-Static-Encoding br always;
{$locationProtocolHeaders}
NGINX;
        // alias maps /pub/media/{rel} → {mediaRoot}/{rel}; do not use try_files $uri with alias.
        $mediaDiskLocationBlock = <<<NGINX

        location ^~ /pub/media/ {
            alias {$quotedMediaAlias};
            access_log off;
            add_header Cache-Control "public, max-age=31536000, immutable" always;
            add_header X-Wls-Media-Disk "1" always;
        }
        # Published compile tree: /static/... → {BP}/pub/static/...
        # Nested compressible locations prefer \$uri.br; else plain try_files.
        location ^~ /static/ {
{$httpRedirectLocation}
            root {$quotedPubRoot};
            location ~* \\.css\$ {
{$staticBrHitHeaders}
                default_type text/css;
            }
            location ~* \\.(?:js|mjs)\$ {
{$staticBrHitHeaders}
                default_type application/javascript;
            }
            location ~* \\.map\$ {
{$staticBrHitHeaders}
                default_type application/json;
            }
            location ~* \\.svg\$ {
{$staticBrHitHeaders}
                default_type image/svg+xml;
            }
            location ~* \\.json\$ {
{$staticBrHitHeaders}
                default_type application/json;
            }
            location ~* \\.xml\$ {
{$staticBrHitHeaders}
                default_type application/xml;
            }
            location ~* \\.txt\$ {
{$staticBrHitHeaders}
                default_type text/plain;
            }
            access_log off;
            add_header Cache-Control \$wls_static_cache_control always;
            add_header X-Wls-Static-Disk "1" always;
            try_files \$uri @wls_static_origin;
{$locationProtocolHeaders}
        }
        location @wls_static_pub_plain {
            root {$quotedPubRoot};
            access_log off;
            add_header Cache-Control \$wls_static_cache_control always;
            add_header X-Wls-Static-Disk "1" always;
            try_files \$uri @wls_static_origin;
{$locationProtocolHeaders}
        }
NGINX;

        // Keep in sync with WorkerPolicyKernel::PATH_SCAN_STATIC_EXTENSIONS.
        // FPC static mirror: generated/static/live/{Host}{uri} (WLS fills on cold).
        // Do NOT proxy_cache @wls_static_origin: a cache HIT skips WLS and never
        // remirrors into the FPC disk tree (DEV module-prefix URLs would stick on origin).
        $staticBinaryExt = 'avif|eot|gif|ico|jpe?g|m4a|aac|mp3|mp4|ogg|otf|png|ttf|wasm|wav|webm|webp|woff2?';
        $fpcBrCommon = <<<NGINX
{$httpRedirectLocation}
            root {$quotedProjectRoot};
            open_file_cache_errors off;
            add_header Cache-Control "public, max-age=31536000, immutable" always;
            add_header X-Wls-Static-Disk "1" always;
            add_header X-Wls-Static-Fpc "1" always;
            access_log off;
            error_page 418 = @wls_static_fpc_plain;
            error_page 419 = @wls_static_origin;
            # DEV/acceptance cache-bust: never serve stale live FPC; WLS remirrors on origin.
            if (\$arg__wb_nc) {
                return 419;
            }
            if (\$arg__weline_dev) {
                return 419;
            }
            if (\$wls_fpc_accepts_br = 0) {
                return 418;
            }
            try_files /generated/static/live/\$wls_fpc_html_host\$uri.br =418;
            charset utf-8;
            gzip off;
            gzip_vary off;
            add_header Content-Encoding br always;
            add_header Vary "Accept-Encoding" always;
            add_header X-Weline-Static-Encoding br always;
{$locationProtocolHeaders}
NGINX;
        $staticCacheLocationBlock = <<<NGINX

        # Module / DEV statics: FPC disk by Host+path, then WLS (which mirrors).
        # Compressible: br then plain (literal default_type). Binary: plain only.
        location ~* \\.css\$ {
{$fpcBrCommon}
            default_type text/css;
        }
        location ~* \\.(?:js|mjs)\$ {
{$fpcBrCommon}
            default_type application/javascript;
        }
        location ~* \\.map\$ {
{$fpcBrCommon}
            default_type application/json;
        }
        location ~* \\.svg\$ {
{$fpcBrCommon}
            default_type image/svg+xml;
        }
        location ~* \\.json\$ {
{$fpcBrCommon}
            default_type application/json;
        }
        location ~* \\.xml\$ {
{$fpcBrCommon}
            default_type application/xml;
        }
        location ~* \\.txt\$ {
{$fpcBrCommon}
            default_type text/plain;
        }
        location ~* \\.(?:{$staticBinaryExt})\$ {
{$httpRedirectLocation}
            root {$quotedProjectRoot};
            open_file_cache_errors off;
            error_page 419 = @wls_static_origin;
            if (\$arg__wb_nc) {
                return 419;
            }
            if (\$arg__weline_dev) {
                return 419;
            }
            try_files /generated/static/live/\$wls_fpc_html_host\$uri @wls_static_origin;
            add_header Cache-Control "public, max-age=31536000, immutable" always;
            add_header X-Wls-Static-Disk "1" always;
            add_header X-Wls-Static-Fpc "1" always;
            access_log off;
{$locationProtocolHeaders}
        }
        location @wls_static_fpc_plain {
            root {$quotedProjectRoot};
            open_file_cache_errors off;
            add_header Cache-Control "public, max-age=31536000, immutable" always;
            add_header X-Wls-Static-Disk "1" always;
            add_header X-Wls-Static-Fpc "1" always;
            access_log off;
            try_files /generated/static/live/\$wls_fpc_html_host\$uri @wls_static_origin;
{$locationProtocolHeaders}
        }
        location @wls_static_origin {
            error_page 502 504 =503 @wls_origin_unavailable;
            proxy_pass http://wls_backend;
            proxy_http_version 1.1;
            proxy_set_header Upgrade \$wls_business_upstream_upgrade;
            proxy_set_header Connection \$wls_business_upstream_connection;
            proxy_set_header Host \$wls_upstream_authority;
            proxy_set_header X-Forwarded-Port \$server_port;
            proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
            proxy_set_header CF-Connecting-IP \$http_cf_connecting_ip;
            proxy_set_header X-Forwarded-Proto \$scheme;
            proxy_set_header X-Real-IP \$remote_addr;
            proxy_set_header Cookie "";
            proxy_set_header Authorization "";
            proxy_connect_timeout {$businessProxyConnectTimeoutSec}s;
            proxy_read_timeout {$businessProxyReadTimeoutSec}s;
            proxy_send_timeout {$businessProxySendTimeoutSec}s;
            proxy_buffering on;
            proxy_buffer_size 64k;
            proxy_buffers 32 64k;
            proxy_busy_buffers_size 128k;
            proxy_max_temp_file_size 0;
            proxy_cache off;
{$locationProtocolHeaders}
        }
NGINX;

        $workerProcesses = $isWindows ? '1' : 'auto';
        // Windows nginx ignores/limits high rlimit; keep a conservative value.
        $rlimit = $isWindows ? \min(8192, $workerConnections) : $workerConnections;
        $eventsExtra = "    multi_accept        on;\n    use                 epoll;\n";
        if ($isWindows) {
            $eventsExtra = "    multi_accept        on;\n";
        }

        $upstreamBlock = $fpmOrigin
            ? $this->buildFpmUpstreamBlock()
            : $this->buildWlsUpstreamBlock($upstreamServers, $upstreamKeepalive, $upstreamKeepaliveTimeoutSec);
        $acmeChallengeLocationBlock = $this->buildAcmeChallengeLocation($fpmOrigin, $quotedAcmeRoot);
        $internalProbeLocationBlock = $this->buildInternalProbeLocation($fpmOrigin, $configGeneration);
        [$originUnavailableServerDirectives, $originUnavailableLocation] = $this->buildOriginUnavailableEdgeFragments();

        $conf = <<<NGINX
worker_processes  {$workerProcesses};
worker_rlimit_nofile  {$rlimit};
worker_shutdown_timeout 300s;
error_log  logs/error.log  warn;
pid        run/nginx.pid;

events {
    worker_connections  {$workerConnections};
{$eventsExtra}}

http {
    map_hash_bucket_size 128;
    map_hash_max_size 4096;
    include       {$quotedMimeTypes};
    default_type  application/octet-stream;
    access_log    off;
    sendfile      on;
    server_tokens off;
    # Default nginx is 1m — that rejects MediaManager / StoreMusic uploads (UI allows up to 512M)
    # while the picker still shows「允许的文件大小：20 MB」. Align with WLS + MediaAssetUploadService.
    client_max_body_size 512m;
{$realIpHttpBlock}    tcp_nopush    on;
    tcp_nodelay   on;
    keepalive_timeout  65;
    keepalive_requests 100000;
    reset_timedout_connection on;
    open_file_cache max=20000 inactive=60s;
    open_file_cache_valid 30s;
    open_file_cache_min_uses 2;
    open_file_cache_errors on;
{$tempBlock}{$gzipBlock}
{$cacheHttpBlock}{$fpcHtmlDiskMapBlock}{$authorityMapBlock}{$protocolMapBlock}
{$edgeLimitHttpZone}
{$upstreamBlock}
    server {
        listen {$ports['http']}{$tcpReuse};
{$sslBlock}
        server_name {$nameList};
{$serverNginxConfigHeader}
{$serverProtocolHeaders}
{$originUnavailableServerDirectives}
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

{$acmeChallengeLocationBlock}
{$internalProbeLocationBlock}
{$nonHtmlOriginLocationBlock}
        include {$quotedSitemapEdgeLocations};
{$mediaDiskLocationBlock}
{$staticCacheLocationBlock}
        # FPC URL tree: generated/html/live/{Host}/…/index.html.br. Miss / bypass / no-br → @wls_html_origin.
        # Non-HTML (/api/, /bin/query, area prefixes) already proxy above — never try_files.
        # Non-empty query / pierce / no-br / http → origin. One if only (no scheme if here).
        location / {
            error_page 418 = @wls_html_origin;
{$maintenanceEdgeDiskBypassSnippet}            if (\$wls_fpc_html_disk_try = 0) {
                return 418;
            }
            root {$quotedFpcHtmlRoot};
            try_files \$uri/index.html.br =418;
            default_type text/html;
            charset utf-8;
{$fpcHtmlDiskHitHeaders}{$locationProtocolHeaders}
        }
        location @wls_html_origin {
            # Avoid stacking gzip_vary Accept-Encoding on upstream's merged Vary.
            gzip_vary off;
            error_page 502 504 =503 @wls_origin_unavailable;
{$edgeLimitLocation}            proxy_pass http://wls_backend;
            proxy_http_version 1.1;
{$httpRedirectLocation}
            proxy_set_header Upgrade \$wls_business_upstream_upgrade;
            proxy_set_header Connection \$wls_business_upstream_connection;
            proxy_set_header Host \$wls_upstream_authority;
            proxy_set_header X-Forwarded-Port \$server_port;
            proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
            proxy_set_header CF-Connecting-IP \$http_cf_connecting_ip;
            proxy_set_header X-Forwarded-Proto \$scheme;
            proxy_set_header X-Real-IP \$remote_addr;
            proxy_set_header X-Wls-Fpc-Bypass "";
            proxy_set_header X-Wls-Internal-Fpc-Bypass "";
            proxy_set_header X-Wls-Dynamic-Warmup "";
            proxy_set_header X-Wls-Internal-Dynamic-Warmup "";
            proxy_set_header X-Wls-Dynamic-Benchmark "";
            proxy_set_header X-Wls-Fpc-Prime "";
            proxy_set_header Wls-Fpc-Bypass "";
            proxy_set_header Wls-Internal-Dynamic-Warmup "";
            proxy_connect_timeout {$businessProxyConnectTimeoutSec}s;
            proxy_read_timeout {$businessProxyReadTimeoutSec}s;
            proxy_send_timeout {$businessProxySendTimeoutSec}s;
            proxy_buffering on;
            proxy_buffer_size 64k;
            proxy_buffers 32 64k;
            proxy_busy_buffers_size 128k;
            proxy_max_temp_file_size 0;
            proxy_cache off;
{$locationProtocolHeaders}
        }
{$originUnavailableLocation}
    }
{$apexRedirectBlock}{$distinctHostBlocks}
    include {$quotedConfDGlob};
}
NGINX;

        // macOS/FreeBSD: epoll is unavailable — rewrite to kqueue.
        // Windows: events.use already omitted above.
        if (\PHP_OS_FAMILY === 'Darwin' || \PHP_OS_FAMILY === 'BSD') {
            $conf = \str_replace("    use                 epoll;\n", "    use                 kqueue;\n", $conf);
        }

        if ($fpmOrigin) {
            $conf = $this->rewriteGeneratedConfForFpmOrigin(
                $conf,
                $quotedPubRoot,
                $quotedFastcgiParams,
                $quotedAcmeRoot,
                $configGeneration,
                $edgeLimitLocation,
                $businessProxyConnectTimeoutSec,
                $businessProxyReadTimeoutSec,
                $businessProxySendTimeoutSec,
                $locationProtocolHeaders,
            );
        }

        $conf = $hostCacheState->configComment() . $conf;
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
            'certificate_generation_managed'
                => (bool)($ssl['generation_managed'] ?? false),
            'certificate_domain' => (string)($ssl['domain'] ?? ''),
            'certificate_generation' => (int)($ssl['generation'] ?? 0),
            'certificate_source_digest' => (string)($ssl['source_digest'] ?? ''),
            'certificate_cert_sha256' => (string)($ssl['cert_sha256'] ?? ''),
            'certificate_key_sha256' => (string)($ssl['key_sha256'] ?? ''),
            'certificate_chain_sha256' => (string)($ssl['chain_sha256'] ?? ''),
            'server_names' => \array_values(\array_unique([...$names, ...$apexRedirectNames, ...$distinctHostNames])),
            'www_canonical_host' => $wwwCanonicalHost,
            'apex_redirect_names' => $apexRedirectNames,
            'distinct_certificate_hosts' => $distinctHostNames,
            'edge_cache' => $edgeCache,
            'edge_cache_dynamic' => $this->paths->dynamicEdgeCacheEnabled(),
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
        $oldGenerations = \array_values(\array_unique($matches[1] ?? []));
        // Publication contract: every X-Wls-Nginx-Config marker shares one
        // generation. Location count is not fixed (static edge location, identity
        // headers, probe paths), so replace all occurrences of that generation.
        if ($markerCount < 1 || \count($oldGenerations) !== 1) {
            throw new \RuntimeException('Managed nginx.conf lacks one consistent WLS config generation.');
        }
        $candidateContents = \str_replace(
            'add_header X-Wls-Nginx-Config ' . $oldGenerations[0] . ' always;',
            'add_header X-Wls-Nginx-Config ' . $generation . ' always;',
            $contents,
            $count,
        );
        if ($count !== $markerCount) {
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
     * Peel hosts that need a different leaf certificate than the primary edge
     * cert into a dedicated map for additional server{} blocks.
     *
     * @param list<string> $names
     * @param array{cert:string,key:string}|null $primarySsl
     * @return array{0:list<string>,1:array<string,array{cert:string,key:string}>}
     */
    private function peelDistinctCertificateHosts(array $names, ?array $primarySsl): array
    {
        if ($primarySsl === null || $names === []) {
            return [$names, []];
        }
        $primaryCert = (string)($primarySsl['cert'] ?? '');
        if ($primaryCert === '' || !\is_file($primaryCert)) {
            return [$names, []];
        }
        $kept = [];
        $distinct = [];
        foreach ($names as $name) {
            $host = \strtolower(\trim((string)$name));
            if ($host === '' || $host === '_') {
                $kept[] = $host;
                continue;
            }
            if ($this->certificateCoversHostname($primaryCert, $host)) {
                $kept[] = $host;
                continue;
            }
            $material = $this->resolveHostSslMaterial($host);
            if ($material === null) {
                // No exact material — keep on primary (legacy behaviour) so the
                // host is still routed; TLS may still mismatch until issued.
                $kept[] = $host;
                continue;
            }
            $distinct[$host] = $material;
        }

        return [\array_values($kept), $distinct];
    }

    /**
     * @return array{cert:string,key:string}|null
     */
    private function resolveHostSslMaterial(string $hostname): ?array
    {
        $hostname = \strtolower(\trim($hostname));
        if ($hostname === '' || !$this->isSafeServerName($hostname)) {
            return null;
        }
        try {
            $active = (new ProjectCertificateGenerationStore($this->paths->projectRoot()))
                ->active($hostname);
            if (\is_array($active)
                && \is_string($active['cert_path'] ?? null)
                && \is_string($active['key_path'] ?? null)
                && \is_file((string)$active['cert_path'])
                && \is_file((string)$active['key_path'])
            ) {
                return $this->localizeSslMaterial(
                    (string)$active['cert_path'],
                    (string)$active['key_path'],
                );
            }
        } catch (\Throwable) {
            // Fall through to legacy app/etc/ssl/{host}/.
        }
        $segment = SslCertificateService::certificateStorageSegmentForFilesystem($hostname);
        $dir = $this->paths->projectRoot() . DIRECTORY_SEPARATOR . 'app'
            . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'ssl'
            . DIRECTORY_SEPARATOR . $segment;
        $cert = $dir . DIRECTORY_SEPARATOR . 'fullchain.pem';
        $key = $dir . DIRECTORY_SEPARATOR . 'privkey.pem';
        if (!\is_file($cert) || !\is_file($key)) {
            return null;
        }
        if (!$this->certificateCoversHostname($cert, $hostname)) {
            return null;
        }

        return $this->localizeSslMaterial($cert, $key);
    }

    private function certificateCoversHostname(string $certPath, string $hostname): bool
    {
        $hostname = \strtolower(\trim($hostname));
        if ($hostname === '' || !\is_file($certPath)) {
            return false;
        }
        $raw = @\file_get_contents($certPath);
        if (!\is_string($raw) || $raw === '') {
            return false;
        }
        $parsed = @\openssl_x509_parse($raw);
        if (!\is_array($parsed)) {
            return false;
        }
        $candidates = [];
        $cn = \strtolower(\trim((string)($parsed['subject']['CN'] ?? '')));
        if ($cn !== '') {
            $candidates[] = $cn;
        }
        $san = (string)($parsed['extensions']['subjectAltName'] ?? '');
        if ($san !== '') {
            foreach (\explode(',', $san) as $part) {
                $part = \trim($part);
                if (\stripos($part, 'DNS:') === 0) {
                    $candidates[] = \strtolower(\trim(\substr($part, 4)));
                }
            }
        }
        foreach ($candidates as $name) {
            if ($name === $hostname) {
                return true;
            }
            if (\str_starts_with($name, '*.')) {
                $suffix = \substr($name, 1); // ".example.com"
                if ($suffix !== ''
                    && \str_ends_with($hostname, $suffix)
                    && !\str_contains(\substr($hostname, 0, -\strlen($suffix)), '.')
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string,array{cert:string,key:string}> $hostSsl
     * @param array{http:int,https:int} $ports
     */
    private function buildDistinctCertificateHostServerBlocks(
        array $hostSsl,
        array $ports,
        bool $http2Enabled,
    ): string {
        if ($hostSsl === []) {
            return '';
        }
        $edgeLimitLocation = '';
        if (\class_exists(\Weline\Server\Service\Capacity\HostCapacityTier::class)) {
            $edgeLimitLocation = (string)(\Weline\Server\Service\Capacity\HostCapacityTier::nginxEdgeLimitFragments()['location_directives'] ?? '');
        }
        $blocks = [];
        foreach ($hostSsl as $host => $ssl) {
            $host = \strtolower(\trim((string)$host));
            if ($host === '' || !$this->isSafeServerName($host)) {
                continue;
            }
            $cert = $this->nginxQuotedPath((string)$ssl['cert']);
            $key = $this->nginxQuotedPath((string)$ssl['key']);
            $http2Line = $http2Enabled ? "\n        http2 on;" : '';
            $connectTimeout = self::BUSINESS_PROXY_CONNECT_TIMEOUT_SEC;
            $readTimeout = self::BUSINESS_PROXY_READ_TIMEOUT_SEC;
            $sendTimeout = self::BUSINESS_PROXY_SEND_TIMEOUT_SEC;
            // 同上方：变量必须与路径同处一对引号内，否则 nginx 报 unexpected "/"。
            $quotedFpcHtmlRoot = '"' . \str_replace(
                ['\\', '"'],
                ['/', '\\"'],
                $this->paths->projectRoot()
                . DIRECTORY_SEPARATOR . 'generated'
                . DIRECTORY_SEPARATOR . 'html'
                . DIRECTORY_SEPARATOR . 'live'
            ) . '/$wls_fpc_html_host"';
            $maintenanceEdgeDiskBypassSnippet = $this->buildMaintenanceEdgeDiskBypassSnippet();
            $fpcHtmlDiskHitHeaders = $this->buildFpcHtmlDiskHitHeaderBlock();
            $originName = $this->nginxSafeNamedLocationSuffix($host);
            $nonHtmlOriginLocationBlock = $this->buildNonHtmlOriginProxyLocations(
                '',
                $edgeLimitLocation,
                $connectTimeout,
                $readTimeout,
                $sendTimeout,
                includeSseStream: true,
                upstreamHostHeader: '$wls_upstream_authority',
                emptyUpstreamConnection: false,
            );
            [$originUnavailableServerDirectives, $originUnavailableLocation] = $this->buildOriginUnavailableEdgeFragments();
            $blocks[] = <<<NGINX

    # WLS distinct-certificate host (SNI exact leaf for {$host})
    server {
        listen {$ports['http']};
        listen {$ports['https']} ssl;{$http2Line}
        ssl_certificate     {$cert};
        ssl_certificate_key {$key};
        ssl_protocols       TLSv1.3;
        ssl_session_cache   shared:WLS_SSL:50m;
        ssl_session_timeout 1d;
        ssl_early_data      off;
        ssl_session_tickets on;
        ssl_buffer_size     4k;
        server_name {$host};
{$originUnavailableServerDirectives}
        location ^~ /.well-known/acme-challenge/ {
            proxy_pass http://wls_backend;
            proxy_http_version 1.1;
            proxy_set_header Connection "";
            proxy_set_header Host \$wls_upstream_authority;
            proxy_set_header X-Forwarded-Port \$server_port;
            proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
            proxy_set_header CF-Connecting-IP \$http_cf_connecting_ip;
            proxy_set_header X-Forwarded-Proto \$scheme;
        }
{$nonHtmlOriginLocationBlock}
        location / {
            error_page 418 = @wls_html_origin_distinct_{$originName};
{$maintenanceEdgeDiskBypassSnippet}            if (\$wls_fpc_html_disk_try = 0) {
                return 418;
            }
            root {$quotedFpcHtmlRoot};
            try_files \$uri/index.html.br =418;
            default_type text/html;
            charset utf-8;
{$fpcHtmlDiskHitHeaders}
        }
        location @wls_html_origin_distinct_{$originName} {
            gzip_vary off;
            error_page 502 504 =503 @wls_origin_unavailable;
{$edgeLimitLocation}            proxy_pass http://wls_backend;
            proxy_http_version 1.1;
            proxy_set_header Upgrade \$wls_business_upstream_upgrade;
            proxy_set_header Connection \$wls_business_upstream_connection;
            proxy_set_header Host \$wls_upstream_authority;
            proxy_set_header X-Forwarded-Port \$server_port;
            proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
            proxy_set_header CF-Connecting-IP \$http_cf_connecting_ip;
            proxy_set_header X-Forwarded-Proto \$scheme;
            proxy_set_header X-Real-IP \$remote_addr;
            proxy_connect_timeout {$connectTimeout}s;
            proxy_read_timeout {$readTimeout}s;
            proxy_send_timeout {$sendTimeout}s;
            proxy_buffering on;
            proxy_cache off;
        }
{$originUnavailableLocation}
    }
NGINX;
        }

        return \implode('', $blocks);
    }

    /**
     * When public_host is www.{apex} and {apex} is also in server_names, peel the
     * apex into a dedicated 301→www server so the primary host stays www-only.
     *
     * @param list<string> $names
     * @return array{0:list<string>,1:list<string>,2:string}
     */
    private function peelWwwCanonicalRedirectHosts(array $names): array
    {
        $publicHost = $this->resolvePublicHost();
        if ($publicHost === ''
            || !\str_starts_with($publicHost, 'www.')
            || !$this->isSafeServerName($publicHost)
        ) {
            return [$names, [], ''];
        }
        $apex = \substr($publicHost, 4);
        if ($apex === ''
            || !$this->isSafeServerName($apex)
            || !\in_array($apex, $names, true)
            || !\in_array($publicHost, $names, true)
        ) {
            return [$names, [], ''];
        }
        $primary = [];
        foreach ($names as $name) {
            if ($name !== $apex) {
                $primary[] = $name;
            }
        }
        if ($primary === []) {
            return [$names, [], ''];
        }

        return [$primary, [$apex], $publicHost];
    }

    private function resolvePublicHost(): string
    {
        $cfgHost = \strtolower(\trim((string)($this->paths->config()['public_host'] ?? '')));
        if ($cfgHost !== '' && $this->isSafeServerName($cfgHost)) {
            return $cfgHost;
        }
        try {
            $env = Env::getInstance()->getConfig();
            $host = \strtolower(\trim((string)(
                (\is_array($env) ? ($env['wls']['public_host'] ?? '') : '')
            )));
            if ($host !== '' && $this->isSafeServerName($host)) {
                return $host;
            }
        } catch (\Throwable) {
        }

        return '';
    }

    /**
     * @param list<string> $apexNames
     * @param array{http:int,https:int} $ports
     * @param array{cert:string,key:string}|null $ssl
     */
    private function buildWwwCanonicalRedirectServerBlock(
        array $apexNames,
        string $wwwHost,
        array $ports,
        ?array $ssl,
        bool $http2Enabled,
        bool $reuseport,
    ): string {
        if ($apexNames === [] || $wwwHost === '') {
            return '';
        }
        $nameList = \implode(' ', $apexNames);
        $reuse = $reuseport ? ' reuseport' : '';
        $sslListen = '';
        if ($ssl !== null) {
            $cert = $this->nginxQuotedPath($ssl['cert']);
            $key = $this->nginxQuotedPath($ssl['key']);
            $http2Line = $http2Enabled ? "\n        http2 on;" : '';
            $sslListen = <<<NGINX

        listen {$ports['https']} ssl{$reuse};{$http2Line}
        ssl_certificate     {$cert};
        ssl_certificate_key {$key};
        ssl_protocols       TLSv1.3;
        ssl_session_cache   shared:WLS_SSL:50m;
        ssl_session_timeout 1d;
        ssl_early_data      off;
        ssl_session_tickets on;
        ssl_buffer_size     4k;
NGINX;
        }
        // Keep same-origin query-bin / REST / BinQuery on apex workable:
        // redirect:manual workers cannot follow the www 301 (opaque → WQB1 toast).
        $apexNonHtmlOriginLocationBlock = $this->buildNonHtmlOriginProxyLocations(
            '',
            '',
            self::BUSINESS_PROXY_CONNECT_TIMEOUT_SEC,
            self::BUSINESS_PROXY_READ_TIMEOUT_SEC,
            self::BUSINESS_PROXY_SEND_TIMEOUT_SEC,
            includeSseStream: true,
            upstreamHostHeader: '$host',
            emptyUpstreamConnection: true,
        );
        [$originUnavailableServerDirectives, $originUnavailableLocation] = $this->buildOriginUnavailableEdgeFragments();

        return <<<NGINX

    # WLS apex->www canonical redirect (primary host = {$wwwHost})
    server {
        listen {$ports['http']}{$reuse};{$sslListen}
        server_name {$nameList};
{$originUnavailableServerDirectives}
        location ^~ /.well-known/acme-challenge/ {
            proxy_pass http://wls_backend;
            proxy_http_version 1.1;
            proxy_set_header Connection "";
            proxy_set_header Host \$host;
            proxy_set_header X-Forwarded-Port \$server_port;
            proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
            proxy_set_header CF-Connecting-IP \$http_cf_connecting_ip;
            proxy_set_header X-Forwarded-Proto \$scheme;
        }
{$apexNonHtmlOriginLocationBlock}
        location / {
            add_header Cache-Control "no-store" always;
            return 301 https://{$wwwHost}\$request_uri;
        }
{$originUnavailableLocation}
    }
NGINX;
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
     * @param array<string,mixed>|null $certificateGeneration
     * @return array<string,mixed>|null
     */
    private function resolveSslMaterial(
        array $serverNames,
        ?array $certificateGeneration = null,
    ): ?array
    {
        if ($certificateGeneration !== null) {
            return $this->resolveManagedCertificateGeneration(
                $serverNames,
                $certificateGeneration,
            );
        }
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

    /**
     * Bind WLS 2.0 managed Nginx to the same immutable certificate selector
     * already activated by Start. Mutable app/etc/ssl source paths remain a
     * legacy-only compatibility path when no generation is supplied.
     *
     * @param list<string> $serverNames
     * @param array<string,mixed> $expected
     * @return array<string,mixed>
     */
    private function resolveManagedCertificateGeneration(
        array $serverNames,
        array $expected,
    ): array {
        $domain = \strtolower(\trim((string)($expected['domain'] ?? '')));
        $generation = (int)($expected['generation'] ?? 0);
        $sourceDigest = \strtolower(\trim((string)(
            $expected['source_digest'] ?? ''
        )));
        if ($domain === ''
            || $generation < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $sourceDigest) !== 1
            || !\in_array($domain, $serverNames, true)
        ) {
            throw new \RuntimeException(
                'Managed Nginx certificate generation identity is incomplete or outside server_name.'
            );
        }
        $active = (new ProjectCertificateGenerationStore($this->paths->projectRoot()))
            ->active($domain);
        if (!\is_array($active)) {
            throw new \RuntimeException(
                'Managed Nginx certificate generation is not active for its domain.'
            );
        }
        if ((int)($active['generation'] ?? 0) !== $generation
            || !\hash_equals(
                $sourceDigest,
                \strtolower((string)($active['source_digest'] ?? '')),
            )
        ) {
            throw new \RuntimeException(
                'Managed Nginx certificate generation changed before config rendering.'
            );
        }
        foreach ([
            'cert_path',
            'key_path',
            'chain_path',
            'leaf_fingerprint_sha256',
            'cert_sha256',
            'key_sha256',
            'chain_sha256',
        ] as $field) {
            if (!\array_key_exists($field, $expected)
                || !\is_string($expected[$field])
                || !\hash_equals(
                    (string)$expected[$field],
                    (string)($active[$field] ?? ''),
                )
            ) {
                throw new \RuntimeException(
                    'Managed Nginx certificate generation after-image changed at ' . $field . '.'
                );
            }
        }
        $localized = $this->localizeSslMaterial(
            (string)$active['cert_path'],
            (string)$active['key_path'],
        );
        $fingerprint = $this->certificateFingerprint($localized['cert']);
        if (!\is_string($fingerprint)
            || !\hash_equals(
                \strtolower((string)$active['leaf_fingerprint_sha256']),
                \strtolower($fingerprint),
            )
        ) {
            throw new \RuntimeException(
                'Managed Nginx immutable certificate fingerprint changed while rendering.'
            );
        }
        return [
            ...$localized,
            'generation_managed' => true,
            'domain' => $domain,
            'generation' => $generation,
            'source_digest' => $sourceDigest,
            'cert_sha256' => (string)$active['cert_sha256'],
            'key_sha256' => (string)$active['key_sha256'],
            'chain_sha256' => (string)$active['chain_sha256'],
        ];
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

    private function nginxSafeNamedLocationSuffix(string $host): string
    {
        $safe = \preg_replace('/[^a-zA-Z0-9_]+/', '_', \strtolower(\trim($host)));
        $safe = \is_string($safe) ? \trim($safe, '_') : '';

        return $safe !== '' ? $safe : 'host';
    }

    /**
     * Path prefixes that never publish FPC HTML disk trees. Requests must
     * proxy_pass to WLS without try_files $uri/index.html.br.
     *
     * @return list<string> URL path segments without leading/trailing slash
     */
    private function resolveNonHtmlOriginPathPrefixes(): array
    {
        $prefixes = [];
        foreach (['rest_frontend', 'rest_backend', 'backend'] as $area) {
            try {
                $prefix = \trim((string)(Env::getAreaRoutePrefix($area) ?: ''), '/');
            } catch (\Throwable) {
                $prefix = '';
            }
            if ($prefix === '' && $area === 'rest_frontend') {
                $prefix = 'api';
            }
            if ($prefix !== '' && $this->isSafeNginxLocationSegment($prefix)) {
                $prefixes[\strtolower($prefix)] = \strtolower($prefix);
            }
        }
        // Semantic /api/ stays even when rest_frontend is customized (Env keeps
        // bare "api" as a reserved area path segment for query-bin / REST).
        if ($this->isSafeNginxLocationSegment('api')) {
            $prefixes['api'] = 'api';
        }

        return \array_values($prefixes);
    }

    private function isSafeNginxLocationSegment(string $segment): bool
    {
        return $segment !== ''
            && \preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $segment) === 1;
    }

    /**
     * When Maintenance writes var/maintenance/edge.on, skip storefront html-disk
     * HIT and 418 → origin so FPM/WLS both serve pub/errors/maintenance pages.
     * Path must stay aligned with Weline\Maintenance\Helper\MaintenanceEdgeFlag.
     */
    private function buildMaintenanceEdgeDiskBypassSnippet(): string
    {
        $flagPath = $this->paths->projectRoot()
            . DIRECTORY_SEPARATOR . 'var'
            . DIRECTORY_SEPARATOR . 'maintenance'
            . DIRECTORY_SEPARATOR . 'edge.on';
        $quoted = '"' . \str_replace(['\\', '"'], ['/', '\\"'], $flagPath) . '"';

        return <<<NGINX
            # Framework maintenance edge flag → origin (same static pages as WLS).
            if (-f {$quoted}) {
                return 418;
            }

NGINX;
    }

    /**
     * Outbound headers for Nginx html-disk FPC HIT (try_files index.html.br).
     *
     * Aligns with Framework FullPageCacheCoordinator::applyFpcHitEdgeCacheHeaders
     * so Cloudflare bypass_by_default can store the response. Disk is body-only;
     * these are static edge-policy headers, not a replayed header bag.
     *
     * gzip off + gzip_vary off (location): disk body is already brotli (.html.br).
     * http-level `gzip on` would wrap those bytes again and emit a second
     * Content-Encoding: gzip beside add_header Content-Encoding br — browsers then
     * show mojibake. Same trap as static $uri.br locations. gzip_vary off also
     * prevents a second Vary: Accept-Encoding beside Vary "Accept-Encoding, Cookie".
     */
    private function buildFpcHtmlDiskHitHeaderBlock(): string
    {
        $edgeTtl = self::FPC_HTML_DISK_CDN_MAX_AGE_DEFAULT;
        try {
            $configuredEdgeTtl = Env::get(
                'wls.performance.fpc_edge_max_age_seconds',
                self::FPC_HTML_DISK_CDN_MAX_AGE_DEFAULT,
            );
            if (\is_numeric($configuredEdgeTtl)) {
                $edgeTtl = (int)$configuredEdgeTtl;
            }
        } catch (\Throwable) {
            $edgeTtl = self::FPC_HTML_DISK_CDN_MAX_AGE_DEFAULT;
        }
        $edgeTtl = max(0, min($edgeTtl, self::FPC_HTML_DISK_CDN_MAX_AGE_LIMIT));

        $browserTtl = self::FPC_HTML_DISK_BROWSER_MAX_AGE_DEFAULT;
        try {
            $browserTtl = (int)Env::get(
                'wls.performance.fpc_browser_max_age_seconds',
                self::FPC_HTML_DISK_BROWSER_MAX_AGE_DEFAULT,
            );
        } catch (\Throwable) {
            $browserTtl = self::FPC_HTML_DISK_BROWSER_MAX_AGE_DEFAULT;
        }
        $browserTtl = max(0, min($browserTtl, $edgeTtl, 86400));
        $cdnDirective = $edgeTtl > 0 ? 'public, max-age=' . $edgeTtl : 'no-store';
        $browserDirective = $browserTtl > 0
            ? 'public, max-age=' . $browserTtl
            : 'public, max-age=0, must-revalidate';

        return <<<NGINX
            gzip off;
            gzip_vary off;
            add_header Content-Encoding br always;
            add_header Vary "Accept-Encoding, Cookie" always;
            add_header Cache-Control "{$browserDirective}" always;
            add_header CDN-Cache-Control "{$cdnDirective}" always;
            add_header Cloudflare-CDN-Cache-Control "{$cdnDirective}" always;
            add_header X-Weline-Cache-Status hit always;
            add_header X-Weline-FPC HIT always;
            add_header X-Weline-FPC-Source html-disk always;

NGINX;
    }

    /**
     * Exact + prefix locations that skip HTML try_files and go straight to origin.
     *
     * Covers: /{rest_frontend|rest_backend|backend}/…, always /api/…,
     * exact /bin/query, and SSE /{prefix}/framework/stream.
     */
    private function buildNonHtmlOriginProxyLocations(
        string $httpRedirectLocation,
        string $edgeLimitLocation,
        int $connectTimeoutSec,
        int $readTimeoutSec,
        int $sendTimeoutSec,
        bool $includeSseStream = true,
        string $upstreamHostHeader = '$wls_upstream_authority',
        bool $emptyUpstreamConnection = false,
    ): string {
        if ($upstreamHostHeader !== '$wls_upstream_authority' && $upstreamHostHeader !== '$host') {
            throw new \InvalidArgumentException('Managed nginx non-HTML origin Host header is unsafe.');
        }
        // Values are interpolated via {$var} into the heredoc — do not pre-escape $.
        $hostHeader = $upstreamHostHeader === '$host' ? '$host' : '$wls_upstream_authority';
        $upgradeHeader = $emptyUpstreamConnection
            ? ''
            : "\n            proxy_set_header Upgrade \$wls_business_upstream_upgrade;";
        $connectionHeader = $emptyUpstreamConnection
            ? '""'
            : '$wls_business_upstream_connection';

        $blocks = [];
        $quotedBqFpcRoot = '"' . \str_replace(
            ['\\', '"'],
            ['/', '\\"'],
            $this->paths->projectRoot()
            . DIRECTORY_SEPARATOR . 'generated'
            . DIRECTORY_SEPARATOR . 'html'
            . DIRECTORY_SEPARATOR . 'live'
        ) . '/$wls_fpc_html_host"';
        // Heredoc would eat `{40}` / leave `{{40}}`; inject quantifier via variable.
        $hexSha1Quant = '{40}';
        foreach ($this->resolveNonHtmlOriginPathPrefixes() as $prefix) {
            if (!$this->isRestFrontendOrApiSemanticPrefix($prefix)) {
                continue;
            }
            // Must be ^~ (not regex ~): otherwise location ^~ /{$prefix}/ steals the URI.
            $blocks[] = <<<NGINX

        # BinQuery FPC leaf (same ~q identity as HTML). HIT = index.bq.br only.
        location ^~ /{$prefix}/framework/query-bin/~fpc/ {
            if (\$request_method !~ ^(GET|HEAD)\$) {
                return 405;
            }
            if (\$wls_fpc_accepts_br = 0) {
                return 404;
            }
            root {$quotedBqFpcRoot};
            rewrite "^/{$prefix}/framework/query-bin/~fpc/([A-Za-z0-9._~%/-]+)/~q/([a-f0-9]{$hexSha1Quant})\$" /\$1/~q/\$2/index.bq.br break;
            try_files \$uri =404;
            default_type application/x-weline-query-bin;
            charset utf-8;
            gzip off;
            gzip_vary off;
            add_header Content-Encoding br always;
            add_header X-Weline-FPC HIT always;
            add_header X-Weline-FPC-Source bq-disk always;
            add_header Cache-Control "public, max-age=60" always;
        }
NGINX;
        }
        if ($includeSseStream) {
            foreach ($this->resolveNonHtmlOriginPathPrefixes() as $prefix) {
                // SSE only exists under rest_frontend (semantic /api/ + configured prefix).
                if (!$this->isRestFrontendOrApiSemanticPrefix($prefix)) {
                    continue;
                }
                $blocks[] = <<<NGINX

        # Framework SSE: long-lived; no try_files / buffering / gzip.
        location = /{$prefix}/framework/stream {
            proxy_pass http://wls_backend;
            proxy_http_version 1.1;
{$httpRedirectLocation}{$upgradeHeader}
            proxy_set_header Connection {$connectionHeader};
            proxy_set_header Host {$hostHeader};
            proxy_set_header X-Forwarded-Port \$server_port;
            proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
            proxy_set_header CF-Connecting-IP \$http_cf_connecting_ip;
            proxy_set_header X-Forwarded-Proto \$scheme;
            proxy_set_header X-Real-IP \$remote_addr;
            proxy_buffering off;
            proxy_cache off;
            proxy_no_cache 1;
            gzip off;
            proxy_read_timeout 300s;
            proxy_send_timeout 300s;
        }
NGINX;
            }
        }

        $blocks[] = <<<NGINX

        # External BinQuery SDK entry — never an FPC HTML path.
        location = /bin/query {
{$edgeLimitLocation}            proxy_pass http://wls_backend;
            proxy_http_version 1.1;
{$httpRedirectLocation}{$upgradeHeader}
            proxy_set_header Connection {$connectionHeader};
            proxy_set_header Host {$hostHeader};
            proxy_set_header X-Forwarded-Port \$server_port;
            proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
            proxy_set_header CF-Connecting-IP \$http_cf_connecting_ip;
            proxy_set_header X-Forwarded-Proto \$scheme;
            proxy_set_header X-Real-IP \$remote_addr;
            proxy_connect_timeout {$connectTimeoutSec}s;
            proxy_read_timeout {$readTimeoutSec}s;
            proxy_send_timeout {$sendTimeoutSec}s;
            proxy_buffering on;
            proxy_cache off;
        }
NGINX;

        foreach ($this->resolveNonHtmlOriginPathPrefixes() as $prefix) {
            $blocks[] = <<<NGINX

        # Area prefix {$prefix}: REST / query-bin / admin API — skip HTML try_files.
        location ^~ /{$prefix}/ {
{$edgeLimitLocation}            proxy_pass http://wls_backend;
            proxy_http_version 1.1;
{$httpRedirectLocation}{$upgradeHeader}
            proxy_set_header Connection {$connectionHeader};
            proxy_set_header Host {$hostHeader};
            proxy_set_header X-Forwarded-Port \$server_port;
            proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
            proxy_set_header CF-Connecting-IP \$http_cf_connecting_ip;
            proxy_set_header X-Forwarded-Proto \$scheme;
            proxy_set_header X-Real-IP \$remote_addr;
            proxy_connect_timeout {$connectTimeoutSec}s;
            proxy_read_timeout {$readTimeoutSec}s;
            proxy_send_timeout {$sendTimeoutSec}s;
            proxy_buffering on;
            proxy_cache off;
        }
NGINX;
        }

        // Module Frontend\\Api PC surfaces (purchase-panel, variant-availability…).
        $blocks[] = <<<NGINX

        location ~* ^/[A-Za-z0-9_-]+/frontend/api(/|\$) {
{$edgeLimitLocation}            proxy_pass http://wls_backend;
            proxy_http_version 1.1;
{$httpRedirectLocation}{$upgradeHeader}
            proxy_set_header Connection {$connectionHeader};
            proxy_set_header Host {$hostHeader};
            proxy_set_header X-Forwarded-Port \$server_port;
            proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
            proxy_set_header CF-Connecting-IP \$http_cf_connecting_ip;
            proxy_set_header X-Forwarded-Proto \$scheme;
            proxy_set_header X-Real-IP \$remote_addr;
            proxy_connect_timeout {$connectTimeoutSec}s;
            proxy_read_timeout {$readTimeoutSec}s;
            proxy_send_timeout {$sendTimeoutSec}s;
            proxy_buffering on;
            proxy_cache off;
        }
NGINX;

        return \implode('', $blocks);
    }

    private function isRestFrontendOrApiSemanticPrefix(string $prefix): bool
    {
        if ($prefix === 'api') {
            return true;
        }
        try {
            $frontend = \strtolower(\trim((string)(Env::getAreaRoutePrefix('rest_frontend') ?: 'api'), '/'));
        } catch (\Throwable) {
            $frontend = 'api';
        }

        return $frontend !== '' && \strtolower($prefix) === $frontend;
    }

    /**
     * @return list<string>
     */
    private function resolveNginxTrustedProxyCidrs(): array
    {
        $rules = ['cdn_trusted_ips' => ['enabled' => true, 'ips' => []]];
        try {
            if (\class_exists(\Weline\Server\Security\AttackDetector::class)) {
                $loaded = \Weline\Server\Security\AttackDetector::getInstance()->getRules();
                if (\is_array($loaded)) {
                    $rules = $loaded;
                }
            }
        } catch (\Throwable) {
            // Unit tests / pre-bootstrap: fall back to CF catalog defaults.
        }

        $acceptGate = [];
        try {
            $wls = Env::getInstance()->getConfig('wls') ?? [];
            if (\is_array($wls) && \is_array($wls['accept_gate'] ?? null)) {
                $acceptGate = $wls['accept_gate'];
            }
        } catch (\Throwable) {
            $acceptGate = [];
        }

        return \Weline\Server\Security\TrustedProxyCidrResolver::forNginx($rules, $acceptGate);
    }

    /**
     * @param list<string> $cidrs
     */
    private function buildRealIpHttpBlock(array $cidrs): string
    {
        $cidrs = \Weline\Server\Security\TrustedProxyCidrResolver::withoutLoopback($cidrs);
        if ($cidrs === []) {
            return '';
        }

        $lines = [
            '    # Trusted proxies (cdn_trusted_ips + CF catalog + accept_gate) → real client $remote_addr',
            '    real_ip_header CF-Connecting-IP;',
        ];
        foreach ($cidrs as $cidr) {
            $lines[] = '    set_real_ip_from ' . $cidr . ';';
        }

        return \implode("\n", $lines) . "\n";
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

    private function resolveFastcgiParamsPath(): string
    {
        $source = $this->paths->installRoot() . DIRECTORY_SEPARATOR
            . 'conf' . DIRECTORY_SEPARATOR . 'fastcgi_params';
        if (\is_file($source)) {
            return $source;
        }

        // Minimal fallback when install tree lacks fastcgi_params (tests / fresh roots).
        $fallback = $this->paths->confDir() . DIRECTORY_SEPARATOR . 'fastcgi_params.weline-fallback';
        if (!\is_file($fallback)) {
            $this->paths->ensureRuntimeDirectories();
            $body = <<<'PARAMS'
fastcgi_param  QUERY_STRING       $query_string;
fastcgi_param  REQUEST_METHOD     $request_method;
fastcgi_param  CONTENT_TYPE       $content_type;
fastcgi_param  CONTENT_LENGTH     $content_length;
fastcgi_param  SCRIPT_NAME        $fastcgi_script_name;
fastcgi_param  REQUEST_URI        $request_uri;
fastcgi_param  DOCUMENT_URI       $document_uri;
fastcgi_param  DOCUMENT_ROOT      $document_root;
fastcgi_param  SERVER_PROTOCOL    $server_protocol;
fastcgi_param  REQUEST_SCHEME     $scheme;
fastcgi_param  HTTPS              $https if_not_empty;
fastcgi_param  GATEWAY_INTERFACE  CGI/1.1;
fastcgi_param  SERVER_SOFTWARE    nginx/$nginx_version;
fastcgi_param  REMOTE_ADDR        $remote_addr;
fastcgi_param  REMOTE_PORT        $remote_port;
fastcgi_param  SERVER_ADDR        $server_addr;
fastcgi_param  SERVER_PORT        $server_port;
fastcgi_param  SERVER_NAME        $server_name;
fastcgi_param  REDIRECT_STATUS    200;

PARAMS;
            if (@\file_put_contents($fallback, $body) === false) {
                throw new \RuntimeException('Unable to write fallback fastcgi_params for managed nginx FPM origin.');
            }
        }

        return $fallback;
    }

    /**
     * nginx upstream server line for PHP-FPM (unix: or host:port).
     */
    private function formatFpmUpstreamServer(string $listen): string
    {
        $listen = \trim($listen);
        if ($listen === '') {
            $listen = '127.0.0.1:9000';
        }
        if (\str_starts_with($listen, 'unix:') || \str_contains($listen, '/')) {
            $path = \str_starts_with($listen, 'unix:') ? \substr($listen, 5) : $listen;

            return 'unix:' . \str_replace('\\', '/', $path);
        }

        return $listen;
    }

    private function buildFpmUpstreamBlock(): string
    {
        $server = $this->formatFpmUpstreamServer($this->paths->fpmListen());

        return <<<NGINX
    upstream weline_fpm {
        server {$server};
        keepalive 8;
    }

NGINX;
    }

    /**
     * Edge-synthesized origin outage is temporary unavailability, not a bad gateway.
     *
     * proxy_intercept_errors stays off so a real upstream 502 is not rewritten.
     * recursive_error_pages is on because HTML and static misses already sit
     * inside error_page 418/419 named locations; without recursion nginx would
     * still emit 502 from those internal redirects.
     *
     * @return array{0:string,1:string} server directives, named location
     */
    private function buildOriginUnavailableEdgeFragments(): array
    {
        $server = <<<'NGINX'
        # Origin down / upstream closed / timeout → 503 (crawlers retry).
        # Do not enable proxy_intercept_errors: application 502 stays 502.
        recursive_error_pages on;
        error_page 502 504 =503 @wls_origin_unavailable;

NGINX;
        $location = <<<'NGINX'
        location @wls_origin_unavailable {
            internal;
            default_type text/html;
            charset utf-8;
            add_header Retry-After 60 always;
            add_header Cache-Control "no-store" always;
            add_header CDN-Cache-Control "no-store" always;
            add_header X-Wls-Edge-Unavailable "origin" always;
            return 503 '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Service Unavailable</title></head><body><p>服务暂时不可用，请稍后再试。 Service temporarily unavailable.</p></body></html>';
        }

NGINX;

        return [$server, $location];
    }

    private function buildWlsUpstreamBlock(
        string $upstreamServers,
        int $upstreamKeepalive,
        int $upstreamKeepaliveTimeoutSec,
    ): string {
        return <<<NGINX
    upstream wls_backend {
{$upstreamServers}
        keepalive {$upstreamKeepalive};
        keepalive_requests 1000;
        keepalive_timeout {$upstreamKeepaliveTimeoutSec}s;
    }

NGINX;
    }

    private function buildAcmeChallengeLocation(bool $fpmOrigin, string $quotedAcmeRoot): string
    {
        if ($fpmOrigin) {
            return <<<NGINX
        location ^~ /.well-known/acme-challenge/ {
            root {$quotedAcmeRoot};
            default_type text/plain;
            allow all;
        }

NGINX;
        }

        return <<<NGINX
        location ^~ /.well-known/acme-challenge/ {
            proxy_pass http://wls_backend;
            proxy_http_version 1.1;
            proxy_set_header Connection "";
            proxy_set_header Host \$wls_upstream_authority;
            proxy_set_header X-Forwarded-Port \$server_port;
            proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
            proxy_set_header CF-Connecting-IP \$http_cf_connecting_ip;
            proxy_set_header X-Forwarded-Proto \$scheme;
        }

NGINX;
    }

    private function buildInternalProbeLocation(bool $fpmOrigin, string $configGeneration): string
    {
        if ($fpmOrigin) {
            // Local health for ManagedNginxService /_wls/health probes — no Worker.
            return <<<NGINX
        location ^~ /_wls/ {
            allow 127.0.0.1;
            allow ::1;
            deny all;
            add_header X-Wls-Nginx-Config {$configGeneration} always;
            add_header Cache-Control "no-store" always;
            default_type text/plain;
            return 200 "ok\\n";
        }

NGINX;
        }

        return <<<NGINX
        # 内部探测不走边缘缓存。
        # X-Wls-Nginx-Config is a publication/probe contract for ManagedNginxService
        # gates (/_wls/health); always emit here — independent of wls.debug.identity_headers.
        location ^~ /_wls/ {
            proxy_pass http://wls_backend;
            allow 127.0.0.1;
            allow ::1;
            deny all;
            add_header X-Wls-Nginx-Config {$configGeneration} always;
            proxy_http_version 1.1;
            proxy_set_header Connection \$wls_probe_upstream_connection;
            proxy_set_header Host \$wls_upstream_authority;
            proxy_set_header X-Forwarded-Port \$server_port;
            proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
            proxy_set_header CF-Connecting-IP \$http_cf_connecting_ip;
            proxy_set_header X-Forwarded-Proto \$scheme;
            proxy_set_header X-Real-IP \$remote_addr;
        }

NGINX;
    }

    /**
     * FPM front-controller directives for a location (named or prefix).
     * Aligns with traditional nginx.conf: execute pub/index.php; keep REQUEST_URI.
     */
    private function buildFpmFrontControllerDirectives(
        string $quotedPubRoot,
        string $quotedFastcgiParams,
        string $edgeLimitLocation,
        int $connectTimeoutSec,
        int $readTimeoutSec,
        int $sendTimeoutSec,
        string $locationProtocolHeaders,
        string $prefixLines = '',
    ): string {
        return <<<NGINX
{$prefixLines}{$edgeLimitLocation}            root {$quotedPubRoot};
            include {$quotedFastcgiParams};
            fastcgi_pass weline_fpm;
            # Front controller — SCRIPT is index.php; path stays in REQUEST_URI.
            fastcgi_param SCRIPT_FILENAME \$document_root/index.php;
            fastcgi_param SCRIPT_NAME /index.php;
            fastcgi_param DOCUMENT_ROOT \$document_root;
            fastcgi_param HTTPS \$https if_not_empty;
            fastcgi_param HTTP_X_FORWARDED_PROTO \$scheme;
            fastcgi_param HTTP_X_FORWARDED_PORT \$server_port;
            fastcgi_param HTTP_X_REAL_IP \$remote_addr;
            fastcgi_param HTTP_X_FORWARDED_FOR \$proxy_add_x_forwarded_for;
            fastcgi_connect_timeout {$connectTimeoutSec}s;
            fastcgi_read_timeout {$readTimeoutSec}s;
            fastcgi_send_timeout {$sendTimeoutSec}s;
            fastcgi_buffers 32 64k;
            fastcgi_buffer_size 64k;
{$locationProtocolHeaders}
NGINX;
    }

    /**
     * Convert residual WLS proxy_pass origins to FPM after the shared template is rendered.
     * Primary ACME /_wls/ upstream blocks are already mode-aware; this covers static/HTML/API.
     */
    private function rewriteGeneratedConfForFpmOrigin(
        string $conf,
        string $quotedPubRoot,
        string $quotedFastcgiParams,
        string $quotedAcmeRoot,
        string $configGeneration,
        string $edgeLimitLocation,
        int $connectTimeoutSec,
        int $readTimeoutSec,
        int $sendTimeoutSec,
        string $locationProtocolHeaders,
    ): string {
        // Distinct-host ACME still emitted as proxy in helper — force local webroot.
        $conf = \preg_replace(
            '/location \^~ \/\.well-known\/acme-challenge\/ \{.*?\n        \}/s',
            \trim($this->buildAcmeChallengeLocation(true, $quotedAcmeRoot)),
            $conf,
        ) ?? $conf;

        // Locations already emit $edgeLimitLocation and $locationProtocolHeaders
        // around the proxy_pass block — do not re-inject either or nginx -t /
        // browsers see duplicate limit_req / X-Wls-* headers.
        $fpmBody = $this->buildFpmFrontControllerDirectives(
            $quotedPubRoot,
            $quotedFastcgiParams,
            '',
            $connectTimeoutSec,
            $readTimeoutSec,
            $sendTimeoutSec,
            '',
        );

        // Replace proxy_pass + following proxy_* / http→https if (blank lines OK).
        // {$httpRedirectLocation} is emitted with a leading newline, which inserts a
        // blank line between proxy_http_version and later proxy_set_header — a
        // proxy_*-only regex stopped there and left dead proxy_* + stacked headers.
        $conf = \preg_replace(
            '/[ \t]*proxy_pass http:\/\/wls_backend;\n'
            . '(?:'
            . '[ \t]*\n'
            . '|'
            . '[ \t]*(?:proxy_[^\n]+|if \(\$scheme = http\) \{[^}\n]*\})\n'
            . ')*/',
            $fpmBody,
            $conf,
        ) ?? $conf;

        if (\preg_match(
            '/add_header X-Wls-Origin-Mode [^\n]+\n(?:[ \t]*add_header Alt-Svc \$wls_alt_svc always;\n)?[ \t]*add_header X-Wls-Origin-Mode /',
            $conf,
        ) === 1) {
            throw new \RuntimeException(
                'FPM origin conf stacks duplicate X-Wls-Origin-Mode headers after rewrite.',
            );
        }
        // Silence unused after deciding not to re-inject into fpmBody.
        unset($locationProtocolHeaders);

        if (\str_contains($conf, 'proxy_pass http://wls_backend')) {
            throw new \RuntimeException(
                'FPM origin conf still contains proxy_pass http://wls_backend after rewrite.'
            );
        }
        if (!\str_contains($conf, 'fastcgi_pass weline_fpm')
            || !\str_contains($conf, 'upstream weline_fpm')
        ) {
            throw new \RuntimeException('FPM origin conf missing weline_fpm upstream or fastcgi_pass.');
        }

        // Ensure /_wls health remains local even if a residual proxy form survived.
        if (!\str_contains($conf, 'location ^~ /_wls/')) {
            throw new \RuntimeException('FPM origin conf missing /_wls/ probe location.');
        }

        unset($configGeneration); // retained for call-site clarity / future header asserts

        return $conf;
    }

}
