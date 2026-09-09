<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Blog\Model\Post;
use Weline\Blog\Model\Post\LocalDescription;
use Weline\Blog\Service\BlogContentResolver;
use Weline\Framework\Context;
use Weline\Blog\Service\BlogContentCache;
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Cache\Contract\SingleFlightInterface;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;

final class BlogContentReadBehaviorTest extends TestCase
{
    protected function setUp(): void
    {
        Context::enter(new Context());
        StorefrontScopeHotCache::resetProcessCache();
    }

    public function testGlobalFallbackIsReusedUnderRequestedWebsite(): void
    {
        $reads = 0;
        $row = ['post_id' => 91, 'website_id' => 0, 'locale' => 'en_US', 'slug' => 'global-news', 'status' => 'published', 'content' => 'Body'];
        $post = $this->getMockBuilder(Post::class)->disableOriginalConstructor()->disableOriginalClone()
            ->onlyMethods(['__clone', 'clearData', '__call', 'hydrateLoadedRow'])->getMock();
        $post->method('clearData')->willReturnSelf();
        $post->method('hydrateLoadedRow')->willReturnSelf();
        $post->method('__call')->willReturnCallback(static function (string $method) use (&$reads, $row, $post): mixed {
            if ($method === 'fetchArray') {
                $reads++;
                return $row;
            }
            return $post;
        });
        $resolver = (new \ReflectionClass(BlogContentResolver::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($resolver, 'contentCache'))->setValue($resolver, $this->cache());
        (new \ReflectionProperty($resolver, 'postModel'))->setValue($resolver, $post);
        $find = new \ReflectionMethod($resolver, 'findPublishedPost');
        self::assertSame($row, $find->invoke($resolver, 7, 'en_US', 'global-news'));
        self::assertSame($row, $find->invoke($resolver, 7, 'en_US', 'global-news'));
        self::assertSame(1, $reads, 'A resolved global row must satisfy the same scoped lookup without another persistence read.');
    }

    public function testLocalizedKeywordResultAndMissAreReused(): void
    {
        $reads = 0;
        $local = $this->getMockBuilder(LocalDescription::class)->disableOriginalConstructor()->disableOriginalClone()
            ->onlyMethods(['__clone', 'clearData', '__call'])->getMock();
        $local->method('clearData')->willReturnSelf();
        $local->method('__call')->willReturnCallback(static function (string $method) use (&$reads, $local): mixed {
            if ($method === 'fetchArray') {
                $reads++;
                return [['post_id' => 91, 'local_code' => 'en_US', 'keywords' => 'Hanfu']];
            }
            return $local;
        });
        $resolver = (new \ReflectionClass(BlogContentResolver::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($resolver, 'contentCache'))->setValue($resolver, $this->cache());
        (new \ReflectionProperty($resolver, 'postLocalDescription'))->setValue($resolver, $local);
        $load = new \ReflectionMethod($resolver, 'loadLocalKeywords');
        self::assertSame('Hanfu', $load->invoke($resolver, 91, 'en_US'));
        self::assertSame('Hanfu', $load->invoke($resolver, 91, 'en_US'));
        self::assertSame(1, $reads, 'Repeated projections must reuse localized keyword values.');
    }
    public function testSharedSnapshotSurvivesRequestAndWorkerChange(): void
    {
        $cache = $this->cache();
        $reads = 0;
        $builder = static function () use (&$reads): array { $reads++; return ['content' => 'Body']; };
        self::assertSame(['content' => 'Body'], $cache->remember(7, 'en_US', 'post_slug', ['news'], $builder));
        Context::enter(new Context());
        StorefrontScopeHotCache::resetProcessCache();
        self::assertSame(['content' => 'Body'], $cache->remember(7, 'en_US', 'post_slug', ['news'], $builder));
        self::assertSame(1, $reads, 'A different worker should reuse the shared envelope.');
        $cache->remember(7, 'zh_Hans_CN', 'post_slug', ['news'], $builder);
        self::assertSame(2, $reads, 'Languages must remain separate.');
    }

    public function testChangedScopesIncludeGlobalFallbackAndPreviousWebsite(): void
    {
        self::assertTrue(method_exists(BlogContentCache::class, 'changedPaths'));
        self::assertSame(['global/storefront/blog/content/website/0', 'global/storefront/blog/content/website/7'],
            BlogContentCache::changedPaths(7, 0));
        $versions = [];
        $cache = $this->cache($versions);
        $reads = 0;
        $builder = static function () use (&$reads): int { return ++$reads; };
        self::assertSame(1, $cache->remember(7, 'en_US', 'post_slug', ['news'], $builder));
        self::assertSame(2, $cache->remember(8, 'en_US', 'post_slug', ['news'], $builder));
        foreach (BlogContentCache::changedPaths(7, 7) as $path) { $versions[$path] = 1; }
        self::assertSame(3, $cache->remember(7, 'en_US', 'post_slug', ['news'], $builder));
        self::assertSame(2, $cache->remember(8, 'en_US', 'post_slug', ['news'], $builder));
        foreach (BlogContentCache::changedPaths(0, 0) as $path) { $versions[$path] = 1; }
        self::assertSame(4, $cache->remember(7, 'en_US', 'post_slug', ['news'], $builder));
        self::assertSame(5, $cache->remember(8, 'en_US', 'post_slug', ['news'], $builder));
    }

    public function testKeywordsAreFetchedOnceForDistinctIdsIncludingMissingTranslations(): void
    {
        $reads = 0;
        $queriedIds = [];
        $local = $this->getMockBuilder(LocalDescription::class)->disableOriginalConstructor()->disableOriginalClone()
            ->onlyMethods(['__clone', 'clearData', '__call'])->getMock();
        $local->method('clearData')->willReturnSelf();
        $local->method('__call')->willReturnCallback(static function (string $method, array $args) use (&$reads, &$queriedIds, $local): mixed {
            if ($method === 'where' && $args[0] === 'post_id') { $queriedIds[] = $args[1]; }
            if ($method === 'fetchArray') { $reads++; return [['post_id' => 91, 'keywords' => 'Hanfu']]; }
            return $local;
        });
        $resolver = (new \ReflectionClass(BlogContentResolver::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($resolver, 'contentCache'))->setValue($resolver, $this->cache());
        (new \ReflectionProperty($resolver, 'postLocalDescription'))->setValue($resolver, $local);
        $posts = [['post_id' => 91, 'website_id' => 7, 'locale' => 'en_US'], ['post_id' => 92, 'website_id' => 7, 'locale' => 'en_US']];
        $warm = new \ReflectionMethod($resolver, 'warmKeywordsForPosts');
        $warm->invoke($resolver, [...$posts, $posts[0]]);
        $warm->invoke($resolver, $posts);
        $load = new \ReflectionMethod($resolver, 'loadLocalKeywords');
        self::assertSame('Hanfu', $load->invoke($resolver, 91, 'en_US'));
        self::assertNull($load->invoke($resolver, 92, 'en_US'));
        self::assertSame([[91, 92]], $queriedIds);
        self::assertSame(1, $reads);
    }

    public function testCategoryRowsAreFetchedOnceForDistinctIds(): void
    {
        $reads = 0;
        $category = $this->getMockBuilder(\Weline\Blog\Model\Category::class)->disableOriginalConstructor()->disableOriginalClone()
            ->onlyMethods(['__clone', 'clearData', '__call'])->getMock();
        $category->method('clearData')->willReturnSelf();
        $category->method('__call')->willReturnCallback(static function (string $method) use (&$reads, $category): mixed {
            if ($method === 'fetchArray') {
                $reads++;
                return [['category_id' => 1, 'slug' => 'news', 'name' => 'News'], ['category_id' => 2, 'slug' => 'style', 'name' => 'Style']];
            }
            return $category;
        });
        $store = $this->createStub(\Weline\Eav\Api\Attribute\EntityAttributeStoreInterface::class);
        $store->method('getAttribute')->willReturn(new \Weline\Eav\Api\Attribute\AttributeRecord(36, 6, 'name', 'name', 5, 'input_string_255', 9, 15, false, false));
        $store->method('readScopedValue')->willReturnCallback(static fn($entity, $id) => \Weline\Eav\Api\Scope\EavScopeValue::explicit('Category ' . $id, 'default'));
        $entity = (new \ReflectionClass(\Weline\Blog\Model\BlogCategoryAttributeEntity::class))->newInstanceWithoutConstructor();
        $attributes = new \Weline\Blog\Service\BlogCategoryAttributeService($store, $entity);
        $resolver = (new \ReflectionClass(BlogContentResolver::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($resolver, 'contentCache'))->setValue($resolver, $this->cache());
        (new \ReflectionProperty($resolver, 'categoryModel'))->setValue($resolver, $category);
        (new \ReflectionProperty($resolver, 'categoryAttributes'))->setValue($resolver, $attributes);
        $posts = [['category_id' => 1, 'website_id' => 7, 'locale' => 'en_US'], ['category_id' => 2, 'website_id' => 7, 'locale' => 'en_US']];
        $warm = new \ReflectionMethod($resolver, 'warmCategoryMetaForPosts');
        $warm->invoke($resolver, [...$posts, $posts[0]], 'en_US');
        $warm->invoke($resolver, $posts, 'en_US');
        $meta = new \ReflectionMethod($resolver, 'resolveCategoryMeta');
        self::assertSame('Category 1', $meta->invoke($resolver, 1, 7, 'en_US')['name']);
        self::assertSame('Category 2', $meta->invoke($resolver, 2, 7, 'en_US')['name']);
        self::assertSame(1, $reads);
    }

    private function cache(?array &$versions = null): BlogContentCache
    {
        $values = [];
        $pool = $this->createMock(CachePoolInterface::class);
        $pool->method('getCustom')->willReturnCallback(static function (string $key) use (&$values): mixed { return $values[$key] ?? null; });
        $pool->method('setCustom')->willReturnCallback(static function (string $key, mixed $value) use (&$values): bool {
            $values[$key] = $value;
            return true;
        });
        $manager = $this->getMockBuilder(CacheManager::class)->disableOriginalConstructor()->onlyMethods(['pool'])->getMock();
        $manager->method('pool')->willReturn($pool);
        $generations = $this->createStub(NamespaceGenerationInterface::class);
        $versions ??= [];
        $generations->method('fingerprint')->willReturnCallback(static function (array $paths) use (&$versions): string {
            return hash('sha256', serialize(array_map(static fn(string $path): int => $versions[$path] ?? 0, $paths)));
        });
        $flight = $this->createStub(SingleFlightInterface::class);
        $flight->method('acquire')->willReturn(null);
        return new BlogContentCache(new StorefrontScopeHotCache($manager, $generations, $flight));
    }
}
