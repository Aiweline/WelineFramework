<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Sitemap;

use PHPUnit\Framework\TestCase;
use ReflectionClass as ReflectionClass;
use ReflectionMethod as ReflectionMethod;
use Weline\Seo\Api\Sitemap\AbstractSitemapUrlProvider;
use Weline\Seo\Interface\SitemapUrlProviderInterface;
use Weline\Seo\Model\SitemapUrl;
use Weline\Seo\Service\Sitemap\AtomicSitemapPublisher;
use Weline\Seo\Service\Sitemap\SitemapEdgeMapCoordinator;
use Weline\Seo\Service\Sitemap\SitemapOperationLock;
use Weline\Seo\Service\Sitemap\SitemapXmlExtensionRenderer;
use Weline\Seo\Service\WebSitemapData;

/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllSeoSitemapContractsPass().
 */
final class SeoSitemapContractsTest extends TestCase
{
    public function testAllSeoSitemapContractsPass(): void
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
            'AtomicSitemapPublisherStableFilename::testLegacyStabilizeAndStablePatternHelpers' => function () {
            $this->setUp_AtomicSitemapPublisherStableFilename();
            try {
                $this->assert_AtomicSitemapPublisherStableFilename_testLegacyStabilizeAndStablePatternHelpers();
            } finally {
                $this->tearDown_AtomicSitemapPublisherStableFilename();
            }
        },
            'AtomicSitemapPublisherStableFilename::testPublishUsesStableFilenameAndKeepsNameWhenContentChanges' => function () {
            $this->setUp_AtomicSitemapPublisherStableFilename();
            try {
                $this->assert_AtomicSitemapPublisherStableFilename_testPublishUsesStableFilenameAndKeepsNameWhenContentChanges();
            } finally {
                $this->tearDown_AtomicSitemapPublisherStableFilename();
            }
        },
            'AtomicSitemapPublisherStableFilename::testPublishRemovesLegacyHashedShardsAndExtraSequences' => function () {
            $this->setUp_AtomicSitemapPublisherStableFilename();
            try {
                $this->assert_AtomicSitemapPublisherStableFilename_testPublishRemovesLegacyHashedShardsAndExtraSequences();
            } finally {
                $this->tearDown_AtomicSitemapPublisherStableFilename();
            }
        },
            'SitemapEdgeMapCoordinator::testCoordinatorHooksAfterCanonicalPublishAndPurgesCdn' => function () {
            $this->assert_SitemapEdgeMapCoordinator_testCoordinatorHooksAfterCanonicalPublishAndPurgesCdn();
        },
            'SitemapLocalePathExpansion::testInterfaceAndAbstractDeclarePathExpansionHook' => function () {
            $this->assert_SitemapLocalePathExpansion_testInterfaceAndAbstractDeclarePathExpansionHook();
        },
        ];
    }

private string $websiteCode_AtomicSitemapPublisherStableFilename = '';

private string $targetDir_AtomicSitemapPublisherStableFilename = '';

private function setUp_AtomicSitemapPublisherStableFilename(): void
{
    parent::setUp();
    $this->websiteCode_AtomicSitemapPublisherStableFilename = 'ut-stable-sm-' . substr(bin2hex(random_bytes(4)), 0, 8);
    $this->targetDir_AtomicSitemapPublisherStableFilename = PUB . 'sitemaps/' . $this->websiteCode_AtomicSitemapPublisherStableFilename . '/canonical';
}

private function tearDown_AtomicSitemapPublisherStableFilename(): void
{
    $root = PUB . 'sitemaps/' . $this->websiteCode_AtomicSitemapPublisherStableFilename;
    if (is_dir($root)) {
        $this->removeTree_AtomicSitemapPublisherStableFilename($root);
    }
    parent::tearDown();
}

private function assert_AtomicSitemapPublisherStableFilename_testLegacyStabilizeAndStablePatternHelpers(): void
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

private function assert_AtomicSitemapPublisherStableFilename_testPublishUsesStableFilenameAndKeepsNameWhenContentChanges(): void
{
    $publisher = new AtomicSitemapPublisher(new SitemapOperationLock(), new SitemapXmlExtensionRenderer());
    $baseUrl = 'https://shop.example.test';
    $first = $publisher->publish(900001, $this->websiteCode_AtomicSitemapPublisherStableFilename, $baseUrl, [['module' => 'Weline_Blog', 'scope' => 'blog_article', 'locale' => 'es_ES', 'urls' => [[SitemapUrl::schema_fields_URL_KEY => 'post-1', SitemapUrl::schema_fields_URL => $baseUrl . '/es_ES/blog/post-1', SitemapUrl::schema_fields_PRIORITY => '0.5', SitemapUrl::schema_fields_CHANGEFREQ => 'weekly']]]], 'canonical');
    self::assertTrue((bool) ($first['success'] ?? false), (string) ($first['message'] ?? 'first publish failed'));
    self::assertNotEmpty($first['shards'] ?? []);
    $filename = (string) $first['shards'][0]['filename'];
    self::assertTrue(AtomicSitemapPublisher::isStableShardFilename($filename));
    self::assertSame('sitemap_weline-blog-blog-article_es-es_1.xml', $filename);
    self::assertDoesNotMatchRegularExpression('/-[a-f0-9]{16}_/', $filename);
    self::assertDoesNotMatchRegularExpression('/_[a-f0-9]{12}\.xml$/D', $filename);
    $firstHash = (string) $first['shards'][0]['hash'];
    $indexXml = (string) file_get_contents($this->targetDir_AtomicSitemapPublisherStableFilename . '/sitemap.xml');
    self::assertStringContainsString('/' . $filename, $indexXml);
    self::assertDoesNotMatchRegularExpression('/sitemap_[a-z0-9-]+_[a-z0-9-]+_\d+_[a-f0-9]{12}\.xml/', $indexXml);
    $second = $publisher->publish(900001, $this->websiteCode_AtomicSitemapPublisherStableFilename, $baseUrl, [['module' => 'Weline_Blog', 'scope' => 'blog_article', 'locale' => 'es_ES', 'urls' => [[SitemapUrl::schema_fields_URL_KEY => 'post-1', SitemapUrl::schema_fields_URL => $baseUrl . '/es_ES/blog/post-1-updated', SitemapUrl::schema_fields_PRIORITY => '0.5', SitemapUrl::schema_fields_CHANGEFREQ => 'weekly']]]], 'canonical');
    self::assertTrue((bool) ($second['success'] ?? false));
    self::assertSame($filename, (string) $second['shards'][0]['filename']);
    self::assertNotSame($firstHash, (string) $second['shards'][0]['hash']);
    $body = (string) file_get_contents($this->targetDir_AtomicSitemapPublisherStableFilename . '/' . $filename);
    self::assertStringContainsString('/es_ES/blog/post-1-updated', $body);
}

private function assert_AtomicSitemapPublisherStableFilename_testPublishRemovesLegacyHashedShardsAndExtraSequences(): void
{
    $publisher = new AtomicSitemapPublisher(new SitemapOperationLock(), new SitemapXmlExtensionRenderer());
    $baseUrl = 'https://shop.example.test';
    $twoShards = $publisher->publish(900002, $this->websiteCode_AtomicSitemapPublisherStableFilename, $baseUrl, [['module' => 'Weline_Faq', 'scope' => 'faq', 'locale' => 'en_US', 'urls' => [[SitemapUrl::schema_fields_URL_KEY => 'faq-1', SitemapUrl::schema_fields_URL => $baseUrl . '/faq/one', SitemapUrl::schema_fields_PRIORITY => '0.4', SitemapUrl::schema_fields_CHANGEFREQ => 'monthly'], [SitemapUrl::schema_fields_URL_KEY => 'faq-2', SitemapUrl::schema_fields_URL => $baseUrl . '/faq/two', SitemapUrl::schema_fields_PRIORITY => '0.4', SitemapUrl::schema_fields_CHANGEFREQ => 'monthly']]]], 'canonical', 1);
    self::assertTrue((bool) ($twoShards['success'] ?? false));
    self::assertCount(2, $twoShards['shards'] ?? []);
    $stable1 = (string) $twoShards['shards'][0]['filename'];
    $stable2 = (string) $twoShards['shards'][1]['filename'];
    self::assertStringEndsWith('_1.xml', $stable1);
    self::assertStringEndsWith('_2.xml', $stable2);
    $legacy = preg_replace('/\.xml$/D', '_aaaaaaaaaaaa.xml', $stable1);
    self::assertIsString($legacy);
    file_put_contents($this->targetDir_AtomicSitemapPublisherStableFilename . '/' . $legacy, '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>');
    $again = $publisher->publish(900002, $this->websiteCode_AtomicSitemapPublisherStableFilename, $baseUrl, [['module' => 'Weline_Faq', 'scope' => 'faq', 'locale' => 'en_US', 'urls' => [[SitemapUrl::schema_fields_URL_KEY => 'faq-1', SitemapUrl::schema_fields_URL => $baseUrl . '/faq/one', SitemapUrl::schema_fields_PRIORITY => '0.4', SitemapUrl::schema_fields_CHANGEFREQ => 'monthly']]]], 'canonical', 1);
    self::assertTrue((bool) ($again['success'] ?? false));
    self::assertCount(1, $again['shards'] ?? []);
    self::assertSame($stable1, (string) $again['shards'][0]['filename']);
    self::assertFileExists($this->targetDir_AtomicSitemapPublisherStableFilename . '/' . $stable1);
    self::assertFileDoesNotExist($this->targetDir_AtomicSitemapPublisherStableFilename . '/' . $stable2);
    self::assertFileDoesNotExist($this->targetDir_AtomicSitemapPublisherStableFilename . '/' . $legacy);
}

private function removeTree_AtomicSitemapPublisherStableFilename(string $directory): void
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
            $this->removeTree_AtomicSitemapPublisherStableFilename($path);
            continue;
        }
        @unlink($path);
    }
    @rmdir($directory);
}

private function assert_SitemapEdgeMapCoordinator_testCoordinatorHooksAfterCanonicalPublishAndPurgesCdn(): void
{
    $web = (string) \file_get_contents(BP . '/app/code/Weline/Seo/Service/WebSitemapData.php');
    $coord = (string) \file_get_contents(BP . '/app/code/Weline/Seo/Service/Sitemap/SitemapEdgeMapCoordinator.php');
    self::assertStringContainsString('SitemapEdgeMapCoordinator', $web);
    self::assertStringContainsString('afterCanonicalPublished', $web);
    self::assertStringContainsString('edgeMapCoordinator', $web);
    self::assertStringContainsString('purgeCdnUrls', $coord);
    self::assertStringContainsString("purge(\$domainId, 'urls'", $coord);
    self::assertStringContainsString('rebuildAll', $coord);
    self::assertStringContainsString('buildSpecFromWebsites', $coord);
    self::assertStringContainsString(WebSitemapData::SITEMAP_DIR, $coord);
    self::assertStringContainsString('sub_path 挂载只认', $coord);
    self::assertStringContainsString("\$subPath === '' && \$host !== ''", $coord);
    self::assertTrue(\class_exists(SitemapEdgeMapCoordinator::class));
}

private function assert_SitemapLocalePathExpansion_testInterfaceAndAbstractDeclarePathExpansionHook(): void
{
    self::assertTrue(method_exists(SitemapUrlProviderInterface::class, 'supportsSiteLanguagePathExpansion'));
    self::assertTrue(method_exists(AbstractSitemapUrlProvider::class, 'supportsSiteLanguagePathExpansion'));
    $method = new ReflectionMethod(AbstractSitemapUrlProvider::class, 'supportsSiteLanguagePathExpansion');
    self::assertFalse($method->isAbstract());
    $src = (string) file_get_contents((new ReflectionClass(AbstractSitemapUrlProvider::class))->getFileName());
    self::assertMatchesRegularExpression('/function\s+supportsSiteLanguagePathExpansion\(\)\s*:\s*bool\s*\{\s*return\s+false\s*;/s', $src);
}
}
