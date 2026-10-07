<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Protocol;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Model\SitemapUrl;
use Weline\Seo\Service\Protocol\SitemapProtocolRenderer;
use Weline\Seo\Service\Protocol\WebsiteProtocolResolver;
use Weline\Seo\Service\SeoWebsiteDirectory;
use Weline\Seo\Service\Sitemap\SitemapXmlExtensionRenderer;
use Weline\Seo\Service\StoreModeSeoHardGate;
use Weline\Seo\Service\WebSitemapData;

/**
 * Protocol serves only current stable shard files; legacy hashed names 404 (no 301).
 */
final class SitemapLegacyHashedRedirectContractTest extends TestCase
{
    private string $websiteCode = '';

    private string $targetDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->websiteCode = 'ut-redir-sm-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $this->targetDir = BP . '/' . WebSitemapData::SITEMAP_DIR . '/' . $this->websiteCode . '/canonical';
        if (!is_dir($this->targetDir) && !mkdir($this->targetDir, 0755, true) && !is_dir($this->targetDir)) {
            self::fail('unable to create sitemap fixture dir');
        }
        $stable = 'sitemap_weline-blog-blog-article_bn-bd_1.xml';
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
            . "  <url>\n"
            . "    <loc>https://shop.example.test/bn_BD/blog/a</loc>\n"
            . "  </url>\n"
            . '</urlset>';
        file_put_contents($this->targetDir . '/' . $stable, $xml);
    }

    protected function tearDown(): void
    {
        $root = BP . '/' . WebSitemapData::SITEMAP_DIR . '/' . $this->websiteCode;
        if (is_dir($root)) {
            foreach (scandir($root . '/canonical') ?: [] as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                @unlink($root . '/canonical/' . $item);
            }
            @rmdir($root . '/canonical');
            @rmdir($root);
        }
        parent::tearDown();
    }

    public function testLegacyHashedFilenameReturns404EvenWhenStableExists(): void
    {
        $renderer = $this->makeRenderer('https://www.changanhanfu.com');
        $legacy = 'sitemap_weline-blog-blog-article_bn-bd_1_923cc59de9f9.xml';
        $result = $renderer->renderFile($this->websiteCode, 'canonical', $legacy);

        self::assertSame(404, $result['status']);
        self::assertStringContainsString('Sitemap 文件不存在', $result['body']);
        self::assertArrayNotHasKey('headers', $result);
    }

    public function testStableSlugFilenameServesXml(): void
    {
        $renderer = $this->makeRenderer('https://www.changanhanfu.com');
        $stable = 'sitemap_weline-blog-blog-article_bn-bd_1.xml';
        $result = $renderer->renderFile($this->websiteCode, 'canonical', $stable);

        self::assertSame(200, $result['status']);
        self::assertStringContainsString('<urlset', $result['body']);
        self::assertStringContainsString('https://www.changanhanfu.com/bn_BD/blog/a', $result['body']);
    }

    private function makeRenderer(string $publicBase): SitemapProtocolRenderer
    {
        $website = [
            'id' => 0,
            'website_id' => 0,
            'code' => $this->websiteCode,
            'url' => $publicBase,
        ];

        $resolver = $this->createMock(WebsiteProtocolResolver::class);
        $resolver->method('currentWebsite')->willReturn($website);

        $directory = $this->createMock(SeoWebsiteDirectory::class);
        $directory->method('listWebsites')->willReturn([$website]);
        $directory->method('requestPublicBaseUrlForWebsite')->willReturn($publicBase);
        $directory->method('rewriteAllOriginsInXml')->willReturnCallback(
            static function (string $xml, string $baseUrl): string {
                return str_replace('https://shop.example.test', rtrim($baseUrl, '/'), $xml);
            }
        );
        $directory->method('rewriteWebsiteOriginUrl')->willReturnCallback(
            static fn (string $url, string $baseUrl): string => $url
        );

        return new SitemapProtocolRenderer(
            $resolver,
            $this->createMock(SitemapUrl::class),
            new SitemapXmlExtensionRenderer(),
            $directory,
            new StoreModeSeoHardGate(),
        );
    }
}
