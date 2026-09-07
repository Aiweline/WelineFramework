<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Blog\Model\BlogCategoryAttributeEntity;
use Weline\Blog\Model\Category;
use Weline\Blog\Service\BlogCategoryAttributeService;
use Weline\Blog\Service\BlogNewsCategoryBootstrap;
use Weline\Eav\Api\Attribute\AttributeRecord;
use Weline\Eav\Api\Attribute\EntityAttributeStoreInterface;
use Weline\Eav\Api\Scope\EavScopeValue;

final class BlogNewsCategoryLocaleTest extends TestCase
{
    public function testNewNewsCategoryReadsEnglishAndChineseNames(): void
    {
        [$bootstrap, $attributes] = $this->fixture([]);

        self::assertTrue($bootstrap->ensure(0)['created']);
        self::assertSame('News Center', $attributes->readName(0, 91, 'en_US'));
        self::assertSame('新闻中心', $attributes->readName(0, 91, 'zh_Hans_CN'));
    }

    public function testExistingNewsCategoryKeepsItsCustomEnglishName(): void
    {
        [$bootstrap, $attributes] = $this->fixture(['category_id' => 91], [
            'name:' => '新闻中心',
            'name:en_US' => 'From the Atelier',
        ]);

        self::assertFalse($bootstrap->ensure(0)['created']);
        self::assertSame('From the Atelier', $attributes->readName(0, 91, 'en_US'));
        self::assertSame('新闻中心', $attributes->readName(0, 91, 'zh_Hans_CN'));
    }

    /**
     * Only persistence boundaries are doubled. Bootstrap and locale writes/reads remain real.
     * @return array{BlogNewsCategoryBootstrap, BlogCategoryAttributeService}
     */
    private function fixture(array $existing, array $values = []): array
    {
        $category = $this->getMockBuilder(Category::class)
            ->disableOriginalConstructor()
            ->disableOriginalClone()
            ->onlyMethods(['__clone', 'clearData', '__call', 'save', 'getCategoryId'])
            ->getMock();
        $category->method('clearData')->willReturnSelf();
        $category->method('__call')->willReturnCallback(
            static fn(string $method, array $arguments): mixed => $method === 'fetchArray' ? $existing : $category,
        );
        $category->method('save')->willReturn(true);
        $category->method('getCategoryId')->willReturn(91);

        $store = $this->createStub(EntityAttributeStoreInterface::class);
        $store->method('getAttribute')->willReturnCallback(
            static fn($entity, string $code): AttributeRecord => new AttributeRecord(
                $code === 'name' ? 36 : 37, 6, $code, $code, 5, 'input_string_255', 9, 15, false, false,
            ),
        );
        $store->method('writeScopedValue')->willReturnCallback(
            static function ($entity, $ownerId, AttributeRecord $attribute, $scope, $value, string $locale) use (&$values): void {
                $values[$attribute->code . ':' . $locale] = $value;
            },
        );
        $store->method('readScopedValue')->willReturnCallback(
            static function ($entity, $ownerId, AttributeRecord $attribute, $scope, string $locale) use (&$values): EavScopeValue {
                $value = $values[$attribute->code . ':' . $locale] ?? $values[$attribute->code . ':'] ?? null;
                return $value === null ? EavScopeValue::unresolved() : EavScopeValue::explicit($value, 'default');
            },
        );
        $entity = (new \ReflectionClass(BlogCategoryAttributeEntity::class))->newInstanceWithoutConstructor();
        $attributes = new BlogCategoryAttributeService($store, $entity);

        return [new BlogNewsCategoryBootstrap($category, $attributes), $attributes];
    }
}
