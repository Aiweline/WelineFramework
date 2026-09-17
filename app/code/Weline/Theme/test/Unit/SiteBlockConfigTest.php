<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\SiteBlockConfig;

final class SiteBlockConfigTest extends TestCase
{
    public function testVisualListConfigRoundTripsArrayAndJsonWithoutInventingItems(): void
    {
        $items = [['title' => '服务 A'], ['title' => '服务 B']];
        self::assertSame($items, SiteBlockConfig::items($items));
        self::assertSame($items, SiteBlockConfig::items(json_encode($items)));
        self::assertSame([], SiteBlockConfig::items('invalid'));
        self::assertSame([], SiteBlockConfig::items(null));
        self::assertSame([$items[0]], SiteBlockConfig::items([$items[0], 'invalid', null]));
    }

    public function testBooleanAndChoicesRespectSavedEditorValues(): void
    {
        self::assertFalse(SiteBlockConfig::boolean('false'));
        self::assertFalse(SiteBlockConfig::boolean('0'));
        self::assertTrue(SiteBlockConfig::boolean('true'));
        self::assertTrue(SiteBlockConfig::boolean(null, true));
        self::assertSame('3', SiteBlockConfig::choice(3, ['2', '3', '4'], '2'));
        self::assertSame('2', SiteBlockConfig::choice('99', ['2', '3', '4'], '2'));
    }

    public function testThemeTokenSizesAndLegacySizesAreAcceptedWithoutCssInjection(): void
    {
        self::assertSame('var(--spacing-lg)', SiteBlockConfig::length('var(--spacing-lg)', '0'));
        self::assertSame('24px', SiteBlockConfig::length('24px', '0'));
        self::assertSame('0', SiteBlockConfig::length('1px;color:red', '0'));
        self::assertSame('0', SiteBlockConfig::length('-20px', '0'));
    }

    public function testTypedMediaAltOverridePreservesPictureSources(): void
    {
        $html = '<picture><source srcset="/photo.webp" type="image/webp"><img src="/photo.jpg" alt="素材原说明" loading="lazy"></picture>';
        $rendered = SiteBlockConfig::mediaHtml($html, '部件专用说明 & "文字"');
        self::assertStringContainsString('srcset="/photo.webp"', $rendered);
        self::assertStringContainsString('src="/photo.jpg"', $rendered);
        $document = \Dom\HTMLDocument::createFromString('<!DOCTYPE html>' . $rendered, 0, 'UTF-8');
        self::assertSame('部件专用说明 & "文字"', $document->getElementsByTagName('img')->item(0)->getAttribute('alt'));
        self::assertSame('picture', $document->getElementsByTagName('img')->item(0)->parentNode->localName);
        self::assertSame($html, SiteBlockConfig::mediaHtml($html, ''));
    }

    public function testLinksAllowRealContactActionsButRejectExecutableSchemes(): void
    {
        // Path-relative stays for <base>; root-relative may be rewritten by getFrontendUrl when bootstrap exists.
        self::assertSame('promotion/deals', \Weline\Theme\Helper\SiteBlockConfig::link('promotion/deals'));
        self::assertSame('mailto:hello@example.com', SiteBlockConfig::link('mailto:hello@example.com'));
        self::assertSame('tel:+86-12345678', SiteBlockConfig::link('tel:+86-12345678'));
        self::assertSame('', SiteBlockConfig::link('javascript:alert(1)'));
        self::assertSame('', SiteBlockConfig::link('mailto:bad%0aBcc:other@example.com'));
        self::assertSame('', SiteBlockConfig::text(['en_US' => 'Not a scalar']));
        $about = SiteBlockConfig::link('/about');
        self::assertNotSame('', $about);
        self::assertTrue(str_contains($about, 'about') || $about === '/about');
    }
}
