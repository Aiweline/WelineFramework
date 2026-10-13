<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http\Fpc;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Fpc\FpcPayloadPathIndex;
use Weline\Framework\Test\ProjectRoot;

final class FpcPayloadPathIndexTest extends TestCase
{
    public function testRememberAndPurgeRemovesBodyFileAndRefs(): void
    {
        ProjectRoot::defineBp(__DIR__);
        $url = 'https://shop-a.test/category/demo-path-index';
        $payloadDir = BP . 'var' . \DIRECTORY_SEPARATOR . 'cache' . \DIRECTORY_SEPARATOR . 'router-fpc-payloads'
            . \DIRECTORY_SEPARATOR . 'aa';
        if (!\is_dir($payloadDir)) {
            @\mkdir($payloadDir, 0775, true);
        }
        $body = $payloadDir . \DIRECTORY_SEPARATOR . 'fpc-path-index-unit.br';
        \file_put_contents($body, 'br-body');
        $cacheKey = 'unified-fpc-test-key-' . \uniqid('', true);
        FpcPayloadPathIndex::remember($url, $cacheKey, $body);
        self::assertFileExists($body);

        $keys = FpcPayloadPathIndex::purgePaths([$url]);
        self::assertContains($cacheKey, $keys);
        self::assertFileDoesNotExist($body);
    }

    public function testNormalizeIndexKeyKeepsHost(): void
    {
        self::assertSame(
            'https://shop.test/product/x',
            FpcPayloadPathIndex::normalizeIndexKey('https://shop.test/product/x')
        );
        self::assertSame(
            'https://shop.test/',
            FpcPayloadPathIndex::normalizeIndexKey('https://shop.test/')
        );
        self::assertSame(
            'https://a.test/',
            FpcPayloadPathIndex::normalizeIndexKey('https://A.TEST/')
        );
        self::assertSame(
            'https://shop.test/guide',
            FpcPayloadPathIndex::normalizeIndexKey('https://shop.test/guide?view=destinations&utm_source=x')
        );
        self::assertSame(
            FpcPayloadPathIndex::normalizeIndexKey('https://shop.test/guide?view=destinations'),
            FpcPayloadPathIndex::normalizeIndexKey('https://shop.test/guide?view=routes')
        );
        self::assertSame(
            FpcPayloadPathIndex::normalizeIndexKey('https://shop.test/product/x'),
            FpcPayloadPathIndex::normalizeIndexKey('https://shop.test/product/x?size=s&style_type=hong-se-jiu')
        );
        self::assertTrue(FpcPayloadPathIndex::isHostScopedIndexKey('https://shop.test/'));
        self::assertTrue(FpcPayloadPathIndex::isHostScopedIndexKey('https://shop.test/guide?view=routes'));
        self::assertFalse(FpcPayloadPathIndex::isHostScopedIndexKey('/'));
        // Path-only helper still strips host for legacy path fragment use.
        self::assertSame('/product/x', FpcPayloadPathIndex::normalizePath('https://shop.test/product/x'));
    }

    public function testAnyQuerySharesOnePathIndexBody(): void
    {
        ProjectRoot::defineBp(__DIR__);
        $payloadDir = BP . 'var' . \DIRECTORY_SEPARATOR . 'cache' . \DIRECTORY_SEPARATOR . 'router-fpc-payloads'
            . \DIRECTORY_SEPARATOR . 'gq';
        if (!\is_dir($payloadDir)) {
            @\mkdir($payloadDir, 0775, true);
        }
        $bodyPath = $payloadDir . \DIRECTORY_SEPARATOR . 'path-only.html';
        \file_put_contents($bodyPath, '<html><body>PATH-ONLY</body></html>');
        $urlClean = 'https://playinginchina.test.weline.com/guide';
        $urlDest = 'https://playinginchina.test.weline.com/guide?view=destinations';
        $urlRoutes = 'https://playinginchina.test.weline.com/guide?view=routes';
        FpcPayloadPathIndex::remember($urlDest, 'k-path', $bodyPath);

        $refsDest = FpcPayloadPathIndex::lookup($urlDest);
        $refsRoutes = FpcPayloadPathIndex::lookup($urlRoutes);
        $refsClean = FpcPayloadPathIndex::lookup($urlClean);
        self::assertSame($bodyPath, $refsDest[\count($refsDest) - 1]['body_file'] ?? null);
        self::assertSame($bodyPath, $refsRoutes[\count($refsRoutes) - 1]['body_file'] ?? null);
        self::assertSame($bodyPath, $refsClean[\count($refsClean) - 1]['body_file'] ?? null);

        FpcPayloadPathIndex::purgePaths([$urlClean, $urlDest, $urlRoutes]);
    }

    public function testLookupIsHostScopedAndDoesNotCrossSites(): void
    {
        ProjectRoot::defineBp(__DIR__);
        $payloadDir = BP . 'var' . \DIRECTORY_SEPARATOR . 'cache' . \DIRECTORY_SEPARATOR . 'router-fpc-payloads'
            . \DIRECTORY_SEPARATOR . 'ab';
        if (!\is_dir($payloadDir)) {
            @\mkdir($payloadDir, 0775, true);
        }
        $bodyA = $payloadDir . \DIRECTORY_SEPARATOR . 'fpc-host-a.html';
        $bodyB = $payloadDir . \DIRECTORY_SEPARATOR . 'fpc-host-b.html';
        \file_put_contents($bodyA, 'site-a-body');
        \file_put_contents($bodyB, 'site-b-body');
        $urlA = 'https://playinginchina.test.weline.com/';
        $urlB = 'https://changanhanfu.test.weline.com/';
        FpcPayloadPathIndex::remember($urlA, 'key-a', $bodyA);
        FpcPayloadPathIndex::remember($urlB, 'key-b', $bodyB);

        $refsA = FpcPayloadPathIndex::lookup($urlA);
        $refsB = FpcPayloadPathIndex::lookup($urlB);
        self::assertNotEmpty($refsA);
        self::assertNotEmpty($refsB);
        self::assertSame($bodyA, $refsA[\count($refsA) - 1]['body_file'] ?? null);
        self::assertSame($bodyB, $refsB[\count($refsB) - 1]['body_file'] ?? null);
        // Path-only `/` must not expose these host-scoped bodies (no cross-serve).
        foreach (FpcPayloadPathIndex::lookup('/') as $ref) {
            self::assertNotSame($bodyA, $ref['body_file'] ?? null);
            self::assertNotSame($bodyB, $ref['body_file'] ?? null);
        }

        FpcPayloadPathIndex::purgePaths([$urlA, $urlB]);
        self::assertSame([], FpcPayloadPathIndex::lookup($urlA));
        self::assertSame([], FpcPayloadPathIndex::lookup($urlB));
    }

    public function testLookupReturnsRefsWithoutDeletingBody(): void
    {
        ProjectRoot::defineBp(__DIR__);
        $url = 'https://shop.test/policy/privacy-lookup-' . \uniqid('', true);
        $payloadDir = BP . 'var' . \DIRECTORY_SEPARATOR . 'cache' . \DIRECTORY_SEPARATOR . 'router-fpc-payloads'
            . \DIRECTORY_SEPARATOR . 'ab';
        if (!\is_dir($payloadDir)) {
            @\mkdir($payloadDir, 0775, true);
        }
        $body = $payloadDir . \DIRECTORY_SEPARATOR . 'fpc-lookup-unit.br';
        \file_put_contents($body, 'br-body-lookup');
        $cacheKey = 'unified-fpc-lookup-' . \uniqid('', true);
        FpcPayloadPathIndex::remember($url, $cacheKey, $body);

        $refs = FpcPayloadPathIndex::lookup($url);
        self::assertNotEmpty($refs);
        self::assertSame($body, $refs[\count($refs) - 1]['body_file'] ?? null);
        self::assertSame($cacheKey, $refs[\count($refs) - 1]['cache_key'] ?? null);
        self::assertFileExists($body);

        FpcPayloadPathIndex::purgePaths([$url]);
        self::assertSame([], FpcPayloadPathIndex::lookup($url));
        self::assertFileDoesNotExist($body);
    }
}
