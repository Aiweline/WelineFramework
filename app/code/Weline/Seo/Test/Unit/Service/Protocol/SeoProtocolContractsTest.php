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
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllSeoProtocolContractsPass().
 */
final class SeoProtocolContractsTest extends TestCase
{
    public function testAllSeoProtocolContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'SitemapLegacyHashedRedirect::testLegacyHashedFilenameReturns404EvenWhenStableExists' => function () {
            $this->setUp_SitemapLegacyHashedRedirect();
            try {
                $this->assert_SitemapLegacyHashedRedirect_testLegacyHashedFilenameReturns404EvenWhenStableExists();
            } finally {
                $this->tearDown_SitemapLegacyHashedRedirect();
            }
        },
            'SitemapLegacyHashedRedirect::testStableSlugFilenameServesXml' => function () {
            $this->setUp_SitemapLegacyHashedRedirect();
            try {
                $this->assert_SitemapLegacyHashedRedirect_testStableSlugFilenameServesXml();
            } finally {
                $this->tearDown_SitemapLegacyHashedRedirect();
            }
        },
        ];
    }

private string $websiteCode_SitemapLegacyHashedRedirect = '';

private string $targetDir_SitemapLegacyHashedRedirect = '';

private function setUp_SitemapLegacyHashedRedirect(): void
{
    parent::setUp();
    $this->websiteCode_SitemapLegacyHashedRedirect = 'ut-redir-sm-' . substr(bin2hex(random_bytes(4)), 0, 8);
    $this->targetDir_SitemapLegacyHashedRedirect = BP . '/' . WebSitemapData::SITEMAP_DIR . '/' . $this->websiteCode_SitemapLegacyHashedRedirect . '/canonical';
    if (!is_dir($this->targetDir_SitemapLegacyHashedRedirect) && !mkdir($this->targetDir_SitemapLegacyHashedRedirect, 0755, true) && !is_dir($this->targetDir_SitemapLegacyHashedRedirect)) {
        self::fail('unable to create sitemap fixture dir');
    }
    $stable = 'sitemap_weline-blog-blog-article_bn-bd_1.xml';
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n" . "  <url>\n" . "    <loc>https://shop.example.test/bn_BD/blog/a</loc>\n" . "  </url>\n" . '</urlset>';
    file_put_contents($this->targetDir_SitemapLegacyHashedRedirect . '/' . $stable, $xml);
}

private function tearDown_SitemapLegacyHashedRedirect(): void
{
    $root = BP . '/' . WebSitemapData::SITEMAP_DIR . '/' . $this->websiteCode_SitemapLegacyHashedRedirect;
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

private function assert_SitemapLegacyHashedRedirect_testLegacyHashedFilenameReturns404EvenWhenStableExists(): void
{
    $renderer = $this->makeRenderer_SitemapLegacyHashedRedirect('https://www.changanhanfu.com');
    $legacy = 'sitemap_weline-blog-blog-article_bn-bd_1_923cc59de9f9.xml';
    $result = $renderer->renderFile($this->websiteCode_SitemapLegacyHashedRedirect, 'canonical', $legacy);
    self::assertSame(404, $result['status']);
    self::assertStringContainsString('Sitemap 文件不存在', $result['body']);
    self::assertArrayNotHasKey('headers', $result);
}

private function assert_SitemapLegacyHashedRedirect_testStableSlugFilenameServesXml(): void
{
    $renderer = $this->makeRenderer_SitemapLegacyHashedRedirect('https://www.changanhanfu.com');
    $stable = 'sitemap_weline-blog-blog-article_bn-bd_1.xml';
    $result = $renderer->renderFile($this->websiteCode_SitemapLegacyHashedRedirect, 'canonical', $stable);
    self::assertSame(200, $result['status']);
    self::assertStringContainsString('<urlset', $result['body']);
    self::assertStringContainsString('https://www.changanhanfu.com/bn_BD/blog/a', $result['body']);
}

private function makeRenderer_SitemapLegacyHashedRedirect(string $publicBase): SitemapProtocolRenderer
{
    $website = ['id' => 0, 'website_id' => 0, 'code' => $this->websiteCode_SitemapLegacyHashedRedirect, 'url' => $publicBase];
    $resolver = $this->createMock(WebsiteProtocolResolver::class);
    $resolver->method('currentWebsite')->willReturn($website);
    $directory = $this->createMock(SeoWebsiteDirectory::class);
    $directory->method('listWebsites')->willReturn([$website]);
    $directory->method('requestPublicBaseUrlForWebsite')->willReturn($publicBase);
    $directory->method('rewriteAllOriginsInXml')->willReturnCallback(static function (string $xml, string $baseUrl): string {
        return str_replace('https://shop.example.test', rtrim($baseUrl, '/'), $xml);
    });
    $directory->method('rewriteWebsiteOriginUrl')->willReturnCallback(static fn(string $url, string $baseUrl): string => $url);
    return new SitemapProtocolRenderer($resolver, $this->createMock(SitemapUrl::class), new SitemapXmlExtensionRenderer(), $directory, new StoreModeSeoHardGate());
}
}
