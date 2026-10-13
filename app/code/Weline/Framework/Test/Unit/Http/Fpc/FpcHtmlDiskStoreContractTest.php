<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http\Fpc;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Fpc\FpcHtmlDiskStore;

/**
 * URL-tree FPC cold disk under generated/html with live/previous generation pointers.
 */
final class FpcHtmlDiskStoreContractTest extends TestCase
{
    private string $fixtureHost = '';

    protected function setUp(): void
    {
        $this->fixtureHost = 'fpc-html-disk-test-' . \bin2hex(\random_bytes(4)) . '.example.test';
    }

    protected function tearDown(): void
    {
        $url = 'https://' . $this->fixtureHost . '/';
        try {
            FpcHtmlDiskStore::abandonStagingGeneration();
        } catch (\Throwable) {
        }
        FpcHtmlDiskStore::purgeUrls([$url, 'https://' . $this->fixtureHost . '/en/demo-product', 'https://' . $this->fixtureHost . '/en/path']);
    }

    public function testPublishReadAndPurgeUrls(): void
    {
        $url = 'https://' . $this->fixtureHost . '/en/demo-product';
        $html = "<!doctype html><html><head><title>t</title></head><body>ok</body></html>\n";
        self::assertTrue(FpcHtmlDiskStore::publish($url, $html, ''));
        $path = FpcHtmlDiskStore::htmlPath($url);
        self::assertIsString($path);
        self::assertFileExists($path);
        self::assertSame($html, (string)\file_get_contents($path));

        $live = FpcHtmlDiskStore::logicalLiveBodyPath($url, false);
        self::assertIsString($live);
        self::assertStringContainsString(
            \DIRECTORY_SEPARATOR . 'html' . \DIRECTORY_SEPARATOR . 'live' . \DIRECTORY_SEPARATOR,
            $live,
        );
        self::assertStringNotContainsString(
            \DIRECTORY_SEPARATOR . 'generations' . \DIRECTORY_SEPARATOR,
            $live,
        );

        $hit = FpcHtmlDiskStore::read($url, '');
        self::assertIsArray($hit);
        self::assertSame($html, $hit['body']);
        self::assertNull($hit['encoding']);

        FpcHtmlDiskStore::purgeUrls([$url]);
        self::assertFileDoesNotExist($path);
        self::assertNull(FpcHtmlDiskStore::read($url, ''));
    }

    public function testBrotliPublishIsBrOnlyAndReadableWithoutAcceptBr(): void
    {
        if (!\function_exists('brotli_compress')) {
            self::markTestSkipped('brotli extension is not loaded');
        }
        $url = 'https://' . $this->fixtureHost . '/en/demo-product';
        $html = "<!doctype html><html><body>br-only</body></html>\n";
        $br = \brotli_compress($html, \Weline\Framework\Http\ContentEncodingNegotiator::BROTLI_QUALITY);
        self::assertIsString($br);
        self::assertNotSame('', $br);
        // Seed a legacy plaintext leaf that br publish must unlink.
        self::assertTrue(FpcHtmlDiskStore::publish($url, $html, ''));
        $htmlPath = FpcHtmlDiskStore::htmlPath($url);
        self::assertIsString($htmlPath);
        self::assertFileExists($htmlPath);

        self::assertTrue(FpcHtmlDiskStore::publish($url, $html, $br));
        $brPath = $htmlPath . '.br';
        self::assertFileExists($brPath);
        self::assertFileDoesNotExist($htmlPath);

        $hitIdentity = FpcHtmlDiskStore::read($url, '');
        self::assertIsArray($hitIdentity);
        self::assertSame('br', $hitIdentity['encoding']);
        self::assertSame($br, $hitIdentity['body']);
        self::assertSame($brPath, $hitIdentity['path']);

        $hitBr = FpcHtmlDiskStore::read($url, 'gzip, deflate, br');
        self::assertIsArray($hitBr);
        self::assertSame('br', $hitBr['encoding']);
        self::assertSame($br, $hitBr['body']);

        FpcHtmlDiskStore::purgeUrls([$url]);
        self::assertFileDoesNotExist($brPath);
        self::assertNull(FpcHtmlDiskStore::read($url, ''));
    }

    public function testAnyQueryIsPathKeyedOnly(): void
    {
        $canonical = 'https://' . $this->fixtureHost . '/en/path';
        $withQuery = $canonical . '?size=s&style_type=hong-se-jiu&_wb_nc=1';
        $html = "<html><body>path</body></html>\n";
        self::assertTrue(FpcHtmlDiskStore::publish($withQuery, $html, ''));
        $path = FpcHtmlDiskStore::htmlPath($withQuery);
        self::assertIsString($path);
        self::assertSame(FpcHtmlDiskStore::htmlPath($canonical), $path);
        self::assertStringNotContainsString('~q', $path);
        self::assertFileExists($path);
        FpcHtmlDiskStore::purgeUrls([$canonical]);
        self::assertFileDoesNotExist($path);
    }

    public function testSoftPurgeAllKeepsPreviousAndRollbackRestores(): void
    {
        $url = 'https://' . $this->fixtureHost . '/en/demo-product';
        $html = "<html><body>gen-a</body></html>\n";
        self::assertTrue(FpcHtmlDiskStore::publish($url, $html, ''));
        $beforeGen = FpcHtmlDiskStore::currentGenerationId();
        self::assertNotSame('', $beforeGen);

        FpcHtmlDiskStore::purgeAll();
        self::assertNull(FpcHtmlDiskStore::read($url, ''));
        self::assertSame($beforeGen, FpcHtmlDiskStore::previousGenerationId());
        $prevLink = FpcHtmlDiskStore::root() . \DIRECTORY_SEPARATOR . FpcHtmlDiskStore::PREVIOUS_NAME;
        self::assertTrue(\is_link($prevLink));

        self::assertTrue(FpcHtmlDiskStore::rollbackToPrevious());
        $hit = FpcHtmlDiskStore::read($url, '');
        self::assertIsArray($hit);
        self::assertSame($html, $hit['body']);
    }

    public function testStagingSeedWarmWritesDoNotFlipLiveUntilPromote(): void
    {
        $url = 'https://' . $this->fixtureHost . '/en/demo-product';
        $old = "<html><body>live-old</body></html>\n";
        $neu = "<html><body>staging-new</body></html>\n";
        self::assertTrue(FpcHtmlDiskStore::publish($url, $old, ''));
        $liveBefore = FpcHtmlDiskStore::currentGenerationId();

        $stagingId = FpcHtmlDiskStore::beginStagingGeneration(true);
        self::assertNotSame('', $stagingId);
        self::assertSame($liveBefore, FpcHtmlDiskStore::currentGenerationId());
        self::assertTrue(FpcHtmlDiskStore::publish($url, $neu, ''));
        // Readers still follow live until promote.
        $hit = FpcHtmlDiskStore::read($url, '');
        self::assertIsArray($hit);
        self::assertSame($old, $hit['body']);

        self::assertTrue(FpcHtmlDiskStore::promoteStagingGeneration());
        $hit2 = FpcHtmlDiskStore::read($url, '');
        self::assertIsArray($hit2);
        self::assertSame($neu, $hit2['body']);
        self::assertSame($liveBefore, FpcHtmlDiskStore::previousGenerationId());
    }

    public function testAbandonStagingKeepsLive(): void
    {
        $url = 'https://' . $this->fixtureHost . '/en/demo-product';
        $old = "<html><body>keep-live</body></html>\n";
        self::assertTrue(FpcHtmlDiskStore::publish($url, $old, ''));
        FpcHtmlDiskStore::beginStagingGeneration(true);
        self::assertTrue(FpcHtmlDiskStore::publish($url, "<html><body>discard</body></html>\n", ''));
        FpcHtmlDiskStore::abandonStagingGeneration();
        $hit = FpcHtmlDiskStore::read($url, '');
        self::assertIsArray($hit);
        self::assertSame($old, $hit['body']);
    }

    public function testCoordinatorExternalizeNoLongerRemembersHashPayloadIndex(): void
    {
        $path = \dirname(__DIR__, 4) . \DIRECTORY_SEPARATOR . 'Router' . \DIRECTORY_SEPARATOR
            . 'FullPageCacheCoordinator.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);
        self::assertStringNotContainsString('FpcPayloadPathIndex::remember', $src);
        self::assertStringContainsString('FpcHtmlDiskStore::publish', $src);
        self::assertStringContainsString('logicalLiveBodyPath', $src);
        self::assertStringContainsString('stagingGenerationId', $src);
    }
}
