<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteVisualUrlService;
use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
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
            'https://backend.test/pagebuilder/backend/ai-site-agent/workspace-preview?public_id=pub_abc&page_type=home&preview=1&virtual_theme_id=456',
            $urls['preview_full_url']
        );
        $this->assertSame(
            'https://backend.test/pagebuilder/backend/ai-site-agent/workspace-preview?public_id=pub_abc&page_type=home&preview=1&visual_editor=1&virtual_theme_id=456',
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
            'https://backend.test/pagebuilder/backend/ai-site-agent/workspace-preview?public_id=pub_abc&page_type=home&preview=1',
            $urls['preview_full_url']
        );
        $this->assertSame(
            'https://backend.test/pagebuilder/backend/ai-site-agent/workspace-preview?public_id=pub_abc&page_type=home&preview=1&visual_editor=1',
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

    public function testResolveVirtualUrlsPreservesCurrentBackendLocalePathPrefix(): void
    {
        $backendPrefix = \trim((string)Env::getAreaRoutePrefix('backend'), '/');
        $previousRequestUri = $_SERVER['REQUEST_URI'] ?? null;
        $previousFullRequestUri = $_SERVER['WELINE_FULL_REQUEST_URI'] ?? null;
        $_SERVER['REQUEST_URI'] = '/' . $backendPrefix . '/CNY/zh_Hans_CN/pagebuilder/backend/ai-site-agent/workspace-preview';
        unset($_SERVER['WELINE_FULL_REQUEST_URI']);

        try {
            $service = new AiSiteVisualUrlService($this->createUrlMockWithBackendPrefix($backendPrefix));
            $urls = $service->resolveVirtualUrls('pub_abc', 'contact_page', 456);

            self::assertStringContainsString(
                '/' . $backendPrefix . '/CNY/zh_Hans_CN/pagebuilder/backend/ai-site-agent/workspace-preview',
                $urls['visual_preview_url']
            );
            self::assertStringContainsString(
                '/' . $backendPrefix . '/CNY/zh_Hans_CN/pagebuilder/backend/page/virtual-edit',
                $urls['visual_edit_url']
            );
        } finally {
            if ($previousRequestUri === null) {
                unset($_SERVER['REQUEST_URI']);
            } else {
                $_SERVER['REQUEST_URI'] = $previousRequestUri;
            }
            if ($previousFullRequestUri === null) {
                unset($_SERVER['WELINE_FULL_REQUEST_URI']);
            } else {
                $_SERVER['WELINE_FULL_REQUEST_URI'] = $previousFullRequestUri;
            }
        }
    }

    public function testNormalizeUrlsPrefersCurrentRequestOriginOverPendingBusinessDomain(): void
    {
        $service = new AiSiteVisualUrlService($this->createUrlMock());

        $urls = $service->normalizeUrlsToLocalBase(
            [
                'preview_full_url' => 'https://teenpattipatti-com-c2ccbb.weline.test/pagebuilder/backend/ai-site-agent/workspace-preview?public_id=pub_abc&page_type=home&preview=1',
                'visual_preview_url' => 'https://backend.test/pagebuilder/backend/ai-site-agent/workspace-preview?public_id=pub_abc&page_type=home&preview=1&visual_editor=1',
                'visual_edit_url' => 'https://backend.test/pagebuilder/backend/page/virtual-edit?public_id=pub_abc&page_type=home',
            ],
            [
                'current_request_origin' => 'https://pre.qipaisaas.com:9981',
                'scope' => [
                    'target_domain' => 'teenpattipatti-com-c2ccbb.weline.test',
                ],
            ]
        );

        self::assertSame(
            'https://pre.qipaisaas.com:9981/pagebuilder/backend/ai-site-agent/workspace-preview?public_id=pub_abc&page_type=home&preview=1',
            $urls['preview_full_url']
        );
        self::assertSame(
            'https://pre.qipaisaas.com:9981/pagebuilder/backend/ai-site-agent/workspace-preview?public_id=pub_abc&page_type=home&preview=1&visual_editor=1',
            $urls['visual_preview_url']
        );
        self::assertSame(
            'https://pre.qipaisaas.com:9981/pagebuilder/backend/page/virtual-edit?public_id=pub_abc&page_type=home',
            $urls['visual_edit_url']
        );
    }

    public function testNormalizeUrlsStillUsesLocalPreviewDomainWhenNoRequestOriginExists(): void
    {
        $service = new AiSiteVisualUrlService($this->createUrlMock());

        $urls = $service->normalizeUrlsToLocalBase(
            [
                'preview_full_url' => 'https://backend.test/pagebuilder/backend/ai-site-agent/workspace-preview?public_id=pub_abc&page_type=home&preview=1',
                'visual_preview_url' => 'https://backend.test/pagebuilder/backend/ai-site-agent/workspace-preview?public_id=pub_abc&page_type=home&preview=1&visual_editor=1',
                'visual_edit_url' => 'https://backend.test/pagebuilder/backend/page/virtual-edit?public_id=pub_abc&page_type=home',
            ],
            [
                'scope' => [
                    'target_domain' => 'demo.weline.test',
                ],
            ]
        );

        self::assertSame(
            'https://demo.weline.test/pagebuilder/backend/ai-site-agent/workspace-preview?public_id=pub_abc&page_type=home&preview=1',
            $urls['preview_full_url']
        );
        self::assertSame(
            'https://demo.weline.test/pagebuilder/backend/ai-site-agent/workspace-preview?public_id=pub_abc&page_type=home&preview=1&visual_editor=1',
            $urls['visual_preview_url']
        );
        self::assertSame(
            'https://demo.weline.test/pagebuilder/backend/page/virtual-edit?public_id=pub_abc&page_type=home',
            $urls['visual_edit_url']
        );
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

    private function createUrlMockWithBackendPrefix(string $backendPrefix): Url
    {
        $url = $this->createMock(Url::class);
        $url->method('getBackendUrl')->willReturnCallback(
            static function (string $path, array $params = []) use ($backendPrefix): string {
                $query = $params === [] ? '' : ('?' . \http_build_query($params));
                return 'https://backend.test/' . \trim($backendPrefix, '/') . '/' . \ltrim($path, '/') . $query;
            }
        );

        return $url;
    }
}
