<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AiSitePreviewLinkRewriteService;
use GuoLaiRen\PageBuilder\Service\AiSiteVisualUrlService;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Url;

final class AiSitePreviewLinkRewriteServiceTest extends TestCase
{
    public function testRewriteMaterializedPreviewLinksUsesBackendPreviewUrls(): void
    {
        $service = new AiSitePreviewLinkRewriteService(new AiSiteVisualUrlService($this->createUrlMock()));

        $html = $service->rewriteMaterializedPreviewLinks(
            '<html><body>'
            . '<a href="/">Home</a>'
            . '<a href="/about#team">About</a>'
            . '<a href="/services">Services</a>'
            . '<a href="mailto:a@example.com">Mail</a>'
            . '<a href="/download" data-glr-ref="abc">Download</a>'
            . '</body></html>',
            [
                ['page_id' => 10, 'type' => Page::TYPE_HOME, 'handle' => '', 'url' => '/'],
                ['page_id' => 11, 'type' => Page::TYPE_ABOUT, 'handle' => 'about', 'url' => '/about'],
            ],
            169
        );

        self::assertStringContainsString(
            'href="https://backend.test/pagebuilder/backend/preview/full?page_id=10&amp;virtual_theme_id=169"',
            $html
        );
        self::assertStringContainsString(
            'href="https://backend.test/pagebuilder/backend/preview/full?page_id=11&amp;virtual_theme_id=169#team"',
            $html
        );
        self::assertStringContainsString('href="#" data-preview-original-href="/services" data-preview-unresolved-href="/services"', $html);
        self::assertStringContainsString('href="mailto:a@example.com"', $html);
        self::assertStringContainsString('href="/download" data-glr-ref="abc"', $html);
    }

    public function testRewriteVirtualPreviewLinksUsesWorkspacePreviewUrls(): void
    {
        $service = new AiSitePreviewLinkRewriteService(new AiSiteVisualUrlService($this->createUrlMock()));

        $html = $service->rewriteVirtualPreviewLinks(
            '<html><body><a href="/about">About</a></body></html>',
            'pub_abc',
            [
                'home' => ['handle' => ''],
                'about' => ['handle' => 'about'],
            ],
            169
        );

        self::assertStringContainsString(
            'href="https://backend.test/pagebuilder/backend/ai-site-agent/workspace-preview?public_id=pub_abc&amp;page_type=about&amp;virtual_theme_id=169"',
            $html
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
}
