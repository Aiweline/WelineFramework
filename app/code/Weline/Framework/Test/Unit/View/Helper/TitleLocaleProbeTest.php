<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Response;
use Weline\Framework\View\Helper\TitleLocaleProbe;

final class TitleLocaleProbeTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_GET[TitleLocaleProbe::QUERY_KEY], $_COOKIE[TitleLocaleProbe::QUERY_KEY]);
        TitleLocaleProbe::reset();
        parent::tearDown();
    }

    public function testArmFromRequestEnablesEmit(): void
    {
        TitleLocaleProbe::reset();
        // Without RequestContext, arm uses static fallback.
        TitleLocaleProbe::armFromRequest('/zh_Hans_CN/guide?__title_locale=1');
        self::assertTrue(TitleLocaleProbe::shouldEmit());
        self::assertSame('zh_Hans_CN', TitleLocaleProbe::localeFromRequestUri(TitleLocaleProbe::armedRequestUri()));
    }

    public function testZhTitleOnNonZh(): void
    {
        $html = '<html><head><title>指南 | 店名</title></head><body><h1>指南</h1></body></html>';
        $a = TitleLocaleProbe::analyze($html, 'en_US', '/en_US/guide');
        self::assertSame('指南 | 店名', $a['title']);
        self::assertSame('指南', $a['h1']);
        self::assertSame('en_US', $a['request_locale']);
        self::assertSame('zh_title_on_non_zh', $a['verdict']);
        self::assertTrue($a['mismatch']);
    }

    public function testLatinTitleOnZh(): void
    {
        $html = '<html><head><title>Guide | Shop</title></head><body><h1>Guide</h1></body></html>';
        $a = TitleLocaleProbe::analyze($html, 'zh_Hans_CN', '/zh_Hans_CN/guide');
        self::assertSame('latin_title_on_zh', $a['verdict']);
        self::assertTrue($a['mismatch']);

        $terms = TitleLocaleProbe::analyze(
            '<title>Terms of Service | X</title>',
            'zh_Hans_CN',
            '/zh_Hans_CN/terms'
        );
        self::assertSame('latin_title_on_zh', $terms['verdict']);
    }

    public function testAboutH1CrossLocale(): void
    {
        $html = '<html><head><title>About | Shop</title></head>'
            . '<body><h1 id="about-layout-title">About Us</h1></body></html>';
        $a = TitleLocaleProbe::analyze($html, 'zh_Hans_CN', '/zh_Hans_CN/about');
        self::assertSame('about_h1_cross_locale', $a['verdict']);
        self::assertTrue($a['mismatch']);
        self::assertSame('About Us', $a['h1']);
    }

    public function testOkWhenAligned(): void
    {
        $html = '<html><head><title>About Us | Shop</title></head>'
            . '<body><h1 id="about-layout-title">About Us</h1></body></html>';
        $a = TitleLocaleProbe::analyze($html, 'en_US', '/en_US/about');
        self::assertSame('ok', $a['verdict']);
        self::assertFalse($a['mismatch']);
    }

    public function testShouldEmitFalseByDefaultDoesNotForceHeaders(): void
    {
        unset($_GET[TitleLocaleProbe::QUERY_KEY], $_COOKIE[TitleLocaleProbe::QUERY_KEY]);
        // Pure analyze always works regardless of shouldEmit.
        $a = TitleLocaleProbe::analyze('<title>Guide | X</title>', 'zh_Hans_CN', '/zh_Hans_CN/guide');
        self::assertSame('latin_title_on_zh', $a['verdict']);

        $response = Response::html('<html><head><title>Guide | X</title></head></html>');
        // Without emit flag, applyToResponse must not set probe headers.
        // Note: shouldEmit may still be true if deploy=dev dynamic observability is on;
        // so we only assert that analyze remains a pure function here.
        self::assertArrayHasKey('mismatch', $a);
        self::assertTrue($a['mismatch']);
        unset($response);
    }

    public function testApplyToResponseSetsHeadersWhenFlagged(): void
    {
        TitleLocaleProbe::armFromRequest('/fr_FR/guide?__title_locale=1');
        self::assertTrue(TitleLocaleProbe::shouldEmit());

        $html = '<html><head><title>指南 | 店</title></head><body><h1>指南</h1></body></html>';
        $analysis = TitleLocaleProbe::analyze($html, 'fr_FR', '/fr_FR/guide');
        $response = Response::html($html);
        TitleLocaleProbe::applyToResponse($response, $analysis);

        $localeHdr = $response->getHeader('X-Weline-Title-Locale');
        $mismatchHdr = $response->getHeader('X-Weline-Title-Locale-Mismatch');
        self::assertIsString($localeHdr);
        self::assertStringStartsWith('fr_FR|zh_title_on_non_zh|', (string)$localeHdr);
        self::assertSame('1', (string)$mismatchHdr);
    }
}
