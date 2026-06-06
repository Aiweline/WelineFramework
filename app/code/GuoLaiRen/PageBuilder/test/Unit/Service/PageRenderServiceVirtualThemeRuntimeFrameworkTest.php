<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Model\Page\LocalDescription;
use GuoLaiRen\PageBuilder\Model\Style;
use GuoLaiRen\PageBuilder\Service\LayoutAssembler;
use GuoLaiRen\PageBuilder\Service\LayoutOwnerResolver;
use GuoLaiRen\PageBuilder\Service\PageRenderService;
use PHPUnit\Framework\TestCase;

final class PageRenderServiceVirtualThemeRuntimeFrameworkTest extends TestCase
{
    public function testRuntimeFrameworkFlattensOldNestedResponsiveMediaAndAddsActionBridge(): void
    {
        $service = new PageRenderService(
            $this->createStub(LayoutAssembler::class),
            $this->createStub(LayoutOwnerResolver::class),
            $this->createStub(Page::class),
            $this->createStub(Style::class),
            $this->createStub(LocalDescription::class),
        );

        $apply = new \ReflectionMethod($service, 'applyVirtualThemeGeneratedComponentRuntimeFramework');
        $apply->setAccessible(true);

        $html = <<<'HTML'
<style>
@media (max-width: 768px) {
    #content-demo .content-demo-card {
        padding: 16px;
    }
    @media (max-width: 768px){#content-demo .pb-c-inner{display:block;}}@media (max-width: 420px){#content-demo .pb-c-root{padding:20px;}}
}
</style>
<section id="content-demo"><button type="button" class="pb-c-cta" data-pb-ai-action="primary_cta">Baixar APK</button></section>
HTML;

        $result = (string)$apply->invoke($service, $html);

        self::assertStringNotContainsString("\n    @media (max-width: 768px)", $result);
        self::assertStringContainsString("\n@media (max-width: 768px){#content-demo .pb-c-inner{display:block;}}", $result);
        self::assertStringContainsString("CustomEvent('pb:cta'", $result);
        self::assertStringContainsString('data-pb-ai-bound', $result);
    }

    public function testRuntimeFrameworkAddsCompactMobileHeaderGuardForAiHeader(): void
    {
        $service = new PageRenderService(
            $this->createStub(LayoutAssembler::class),
            $this->createStub(LayoutOwnerResolver::class),
            $this->createStub(Page::class),
            $this->createStub(Style::class),
            $this->createStub(LocalDescription::class),
        );

        $apply = new \ReflectionMethod($service, 'applyVirtualThemeGeneratedComponentRuntimeFramework');
        $apply->setAccessible(true);

        $html = <<<'HTML'
<style>
@media (max-width: 992px) {
    #header-demo .header-demo-nav {
        position: fixed;
        bottom: 0;
        transform: translateX(100%);
    }
}
</style>
<header id="header-demo"><nav class="header-demo-nav">首页</nav></header>
HTML;

        $result = (string)$apply->invoke($service, $html, 'header/ai-site-header');

        self::assertStringContainsString('data-pb-ai-header-mobile-compact="1"', $result);
        self::assertStringContainsString('bottom: auto !important;', $result);
        self::assertStringContainsString('max-height: min(70vh, 360px) !important;', $result);
        self::assertStringContainsString('overflow-wrap: anywhere !important;', $result);
        self::assertStringContainsString('white-space: normal !important;', $result);
        self::assertStringContainsString('max-width: 100vw !important;', $result);

        $contentResult = (string)$apply->invoke($service, $html, 'content/hero');
        self::assertStringNotContainsString('data-pb-ai-header-mobile-compact="1"', $contentResult);
    }

    public function testStaticConfigOverridesDoNotReplaceBrandFromNavigationLabels(): void
    {
        $service = new PageRenderService(
            $this->createStub(LayoutAssembler::class),
            $this->createStub(LayoutOwnerResolver::class),
            $this->createStub(Page::class),
            $this->createStub(Style::class),
            $this->createStub(LocalDescription::class),
        );

        $apply = new \ReflectionMethod($service, 'applyVirtualThemeConfigOverridesToStaticHtml');
        $apply->setAccessible(true);

        $html = '<header><a class="logo"><span>Card Room Download Hub</span></a><nav>'
            . '<a>Card Room Download Hub</a><a>Card Room Download Hub</a><a>Card Room Download Hub</a>'
            . '</nav></header><main><h1>Prior hero headline</h1></main>';
        $referenceConfig = [
            'logo.text' => 'Card Room Download Hub',
            'navigation.items' => "Card Room Download Hub=>/\nCard Room Download Hub=>/about\nCard Room Download Hub=>/contact",
            'nav_items' => [
                ['text' => 'Card Room Download Hub', 'href' => '/'],
                ['text' => 'Card Room Download Hub', 'href' => '/about'],
                ['text' => 'Card Room Download Hub', 'href' => '/contact'],
            ],
            'content.title' => 'Prior hero headline',
        ];
        $currentConfig = [
            'logo.text' => 'Card Room Download Hub',
            'navigation.items' => "Home=>/\nAbout=>/about\nContact=>/contact",
            'nav_items' => [
                ['text' => 'Home', 'href' => '/'],
                ['text' => 'About', 'href' => '/about'],
                ['text' => 'Contact', 'href' => '/contact'],
            ],
            'content.title' => 'New hero headline',
        ];

        $result = (string)$apply->invoke($service, $html, $referenceConfig, $currentConfig);

        self::assertStringContainsString('<span>Card Room Download Hub</span>', $result);
        self::assertStringNotContainsString('<span>Contact</span>', $result);
        self::assertSame(4, \substr_count($result, 'Card Room Download Hub'));
        self::assertStringContainsString('<h1>New hero headline</h1>', $result);
    }

    public function testVisualModeDocumentLanguageUsesRenderLocale(): void
    {
        $layoutOwnerResolver = $this->createMock(LayoutOwnerResolver::class);
        $layoutOwnerResolver->method('resolveLayoutOwnerPageId')->willReturn(0);

        $service = new PageRenderService(
            $this->createStub(LayoutAssembler::class),
            $layoutOwnerResolver,
            $this->createStub(Page::class),
            $this->createStub(Style::class),
            $this->createStub(LocalDescription::class),
        );

        $assign = new \ReflectionMethod($service, 'assign');
        $assign->setAccessible(true);
        $assign->invoke($service, 'lang_local', 'pt_BR');
        $assign->invoke($service, 'current_locale', 'pt_BR');
        $assign->invoke($service, 'lang', 'pt_BR');

        $page = $this->createStub(Page::class);
        $page->method('getId')->willReturn(0);
        $page->method('getData')->willReturnCallback(
            static fn (string $key = '') => $key === 'title' ? 'Inicio' : null
        );

        $render = new \ReflectionMethod($service, 'renderVisualMode');
        $render->setAccessible(true);

        $html = (string)$render->invoke($service, '', '<main>Conteudo</main>', '', '', '', $page, 'default');

        self::assertStringContainsString('<html lang="pt-BR" dir="ltr">', $html);
        self::assertStringNotContainsString('<html lang="zh-CN">', $html);
    }

    public function testStandardDocumentDirectionUsesRenderLocale(): void
    {
        $service = new PageRenderService(
            $this->createStub(LayoutAssembler::class),
            $this->createStub(LayoutOwnerResolver::class),
            $this->createStub(Page::class),
            $this->createStub(Style::class),
            $this->createStub(LocalDescription::class),
        );

        $assign = new \ReflectionMethod($service, 'assign');
        $assign->setAccessible(true);
        $assign->invoke($service, 'lang_local', 'ar_SA');
        $assign->invoke($service, 'current_locale', 'ar_SA');
        $assign->invoke($service, 'lang', 'ar_SA');

        $page = $this->createStub(Page::class);
        $page->method('getData')->willReturn(null);

        $render = new \ReflectionMethod($service, 'renderStandardDocument');
        $render->setAccessible(true);

        $html = (string)$render->invoke($service, '', '<main>مرحبا</main>', '', '', $page);

        self::assertStringContainsString('<html lang="ar-SA" dir="rtl">', $html);
        self::assertStringContainsString('<body dir="rtl">', $html);
    }
}
