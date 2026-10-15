<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http\Fpc;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\ContentEncodingNegotiator;
use Weline\Framework\Http\Fpc\FpcStaticDiskStore;
use Weline\Framework\Http\Fpc\StaticAssetBrotliSidecar;
use Weline\Framework\Test\ProjectRoot;

final class FpcStaticDiskStoreContractTest extends TestCase
{
    private string $fixtureHost = '';

    private string $sourceFile = '';

    protected function setUp(): void
    {
        ProjectRoot::defineBp(__DIR__);
        $this->fixtureHost = 'fpc-static-disk-' . \bin2hex(\random_bytes(4)) . '.example.test';
        $dir = BP . 'var' . \DIRECTORY_SEPARATOR . 'tmp' . \DIRECTORY_SEPARATOR . 'fpc-static-src';
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0775, true);
        }
        $this->sourceFile = $dir . \DIRECTORY_SEPARATOR . 'geo-fixture.js';
        // ≥ MIN_BYTES so StaticAssetBrotliSidecar will write `.br`.
        \file_put_contents($this->sourceFile, \str_repeat("console.log('fpc-static');\n", 80));
    }

    protected function tearDown(): void
    {
        FpcStaticDiskStore::purgePaths($this->fixtureHost, [
            '/Weline/Geo/view/statics/frontend/js/geo-fixture.js',
            '/Weline/Geo/view/statics/frontend/js/geo-fixture-live-mirror.js',
            '/Weline/Geo/view/statics/frontend/js/geo-fixture-br-heal.js',
        ]);
        if ($this->sourceFile !== '' && \is_file($this->sourceFile)) {
            @\unlink($this->sourceFile);
        }
    }

    public function testPublishFromAppCodeMirrorAndSkipPub(): void
    {
        $storeSrc = (string)\file_get_contents(
            BP . 'app/code/Weline/Framework/Http/Fpc/FpcStaticDiskStore.php'
        );
        self::assertStringContainsString('StaticAssetBrotliSidecar::publish($target)', $storeSrc);
        self::assertGreaterThanOrEqual(
            3,
            \substr_count($storeSrc, 'StaticAssetBrotliSidecar::publish($target)'),
            'publishFromFile early-out + copy path + republish hash-match must all refresh .br',
        );

        $uri = '/Weline/Geo/view/statics/frontend/js/geo-fixture.js';
        self::assertTrue(FpcStaticDiskStore::shouldMirrorResolvedFile($this->sourceFile));
        self::assertTrue(FpcStaticDiskStore::publishFromFile($this->fixtureHost, $uri, $this->sourceFile));
        $target = FpcStaticDiskStore::absolutePath($this->fixtureHost, $uri);
        self::assertIsString($target);
        self::assertFileExists($target);
        self::assertStringContainsString(
            \DIRECTORY_SEPARATOR . 'static' . \DIRECTORY_SEPARATOR . 'live' . \DIRECTORY_SEPARATOR,
            $target,
        );
        self::assertStringContainsString("console.log('fpc-static');", (string)\file_get_contents($target));

        $pubProbe = BP . 'pub' . \DIRECTORY_SEPARATOR . 'static' . \DIRECTORY_SEPARATOR . 'probe-skip.js';
        $pubDir = \dirname($pubProbe);
        if (!\is_dir($pubDir)) {
            @\mkdir($pubDir, 0775, true);
        }
        \file_put_contents($pubProbe, 'skip');
        try {
            self::assertFalse(FpcStaticDiskStore::shouldMirrorResolvedFile($pubProbe));
        } finally {
            @\unlink($pubProbe);
        }

        FpcStaticDiskStore::purgePaths($this->fixtureHost, [$uri]);
        self::assertFileDoesNotExist($target);
    }

    public function testPurgeAllLiveRemovesMirroredLeaves(): void
    {
        $uri = '/Weline/Geo/view/statics/frontend/js/geo-fixture.js';
        self::assertTrue(FpcStaticDiskStore::publishFromFile($this->fixtureHost, $uri, $this->sourceFile));
        $target = FpcStaticDiskStore::absolutePath($this->fixtureHost, $uri);
        self::assertIsString($target);
        self::assertFileExists($target);
        $removed = FpcStaticDiskStore::purgeAllLive();
        self::assertGreaterThan(0, $removed);
        self::assertFileDoesNotExist($target);
    }

    public function testUriFromAppCodeAbsoluteAndRepublishOnAllHosts(): void
    {
        $uri = '/Weline/Geo/view/statics/frontend/js/geo-fixture.js';
        // Seed a live host leaf with stale bytes (simulates Nginx try_files lag).
        self::assertTrue(FpcStaticDiskStore::publishFromFile($this->fixtureHost, $uri, $this->sourceFile));
        $target = FpcStaticDiskStore::absolutePath($this->fixtureHost, $uri);
        self::assertIsString($target);
        \file_put_contents($target, "console.log('STALE');\n");

        $moduleSrcDir = BP . 'app' . \DIRECTORY_SEPARATOR . 'code' . \DIRECTORY_SEPARATOR . 'Weline'
            . \DIRECTORY_SEPARATOR . 'Geo' . \DIRECTORY_SEPARATOR . 'view' . \DIRECTORY_SEPARATOR . 'statics'
            . \DIRECTORY_SEPARATOR . 'frontend' . \DIRECTORY_SEPARATOR . 'js';
        if (!\is_dir($moduleSrcDir)) {
            @\mkdir($moduleSrcDir, 0775, true);
        }
        $moduleSrc = $moduleSrcDir . \DIRECTORY_SEPARATOR . 'geo-fixture-live-mirror.js';
        $fresh = "console.log('FRESH-LIVE-MIRROR');\n";
        \file_put_contents($moduleSrc, $fresh);
        $mappedUri = '/Weline/Geo/view/statics/frontend/js/geo-fixture-live-mirror.js';
        try {
            self::assertSame($mappedUri, FpcStaticDiskStore::uriFromAppCodeAbsolute($moduleSrc));
            self::assertContains($this->fixtureHost, FpcStaticDiskStore::listLiveHosts());
            // Stale leaf for the mapped URI on the same host.
            self::assertTrue(FpcStaticDiskStore::publishFromFile($this->fixtureHost, $mappedUri, $this->sourceFile));
            $mappedTarget = FpcStaticDiskStore::absolutePath($this->fixtureHost, $mappedUri);
            self::assertIsString($mappedTarget);
            \file_put_contents($mappedTarget, "console.log('STALE');\n");
            $n = FpcStaticDiskStore::republishFromFileOnAllHosts($mappedUri, $moduleSrc);
            self::assertGreaterThan(0, $n);
            self::assertSame($fresh, (string)\file_get_contents($mappedTarget));
            $purged = FpcStaticDiskStore::purgeUriOnAllHosts([$mappedUri]);
            self::assertGreaterThan(0, $purged);
            self::assertFileDoesNotExist($mappedTarget);
        } finally {
            FpcStaticDiskStore::purgePaths($this->fixtureHost, [$mappedUri, $uri]);
            if (\is_file($moduleSrc)) {
                @\unlink($moduleSrc);
            }
        }
    }

    public function testPublishFromFileEarlyOutStillRefreshesStaleBrotliSidecar(): void
    {
        if (!ContentEncodingNegotiator::brotliAvailable()) {
            self::markTestSkipped('ext-brotli required');
        }
        $uri = '/Weline/Geo/view/statics/frontend/js/geo-fixture-br-heal.js';
        self::assertTrue(FpcStaticDiskStore::publishFromFile($this->fixtureHost, $uri, $this->sourceFile));
        $target = FpcStaticDiskStore::absolutePath($this->fixtureHost, $uri);
        self::assertIsString($target);
        $br = $target . '.br';
        self::assertFileExists($br);
        // Poison sidecar while leaving plaintext identical to source (early-out path).
        \file_put_contents($br, 'NOT-BROTLI-STALE');
        @\touch($br, \time() - 3600);
        self::assertTrue(FpcStaticDiskStore::publishFromFile($this->fixtureHost, $uri, $this->sourceFile));
        $decoded = ContentEncodingNegotiator::decode(
            (string)\file_get_contents($br),
            ContentEncodingNegotiator::ENCODING_BROTLI,
        );
        self::assertSame((string)\file_get_contents($target), $decoded);
        FpcStaticDiskStore::purgePaths($this->fixtureHost, [$uri]);
    }

    public function testRepublishHashMatchStillRefreshesStaleBrotliSidecar(): void
    {
        if (!ContentEncodingNegotiator::brotliAvailable()) {
            self::markTestSkipped('ext-brotli required');
        }
        $moduleSrcDir = BP . 'app' . \DIRECTORY_SEPARATOR . 'code' . \DIRECTORY_SEPARATOR . 'Weline'
            . \DIRECTORY_SEPARATOR . 'Geo' . \DIRECTORY_SEPARATOR . 'view' . \DIRECTORY_SEPARATOR . 'statics'
            . \DIRECTORY_SEPARATOR . 'frontend' . \DIRECTORY_SEPARATOR . 'js';
        if (!\is_dir($moduleSrcDir)) {
            @\mkdir($moduleSrcDir, 0775, true);
        }
        $moduleSrc = $moduleSrcDir . \DIRECTORY_SEPARATOR . 'geo-fixture-br-heal.js';
        $body = \str_repeat("console.log('br-heal');\n", 80);
        \file_put_contents($moduleSrc, $body);
        $uri = '/Weline/Geo/view/statics/frontend/js/geo-fixture-br-heal.js';
        try {
            self::assertTrue(FpcStaticDiskStore::publishFromFile($this->fixtureHost, $uri, $moduleSrc));
            $target = FpcStaticDiskStore::absolutePath($this->fixtureHost, $uri);
            self::assertIsString($target);
            $br = $target . '.br';
            self::assertFileExists($br);
            \file_put_contents($br, 'NOT-BROTLI-STALE');
            $n = FpcStaticDiskStore::republishFromFileOnAllHosts($uri, $moduleSrc);
            self::assertGreaterThan(0, $n);
            $decoded = ContentEncodingNegotiator::decode(
                (string)\file_get_contents($br),
                ContentEncodingNegotiator::ENCODING_BROTLI,
            );
            self::assertSame($body, $decoded);
        } finally {
            FpcStaticDiskStore::purgePaths($this->fixtureHost, [$uri]);
            if (\is_file($moduleSrc)) {
                @\unlink($moduleSrc);
            }
        }
    }
}
