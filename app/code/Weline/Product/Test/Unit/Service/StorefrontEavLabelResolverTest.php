<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Eav\Api\Entity\EntityDefinitionInterface;
use Weline\Eav\Api\Metadata\AttributeGroupMetadata;
use Weline\Eav\Api\Metadata\AttributeMetadata;
use Weline\Eav\Api\Metadata\AttributeMetadataCatalogInterface;
use Weline\Eav\Api\Metadata\AttributeOptionMetadata;
use Weline\Eav\Api\Metadata\AttributeSetMetadata;
use Weline\Product\Model\ProductCatalogAttributeEntity;
use Weline\Product\Service\StorefrontEavLabelResolver;
use Weline\Product\Service\StorefrontVariantAxisResolver;

final class StorefrontEavLabelResolverTest extends TestCase
{
    public function testCanonicalIdCodeAndStoredValueResolveToTheSameLabel(): void
    {
        [$metadata, $entity] = $this->metadata();
        $resolver = new StorefrontEavLabelResolver($metadata, $entity);

        self::assertSame('白色仅上衣2307', $resolver->resolve('color', '48'));
        self::assertSame('白色仅上衣2307', $resolver->resolve('color', 'white-top-2307'));
        self::assertSame('白色仅上衣2307', $resolver->resolve('color', '白色仅上衣2307'));
        self::assertSame('unknown', $resolver->resolve('color', 'unknown'));
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
            options: [$option],
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
}
