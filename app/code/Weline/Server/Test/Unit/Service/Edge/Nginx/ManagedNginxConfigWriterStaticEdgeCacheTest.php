<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\ManagedNginxConfigWriter;
use Weline\Server\Service\Edge\Nginx\ManagedNginxPaths;

/**
 * Browsers attach same-origin Cookie to CSS/JS; edge must still cache those
 * assets. Cookie bypass stays on HTML/API only.
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

    public function testEdgeCacheEmitsStaticLocationThatIgnoresCookieBypass(): void
    {
        $paths = new ManagedNginxPaths($this->root, [
            'runtime_root' => 'runtime',
            'install_root' => 'install',
            'listen_http' => 18091,
            'listen_https' => 18491,
            'edge_cache' => true,
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

        $staticStart = \strpos($config, 'location ~* \.(?:');
        self::assertIsInt(
            $staticStart,
            'Managed nginx must emit a regex location for public static extensions.',
        );
        $genericStart = \strpos($config, 'location / {');
        self::assertIsInt($genericStart);
        self::assertLessThan(
            $genericStart,
            $staticStart,
            'Static asset location must precede the catch-all location /.',
        );

        $staticBlock = \substr($config, $staticStart, $genericStart - $staticStart);
        self::assertStringContainsString('proxy_cache wls_edge;', $staticBlock);
        self::assertStringContainsString(
            'add_header X-Wls-Edge-Cache $upstream_cache_status always;',
            $staticBlock,
        );
        self::assertMatchesRegularExpression('/\bcss\b/', $staticBlock);
        self::assertMatchesRegularExpression('/\bjs\b/', $staticBlock);
        self::assertStringNotContainsString(
            'proxy_cache_bypass $wls_edge_bypass;',
            $staticBlock,
            'Static assets must not inherit Cookie/Authorization edge bypass.',
        );
        self::assertStringNotContainsString(
            'proxy_no_cache $wls_edge_bypass;',
            $staticBlock,
        );
        self::assertStringContainsString(
            'proxy_set_header Cookie "";',
            $staticBlock,
            'Edge must discard inbound Cookie before proxying public static assets.',
        );
        self::assertStringContainsString(
            'proxy_set_header Authorization "";',
            $staticBlock,
            'Edge must discard Authorization before proxying public static assets.',
        );

        $genericBlock = \substr($config, $genericStart);
        self::assertStringContainsString('proxy_cache_bypass $wls_edge_bypass;', $genericBlock);
        self::assertStringContainsString(
            'proxy_no_cache $wls_edge_bypass $wls_edge_skip_fpc_miss_status $wls_edge_skip_fpc_miss_weline;',
            $genericBlock,
            'HTML edge must refuse to store upstream FPC MISS/BYPASS responses.',
        );
        self::assertStringContainsString(
            'proxy_cache_key "$scheme$request_method$host$request_uri|fpc2";',
            $genericBlock,
            'HTML edge cache key generation must bump past MISS-poisoned entries.',
        );
        self::assertStringContainsString(
            'map "$http_cookie|$http_authorization|$http_upgrade" $wls_edge_bypass',
            $config,
        );
        self::assertStringContainsString(
            'map $upstream_http_x_wls_fpc_status $wls_edge_skip_fpc_miss_status',
            $config,
        );
        self::assertStringContainsString(
            'map $upstream_http_x_weline_fpc $wls_edge_skip_fpc_miss_weline',
            $config,
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
