<?php

declare(strict_types=1);

namespace Weline\UrlManager\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Event\Event;
use Weline\Framework\Runtime\RequestContext;
use Weline\UrlManager\Model\UrlRewrite;
use Weline\UrlManager\Observer\SeoUrlGenerateRewrite;

final class SeoUrlGenerateRewriteBatchTest extends TestCase
{
    protected function setUp(): void
    {
        RequestContext::init();
        RequestContext::websiteId(7);
        RequestContext::set('input.host', 'batch.example.test');
        RequestContext::setWelineWebsiteUrl('https://batch.example.test');
    }

    protected function tearDown(): void
    {
        RequestContext::cleanup();
    }

    public function testFiftyOneCategoriesUseOneBatchAndSubsequentEventsUseContext(): void
    {
        $model = new BatchRewriteLookupFixture();
        $cache = $this->createMock(CachePoolInterface::class);
        $cache->expects(self::once())->method('getMultiple')->willReturn([]);
        $cache->expects(self::once())->method('setMultiple')->with(
            self::callback(static fn(array $values): bool => count($values) === 51
                && array_unique(array_values($values)) === ['not_found']),
            300,
        )->willReturn(true);
        $cache->expects(self::never())->method('get');
        $cache->expects(self::never())->method('set');
        $observer = $this->observer($model, $cache);
        $urls = array_map(static fn(int $id): string => 'https://batch.example.test/en_US/category/' . $id, range(1, 51));

        $observer->prefetch($urls);
        $observer->prefetch($urls);
        foreach ($urls as $url) {
            $event = new Event(['data' => $url]);
            $observer->execute($event);
            self::assertSame($url, $event->getData('data'));
        }
        self::assertCount(1, $model->calls);
        self::assertSame(7, $model->calls[0][0]);
        self::assertCount(102, $model->calls[0][1]);
    }

    public function testPrimaryThenFallbackAndEmptyRewriteKeepSingleEventSemantics(): void
    {
        $model = new BatchRewriteLookupFixture();
        $model->rows = [
            'en_us/category/a' => ['rewrite' => 'primary'],
            'category/a' => ['rewrite' => 'fallback-must-not-win'],
            'category/b' => ['rewrite' => 'fallback'],
            'en_us/category/c' => ['rewrite' => ''],
        ];
        $cache = $this->createMock(CachePoolInterface::class);
        $cache->method('getMultiple')->willReturn([]);
        $cache->expects(self::once())->method('setMultiple')->with(self::countOf(3), 300)->willReturn(true);
        $observer = $this->observer($model, $cache);
        $urls = [
            'https://batch.example.test/en_US/category/a?x=1',
            'https://batch.example.test/en_US/category/b',
            'https://batch.example.test/en_US/category/c',
        ];
        $observer->prefetch($urls);
        $expected = [
            'https://batch.example.test/primary?x=1',
            'https://batch.example.test/en_US/fallback',
            'https://batch.example.test/',
        ];
        foreach ($urls as $i => $url) {
            $event = new Event(['data' => $url]);
            $observer->execute($event);
            self::assertSame($expected[$i], $event->getData('data'));
        }
    }

    public function testSharedHitsCopyIntoNextRequestAndOnlyMissingPathsReachDatabase(): void
    {
        $model = new BatchRewriteLookupFixture();
        $model->rows = ['category/a' => ['rewrite' => 'a-rewritten']];
        $shared = [];
        $cache = $this->createMock(CachePoolInterface::class);
        $cache->expects(self::exactly(2))->method('getMultiple')->willReturnCallback(
            static function (array $keys) use (&$shared): array { return array_intersect_key($shared, array_flip($keys)); },
        );
        $cache->expects(self::exactly(2))->method('setMultiple')->willReturnCallback(
            static function (array $values, int $ttl) use (&$shared): bool {
                self::assertSame(300, $ttl);
                $shared = array_replace($shared, $values);
                return true;
            },
        );
        $cache->expects(self::never())->method('get');
        $observer = $this->observer($model, $cache);
        $observer->prefetch(['https://batch.example.test/category/a', 'https://batch.example.test/category/missing']);
        RequestContext::cleanup();
        $this->setUp();
        $observer->prefetch(['https://batch.example.test/category/a', 'https://batch.example.test/category/missing', 'https://batch.example.test/category/new']);
        self::assertCount(2, $model->calls);
        self::assertSame(['category/new'], $model->calls[1][1]);
        $event = new Event(['data' => 'https://batch.example.test/category/a']);
        $observer->execute($event);
        self::assertSame('https://batch.example.test/a-rewritten', $event->getData('data'));
    }

    public function testFailedLookupDoesNotPublishNegativeCache(): void
    {
        $model = new BatchRewriteLookupFixture();
        $model->fail = true;
        $cache = $this->createMock(CachePoolInterface::class);
        $cache->method('getMultiple')->willReturn([]);
        $cache->expects(self::never())->method('setMultiple');
        $observer = $this->observer($model, $cache);
        $this->expectExceptionMessage('lookup unavailable');
        $observer->prefetch(['https://batch.example.test/category/a']);
    }

    public function testEmptyAndAssetBatchesDoNotReadStorage(): void
    {
        $cache = $this->createMock(CachePoolInterface::class);
        $cache->expects(self::never())->method('getMultiple');
        $observer = $this->observer(new BatchRewriteLookupFixture(), $cache);
        $observer->prefetch([]);
        $observer->prefetch([
            'https://batch.example.test/statics/app.js',
            'https://batch.example.test/media/a.jpg',
            // External URLs remain in the ordinary per-link path. Batch parsing
            // must not change the active website/language context in advance.
            'https://external.example.test/category/a',
        ]);
    }

    private function observer(BatchRewriteLookupFixture $model, CachePoolInterface $cache): SeoUrlGenerateRewrite
    {
        $observer = new SeoUrlGenerateRewrite($model);
        (new \ReflectionProperty($observer, 'cache'))->setValue($observer, $cache);
        return $observer;
    }
}

final class BatchRewriteLookupFixture extends UrlRewrite
{
    public array $calls = [];
    public array $rows = [];
    public bool $fail = false;

    public function __construct() {}

    public function findLatestByWebsiteAndPath(int $websiteId, string $path): ?array
    {
        throw new \RuntimeException('A prefetched URL must reuse the request context.');
    }

    public function findLatestByWebsiteAndPaths(int $websiteId, array $paths): array
    {
        if ($this->fail) {
            throw new \RuntimeException('lookup unavailable');
        }
        $this->calls[] = [$websiteId, $paths];
        return array_replace(array_fill_keys($paths, null), array_intersect_key($this->rows, array_flip($paths)));
    }
}
