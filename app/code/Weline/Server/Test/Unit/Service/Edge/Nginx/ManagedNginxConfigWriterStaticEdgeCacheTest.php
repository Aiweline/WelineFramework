<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Test\ProjectRoot;
use Weline\Server\Service\Edge\Nginx\ManagedNginxConfigWriter;
use Weline\Server\Service\Edge\Nginx\ManagedNginxPaths;

\defined('DS') || \define('DS', \DIRECTORY_SEPARATOR);
ProjectRoot::defineBp(__DIR__);
\defined('APP_PATH') || \define('APP_PATH', BP . 'app' . DS);
\defined('APP_CODE_PATH') || \define('APP_CODE_PATH', APP_PATH . 'code' . DS);
\defined('APP_ETC_PATH') || \define('APP_ETC_PATH', APP_PATH . 'etc' . DS);
\defined('PUB') || \define('PUB', PUB);
\defined('VAR_DIR') || \define('VAR_DIR', VAR_DIR);

/**
 * Static layers: ^~ /pub/media, ^~ /static → pub, regex → FPC static disk,
 * then @wls_static_origin (+ optional proxy_cache). No app/code try_files.
 */
final class ManagedNginxConfigWriterStaticEdgeCacheTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-nginx-static-edge-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root, 0700, true));
        $canonical = \realpath($this->root);
        self::assertIsString($canonical);
        $this->root = $canonical;
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testEdgeCacheEmitsStaticLocationAndHtmlDiskTryFiles(): void
    {
        $paths = new ManagedNginxPaths($this->root, [
            'runtime_root' => 'runtime',
            'install_root' => 'install',
            'listen_http' => 18091,
            'listen_https' => 18491,
            'edge_cache' => true,
            'edge_cache_dynamic' => true,
            'gzip' => true,
        ]);
        $paths->ensureRuntimeDirectories();
        $result = (new ManagedNginxConfigWriter($paths))->write(
            19091,
            '127.0.0.1',
            ['_'],
        );
        $config = \file_get_contents($result['conf']);
        self::assertIsString($config);

        self::assertMatchesRegularExpression(
            '#include\s+"[^"]+/conf\.d/\*\.conf"\s*;#',
            $config,
            'Managed nginx must include conf.d/*.conf so multi-project satellite vhosts survive regen.',
        );
        $confDIncludePos = \strpos($config, '/conf.d/*.conf');
        self::assertIsInt($confDIncludePos);
        $httpClosePos = \strrpos($config, "\n}");
        self::assertIsInt($httpClosePos);
        self::assertLessThan(
            $httpClosePos,
            $confDIncludePos,
            'conf.d include must sit inside the http {} block before its closing brace.',
        );

        $mediaStart = \strpos($config, 'location ^~ /pub/media/');
        self::assertIsInt(
            $mediaStart,
            'Managed nginx must disk-serve /pub/media/ so listing images survive WLS drains.',
        );
        self::assertStringContainsString('X-Wls-Media-Disk "1"', $config);
        self::assertMatchesRegularExpression(
            '#location\s+\^~\s+/pub/media/\s*\{[^}]*alias\s+"[^"]+/"\s*;#s',
            $config,
            'Media alias trailing slash must be inside the quoted path (alias "/path"/; fails nginx -t).',
        );
        self::assertStringNotContainsString("location ^~ /pub/media/ {\n            root ", $config);
        self::assertStringContainsString('location ^~ /static/', $config);
        self::assertStringContainsString('map $uri $wls_static_br_eligible', $config);
        self::assertStringNotContainsString('map $uri $wls_static_br_mime', $config);
        self::assertStringContainsString('try_files $uri.br =418;', $config);
        self::assertStringContainsString('default_type text/css;', $config);
        self::assertStringContainsString('default_type application/javascript;', $config);
        self::assertStringContainsString('Content-Encoding br always;', $config);
        self::assertStringContainsString('location @wls_static_pub_plain', $config);
        self::assertStringContainsString('try_files $uri @wls_static_origin;', $config);
        self::assertStringNotContainsString('try_files $uri.gz', $config);
        self::assertStringNotContainsString('default_type $wls_static_br_mime;', $config);

        $cssStart = \strpos($config, 'location ~* \.css$');
        self::assertIsInt(
            $cssStart,
            'Managed nginx must emit a regex location for FPC css (br then plain).',
        );
        self::assertLessThan(
            $cssStart,
            $mediaStart,
            'Disk /pub/media/ location must precede the extension regex proxy location.',
        );
        $genericStart = \strpos($config, 'location / {');
        self::assertIsInt($genericStart);
        self::assertLessThan(
            $genericStart,
            $cssStart,
            'Static asset location must precede the catch-all location /.',
        );

        $staticBlock = \substr($config, $cssStart, $genericStart - $cssStart);
        self::assertStringContainsString(
            'try_files /generated/static/live/$wls_fpc_html_host$uri.br =418;',
            $staticBlock,
            'Compressible FPC statics prefer precomputed .br when client accepts br.',
        );
        self::assertStringContainsString(
            'error_page 419 = @wls_static_origin;',
            $staticBlock,
            'Cache-bust query must be able to jump straight to WLS remirror origin.',
        );
        self::assertStringContainsString(
            'if ($arg__wb_nc)',
            $staticBlock,
            'Acceptance _wb_nc must bypass stale static live FPC.',
        );
        self::assertStringContainsString(
            'if ($arg__weline_dev)',
            $staticBlock,
            'DEV _weline_dev must bypass stale static live FPC.',
        );
        self::assertStringContainsString(
            'location @wls_static_fpc_plain',
            $staticBlock,
        );
        self::assertStringContainsString(
            'try_files /generated/static/live/$wls_fpc_html_host$uri @wls_static_origin;',
            $staticBlock,
            'FPC plain miss / non-br clients fall through to plain leaf then WLS.',
        );
        self::assertStringContainsString('default_type text/css;', $staticBlock);
        self::assertStringContainsString('default_type application/javascript;', $staticBlock);
        self::assertStringContainsString(
            'open_file_cache_errors off;',
            $staticBlock,
            'Cold try_files miss must not be open_file_cache-poisoned after WLS mirrors.',
        );
        self::assertStringNotContainsString('/app/code$uri', $staticBlock);
        self::assertStringContainsString('X-Wls-Static-Disk "1"', $staticBlock);
        self::assertStringContainsString('X-Wls-Static-Fpc "1"', $staticBlock);
        self::assertStringContainsString('location @wls_static_origin', $staticBlock);
        self::assertStringContainsString(
            'proxy_cache off;',
            $staticBlock,
            'Static origin must not proxy_cache: HIT would skip WLS remirror into FPC disk.',
        );
        self::assertStringNotContainsString(
            'proxy_cache wls_edge;',
            $staticBlock,
            'FPC static disk is the durable L2 for module/DEV extension URLs.',
        );
        self::assertMatchesRegularExpression('/\bcss\b/', $staticBlock);
        self::assertMatchesRegularExpression('/\bjs\b/', $staticBlock);
        self::assertStringNotContainsString(
            'proxy_cache_bypass $wls_edge_bypass;',
            $staticBlock,
            'Static assets must not inherit Cookie/Authorization edge bypass.',
        );
        self::assertStringNotContainsString(
            'expires 365d;',
            $staticBlock,
            'expires must not stack a second Cache-Control alongside add_header immutable.',
        );
        self::assertStringContainsString(
            'Cache-Control $wls_static_cache_control always;',
            $staticBlock,
            'Static cache policy must come from the version map, not a fixed immutable header.',
        );
        self::assertStringContainsString('map $uri $wls_static_versioned', $config);
        self::assertStringContainsString('map $args $wls_static_arg_versioned', $config);
        self::assertStringContainsString(
            'map "$wls_static_versioned$wls_static_arg_versioned" $wls_static_cache_control',
            $config,
        );
        self::assertStringContainsString(
            'default "public, max-age=0, must-revalidate";',
            $config,
            'Unversioned /static/** must revalidate so stale FPC references cannot pin old bytes.',
        );
        self::assertStringContainsString(
            'proxy_set_header Cookie "";',
            $staticBlock,
            'Miss path must discard inbound Cookie before proxying to WLS.',
        );
        self::assertStringContainsString(
            'proxy_set_header Authorization "";',
            $staticBlock,
            'Miss path must discard Authorization before proxying to WLS.',
        );

        $genericBlock = \substr($config, $genericStart);
        self::assertStringContainsString('html/live/$wls_fpc_html_host', $config);
        self::assertStringContainsString('try_files $uri/index.html.br =418;', $genericBlock);
        self::assertStringContainsString('Content-Encoding br always;', $genericBlock);
        self::assertStringContainsString('gzip off;', $genericBlock);
        self::assertStringContainsString('gzip_vary off;', $genericBlock);
        self::assertStringContainsString('Vary "Accept-Encoding, Cookie" always;', $genericBlock);
        self::assertMatchesRegularExpression(
            '/try_files \$uri\/index\.html\.br =418;[\s\S]*?gzip off;[\s\S]*?Content-Encoding br always;/',
            $genericBlock,
            'html-disk HIT must gzip off before serving precompressed .br (no dual Content-Encoding).',
        );
        self::assertMatchesRegularExpression(
            '/location @wls_html_origin\s*\{[^}]*gzip_vary off;/s',
            $genericBlock,
            'HTML origin must turn off gzip_vary so upstream Vary is not stacked.',
        );
        self::assertStringContainsString(
            'CDN-Cache-Control "public, max-age=300" always;',
            $genericBlock,
            'html-disk HIT must emit a short CDN-Cache-Control so a missed purge self-heals.',
        );
        self::assertStringContainsString(
            'Cloudflare-CDN-Cache-Control "public, max-age=300" always;',
            $genericBlock,
        );
        self::assertStringContainsString('Cache-Control "public, max-age=', $genericBlock);
        self::assertStringContainsString('X-Weline-Cache-Status hit always;', $genericBlock);
        self::assertStringContainsString(
            'if ($wls_fpc_html_disk_try = 0)',
            $genericBlock,
            'HTML disk gate is the single map $wls_fpc_html_disk_try (scheme/bypass/allowlist/br).',
        );
        self::assertStringContainsString(
            'var/maintenance/edge.on',
            $genericBlock,
            'Maintenance edge.on must force 418→origin so FPM/WLS share pub/errors/maintenance pages.',
        );
        self::assertStringContainsString('X-Weline-FPC-Source html-disk', $genericBlock);
        self::assertStringContainsString('location @wls_html_origin', $genericBlock);
        self::assertStringContainsString(
            'error_page 502 504 =503 @wls_origin_unavailable;',
            $config,
            'Nginx-synthesized upstream failure must be 503 so crawlers retry.',
        );
        self::assertMatchesRegularExpression(
            '/location @wls_html_origin\s*\{[^}]*error_page 502 504 =503 @wls_origin_unavailable;/s',
            $config,
            'HTML origin location defines its own error_page chain, so 502 must be mapped there too.',
        );
        self::assertStringContainsString('recursive_error_pages on;', $config);
        self::assertStringContainsString('Retry-After 60 always;', $config);
        self::assertStringContainsString('X-Wls-Edge-Unavailable "origin" always;', $config);
        self::assertStringNotContainsString(
            'proxy_intercept_errors on;',
            $config,
            'Application-originated 502 must pass through unchanged.',
        );
        self::assertStringContainsString('proxy_cache off;', $genericBlock);
        self::assertStringNotContainsString(
            'proxy_cache wls_edge;',
            $genericBlock,
            'Business HTML must not use proxy_cache after URL-tree disk migration.',
        );
        self::assertStringNotContainsString(
            'proxy_cache_key "$scheme$request_method$host$request_uri|fpc2',
            $config,
            'HTML |fpc2 proxy_cache key must be retired.',
        );
        self::assertStringContainsString(
            'map $http_authorization $wls_fpc_html_bypass_auth',
            $config,
        );
        self::assertStringContainsString(
            'map $request_uri $wls_fpc_html_bypass_preview_path',
            $config,
        );
        self::assertStringContainsString(
            'map $request_uri $wls_fpc_html_diag_query',
            $config,
        );
        self::assertStringContainsString(
            'i18n_inline',
            $config,
            'Storefront point-translate query must edge-bypass with pierce (not FPC HIT).',
        );
        self::assertStringContainsString(
            'w_i18n_inline_edit',
            $config,
            'Inline-edit session cookie must edge-bypass with pierce after query drops.',
        );
        self::assertStringContainsString(
            '$wls_fpc_html_i18n_inline_cookie_bypass',
            $config,
        );
        self::assertStringContainsString(
            'map $request_uri $wls_fpc_html_webhook_path',
            $config,
        );
        self::assertStringContainsString(
            '$wls_fpc_pierce_ok$wls_fpc_html_webhook_path',
            $config,
            'Deploy /~wh~ must require pierce stamp (same gate as diag).',
        );
        self::assertStringNotContainsString(
            '$wls_fpc_html_bypass_webhook_path$',
            $config,
            'Unconditional webhook bypass must not remain in disk_bypass OR-chain.',
        );
        self::assertStringContainsString(
            '$wls_fpc_pierce_ok',
            $config,
        );
        self::assertStringContainsString(
            'fpc-pierce.map.conf',
            $config,
        );
        self::assertStringContainsString(
            'fpc-query-allowlist.map.conf',
            $config,
            'Allowlist map still included (default 0; not OR’d into disk bypass).',
        );
        self::assertDoesNotMatchRegularExpression(
            '/map "\$wls_fpc_html_bypass_auth[^\"]*query_allowlist_bypass/',
            $config,
            'Allowlist bypass must not OR into $wls_fpc_html_disk_bypass.',
        );
        self::assertStringContainsString(
            'query-bin/~fpc/',
            $config,
            'BinQuery FPC leaf location must try_files index.bq.br under ~q/{sha}.',
        );
        self::assertStringContainsString('index.bq.br', $config);
        self::assertStringContainsString(
            'proxy_set_header X-Wls-Fpc-Bypass "";',
            $config,
        );
        self::assertStringContainsString(
            '$wls_fpc_html_disk_bypass',
            $config,
        );
        self::assertStringContainsString(
            'map $http_accept_encoding $wls_fpc_accepts_br',
            $config,
        );
        self::assertStringNotContainsString(
            'map "$http_cookie|$http_authorization|$http_upgrade" $wls_edge_bypass',
            $config,
            'Cookie must not bypass FPC HTML disk try_files.',
        );

        // Non-HTML surfaces must proxy before location / — never pay try_files.
        $apiPrefixStart = \strpos($config, 'location ^~ /api/');
        self::assertIsInt($apiPrefixStart, 'Managed nginx must prefix-proxy /api/ without try_files.');
        self::assertLessThan(
            $genericStart,
            $apiPrefixStart,
            '/api/ origin proxy must precede HTML try_files location /.',
        );
        self::assertStringContainsString('location = /bin/query', $config);
        self::assertLessThan(
            $genericStart,
            (int)\strpos($config, 'location = /bin/query'),
            '/bin/query must precede HTML try_files location /.',
        );
        self::assertStringContainsString('location = /api/framework/stream', $config);
        self::assertStringContainsString(
            'location ~* ^/[A-Za-z0-9_-]+/frontend/api(/|$)',
            $config,
        );
        $apiBlock = \substr($config, $apiPrefixStart, $genericStart - $apiPrefixStart);
        self::assertStringContainsString('proxy_pass http://wls_backend;', $apiBlock);
        self::assertStringNotContainsString(
            'try_files $uri/index.html.br',
            $apiBlock,
            'API / BinQuery locations must not run FPC HTML try_files.',
        );
    }

    private function removeTree(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        $items = \scandir($dir);
        if (!\is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (\is_dir($path) && !\is_link($path)) {
                $this->removeTree($path);
                continue;
            }
            @\unlink($path);
        }
        @\rmdir($dir);
    }
}
