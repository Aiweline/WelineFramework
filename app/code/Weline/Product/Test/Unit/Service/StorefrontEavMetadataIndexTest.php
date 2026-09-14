<?php
declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Eav\Api\Entity\EntityDefinitionInterface;
use Weline\Eav\Api\Metadata\AttributeMetadata;
use Weline\Eav\Api\Metadata\AttributeGroupMetadata;
use Weline\Eav\Api\Metadata\AttributeSetMetadata;
use Weline\Eav\Api\Metadata\AttributeOptionMetadata;
use Weline\Eav\Api\Metadata\AttributeMetadataCatalogInterface;
use Weline\Eav\Api\Metadata\AttributeMetadataCodeIndexInterface;
use Weline\Eav\Service\AttributeMetadataCatalog;
use Weline\Framework\App\State;
use Weline\Framework\Runtime\RequestContext;
use Weline\Product\Model\ProductCatalogAttributeEntity;
use Weline\Product\Service\StorefrontEavLabelResolver;

final class StorefrontEavMetadataIndexTest extends TestCase
{
    protected function setUp(): void
    {
        RequestContext::init();
        RequestContext::setWelineUserLang('en_US');
    }

    protected function tearDown(): void
    {
        RequestContext::cleanup();
        State::resetRequestPathLocalizationCache();
    }

    public function testResolverUsesIndexOncePerCodeAndPreservesProductScope(): void
    {
        $shared = $this->set('Common', 'public');
        $privateA = $this->set('Only A', 'private-a');
        $privateB = $this->set('Only B', 'private-b');
        $provider = new CodeIndexMetadataFixture([$shared]);
        $provider->products = [11 => [$shared, $privateA], 22 => [$shared, $privateB]];
        $entity = (new ReflectionClass(ProductCatalogAttributeEntity::class))->newInstanceWithoutConstructor();
        $base = new StorefrontEavLabelResolver($provider, $entity);
        $a = $base->forProduct(11);
        $b = $base->forProduct(22);
        self::assertSame('Common', $a->resolve('color', 'public'));
        self::assertSame('Only A', $a->resolve('color', 'private-a'));
        self::assertSame('Only A', $a->attributeLabel('color'));
        self::assertSame('Only B', $b->resolve('color', 'private-b'));
        self::assertSame('Only B', $b->attributeLabel('color'));
        self::assertSame('Common', $base->resolve('color', 'public'));
        self::assertSame('Common', $base->attributeLabel('color'));
        self::assertSame(3, $provider->indexCalls, '选项与属性名应使用同一按code索引结果。');
        self::assertSame(3, $provider->catalogCalls);
    }

    public function testLocaleAndReplacedCatalogUseNewDtoIndex(): void
    {
        $provider = new CodeIndexMetadataFixture([$this->set('English', 'public')]);
        $entity = (new ReflectionClass(ProductCatalogAttributeEntity::class))->newInstanceWithoutConstructor();
        $labels = (new StorefrontEavLabelResolver($provider, $entity))->forProduct(11);
        self::assertSame('English', $labels->resolve('color', 'public'));
        $provider->sets = [$this->set('Français', 'public')];
        RequestContext::setWelineUserLang('fr_FR');
        self::assertSame('Français', $labels->resolve('color', 'public'));
        self::assertSame('Français', $labels->attributeLabel('color'));
        $provider->sets = [$this->set('Updated', 'public')];
        RequestContext::cleanup();
        RequestContext::init();
        RequestContext::setWelineUserLang('fr_FR');
        $next = (new StorefrontEavLabelResolver($provider, $entity))->forProduct(11);
        self::assertSame('Updated', $next->resolve('color', 'public'));
        self::assertSame(3, $provider->indexCalls);
    }

    public function testIndexPreservesDtoReferencesPlacementOrderAndReleasesCatalog(): void
    {
        $catalog = (new ReflectionClass(AttributeMetadataCatalog::class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(AttributeMetadataCodeIndexInterface::class, $catalog);
        $first = $this->set('First', 'same');
        $second = $this->set('Second', 'same');
        $attribute = $first->groups[0]->attributes[0];
        $other = $second->groups[0]->attributes[0];
        self::assertSame([$attribute, $attribute, $other], $catalog->attributesByCode([$first, $first, $second], ' Color '));
        self::assertSame([], $catalog->attributesByCode([$first], 'missing'));
        self::assertSame([$other], $catalog->attributesByCode([$second], 'color'));
        $reference = \WeakReference::create($first);
        unset($first);
        gc_collect_cycles();
        self::assertNull($reference->get(), '索引不能反向持有属性集，使私有目录滞留进程。');
    }

    public function testManyProductsReuseOneImmutableOptionTokenIndex(): void
    {
        $options = [];
        for ($id = 1; $id <= 1024; $id++) {
            $options[] = new AttributeOptionMetadata($id, 'source-' . $id, 'code-' . $id, 'Label ' . $id, $id);
        }
        $attribute = $this->optionTokenAttribute($options);
        [$provider] = $this->optionTokenProvider([$this->optionTokenSet([$attribute])]);
        $entity = (new \ReflectionClass(ProductCatalogAttributeEntity::class))->newInstanceWithoutConstructor();
        $base = new StorefrontEavLabelResolver($provider, $entity);
        self::assertSame('Label 1024', $base->resolve('style_type', 'code-1024'));
        $before = memory_get_usage(false);
        $retained = [];
        for ($product = 1; $product <= 32; $product++) {
            $labels = $base->forProduct($product);
            self::assertSame('Label 1024', $labels->resolve('style_type', 'CODE-1024'));
            $retained[] = $labels;
        }
        self::assertLessThan(2 * 1048576, memory_get_usage(false) - $before,
            'Product clones must reuse the Eav option index instead of copying every shared alias.');
    }

    public function testLookupPreservesDuplicateDtoAndAliasPrecedenceInBothOrders(): void
    {
        $a = new AttributeOptionMetadata(11, 'source-a', 'Alias', 'First', 0);
        $b = new AttributeOptionMetadata(22, 'source-b', 'ALIAS', 'Upper', 0);
        $c = new AttributeOptionMetadata(33, 'source-c', 'Alias', 'Last', 0);
        $first = $this->optionTokenAttribute([$a, $b]);
        $second = $this->optionTokenAttribute([$c, $a]);
        $owner = (new \ReflectionClass(AttributeMetadataCatalog::class))->newInstanceWithoutConstructor();
        self::assertTrue(method_exists($owner, 'findOptionByToken'), 'Eav must own the reusable token lookup.');
        self::assertSame($c, $owner->findOptionByToken([$first, $second, $first], 'Alias'));
        self::assertSame($c, $owner->findOptionByToken([$first, $second, $first], 'aLiAs'));
        self::assertSame($b, $owner->findOptionByToken([$first, $second, $first], 'ALIAS'));
        self::assertSame($a, $owner->findOptionByToken([$second, $first], 'Alias'));
        self::assertSame($a, $owner->findOptionByToken([$second, $first], 'aLiAs'));
        self::assertSame($b, $owner->findOptionByToken([$second, $first], 'ALIAS'));
        self::assertSame($a, $owner->findOptionByToken([$first], '11'));
        self::assertSame($b, $owner->findOptionByToken([$first], 'SOURCE-B'));
        self::assertNull($owner->findOptionByToken([$first], 'unknown'));
    }

    public function testPrivateOverlayAndWeakKeysDoNotLeakToAnotherProduct(): void
    {
        $shared = $this->optionTokenAttribute([new AttributeOptionMetadata(1, 'shared', 'token', 'Shared', 0)]);
        $privateA = $this->optionTokenAttribute([new AttributeOptionMetadata(2, 'only-a', 'token', 'Only A', 0)]);
        $privateB = $this->optionTokenAttribute([new AttributeOptionMetadata(3, 'only-b', 'token', 'Only B', 0)]);
        $owner = (new \ReflectionClass(AttributeMetadataCatalog::class))->newInstanceWithoutConstructor();
        self::assertTrue(method_exists($owner, 'findOptionByToken'));
        self::assertSame('Only A', $owner->findOptionByToken([$shared, $privateA], 'token')->label);
        self::assertSame('Only B', $owner->findOptionByToken([$shared, $privateB], 'token')->label);
        self::assertSame('Shared', $owner->findOptionByToken([$shared], 'token')->label);
        $attributeRef = \WeakReference::create($privateA);
        $optionRef = \WeakReference::create($privateA->options[0]);
        unset($privateA);
        gc_collect_cycles();
        self::assertNull($attributeRef->get());
        self::assertNull($optionRef->get(), 'Private branch values must release when their immutable attribute key expires.');
        self::assertSame('Only B', $owner->findOptionByToken([$shared, $privateB], 'token')->label);
    }

    public function testFailedOptionalLookupFallsBackWithoutLosingTheOriginalLabel(): void
    {
        [$provider] = $this->optionTokenProvider([$this->optionTokenSet([$this->optionTokenAttribute([
            new AttributeOptionMetadata(7, 'raw-source', 'code', 'Translated', 0),
        ])])], true);
        $entity = (new \ReflectionClass(ProductCatalogAttributeEntity::class))->newInstanceWithoutConstructor();
        $labels = (new StorefrontEavLabelResolver($provider, $entity))->forProduct(11);
        self::assertSame('Translated', $labels->resolve('style_type', 'CODE'));
        self::assertSame('Translated', $labels->resolve('style_type', '7'));
    }

    public function testProductIdentityTokenDoesNotBuildTheFullProductCatalog(): void
    {
        $provider = $this->createMockForIntersectionOfInterfaces([
            AttributeMetadataCatalogInterface::class,
            \Weline\Eav\Api\Metadata\AttributeProductOptionIdentityCatalogInterface::class,
        ]);
        $provider->expects(self::never())->method('catalogForProduct');
        $provider->expects(self::once())->method('productOptionIdentity')
            ->willReturn(new AttributeOptionMetadata(7, 'source', 'code', 'Translated', 0));
        $entity = (new ReflectionClass(ProductCatalogAttributeEntity::class))->newInstanceWithoutConstructor();
        $labels = (new StorefrontEavLabelResolver($provider, $entity))->forProduct(11);

        self::assertSame('Translated', $labels->resolve('color', 'code'));
    }

    public function testOptionalTokenLookupRefreshesTheSameResolverWhenLocaleChanges(): void
    {
        $sets = [
            'en_US' => [$this->optionTokenSet([$this->optionTokenAttribute([
                new AttributeOptionMetadata(7, 'source', 'code', 'English', 0),
            ])])],
            'fr_FR' => [$this->optionTokenSet([$this->optionTokenAttribute([
                new AttributeOptionMetadata(7, 'source', 'code', 'Français', 0),
            ])])],
        ];
        $owner = (new ReflectionClass(AttributeMetadataCatalog::class))->newInstanceWithoutConstructor();
        $provider = $this->createMockForIntersectionOfInterfaces([
            AttributeMetadataCatalogInterface::class,
            AttributeMetadataCodeIndexInterface::class,
            \Weline\Eav\Api\Metadata\AttributeMetadataOptionTokenIndexInterface::class,
        ]);
        $provider->method('catalogForProduct')->willReturnCallback(
            static fn(): array => $sets[RequestContext::getWelineUserLang()],
        );
        $provider->method('attributesByCode')->willReturnCallback($owner->attributesByCode(...));
        $provider->method('findOptionByToken')->willReturnCallback($owner->findOptionByToken(...));
        $entity = (new ReflectionClass(ProductCatalogAttributeEntity::class))->newInstanceWithoutConstructor();
        $labels = (new StorefrontEavLabelResolver($provider, $entity))->forProduct(11);
        self::assertSame('English', $labels->resolve('style_type', 'code'));
        RequestContext::setWelineUserLang('fr_FR');
        self::assertSame('Français', $labels->resolve('style_type', 'code'));
    }

    public function testSameLocaleRequestChangeRefreshesTheSameResolver(): void
    {
        $provider = new CodeIndexMetadataFixture([$this->set('First', 'public')]);
        $entity = (new ReflectionClass(ProductCatalogAttributeEntity::class))->newInstanceWithoutConstructor();
        $labels = (new StorefrontEavLabelResolver($provider, $entity))->forProduct(11);
        self::assertSame('First', $labels->resolve('color', 'public'));

        $provider->sets = [$this->set('Second', 'public')];
        RequestContext::setId('eav-label-next-request');
        self::assertSame('Second', $labels->resolve('color', 'public'));
        self::assertSame(2, $provider->catalogCalls);
    }

    private function optionTokenProvider(array $sets, bool $throw = false): array
    {
        $interfaces = [AttributeMetadataCatalogInterface::class, AttributeMetadataCodeIndexInterface::class];
        $optional = 'Weline\\Eav\\Api\\Metadata\\AttributeMetadataOptionTokenIndexInterface';
        if (interface_exists($optional)) { $interfaces[] = $optional; }
        $provider = $this->createMockForIntersectionOfInterfaces($interfaces);
        $owner = (new \ReflectionClass(AttributeMetadataCatalog::class))->newInstanceWithoutConstructor();
        $provider->method('catalog')->willReturn($sets);
        $provider->method('catalogForProduct')->willReturn($sets);
        $provider->method('attributeIndexByEntityCode')->willReturn([]);
        $provider->method('attributesByCode')->willReturnCallback($owner->attributesByCode(...));
        if (interface_exists($optional)) {
            $provider->method('findOptionByToken')->willReturnCallback(static function (array $attributes, string $token) use ($owner, $throw): ?AttributeOptionMetadata {
                if ($throw) { throw new \RuntimeException('temporary option index unavailable'); }
                return $owner->findOptionByToken($attributes, $token);
            });
        }
        return [$provider, $owner];
    }

    private function optionTokenAttribute(array $options): AttributeMetadata
    {
        return new AttributeMetadata(7, 1, 'style_type', 'Type', 'varchar', 'select', 'select', 1, 1,
            false, false, true, true, 0, $options);
    }
    private function optionTokenSet(array $attributes): AttributeSetMetadata
    {
        return new AttributeSetMetadata(1, 1, 'set', 'Set', 0,
            [new AttributeGroupMetadata(1, 1, 1, 'group', 'Group', 0, $attributes)]);
    }

    private function set(string $label, string $token): AttributeSetMetadata
    {
        $attribute = new AttributeMetadata(7, 1, 'color', $label, 'varchar', 'select', 'select', 1, 1,
            false, false, true, true, 0, [new AttributeOptionMetadata(48, $token, $token, $label, 0)]);
        return new AttributeSetMetadata(1, 1, 'set', 'Set', 0,
            [new AttributeGroupMetadata(1, 1, 1, 'group', 'Group', 0, [$attribute])]);
    }
}

final class CodeIndexMetadataFixture implements AttributeMetadataCatalogInterface, AttributeMetadataCodeIndexInterface
{
    public int $catalogCalls = 0;
    public int $indexCalls = 0;
    public array $products = [];
    private AttributeMetadataCatalog $index;

    public function __construct(public array $sets)
    {
        $this->index = (new ReflectionClass(AttributeMetadataCatalog::class))->newInstanceWithoutConstructor();
    }
    public function catalog(EntityDefinitionInterface $entity): array { ++$this->catalogCalls; return $this->sets; }
    public function catalogForProduct(EntityDefinitionInterface $entity, int $productId, string $freeSetCode = '__product_free'): array
    { ++$this->catalogCalls; return $this->products[$productId] ?? $this->sets; }
    public function attributeIndexByEntityCode(string $entityCode): array { return []; }
    public function attributesByCode(array $sets, string $attributeCode): array
    { ++$this->indexCalls; return $this->index->attributesByCode($sets, $attributeCode); }
}
