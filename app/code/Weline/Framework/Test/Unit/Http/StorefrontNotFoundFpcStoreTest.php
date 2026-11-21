<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Fpc\StorefrontNotFoundFpcStore;

/**
 * Per-language special FPC store for storefront 404 pages.
 */
final class StorefrontNotFoundFpcStoreTest extends TestCase
{
    public function testFilePathSanitizesCodeAndNormalizesLang(): void
    {
        $path = StorefrontNotFoundFpcStore::filePath('shop', 'en-us');
        self::assertStringEndsWith(\DIRECTORY_SEPARATOR . 'shop' . \DIRECTORY_SEPARATOR . 'en_US.html', $path);
        self::assertStringContainsString('error-pages', $path);
        self::assertStringContainsString('not-found', $path);
    }

    public function testFilePathFallsBackToDefaultCodeOnInvalid(): void
    {
        $path = StorefrontNotFoundFpcStore::filePath('../etc', 'en_US');
        self::assertStringContainsString(\DIRECTORY_SEPARATOR . 'default' . \DIRECTORY_SEPARATOR, $path);
        self::assertStringNotContainsString('..', $path);
    }

    public function testReadReturnsNullWhenNothingCached(): void
    {
        self::assertNull(StorefrontNotFoundFpcStore::read('__no_such_site_' . \bin2hex(\random_bytes(4)), 'en_US'));
    }

    public function testPublishRejectsEmptyHtml(): void
    {
        self::assertFalse(StorefrontNotFoundFpcStore::publish('default', 'en_US', '   '));
    }

    public function testPublishScrubsVolatileGenerationClaims(): void
    {
        $code = '__test_nf_' . \bin2hex(\random_bytes(4));
        $html = self::fixtureHtml(
            '"themePublishedVersionId":"t3.frontend.normal.58083e57886d.v26.e225",'
            . '"themePublishedVersion":"v26",'
            . '"generationUid":"87d75b385c01~hanfu/v282",'
            . '"theme_published_version_id":"t9.frontend.normal.aaaaaaaaaaaa.v7.e99",'
            . '"generation_uid":"b1~x/v2"'
            . '</script><div data-global-region-src="/shell?fragment=base.body_end.x&amp;cur=CNY&amp;v=t3.frontend.normal.58083e57886d.v26.e225" data-global-region-ttl="60"></div><script>{'
        );
        try {
            self::assertTrue(StorefrontNotFoundFpcStore::publish($code, 'en_US', $html));
            $raw = (string)\file_get_contents(StorefrontNotFoundFpcStore::filePath($code, 'en_US'));
            self::assertStringContainsString('"themePublishedVersionId":""', $raw);
            self::assertStringContainsString('"themePublishedVersion":""', $raw);
            self::assertStringContainsString('"generationUid":""', $raw);
            self::assertStringContainsString('"theme_published_version_id":""', $raw);
            self::assertStringContainsString('"generation_uid":""', $raw);
            self::assertStringNotContainsString('v26.e225', $raw);
            self::assertStringNotContainsString('hanfu/v282', $raw);
            self::assertStringNotContainsString('e99', $raw);
            self::assertStringNotContainsString('b1~x/v2', $raw);
            self::assertStringContainsString('&amp;v="', $raw);
            self::assertStringNotContainsString('v=t3.frontend', $raw);
        } finally {
            StorefrontNotFoundFpcStore::purge($code);
        }
    }

    /**
     * Pre-fix leaves already carry baked generation stamps (the Nov-2026 editor
     * 「更新到最新版本」 loop). read() must serve them scrubbed so the client
     * skips generation enforcement instead of looping 409 reloads.
     */
    public function testReadScrubsLegacyBakedGenerationClaims(): void
    {
        $code = '__test_nf_' . \bin2hex(\random_bytes(4));
        $path = StorefrontNotFoundFpcStore::filePath($code, 'en_US');
        if (!\is_dir(\dirname($path))) {
            @\mkdir(\dirname($path), 0775, true);
        }
        $html = self::fixtureHtml(
            '"themePublishedVersionId":"t3.frontend.normal.58083e57886d.v26.e225",'
            . '"generationUid":"87d75b385c01~hanfu/v282"'
        );
        \file_put_contents($path, $html);
        try {
            $hit = StorefrontNotFoundFpcStore::read($code, 'en_US');
            self::assertNotNull($hit);
            self::assertStringContainsString('"themePublishedVersionId":""', $hit['body']);
            self::assertStringContainsString('"generationUid":""', $hit['body']);
            self::assertStringNotContainsString('v26.e225', $hit['body']);
            self::assertStringNotContainsString('hanfu/v282', $hit['body']);
        } finally {
            StorefrontNotFoundFpcStore::purge($code);
        }
    }

    private static function fixtureHtml(string $configJson): string
    {
        $doc = '<!doctype html><html lang="en-US"><head><title>nf</title>'
            . '<script type="application/json" data-weline-runtime-config>{' . $configJson . '}</script>'
            . '</head><body><main>not found</main></body></html>';

        return \str_pad($doc, 1200, ' ');
    }
}
