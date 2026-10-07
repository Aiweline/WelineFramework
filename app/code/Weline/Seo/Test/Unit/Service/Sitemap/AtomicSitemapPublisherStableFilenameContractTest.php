<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Sitemap;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Model\SitemapUrl;
use Weline\Seo\Service\Sitemap\AtomicSitemapPublisher;
use Weline\Seo\Service\Sitemap\SitemapOperationLock;
use Weline\Seo\Service\Sitemap\SitemapXmlExtensionRenderer;

final class AtomicSitemapPublisherStableFilenameContractTest extends TestCase
{
    private string $websiteCode = '';

    private string $targetDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->websiteCode = 'ut-stable-sm-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $this->targetDir = BP . '/pub/sitemaps/' . $this->websiteCode . '/canonical';
    }

    protected function tearDown(): void
    {
        $root = BP . '/pub/sitemaps/' . $this->websiteCode;
        if (is_dir($root)) {
            $this->removeTree($root);
        }
        parent::tearDown();
    }

    public function testLegacyStabilizeAndStablePatternHelpers(): void
    {
        $legacy = 'sitemap_weline-blog-blog-article_es-es_1_923cc59de9f9.xml';
        $stable = 'sitemap_weline-blog-blog-article_es-es_1.xml';

        self::assertTrue(AtomicSitemapPublisher::isLegacyHashedShardFilename($legacy));
        self::assertFalse(AtomicSitemapPublisher::isStableShardFilename($legacy));
        self::assertSame($stable, AtomicSitemapPublisher::stabilizeLegacyShardFilename($legacy));
        self::assertTrue(AtomicSitemapPublisher::isStableShardFilename($stable));
        self::assertNull(AtomicSitemapPublisher::stabilizeLegacyShardFilename($stable));
        self::assertNull(AtomicSitemapPublisher::stabilizeLegacyShardFilename('sitemap.xml'));
    }

    public function testPublishUsesStableFilenameAndKeepsNameWhenContentChanges(): void
    {
        $publisher = new AtomicSitemapPublisher(new SitemapOperationLock(), new SitemapXmlExtensionRenderer());
        $baseUrl = 'https://shop.example.test';

        $first = $publisher->publish(
            900001,
            $this->websiteCode,
            $baseUrl,
            [[
                'module' => 'Weline_Blog',
                'scope' => 'blog_article',
                'locale' => 'es_ES',
                'urls' => [[
                    SitemapUrl::schema_fields_URL_KEY => 'post-1',
                    SitemapUrl::schema_fields_URL => $baseUrl . '/es_ES/blog/post-1',
                    SitemapUrl::schema_fields_PRIORITY => '0.5',
                    SitemapUrl::schema_fields_CHANGEFREQ => 'weekly',
                ]],
            ]],
            'canonical',
        );
        self::assertTrue((bool)($first['success'] ?? false), (string)($first['message'] ?? 'first publish failed'));
        self::assertNotEmpty($first['shards'] ?? []);
        $filename = (string)$first['shards'][0]['filename'];
        self::assertTrue(AtomicSitemapPublisher::isStableShardFilename($filename));
        self::assertSame('sitemap_weline-blog-blog-article_es-es_1.xml', $filename);
        self::assertDoesNotMatchRegularExpression('/-[a-f0-9]{16}_/', $filename);
        self::assertDoesNotMatchRegularExpression('/_[a-f0-9]{12}\.xml$/D', $filename);
        $firstHash = (string)$first['shards'][0]['hash'];
        $indexXml = (string)file_get_contents($this->targetDir . '/sitemap.xml');
        self::assertStringContainsString('/' . $filename, $indexXml);
        self::assertDoesNotMatchRegularExpression('/sitemap_[a-z0-9-]+_[a-z0-9-]+_\d+_[a-f0-9]{12}\.xml/', $indexXml);

        $second = $publisher->publish(
            900001,
            $this->websiteCode,
            $baseUrl,
            [[
                'module' => 'Weline_Blog',
                'scope' => 'blog_article',
                'locale' => 'es_ES',
                'urls' => [[
                    SitemapUrl::schema_fields_URL_KEY => 'post-1',
                    SitemapUrl::schema_fields_URL => $baseUrl . '/es_ES/blog/post-1-updated',
                    SitemapUrl::schema_fields_PRIORITY => '0.5',
                    SitemapUrl::schema_fields_CHANGEFREQ => 'weekly',
                ]],
            ]],
            'canonical',
        );
        self::assertTrue((bool)($second['success'] ?? false));
        self::assertSame($filename, (string)$second['shards'][0]['filename']);
        self::assertNotSame($firstHash, (string)$second['shards'][0]['hash']);
        $body = (string)file_get_contents($this->targetDir . '/' . $filename);
        self::assertStringContainsString('/es_ES/blog/post-1-updated', $body);
    }

    public function testPublishRemovesLegacyHashedShardsAndExtraSequences(): void
    {
        $publisher = new AtomicSitemapPublisher(new SitemapOperationLock(), new SitemapXmlExtensionRenderer());
        $baseUrl = 'https://shop.example.test';

        $twoShards = $publisher->publish(
            900002,
            $this->websiteCode,
            $baseUrl,
            [[
                'module' => 'Weline_Faq',
                'scope' => 'faq',
                'locale' => 'en_US',
                'urls' => [
                    [
                        SitemapUrl::schema_fields_URL_KEY => 'faq-1',
                        SitemapUrl::schema_fields_URL => $baseUrl . '/faq/one',
                        SitemapUrl::schema_fields_PRIORITY => '0.4',
                        SitemapUrl::schema_fields_CHANGEFREQ => 'monthly',
                    ],
                    [
                        SitemapUrl::schema_fields_URL_KEY => 'faq-2',
                        SitemapUrl::schema_fields_URL => $baseUrl . '/faq/two',
                        SitemapUrl::schema_fields_PRIORITY => '0.4',
                        SitemapUrl::schema_fields_CHANGEFREQ => 'monthly',
                    ],
                ],
            ]],
            'canonical',
            1,
        );
        self::assertTrue((bool)($twoShards['success'] ?? false));
        self::assertCount(2, $twoShards['shards'] ?? []);
        $stable1 = (string)$twoShards['shards'][0]['filename'];
        $stable2 = (string)$twoShards['shards'][1]['filename'];
        self::assertStringEndsWith('_1.xml', $stable1);
        self::assertStringEndsWith('_2.xml', $stable2);

        $legacy = preg_replace('/\.xml$/D', '_aaaaaaaaaaaa.xml', $stable1);
        self::assertIsString($legacy);
        file_put_contents(
            $this->targetDir . '/' . $legacy,
            '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>',
        );

        $again = $publisher->publish(
            900002,
            $this->websiteCode,
            $baseUrl,
            [[
                'module' => 'Weline_Faq',
                'scope' => 'faq',
                'locale' => 'en_US',
                'urls' => [[
                    SitemapUrl::schema_fields_URL_KEY => 'faq-1',
                    SitemapUrl::schema_fields_URL => $baseUrl . '/faq/one',
                    SitemapUrl::schema_fields_PRIORITY => '0.4',
                    SitemapUrl::schema_fields_CHANGEFREQ => 'monthly',
                ]],
            ]],
            'canonical',
            1,
        );
        self::assertTrue((bool)($again['success'] ?? false));
        self::assertCount(1, $again['shards'] ?? []);
        self::assertSame($stable1, (string)$again['shards'][0]['filename']);
        self::assertFileExists($this->targetDir . '/' . $stable1);
        self::assertFileDoesNotExist($this->targetDir . '/' . $stable2);
        self::assertFileDoesNotExist($this->targetDir . '/' . $legacy);
    }

    private function removeTree(string $directory): void
    {
        $items = scandir($directory);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . '/' . $item;
            if (is_dir($path)) {
                $this->removeTree($path);
                continue;
            }
            @unlink($path);
        }
        @rmdir($directory);
    }
}
