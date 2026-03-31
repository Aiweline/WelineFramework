<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteVisualUrlService;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Url;

class AiSiteVisualUrlServiceTest extends TestCase
{
    public function testResolveUrlsIncludesThemeIdWhenAvailable(): void
    {
        $service = new AiSiteVisualUrlService($this->createUrlMock());

        $urls = $service->resolveUrls(123, 456);

        $this->assertSame(
            'https://backend.test/pagebuilder/backend/preview/full?page_id=123&virtual_theme_id=456',
            $urls['preview_full_url']
        );
        $this->assertSame(
            'https://backend.test/pagebuilder/backend/preview/full?page_id=123&visual_editor=1&virtual_theme_id=456',
            $urls['visual_preview_url']
        );
        $this->assertSame(
            'https://backend.test/pagebuilder/backend/page/edit?id=123&virtual_theme_id=456',
            $urls['visual_edit_url']
        );
    }

    public function testResolveUrlsOmitsThemeIdWhenNotAvailable(): void
    {
        $service = new AiSiteVisualUrlService($this->createUrlMock());

        $urls = $service->resolveUrls(88);

        $this->assertSame(
            'https://backend.test/pagebuilder/backend/preview/full?page_id=88',
            $urls['preview_full_url']
        );
        $this->assertSame(
            'https://backend.test/pagebuilder/backend/preview/full?page_id=88&visual_editor=1',
            $urls['visual_preview_url']
        );
        $this->assertSame(
            'https://backend.test/pagebuilder/backend/page/edit?id=88',
            $urls['visual_edit_url']
        );
    }

    public function testResolveUrlsReturnsEmptyValuesWhenPreviewPageIsMissing(): void
    {
        $service = new AiSiteVisualUrlService($this->createUrlMock());

        $this->assertSame([
            'preview_full_url' => '',
            'visual_preview_url' => '',
            'visual_edit_url' => '',
        ], $service->resolveUrls(0, 456));
    }

    public function testResolveVirtualUrlsIncludesThemeIdWhenAvailable(): void
    {
        $service = new AiSiteVisualUrlService($this->createUrlMock());

        $urls = $service->resolveVirtualUrls('pub_abc', 'home', 456);

        $this->assertSame(
            'https://backend.test/pagebuilder/backend/preview/full?public_id=pub_abc&page_type=home&virtual_theme_id=456',
            $urls['preview_full_url']
        );
        $this->assertSame(
            'https://backend.test/pagebuilder/backend/preview/full?public_id=pub_abc&page_type=home&visual_editor=1&virtual_theme_id=456',
            $urls['visual_preview_url']
        );
        $this->assertSame(
            'https://backend.test/pagebuilder/backend/page/virtual-edit?public_id=pub_abc&page_type=home&virtual_theme_id=456',
            $urls['visual_edit_url']
        );
    }

    public function testResolveVirtualUrlsOmitsThemeIdWhenNotAvailable(): void
    {
        $service = new AiSiteVisualUrlService($this->createUrlMock());

        $urls = $service->resolveVirtualUrls('pub_abc', 'home');

        $this->assertSame(
            'https://backend.test/pagebuilder/backend/preview/full?public_id=pub_abc&page_type=home',
            $urls['preview_full_url']
        );
        $this->assertSame(
            'https://backend.test/pagebuilder/backend/preview/full?public_id=pub_abc&page_type=home&visual_editor=1',
            $urls['visual_preview_url']
        );
        $this->assertSame(
            'https://backend.test/pagebuilder/backend/page/virtual-edit?public_id=pub_abc&page_type=home',
            $urls['visual_edit_url']
        );
    }

    public function testResolveVirtualUrlsReturnsEmptyValuesWhenRequiredParamsMissing(): void
    {
        $service = new AiSiteVisualUrlService($this->createUrlMock());

        $this->assertSame([
            'preview_full_url' => '',
            'visual_preview_url' => '',
            'visual_edit_url' => '',
        ], $service->resolveVirtualUrls('', 'home', 456));

        $this->assertSame([
            'preview_full_url' => '',
            'visual_preview_url' => '',
            'visual_edit_url' => '',
        ], $service->resolveVirtualUrls('pub_abc', '', 456));
    }

    private function createUrlMock(): Url
    {
        $url = $this->createMock(Url::class);
        $url->method('getBackendUrl')->willReturnCallback(
            static function (string $path, array $params = []): string {
                $query = $params === [] ? '' : ('?' . \http_build_query($params));
                return 'https://backend.test/' . \ltrim($path, '/') . $query;
            }
        );

        return $url;
    }
}
