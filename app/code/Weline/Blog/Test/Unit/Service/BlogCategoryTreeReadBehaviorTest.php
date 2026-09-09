<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Blog\Model\BlogCategoryAttributeEntity;
use Weline\Blog\Model\Category;
use Weline\Blog\Service\BlogCategoryAdminService;
use Weline\Blog\Service\BlogCategoryAttributeService;
use Weline\Blog\Service\BlogContentCache;
use Weline\Eav\Api\Attribute\AttributeRecord;
use Weline\Eav\Api\Attribute\EntityAttributeStoreInterface;
use Weline\Eav\Api\Attribute\ScopedAttributeBatchReaderInterface;
use Weline\Eav\Service\EavScopeResolver;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Context;

final class BlogCategoryTreeReadBehaviorTest extends TestCase
{
    private mixed $originalContext;

    protected function setUp(): void
    {
        $this->originalContext = Context::getCurrent();
        Context::enter(new Context());
    }

    protected function tearDown(): void
    {
        Context::leave();
        if ($this->originalContext !== null) {
            Context::enter($this->originalContext);
        }
    }

    public function testTreeBatchesFieldsAndPreservesLocalizedNamesAndStructuralPaths(): void
    {
        [$admin, $db] = $this->fixture();
        $tree = $admin->tree(7, 'en-US');
        self::assertSame('English parent', $tree[0]['name']);
        self::assertSame('/parent', $tree[0]['path']);
        self::assertSame('parent', $tree[0]['slug']);
        self::assertSame('Neutral child', $tree[0]['nodes'][0]['name']);
        self::assertSame('/child', $tree[0]['nodes'][0]['path']);
        self::assertSame('global-image', $tree[0]['nodes'][0]['image']);
        self::assertSame('', $tree[0]['nodes'][0]['banner'], 'Cleared must block global fallback.');
        self::assertSame('中文父类', $admin->tree(7, 'zh_Hans_CN')[0]['name']);
        self::assertSame($tree, $admin->tree(7, 'en-US'), 'The same request reuses the tree snapshot.');
        self::assertSame(2, $db->reads, 'Each tree must issue one bulk value read, independent of category and field count.');
        self::assertSame(0, $db->singleReads, 'A batched provider must not be called once per cell.');
        $db->pdo->exec("UPDATE values_fixture SET value = 'Updated English' WHERE owner = 1 AND locale = 'en_US'");
        BlogContentCache::clearRequestSnapshots();
        self::assertSame('Updated English', $admin->tree(7, 'en-US')[0]['name'], 'The established Blog write invalidator refreshes the request snapshot.');
        self::assertSame(3, $db->reads);
    }

    private function fixture(): array
    {
        $db = (object)['pdo' => new \PDO('sqlite::memory:'), 'reads' => 0, 'singleReads' => 0];
        $db->pdo->exec('CREATE TABLE values_fixture (owner INTEGER, code TEXT, scope TEXT, locale TEXT, value TEXT, cleared INTEGER)');
        $insert = $db->pdo->prepare('INSERT INTO values_fixture VALUES (?, ?, ?, ?, ?, ?)');
        foreach ([
            [1, 'name', 'default', 'en_US', 'English parent', 0],
            [1, 'name', 'default', 'zh_Hans_CN', '中文父类', 0],
            [2, 'name', 'default', '', 'Neutral child', 0],
            [2, 'image', '', '', 'global-image', 0],
            [2, 'banner', '', '', 'must-not-inherit', 0],
            [2, 'banner', 'default', 'en_US', '', 1],
        ] as $row) {
            $insert->execute($row);
        }
        $category = $this->getMockBuilder(Category::class)->disableOriginalConstructor()->disableOriginalClone()
            ->onlyMethods(['__clone', 'clearData', '__call'])->getMock();
        $category->method('clearData')->willReturnSelf();
        $category->method('__call')->willReturnCallback(static fn(string $method) => $method === 'fetchArray' ? [
            ['category_id' => 1, 'website_id' => 7, 'name' => 'Parent', 'slug' => 'parent', 'parent_id' => 0, 'sort_order' => 1],
            ['category_id' => 2, 'website_id' => 0, 'name' => 'Child', 'slug' => 'child', 'parent_id' => 1, 'sort_order' => 2],
        ] : $category);
        $store = $this->createMockForIntersectionOfInterfaces([EntityAttributeStoreInterface::class, ScopedAttributeBatchReaderInterface::class]);
        $store->method('getAttribute')->willReturnCallback(static fn($entity, string $code) => new AttributeRecord(
            ['name' => 36, 'image' => 37, 'banner' => 38, 'summary' => 39, 'description' => 40][$code], 6, $code, $code, 5, 'input_string_255', 9, 15, false, false,
        ));
        $read = static function (array $ids, array $attributes, $scope, string $locale) use ($db): array {
            $db->reads++;
            $codes = array_map(static fn(AttributeRecord $a) => $a->code, $attributes);
            $sql = 'SELECT * FROM values_fixture WHERE owner IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND code IN (' . implode(',', array_fill(0, count($codes), '?')) . ')';
            $query = $db->pdo->prepare($sql);
            $query->execute([...$ids, ...$codes]);
            $records = [];
            foreach ($query->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $records[$row['owner']][$row['code']][EavScopeResolver::recordKey($row['scope'], $row['locale'])] = ['value' => $row['value'], 'cleared' => (bool)$row['cleared']];
            }
            $values = [];
            foreach ($ids as $id) {
                foreach ($attributes as $attribute) {
                    $values[$id][$attribute->code] = (new EavScopeResolver())->resolveForIdentity($records[$id][$attribute->code] ?? [], $scope, $locale);
                }
            }
            return $values;
        };
        $store->method('readScopedValues')->willReturnCallback(static fn($entity, array $ids, array $attributes, $scope, string $locale) => $read($ids, $attributes, $scope, $locale));
        $store->method('readScopedValue')->willReturnCallback(static function ($entity, $id, AttributeRecord $attribute, $scope, string $locale) use ($db, $read) {
            $db->singleReads++;
            return $read([$id], [$attribute], $scope, $locale)[$id][$attribute->code];
        });
        $entity = (new \ReflectionClass(BlogCategoryAttributeEntity::class))->newInstanceWithoutConstructor();
        $admin = new BlogCategoryAdminService($category, new BlogCategoryAttributeService($store, $entity));
        (new \ReflectionProperty($admin, 'contentCache'))->setValue($admin, new BlogContentCache(new StorefrontScopeHotCache()));
        return [$admin, $db];
    }
}
