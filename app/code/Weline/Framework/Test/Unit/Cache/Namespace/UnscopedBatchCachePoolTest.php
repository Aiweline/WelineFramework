<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache\Namespace;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\Contract\TaggableInterface;
use Weline\Framework\Cache\Namespace\NamespaceGenerationRepository;
use Weline\Framework\Cache\Namespace\NamespaceGenerationSnapshot;
use Weline\Framework\Cache\Namespace\NamespaceKeyDecorator;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Cache\Pool\NamespaceScopedCachePool;
use Weline\Framework\Model\Cache\NamespaceVersion;
use Weline\Framework\Runtime\RequestContext;

interface UnscopedBatchTaggableFixture extends CachePoolInterface, TaggableInterface
{
    public function getAllTags(): array;
    public function getTagStats(): array;
}

final class UnscopedBatchCachePoolTest extends TestCase
{
    public function testReadWithoutNamespacesKeepsLogicalKeysAndEmptyDictionary(): void
    {
        $keys = ['module_dictionary|v1|known', 'module_dictionary|v1|empty', 'module_dictionary|v1|missing', 'false', 'zero'];
        $values = [$keys[0] => ['Source' => 'Translation'], $keys[1] => [], $keys[2] => null, 'false' => false, 'zero' => 0];
        $pool = $this->createMock(CachePoolInterface::class);
        $pool->expects(self::once())->method('getMultiple')->with($keys)->willReturn($values);
        $pool->expects(self::never())->method('get');

        self::assertSame($values, NamespaceScopedCachePool::create($pool, [])->getMultiple($keys));
    }

    public function testWriteWithoutNamespacesKeepsLogicalKeysAndTtl(): void
    {
        $values = ['module_dictionary|v1|known' => ['Source' => 'Translation'], 'module_dictionary|v1|empty' => [], 'false' => false, 'zero' => 0, 'null' => null];
        $pool = $this->createMock(CachePoolInterface::class);
        $pool->expects(self::once())->method('setMultiple')->with($values, 86400)->willReturn(true);
        $pool->expects(self::never())->method('set');

        self::assertTrue(NamespaceScopedCachePool::create($pool, [])->setMultiple($values, 86400));
    }

    public function testDeleteWithoutNamespacesKeepsLogicalKeys(): void
    {
        $keys = ['module_dictionary|v1|known', 'module_dictionary|v1|empty'];
        $pool = $this->createMock(CachePoolInterface::class);
        $pool->expects(self::once())->method('deleteMultiple')->with($keys)->willReturn(true);
        $pool->expects(self::never())->method('delete');

        self::assertTrue(NamespaceScopedCachePool::create($pool, [])->deleteMultiple($keys));
    }

    public function testTaggedWriteWithoutNamespacesKeepsOriginalKeyAndTags(): void
    {
        $pool = $this->createMock(UnscopedBatchTaggableFixture::class);
        $pool->expects(self::once())->method('setWithTags')->with('key', false, ['catalog', 'language'], 600)->willReturn(true);
        self::assertTrue(NamespaceScopedCachePool::create($pool, [])->setWithTags('key', false, ['catalog', 'language'], 600));
    }

    public function testTagInvalidationWithoutNamespacesKeepsOriginalTags(): void
    {
        $pool = $this->createMock(UnscopedBatchTaggableFixture::class);
        $pool->expects(self::once())->method('invalidateTags')->with(['catalog', 'language'])->willReturn(true);
        self::assertTrue(NamespaceScopedCachePool::create($pool, [])->invalidateTags(['catalog', 'language']));
    }

    public function testTagKeysWithoutNamespacesRemainLogicalKeys(): void
    {
        $pool = $this->createMock(UnscopedBatchTaggableFixture::class);
        $pool->expects(self::once())->method('getKeysByTag')->with('catalog')->willReturn(['key', 'other']);
        self::assertSame(['key', 'other'], NamespaceScopedCachePool::create($pool, [])->getKeysByTag('catalog'));
    }

    public function testTagListWithoutNamespacesRemainsOriginalTags(): void
    {
        $pool = $this->createMock(UnscopedBatchTaggableFixture::class);
        $pool->expects(self::once())->method('getAllTags')->willReturn(['catalog', 'language']);
        self::assertSame(['catalog', 'language'], NamespaceScopedCachePool::create($pool, [])->getAllTags());
    }

    public function testTagStatsWithoutNamespacesRemainOriginalTags(): void
    {
        $pool = $this->createMock(UnscopedBatchTaggableFixture::class);
        $pool->expects(self::once())->method('getTagStats')->willReturn(['catalog' => 2, 'language' => 0]);
        self::assertSame(['catalog' => 2, 'language' => 0], NamespaceScopedCachePool::create($pool, [])->getTagStats());
    }

    public function testNonEmptyNamespaceStillIsolatesBatchKeysAndTags(): void
    {
        RequestContext::init();
        try {
            $namespace = 'website/default/catalog';
            $path = new NamespacePath();
            $snapshot = new NamespaceGenerationSnapshot();
            $snapshot->resolve($path->expandAncestors([$namespace]), static fn(array $requested): array => [
                NamespacePath::AUTHORITY_CLOCK => 7, 'website/default' => 2, $namespace => 4,
            ]);
            $decorator = new NamespaceKeyDecorator();
            $repository = new NamespaceGenerationRepository(
                $this->createMock(NamespaceVersion::class), $path, $snapshot, $decorator,
            );
            $fingerprint = $repository->fingerprint([$namespace]);
            $key = $decorator->decorate('key', $fingerprint);
            $tag = $decorator->decorateTag('catalog', $fingerprint);
            $foreignKey = $decorator->decorate('foreign', str_repeat('f', 64));
            $foreignTag = $decorator->decorateTag('foreign', str_repeat('f', 64));
            $pool = $this->createMock(UnscopedBatchTaggableFixture::class);
            $pool->expects(self::once())->method('getMultiple')->with([$key])->willReturn([$key => false]);
            $pool->expects(self::once())->method('setMultiple')->with([$key => []], 600)->willReturn(true);
            $pool->expects(self::once())->method('deleteMultiple')->with([$key])->willReturn(true);
            $pool->expects(self::once())->method('setWithTags')->with($key, 0, [$tag], 600)->willReturn(true);
            $pool->expects(self::once())->method('invalidateTags')->with([$tag])->willReturn(true);
            $pool->expects(self::once())->method('getKeysByTag')->with($tag)->willReturn([$key, $foreignKey, 'unscoped']);
            $pool->expects(self::once())->method('getAllTags')->willReturn([$tag, $foreignTag, 'unscoped']);
            $pool->expects(self::once())->method('getTagStats')->willReturn([$tag => 2, $foreignTag => 3, 'unscoped' => 1]);
            $scoped = NamespaceScopedCachePool::create($pool, [$namespace], $repository, $decorator);

            self::assertSame(['key' => false], $scoped->getMultiple(['key']));
            self::assertTrue($scoped->setMultiple(['key' => []], 600));
            self::assertTrue($scoped->deleteMultiple(['key']));
            self::assertTrue($scoped->setWithTags('key', 0, ['catalog'], 600));
            self::assertTrue($scoped->invalidateTags(['catalog']));
            self::assertSame(['key'], $scoped->getKeysByTag('catalog'));
            self::assertSame(['catalog'], $scoped->getAllTags());
            self::assertSame(['catalog' => 2], $scoped->getTagStats());
        } finally {
            RequestContext::cleanup();
        }
    }
}
