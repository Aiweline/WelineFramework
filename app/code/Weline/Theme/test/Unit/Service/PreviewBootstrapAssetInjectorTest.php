<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Url;
use Weline\Framework\View\Template;
use Weline\Theme\Service\PreviewBootstrapAssetInjector;
use Weline\Theme\Service\PreviewTokenService;

/**
 * 预览 Bootstrap 资源只允许出现在预览模式页面；普通店面页 head 不得注入。
 */
final class PreviewBootstrapAssetInjectorTest extends TestCase
{
    private const HTML = '<html><head><title>t</title></head><body>x</body></html>';

    public function testSkipsInjectionOutsidePreviewMode(): void
    {
        $injector = $this->injector(false);
        self::assertSame(self::HTML, $injector->inject(self::HTML));
    }

    public function testInjectsInPreviewMode(): void
    {
        $injector = $this->injector(true);
        $html = $injector->inject(self::HTML);
        self::assertStringContainsString('data-w-preview-bootstrap', $html);
        self::assertStringContainsString('wPreviewBootstrapUrl', $html);
    }

    public function testIsIdempotentWhenAlreadyInjected(): void
    {
        $injector = $this->injector(true);
        $once = $injector->inject(self::HTML);
        self::assertSame($once, $injector->inject($once));
    }

    private function injector(bool $isPreview): PreviewBootstrapAssetInjector
    {
        $template = $this->createMock(Template::class);
        $template->method('fetchTagSource')->willReturn('/static/Weline/Theme/ui/pages/weline-preview-bootstrap.js');

        $url = $this->createMock(Url::class);
        $url->method('getFrontendUrl')->willReturn('/theme/frontend/theme-preview/bootstrap');

        $tokens = $this->createMock(PreviewTokenService::class);
        $tokens->method('isPreviewMode')->willReturn($isPreview);

        return new PreviewBootstrapAssetInjector($template, $url, $tokens);
    }
}
