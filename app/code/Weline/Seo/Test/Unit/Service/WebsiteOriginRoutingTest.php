<?php
declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\SeoWebsiteDirectory;
use Weline\Seo\Service\Protocol\RobotsTxtRenderer;
use Weline\Seo\Service\Protocol\WebsiteProtocolResolver;
use Weline\Seo\Model\WebsiteProtocolConfig;

final class WebsiteOriginRoutingTest extends TestCase
{
    public function testRobotsDeclaresOnlyCanonicalSitemapIncludingBasePath(): void
    {
        $resolver = $this->createMock(WebsiteProtocolResolver::class);
        $resolver->method('listPublicOrigins')->willReturn([
            ['sitemap_url' => 'https://shop.example:9555/cn/sitemap.xml'],
            ['sitemap_url' => 'http://localhost/sitemap.xml'],
            ['sitemap_url' => 'https://shop.example/sitemap.xml'],
        ]);
        $renderer = new RobotsTxtRenderer($resolver, $this->createMock(WebsiteProtocolConfig::class));
        $method = new \ReflectionMethod($renderer, 'sitemapDeclarationUrls');
        self::assertSame(['https://shop.example:9555/cn/sitemap.xml'], $method->invoke($renderer, ['url' => 'https://shop.example:9555/cn/']));
    }

    public function testWebsiteMatchesOnlyRegisteredOriginAndPort(): void
    {
        $directory = new class extends SeoWebsiteDirectory {
            public function listWebsites(): array { return [['website_id' => 0, 'url' => 'https://shop.example']]; }
            public function listPublicOrigins(?array $website = null): array { return [
                ['base_url' => 'https://shop.example'],
                ['base_url' => 'https://sales.example:8443/cn'],
            ]; }
        };
        self::assertSame(0, $directory->matchWebsiteByUrl('https://sales.example:8443/cn/product/1')['website_id'] ?? null);
        self::assertNull($directory->matchWebsiteByUrl('https://sales.example/cn/product/1'));
        self::assertNull($directory->matchWebsiteByUrl('https://www.shop.example/product/1'));
        self::assertNull($directory->matchWebsiteByUrl('https://sales.example:8443/cn-other/product/1'));
    }

    public function testRequestWebsiteBaseKeepsStoreSubpath(): void
    {
        $before = $_SERVER;
        try {
            $_SERVER['WELINE_WEBSITE_URL'] = 'https://shop.example:8443/store/cn/';
            $_SERVER['REQUEST_SCHEME'] = 'https';
            $_SERVER['HTTP_HOST'] = 'shop.example:8443';
            self::assertSame('https://shop.example:8443/store/cn', (new SeoWebsiteDirectory())->currentBaseUrl());
        } finally { $_SERVER = $before; }
    }
}
