<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use Weline\Framework\Test\TestCore;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\ThemeLayoutVersion;
use Weline\Theme\Service\SlotRendererService;
use Weline\Theme\Service\SlotBoundaryMarkers;
use Weline\Theme\Service\ThemeLayoutService;
use Weline\Theme\Service\ThemeLayoutVersionService;
use Weline\Theme\Service\ThemePageTypeResolver;
use Weline\Theme\Service\ThemePreviewContentRenderer;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\ThemeRuntimeLayoutResolver;

class ThemePreviewContentRendererRestoreTest extends TestCore
{
    public function testDraftPreviewRespectsEmptyRestoreVersionWithoutPublishedFallback(): void
    {
        $layoutService = $this->createMock(ThemeLayoutService::class);
        $layoutService->method('getFullLayout')->willReturnCallback(
            static function (int $themeId, string $pageType, string $status): array {
                if ($status === ThemeLayout::STATUS_PUBLISHED) {
                    return [
                        'content' => [
                            'widgets' => [
                                [
                                    'widget_code' => 'basic/button',
                                    'slot_id' => 'content',
                                    'area' => 'content',
                                ],
                            ],
                        ],
                    ];
                }

                return [];
            }
        );

        $currentVersion = new ThemeLayoutVersion();
        $currentVersion->setVersionType(ThemeLayoutVersion::TYPE_RESTORE);
        $currentVersion->setSnapshotData([]);

        $versionService = new readonly class($currentVersion) extends ThemeLayoutVersionService {
            public function __construct(private ThemeLayoutVersion $currentVersion)
            {
            }

            public function getCurrentVersion(int $themeId, string $pageType, array $identity = []): ?ThemeLayoutVersion
            {
                return $this->currentVersion;
            }
        };

        $slotRenderer = $this->createMock(SlotRendererService::class);
        $slotRenderer->expects($this->never())->method('processSlots');

        $pageTypeResolver = new ThemePageTypeResolver();

        $renderer = new ThemePreviewContentRenderer(
            $layoutService,
            $slotRenderer,
            $pageTypeResolver,
            ObjectManager::getInstance(ThemeRuntimeLayoutResolver::class),
            $versionService,
        );

        $payload = $renderer->build(1, 'codex_restore', ThemeLayout::STATUS_DRAFT);

        $this->assertSame('codex_restore', $payload['page_type']);
        $this->assertSame(ThemeLayout::STATUS_DRAFT, $payload['status']);
        $this->assertSame('', $payload['content']);
        $this->assertSame([], $payload['meta']);
    }

    public function testBuildContentHtmlExcludesHeaderChromeSlots(): void
    {
        $layoutService = $this->createMock(ThemeLayoutService::class);
        $slotRenderer = $this->createMock(SlotRendererService::class);
        $pageTypeResolver = new ThemePageTypeResolver();
        $renderer = new ThemePreviewContentRenderer(
            $layoutService,
            $slotRenderer,
            $pageTypeResolver,
            ObjectManager::getInstance(ThemeRuntimeLayoutResolver::class),
        );

        $method = new \ReflectionMethod(ThemePreviewContentRenderer::class, 'buildContentHtml');
        $method->setAccessible(true);
        $html = (string)$method->invoke(
            $renderer,
            ThemeLayout::PAGE_TYPE_HOME,
            [
                'content',
                'homepage-hero',
                'delivery',
                'search',
                'category-menu',
                'navigation',
                'logo',
                'header-nav-extensions',
                'top-bar-rights',
                'footer-about-links',
                'footer-partner-links',
                'footer-payment-account-links',
                'footer-help-links',
            ],
            [
                'content' => '<div class="widget-wrapper" data-widget-code="ad-banner">banner</div>',
                'homepage-hero' => '<div class="widget-wrapper" data-widget-code="hero-slider">hero</div>',
                'delivery' => '<div class="widget-wrapper" data-widget-code="checkout-delivery-context">delivery</div>',
                'search' => '<div class="widget-wrapper" data-widget-code="header-search">search</div>',
                'category-menu' => '<div class="widget-wrapper" data-widget-code="category-menu">cats</div>',
                'navigation' => '<div class="widget-wrapper" data-widget-code="main-nav">nav</div>',
                'logo' => '<div class="widget-wrapper" data-widget-code="logo">logo</div>',
                'header-nav-extensions' => '<div class="widget-wrapper" data-widget-code="header-blog-link">blog</div>',
                'top-bar-rights' => '<div class="widget-wrapper" data-widget-code="help-center-link">help</div>',
                'footer-about-links' => '<div class="widget-wrapper" data-widget-code="footer-blog-link">footer-blog</div>',
                'footer-partner-links' => '<div class="widget-wrapper" data-widget-code="footer-promote-link">promote</div>',
                'footer-payment-account-links' => '<div class="widget-wrapper" data-widget-code="footer-payment-methods-link">pay</div>',
                'footer-help-links' => '<div class="widget-wrapper" data-widget-code="footer-my-account-link">account</div>',
            ],
            [],
        );

        $this->assertStringContainsString('ad-banner', $html);
        $this->assertStringContainsString('hero-slider', $html);
        $this->assertStringNotContainsString('checkout-delivery-context', $html);
        $this->assertStringNotContainsString('header-search', $html);
        $this->assertStringNotContainsString('category-menu', $html);
        $this->assertStringNotContainsString('main-nav', $html);
        $this->assertStringNotContainsString('data-widget-code="logo"', $html);
        $this->assertStringNotContainsString('header-blog-link', $html);
        $this->assertStringNotContainsString('help-center-link', $html);
        $this->assertStringNotContainsString('footer-blog-link', $html);
        $this->assertStringNotContainsString('footer-promote-link', $html);
        $this->assertStringNotContainsString('footer-payment-methods-link', $html);
        $this->assertStringNotContainsString('footer-my-account-link', $html);
    }

    public function testExtractSlotHtmlPreservesFeaturedAmazonMarkupWhenPriorSlotHasScriptLt(): void
    {
        $renderer = new ThemePreviewContentRenderer(
            $this->createMock(ThemeLayoutService::class),
            $this->createMock(SlotRendererService::class),
            new ThemePageTypeResolver(),
            ObjectManager::getInstance(ThemeRuntimeLayoutResolver::class),
        );

        $promo = '<section class="wc-theme_widget_promo_banner"><script>'
            . '(function () { var hoursSinceClosed = 1; if (hoursSinceClosed < 24) {} })();'
            . '</script></section>';
        $featured = '<div class="weline-template-widget widget-wrapper" data-weline-template-widget="1">'
            . '<link rel="stylesheet" href="/Weline/Theme/view/statics/css/widgets/amazon-product-card.css" data-no-extract="true">'
            . '<section class="wc-theme_widget_featured_products weline-amz-card-widget">'
            . '<div class="products-grid columns-4">'
            . '<article class="product-card" data-product-id="28"><div class="product-info">ok</div></article>'
            . '</div></section></div>';
        $html = SlotBoundaryMarkers::open('homepage-promo')
            . '<div data-preview-slot="homepage-promo" data-wslot="homepage-promo">' . $promo . '</div>'
            . SlotBoundaryMarkers::close('homepage-promo')
            . SlotBoundaryMarkers::open('homepage-featured')
            . '<div data-preview-slot="homepage-featured" data-wslot="homepage-featured">' . $featured . '</div>'
            . SlotBoundaryMarkers::close('homepage-featured');

        $method = new \ReflectionMethod(ThemePreviewContentRenderer::class, 'extractSlotHtml');
        $method->setAccessible(true);
        /** @var array<string,string> $slotHtml */
        $slotHtml = $method->invoke($renderer, $html, ['homepage-promo', 'homepage-featured']);

        $featuredHtml = (string)($slotHtml['homepage-featured'] ?? '');
        $this->assertStringContainsString('weline-amz-card-widget', $featuredHtml);
        $this->assertStringContainsString('amazon-product-card.css', $featuredHtml);
        $this->assertStringContainsString('data-product-id="28"', $featuredHtml);
        $this->assertStringNotContainsString('w-product-labels product-badges">', ltrim($featuredHtml));
    }
}
