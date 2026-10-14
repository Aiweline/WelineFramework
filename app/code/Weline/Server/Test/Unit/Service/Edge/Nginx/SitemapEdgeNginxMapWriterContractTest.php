<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\ManagedNginxConfigWriter;
use Weline\Server\Service\Edge\Nginx\ManagedNginxPaths;
use Weline\Server\Service\Edge\Nginx\SitemapEdgeNginxMapWriter;

final class SitemapEdgeNginxMapWriterContractTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-sitemap-edge-'
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

    public function testPublishWritesHostMapAndDiskLocations(): void
    {
        $paths = new ManagedNginxPaths($this->root, [
            'runtime_root' => 'runtime',
            'install_root' => 'install',
            'listen_http' => 18092,
            'listen_https' => 18492,
        ]);
        $paths->ensureRuntimeDirectories();
        $writer = new SitemapEdgeNginxMapWriter($paths);
        $result = $writer->publish([
            'default_website_code' => 'default',
            'host_to_code' => [
                'www.example.test' => 'default',
                'shop.example.test' => 'shop',
            ],
            'index_locations' => [
                ['path' => '/sitemap.xml', 'website_code' => 'default'],
                ['path' => '/shop/sitemap.xml', 'website_code' => 'shop'],
            ],
        ]);
        self::assertTrue($result['ok']);
        $map = (string)\file_get_contents($writer->mapFilePath());
        $loc = (string)\file_get_contents($writer->locationsFilePath());
        self::assertStringContainsString('map $host $wls_sitemap_website_code', $map);
        self::assertStringContainsString('"www.example.test" "default"', $map);
        self::assertStringContainsString('"shop.example.test" "shop"', $map);
        self::assertStringContainsString('location ^~ /sitemaps/', $loc);
        self::assertStringContainsString('X-Wls-Sitemap-Disk "1"', $loc);
        self::assertStringContainsString(
            'try_files /sitemaps/$wls_sitemap_website_code/canonical/sitemap.xml =404;',
            $loc,
        );
        self::assertStringContainsString('location = /shop/sitemap.xml', $loc);
        self::assertStringContainsString(
            'try_files /sitemaps/shop/canonical/sitemap.xml =404;',
            $loc,
        );
        self::assertSame(1, \substr_count($loc, 'location = /sitemap.xml'));
    }

    public function testManagedNginxWriterIncludesSitemapEdgeSnippets(): void
    {
        $paths = new ManagedNginxPaths($this->root, [
            'runtime_root' => 'runtime',
            'install_root' => 'install',
            'listen_http' => 18093,
            'listen_https' => 18493,
            'edge_cache' => true,
            'gzip' => true,
        ]);
        $paths->ensureRuntimeDirectories();
        $result = (new ManagedNginxConfigWriter($paths))->write(
            19093,
            '127.0.0.1',
            ['_'],
        );
        $config = (string)\file_get_contents($result['conf']);
        self::assertStringContainsString(SitemapEdgeNginxMapWriter::MAP_BASENAME, $config);
        self::assertStringContainsString(SitemapEdgeNginxMapWriter::LOCATIONS_BASENAME, $config);
        self::assertFileExists(
            $paths->confDir() . DIRECTORY_SEPARATOR . SitemapEdgeNginxMapWriter::MAP_BASENAME
        );
        self::assertFileExists(
            $paths->confDir() . DIRECTORY_SEPARATOR . SitemapEdgeNginxMapWriter::LOCATIONS_BASENAME
        );
    }

    private function removeTree(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }
        $items = \scandir($path);
        if (!\is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . DIRECTORY_SEPARATOR . $item;
            if (\is_dir($full)) {
                $this->removeTree($full);
            } else {
                @\unlink($full);
            }
        }
        @\rmdir($path);
    }
}
