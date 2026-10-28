<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\WidgetAssetHtmlPlacement;

/**
 * Regression guard for layout-pack-noscript-anchor-v1.
 *
 * Head CSS packs are emitted as `<link rel="preload" ...><noscript><link rel="stylesheet"
 * data-weline-theme-head-pack="..."></noscript>`. Both copies carry the chrome marker, so the
 * "after theme chrome" heuristic must never anchor the big layout pack inside <noscript>:
 * JS-enabled browsers parse head <noscript> content as inert text and never load it.
 */
final class WidgetAssetHeadNoscriptAnchorTest extends TestCase
{
    private const LAYOUT_TAG = '<link data-weline-layout-pack="1" data-weline-widget-asset="layout" rel="stylesheet" href="/static/widget-assets/layout.css" data-weline-source-position="head">';

    private function placer(): WidgetAssetHtmlPlacement
    {
        return new WidgetAssetHtmlPlacement();
    }

    public function testLayoutPackIsNeverAnchoredInsideNoscriptFallback(): void
    {
        $html = '<html><head>'
            . '<link rel="stylesheet" data-weline-theme-head-pack="tokens" href="/tokens.css">'
            . '<link rel="preload" as="style" href="/storefront.css" data-weline-theme-head-pack="storefront" onload="this.onload=null;this.rel=\'stylesheet\'">'
            . '<noscript><link rel="stylesheet" href="/storefront.css" data-weline-theme-head-pack="storefront"></noscript>'
            . '</head><body><main>x</main></body></html>';

        $out = $this->placer()->inject($html, self::LAYOUT_TAG);

        $packAt = strpos($out, 'data-weline-layout-pack');
        $noscriptOpen = strpos($out, '<noscript>');
        $noscriptClose = strpos($out, '</noscript>');

        self::assertNotFalse($packAt, 'layout pack must be placed');
        self::assertSame(1, substr_count($out, 'data-weline-layout-pack'));
        self::assertNotFalse($noscriptOpen);
        self::assertNotFalse($noscriptClose);
        self::assertFalse(
            $packAt > $noscriptOpen && $packAt < $noscriptClose,
            'layout pack must stay outside <noscript> (inert for JS browsers): '
            . substr($out, 0, 400)
        );
        self::assertGreaterThan($noscriptClose, $packAt);
        self::assertLessThan(strpos($out, '</head>'), $packAt);
    }

    public function testLayoutPackStillFollowsChromeLinksWithoutNoscript(): void
    {
        $html = '<html><head>'
            . '<link rel="stylesheet" data-weline-theme-head-pack="tokens" href="/tokens.css">'
            . '</head><body><main>x</main></body></html>';

        $out = $this->placer()->inject($html, self::LAYOUT_TAG);

        $packAt = strpos($out, 'data-weline-layout-pack');
        self::assertNotFalse($packAt);
        self::assertGreaterThan(strpos($out, 'data-weline-theme-head-pack'), $packAt);
        self::assertLessThan(strpos($out, '</head>'), $packAt);
    }

    public function testExplicitHeadSlotStillWins(): void
    {
        $html = '<html><head>'
            . '<link rel="stylesheet" data-weline-theme-head-pack="tokens" href="/tokens.css">'
            . '<noscript><link rel="stylesheet" href="/storefront.css" data-weline-theme-head-pack="storefront"></noscript>'
            . '<!--weline:head-layout-assets-->'
            . '</head><body><main>x</main></body></html>';

        $out = $this->placer()->inject($html, self::LAYOUT_TAG);

        self::assertStringNotContainsString('weline:head-layout-assets', $out);
        $packAt = strpos($out, 'data-weline-layout-pack');
        self::assertNotFalse($packAt);
        self::assertGreaterThan(strpos($out, '</noscript>'), $packAt);
        self::assertLessThan(strpos($out, '</head>'), $packAt);
    }
}
