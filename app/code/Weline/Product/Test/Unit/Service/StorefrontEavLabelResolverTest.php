<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Eav\Api\Entity\EntityDefinitionInterface;
use Weline\Eav\Api\Metadata\AttributeGroupMetadata;
use Weline\Eav\Api\Metadata\AttributeMetadata;
use Weline\Eav\Api\Metadata\AttributeMetadataCatalogInterface;
use Weline\Eav\Api\Metadata\AttributeOptionIdentityCatalogInterface;
use Weline\Eav\Api\Metadata\AttributeOptionMetadata;
use Weline\Eav\Api\Metadata\AttributeSetMetadata;
use Weline\Eav\Api\Attribute\Option\AttributeOptionStoreInterface;
use Weline\Framework\Runtime\RequestContext;
use Weline\Product\Model\ProductCatalogAttributeEntity;
use Weline\Product\Service\StorefrontEavLabelResolver;
use Weline\Product\Service\StorefrontVariantAxisResolver;

final class StorefrontEavLabelResolverTest extends TestCase
{
    public function testPublicQueryBatchesOnlyParticipatingAxesWithoutReadingDisplayCatalog(): void
    {
        $metadata = $this->createMockForIntersectionOfInterfaces([
            AttributeMetadataCatalogInterface::class,
            AttributeOptionIdentityCatalogInterface::class,
        ]);
        $entity = (new \ReflectionClass(ProductCatalogAttributeEntity::class))->newInstanceWithoutConstructor();
        $metadata->expects(self::never())->method('catalog');
        $calls = [];
        $metadata->expects(self::exactly(4))->method('sharedOptionIdentities')
            ->with($entity, self::anything())
            ->willReturnCallback(static function ($entity, array $attributeCodes) use (&$calls): array {
                $calls[] = $attributeCodes;
                return [
                    'color' => [new AttributeOptionMetadata(48, '白色', 'white', '白色', 48)],
                    'size' => [new AttributeOptionMetadata(60, '成人L', 'adult-l', '成人L', 60)],
                ];
            });
        $resolver = new StorefrontEavLabelResolver($metadata, $entity);
        self::assertSame(['color' => 'white', 'size' => 'adult-l'], $resolver->toPublicQuery([
            ' Color ' => 48, 'size' => '成人L', 'ignored' => [],
        ]));
        self::assertSame('48', $resolver->canonicalOptionId('color', 'WHITE'));
        self::assertSame('unknown', $resolver->publicOptionCode('size', 'unknown'));
        self::assertSame(['color' => '48', 'page' => '2'], $resolver->canonicalizeAxisQuery(
            ['color' => 'white', 'page' => '2'], ['color', 'absent'],
        ));
        self::assertSame([['color', 'size'], ['color'], ['size'], ['color']], $calls);
    }

    public function testFailedIdentityBatchCanRetryAndDoesNotBecomeAnEmptyAxis(): void
    {
        $metadata = $this->createMockForIntersectionOfInterfaces([
            AttributeMetadataCatalogInterface::class,
            AttributeOptionIdentityCatalogInterface::class,
        ]);
        $entity = (new \ReflectionClass(ProductCatalogAttributeEntity::class))->newInstanceWithoutConstructor();
        $attempt = 0;
        $metadata->expects(self::exactly(2))->method('sharedOptionIdentities')
            ->willReturnCallback(static function () use (&$attempt): array {
                if (++$attempt === 1) {
                    throw new \RuntimeException('temporary identity read failure');
                }
                return ['color' => [new AttributeOptionMetadata(48, '白色', 'white', '白色', 48)]];
            });
        $resolver = new StorefrontEavLabelResolver($metadata, $entity);
        self::assertSame('48', $resolver->publicOptionCode('color', '48'));
        self::assertSame('white', $resolver->publicOptionCode('color', '48'));
    }

    public function testIdentityProjectionDoesNotReplaceDisplayOrPrivateScopeAndExpiresWithRequest(): void
    {
        $original = \Weline\Framework\Context::getCurrent();
        if ($original !== null) {
            \Weline\Framework\Context::leave();
        }
        \Weline\Framework\Context::enter(new \Weline\Framework\Context());
        RequestContext::setId('identity-first');
        try {
            $metadata = $this->createMockForIntersectionOfInterfaces([
                AttributeMetadataCatalogInterface::class,
                AttributeOptionIdentityCatalogInterface::class,
            ]);
            $entity = (new \ReflectionClass(ProductCatalogAttributeEntity::class))->newInstanceWithoutConstructor();
            $metadata->expects(self::exactly(2))->method('sharedOptionIdentities')->willReturn(
                ['style_type' => [new AttributeOptionMetadata(48, '粉色仅上衣2307', 'look', '粉色仅上衣2307', 48)]],
                ['style_type' => [new AttributeOptionMetadata(48, '粉色仅上衣2307', 'updated', '粉色仅上衣2307', 48)]],
            );
            $metadata->expects(self::once())->method('catalog')->willReturn([$this->privateOptionSet(48, 'Translated shared')]);
            $metadata->expects(self::once())->method('catalogForProduct')->with($entity, 101)
                ->willReturn([$this->privateOptionSet(77, 'Translated private')]);
            $resolver = new StorefrontEavLabelResolver($metadata, $entity);
            self::assertSame('look', $resolver->publicOptionCode('style_type', '48'));
            self::assertSame('Translated shared', $resolver->resolve('style_type', '48'));
            $private = $resolver->forProduct(101);
            self::assertSame('77', $private->canonicalOptionId('style_type', 'look'));
            self::assertSame('Translated private', $private->resolve('style_type', '77'));
            \Weline\Framework\Context::leave();
            \Weline\Framework\Context::enter(new \Weline\Framework\Context());
            RequestContext::setId('identity-second');
            self::assertSame('updated', $resolver->publicOptionCode('style_type', '48'));
        } finally {
            \Weline\Framework\Context::leave();
            if ($original !== null) {
                \Weline\Framework\Context::enter($original);
            }
        }
    }

    public function testCanonicalIdCodeAndStoredValueResolveToTheSameLabel(): void
    {
        [$metadata, $entity] = $this->metadata();
        $resolver = new StorefrontEavLabelResolver($metadata, $entity);

        self::assertSame('白色仅上衣2307', $resolver->resolve('color', '48'));
        self::assertSame('白色仅上衣2307', $resolver->resolve('color', 'white-top-2307'));
        self::assertSame('白色仅上衣2307', $resolver->resolve('color', '白色仅上衣2307'));
        self::assertSame('unknown', $resolver->resolve('color', 'unknown'));
    }

    public function testExactOptionAliasTakesPrecedenceOverCaseInsensitiveFallback(): void
    {
        [$metadata, $entity] = $this->metadata();
        $resolver = new StorefrontEavLabelResolver($metadata, $entity);

        self::assertSame('精确大写选项', $resolver->resolve('color', 'WHITE-TOP-2307'));
    }

    public function testAttributeLabelUsesEavMetadataName(): void
    {
        [$metadata, $entity] = $this->metadata();
        $resolver = new StorefrontEavLabelResolver($metadata, $entity);

        self::assertSame('颜色', $resolver->attributeLabel('color'));
        self::assertSame('颜色', $resolver->attributeLabel('Color'));
        self::assertSame('', $resolver->attributeLabel('missing_attr'));
    }

    public function testPublicUrlPrefersOptionCodeAndCanonicalizesBackToId(): void
    {
        [$metadata, $entity] = $this->metadata();
        $resolver = new StorefrontEavLabelResolver($metadata, $entity);

        self::assertSame('white-top-2307', $resolver->publicOptionCode('color', '48'));
        self::assertSame('white-top-2307', $resolver->publicOptionCode('color', 'white-top-2307'));
        self::assertSame('48', $resolver->canonicalOptionId('color', 'white-top-2307'));
        self::assertSame(
            ['color' => 'white-top-2307'],
            $resolver->toPublicQuery(['color' => '48']),
        );
        self::assertSame(
            ['color' => '48', 'slug' => 'keep'],
            $resolver->canonicalizeAxisQuery(
                ['color' => 'white-top-2307', 'slug' => 'keep'],
                ['color'],
            ),
        );
    }

    public function testVariantAxisKeepsCanonicalIdAndDisplaysTheEavLabel(): void
    {
        [$metadata, $entity] = $this->metadata();
        $labels = new StorefrontEavLabelResolver($metadata, $entity);
        $resolver = new StorefrontVariantAxisResolver($metadata, $entity, $labels);

        $axes = $resolver->buildAxes(
            ['color' => '48'],
            ['color' => ['48']],
        );

        self::assertSame('48', $axes[0]['value']);
        self::assertSame('白色仅上衣2307', $axes[0]['value_label']);
        self::assertSame('48', $axes[0]['options'][0]['value']);
        self::assertSame('white-top-2307', $axes[0]['options'][0]['code']);
        self::assertSame('白色仅上衣2307', $axes[0]['options'][0]['label']);
        self::assertSame('#ffffff', $axes[0]['options'][0]['swatch_color']);
    }

    public function testVariantAxisReusesProductScopedLabelResolverWithinOneProjection(): void
    {
        [$metadata, $entity] = $this->metadata();
        $labels = new class extends StorefrontEavLabelResolver {
            public int $forProductCalls = 0;

            public function __construct()
            {
            }

            public function forProduct(int $productId): self
            {
                ++$this->forProductCalls;

                return $this;
            }
        };
        $resolver = (new StorefrontVariantAxisResolver($metadata, $entity, $labels))->forProduct(83);
        $method = new \ReflectionMethod(StorefrontVariantAxisResolver::class, 'scopedLabels');

        $first = $method->invoke($resolver);
        $second = $method->invoke($resolver);

        self::assertSame($first, $second);
        self::assertSame(1, $labels->forProductCalls);
    }

    public function testVariantAxisReusesTheLastProductScopedResolverAcrossOfferProjections(): void
    {
        [$metadata, $entity] = $this->metadata();
        $labels = new StorefrontEavLabelResolver($metadata, $entity);
        $resolver = new StorefrontVariantAxisResolver($metadata, $entity, $labels);

        $first = $resolver->forProduct(109);
        $second = $resolver->forProduct(109);
        $nextProduct = $resolver->forProduct(110);

        self::assertSame($first, $second);
        self::assertNotSame($first, $nextProduct);
    }

    public function testProductScopedPrivateOptionResolvesByOptionId(): void
    {
        [$metadata, $entity] = $this->metadata();
        $store = new class implements \Weline\Eav\Api\Attribute\Option\AttributeOptionStoreInterface {
            public function register(\Weline\Eav\Api\Attribute\Option\AttributeOptionDefinition $definition): void
            {
            }

            public function find(int $attributeId, string $code): ?\Weline\Eav\Api\Attribute\Option\AttributeOptionRecord
            {
                return null;
            }

            public function findInScope(
                int $attributeId,
                string $code,
                int $scopeInstanceId,
            ): ?\Weline\Eav\Api\Attribute\Option\AttributeOptionRecord {
                return null;
            }

            public function ensureInScope(
                int $eavEntityId,
                int $attributeId,
                int $scopeInstanceId,
                string $code,
                string $label,
                string $swatchColor = '',
                string $swatchImage = '',
                string $swatchText = '',
            ): \Weline\Eav\Api\Attribute\Option\AttributeOptionRecord {
                throw new \RuntimeException('unused');
            }

            public function assertUsableByInstance(
                int $optionId,
                int $scopeInstanceId,
            ): \Weline\Eav\Api\Attribute\Option\AttributeOptionRecord {
                if ($optionId === 597 && $scopeInstanceId === 125) {
                    return new \Weline\Eav\Api\Attribute\Option\AttributeOptionRecord(
                        id: 597,
                        attributeId: 40,
                        code: 'si-ma-yi',
                        value: '司马懿',
                        scopeInstanceId: 125,
                    );
                }
                throw new \InvalidArgumentException('eav_attribute_option_scope_forbidden');
            }
        };
        $labels = (new StorefrontEavLabelResolver($metadata, $entity, $store))->forProduct(125);

        self::assertSame('司马懿', $labels->resolve('character', '597'));
        self::assertSame('si-ma-yi', $labels->publicOptionCode('character', '597'));
        self::assertSame('597', $labels->canonicalOptionId('character', '597'));
    }

    public function testProductScopedMetadataUsesTranslatedLabelAndPreservesOptionIdentities(): void
    {
        [, $entity] = $this->metadata();
        $metadata = $this->createMock(AttributeMetadataCatalogInterface::class);
        $metadata->method('catalog')->willReturn([]);
        $metadata->expects(self::once())->method('catalogForProduct')
            ->with($entity, 83)
            ->willReturn([$this->privateOptionSet(77, 'Pink Top Only 2307')]);
        $store = $this->createMock(AttributeOptionStoreInterface::class);
        $store->expects(self::never())->method('assertUsableByInstance');
        $labels = (new StorefrontEavLabelResolver($metadata, $entity, $store))->forProduct(83);

        foreach (['77', 'look', '粉色仅上衣2307'] as $identity) {
            self::assertSame('Pink Top Only 2307', $labels->resolve('style_type', $identity));
            self::assertSame('77', $labels->canonicalOptionId('style_type', $identity));
            self::assertSame('look', $labels->publicOptionCode('style_type', $identity));
        }
        self::assertSame('Style', $labels->attributeLabel('style_type'));
    }

    public function testWarmedMetadataDoesNotLeakAcrossProductsOrIntoSharedScope(): void
    {
        [, $entity] = $this->metadata();
        $metadata = $this->createMock(AttributeMetadataCatalogInterface::class);
        $metadata->method('catalog')->willReturn([]);
        $metadata->method('catalogForProduct')->willReturnCallback(
            fn(EntityDefinitionInterface $entity, int $productId): array => [
                $this->privateOptionSet($productId === 83 ? 77 : 78, $productId === 83 ? 'Pink Top Only 2307' : 'Red Top'),
            ],
        );
        $store = $this->createMock(AttributeOptionStoreInterface::class);
        $store->method('assertUsableByInstance')->willThrowException(new \InvalidArgumentException('option_not_in_scope'));
        $shared = new StorefrontEavLabelResolver($metadata, $entity, $store);
        self::assertSame('look', $shared->resolve('style_type', 'look'));

        $first = $shared->forProduct(83);
        self::assertSame('Pink Top Only 2307', $first->resolve('style_type', 'look'));
        $second = $first->forProduct(84);
        self::assertSame('Red Top', $second->resolve('style_type', 'look'));
        self::assertSame('78', $second->canonicalOptionId('style_type', 'look'));
        self::assertSame('77', $second->resolve('style_type', '77'));
        self::assertSame('Pink Top Only 2307', $first->resolve('style_type', 'look'));
        self::assertSame('look', $second->forProduct(0)->resolve('style_type', 'look'));
        self::assertSame('look', $shared->resolve('style_type', 'look'));
    }

    public function testProductMetadataRefreshesWhenTheRequestLanguageChanges(): void
    {
        [, $entity] = $this->metadata();
        $metadata = $this->createMock(AttributeMetadataCatalogInterface::class);
        $metadata->method('catalog')->willReturn([]);
        $metadata->expects(self::exactly(3))->method('catalogForProduct')->willReturnCallback(
            fn(): array => [$this->privateOptionSet(77, RequestContext::getWelineUserLang() === 'en_US'
                ? 'Pink Top Only 2307'
                : '粉色仅上衣2307')],
        );
        $store = $this->createMock(AttributeOptionStoreInterface::class);
        $store->expects(self::never())->method('assertUsableByInstance');
        $labels = (new StorefrontEavLabelResolver($metadata, $entity, $store))->forProduct(83);
        $originalLocale = RequestContext::getWelineUserLang();

        try {
            foreach (['en_US' => 'Pink Top Only 2307', 'zh_Hans_CN' => '粉色仅上衣2307'] as $locale => $label) {
                RequestContext::setWelineUserLang($locale);
                self::assertSame($label, $labels->resolve('style_type', '77'));
                self::assertSame($label, $labels->resolve('style_type', 'look'));
            }
            RequestContext::setWelineUserLang('en_US');
            self::assertSame('Pink Top Only 2307', $labels->resolve('style_type', '77'));
        } finally {
            RequestContext::setWelineUserLang($originalLocale);
        }
    }

    public function testLegacyOptionLookupReusesSqlIdsAndMissesWithinTheRequest(): void
    {
        $this->withLegacyOptionLookupFixture(function (callable $resolver, object $db, callable $newRequest): void {
            self::assertSame('shared:101:en_US', $resolver(101)->resolve('plain_text', 'shared'));
            self::assertSame('shared:202:en_US', $resolver(202)->resolve('unknown_attribute', 'shared'));
            self::assertSame([[7, 101], [7, 202]], $db->validated);
            self::assertSame(1, $db->reads['0:shared']);

            self::assertSame('private-a:101:en_US', $resolver(101)->resolve('plain_text', 'private'));
            self::assertSame('private-b:202:en_US', $resolver(202)->resolve('plain_text', 'private'));
            self::assertSame(1, $db->reads['0:private']);
            self::assertSame(1, $db->reads['101:private']);
            self::assertSame(1, $db->reads['202:private']);

            self::assertSame('cotton', $resolver(101)->resolve('plain_text', 'cotton'));
            self::assertSame('cotton', $resolver(202)->resolve('plain_text', 'cotton'));
            self::assertSame('cotton', $resolver(101)->resolve('plain_text', 'cotton'));
            self::assertSame(1, $db->reads['0:cotton']);
            self::assertSame(1, $db->reads['101:cotton']);
            self::assertSame(1, $db->reads['202:cotton']);

            RequestContext::setWelineUserLang('fr_FR');
            self::assertSame('shared:101:fr_FR', $resolver(101)->resolve('plain_text', 'shared'));
            self::assertSame(1, $db->reads['0:shared']);

            $db->pdo->exec("INSERT INTO options VALUES (11, 'cotton', 0)");
            $db->records[11] = [11, 'cotton', 0, 'new-option'];
            $newRequest();
            self::assertSame('new-option:202:en_US', $resolver(202)->resolve('plain_text', 'cotton'));
            self::assertSame(2, $db->reads['0:cotton']);
        });
    }

    public function testFailedLegacyOptionLookupIsRetriedInsteadOfMemoizedAsMissing(): void
    {
        $this->withLegacyOptionLookupFixture(function (callable $resolver, object $db): void {
            $db->failures['0:retry'] = 1;
            self::assertSame('retry', $resolver(101)->resolve('plain_text', 'retry'));
            self::assertSame('retry-shared:101:en_US', $resolver(101)->resolve('plain_text', 'retry'));
            self::assertSame(2, $db->reads['0:retry']);
            self::assertSame([[10, 101]], $db->validated);
        });
    }

    private function withLegacyOptionLookupFixture(callable $run): void
    {
        $originalContext = \Weline\Framework\Context::getCurrent();
        $originalInstances = \Weline\Framework\Manager\ObjectManager::getInstances();
        $manager = new \ReflectionProperty(\Weline\Framework\Manager\ObjectManager::class, 'instance');
        $originalManager = $manager->getValue();
        $manager->setValue(null, (new \ReflectionClass(\Weline\Framework\Manager\ObjectManager::class))->newInstanceWithoutConstructor());
        $db = (object)[
            'pdo' => new \PDO('sqlite::memory:'),
            'reads' => [],
            'failures' => [],
            'validated' => [],
            'records' => [
                7 => [7, 'SHARED', 0, 'shared'],
                8 => [8, 'private', 101, 'private-a'],
                9 => [9, 'private', 202, 'private-b'],
                10 => [10, 'retry', 0, 'retry-shared'],
            ],
        ];
        $db->pdo->exec('CREATE TABLE options (option_id INTEGER PRIMARY KEY, code TEXT COLLATE NOCASE, scope_instance_id INTEGER)');
        $insert = $db->pdo->prepare('INSERT INTO options VALUES (?, ?, ?)');
        foreach ($db->records as $row) {
            $insert->execute(array_slice($row, 0, 3));
        }
        $model = new class($db) {
            private array $conditions = [];
            private int $optionId = 0;
            public function __construct(private readonly object $db) {}
            public function clearData(): self { $this->optionId = 0; return $this; }
            public function clearQuery(): self { $this->conditions = []; return $this; }
            public function where(string $field, mixed $value): self { $this->conditions[$field] = $value; return $this; }
            public function find(): self { return $this; }
            public function fetch(): self
            {
                $code = (string)$this->conditions['code'];
                $scope = (int)$this->conditions['scope_instance_id'];
                $key = $scope . ':' . $code;
                $this->db->reads[$key] = ($this->db->reads[$key] ?? 0) + 1;
                if (($this->db->failures[$key] ?? 0) > 0) {
                    --$this->db->failures[$key];
                    throw new \RuntimeException('temporary lookup failure');
                }
                $query = $this->db->pdo->prepare('SELECT option_id FROM options WHERE code = ? AND scope_instance_id = ? LIMIT 1');
                $query->execute([$code, $scope]);
                $this->optionId = (int)$query->fetchColumn();
                return $this;
            }
            public function getOptionId(): int { return $this->optionId; }
        };
        $cache = new \Weline\Framework\Cache\Service\StorefrontScopeHotCache();
        $newRequest = static function () use ($model, $cache): void {
            if (\Weline\Framework\Context::hasCurrent()) {
                \Weline\Framework\Context::leave();
            }
            \Weline\Framework\Context::enter(new \Weline\Framework\Context());
            RequestContext::setId(uniqid('option-lookup-', true));
            RequestContext::setWelineUserLang('en_US');
            \Weline\Framework\Manager\ObjectManager::setInstance(\Weline\Eav\Model\EavAttribute\Option::class, $model);
            \Weline\Framework\Manager\ObjectManager::setInstance(\Weline\Framework\Cache\Service\StorefrontScopeHotCache::class, $cache);
        };
        $metadata = $this->createMock(AttributeMetadataCatalogInterface::class);
        $metadata->method('catalogForProduct')->willReturn([]);
        $entity = (new \ReflectionClass(ProductCatalogAttributeEntity::class))->newInstanceWithoutConstructor();
        $store = $this->createMock(AttributeOptionStoreInterface::class);
        $store->method('assertUsableByInstance')->willReturnCallback(
            static function (int $optionId, int $productId) use ($db): \Weline\Eav\Api\Attribute\Option\AttributeOptionRecord {
                $db->validated[] = [$optionId, $productId];
                $row = $db->records[$optionId];
                if ($row[2] !== 0 && $row[2] !== $productId) {
                    throw new \InvalidArgumentException('option_not_in_scope');
                }
                return new \Weline\Eav\Api\Attribute\Option\AttributeOptionRecord(
                    id: $optionId,
                    attributeId: 40,
                    code: $row[1],
                    value: $row[3] . ':' . $productId . ':' . RequestContext::getWelineUserLang(),
                    scopeInstanceId: $row[2],
                );
            },
        );
        $resolver = static fn(int $productId): StorefrontEavLabelResolver =>
            (new StorefrontEavLabelResolver($metadata, $entity, $store))->forProduct($productId);

        try {
            $newRequest();
            $run($resolver, $db, $newRequest);
        } finally {
            \Weline\Framework\Context::leave();
            (new \ReflectionProperty(\Weline\Framework\Manager\ObjectManager::class, 'instances'))->setValue(null, $originalInstances);
            $manager->setValue(null, $originalManager);
            if ($originalContext !== null) {
                \Weline\Framework\Context::enter($originalContext);
            }
        }
    }

    private function privateOptionSet(int $optionId, string $label): AttributeSetMetadata
    {
        $attribute = new AttributeMetadata(
            id: 38,
            entityId: 1,
            code: 'style_type',
            name: 'Style',
            typeCode: 'varchar',
            fieldType: 'multiselect',
            element: 'select',
            setId: 1,
            groupId: 1,
            required: false,
            multiple: true,
            enabled: true,
            hasOption: true,
            sortOrder: 1,
            options: [new AttributeOptionMetadata(
                id: $optionId,
                value: '粉色仅上衣2307',
                code: 'look',
                label: $label,
                sortOrder: 1,
            )],
        );

        return new AttributeSetMetadata(
            id: 1,
            entityId: 1,
            code: 'hanfu',
            name: 'Hanfu',
            sortOrder: 1,
            groups: [new AttributeGroupMetadata(
                id: 1,
                entityId: 1,
                setId: 1,
                code: 'variants',
                name: 'Variants',
                sortOrder: 1,
                attributes: [$attribute],
            )],
        );
    }

    /**
     * @return array{AttributeMetadataCatalogInterface, ProductCatalogAttributeEntity}
     */
    private function metadata(): array
    {
        $option = new AttributeOptionMetadata(
            id: 48,
            value: '白色仅上衣2307',
            code: 'white-top-2307',
            label: '白色仅上衣2307',
            sortOrder: 1,
            swatchColor: '#ffffff',
        );
        $uppercaseOption = new AttributeOptionMetadata(
            id: 49,
            value: '精确大写选项',
            code: 'WHITE-TOP-2307',
            label: '精确大写选项',
            sortOrder: 2,
        );
        $attribute = new AttributeMetadata(
            id: 7,
            entityId: 1,
            code: 'color',
            name: '颜色',
            typeCode: 'varchar',
            fieldType: 'multiselect',
            element: 'select',
            setId: 1,
            groupId: 1,
            required: false,
            multiple: true,
            enabled: true,
            hasOption: true,
            sortOrder: 1,
            options: [$option, $uppercaseOption],
        );
        $set = new AttributeSetMetadata(
            id: 1,
            entityId: 1,
            code: 'hanfu',
            name: '汉服',
            sortOrder: 1,
            groups: [
                new AttributeGroupMetadata(
                    id: 1,
                    entityId: 1,
                    setId: 1,
                    code: 'hanfu_variants',
                    name: '汉服规格',
                    sortOrder: 1,
                    attributes: [$attribute],
                ),
            ],
        );
        $metadata = new class($set) implements AttributeMetadataCatalogInterface {
            public function __construct(private readonly AttributeSetMetadata $set)
            {
            }

            public function catalog(EntityDefinitionInterface $entity): array
            {
                return [$this->set];
            }

            public function catalogForProduct(
                EntityDefinitionInterface $entity,
                int $productId,
                string $freeSetCode = '__product_free',
            ): array {
                return [$this->set];
            }

            public function attributeIndexByEntityCode(string $entityCode): array
            {
                return [];
            }
        };
        $entity = (new \ReflectionClass(ProductCatalogAttributeEntity::class))
            ->newInstanceWithoutConstructor();

        return [$metadata, $entity];
    }

    public function testOptionLookupTokensDecodePercentEncodedValues(): void
    {
        $encoded = rawurlencode('【花间令】白色全套');
        $tokens = StorefrontEavLabelResolver::optionLookupTokens($encoded);
        self::assertContains($encoded, $tokens);
        self::assertContains('【花间令】白色全套', $tokens);
        self::assertSame('【花间令】白色全套', StorefrontEavLabelResolver::displayOptionToken($encoded));
    }

    public function testUsableOptionLabelRejectsPercentEncodedLocal(): void
    {
        $encoded = '[%E5%85%A5%E5%9F%8E%E8%A5%90]%E6%A1%B6%E8%8E%9C';
        self::assertSame(
            '【满庭芳】樱花粉套装',
            StorefrontEavLabelResolver::usableOptionLabel($encoded, '【满庭芳】樱花粉套装'),
        );
        self::assertSame(
            '【Mantingfang】Mint Green Set',
            StorefrontEavLabelResolver::usableOptionLabel('【Mantingfang】Mint Green Set', '【满庭芳】薄荷绿套装'),
        );
        self::assertSame('', StorefrontEavLabelResolver::usableOptionLabel($encoded, ''));
    }
}
