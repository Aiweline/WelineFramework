<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Eav\Api\Entity\EntityDefinitionInterface;
use Weline\Eav\Api\Metadata\AttributeMetadataCatalogInterface;
use Weline\Eav\Service\AttributeMetadataCatalog;

final class AttributeMetadataCatalogContractTest extends TestCase
{
    public function testPublicContractAndModuleBindingAreStable(): void
    {
        $method = new \ReflectionMethod(AttributeMetadataCatalogInterface::class, 'catalog');
        self::assertSame(EntityDefinitionInterface::class, (string)$method->getParameters()[0]->getType());
        self::assertSame('array', (string)$method->getReturnType());

        $module = require dirname(__DIR__, 3) . '/etc/module.php';
        self::assertSame(
            AttributeMetadataCatalog::class,
            $module['provides'][AttributeMetadataCatalogInterface::class] ?? null,
        );
        self::assertTrue((new ReflectionClass(AttributeMetadataCatalog::class))
            ->implementsInterface(AttributeMetadataCatalogInterface::class));

        $indexMethod = new \ReflectionMethod(AttributeMetadataCatalogInterface::class, 'attributeIndexByEntityCode');
        self::assertSame('array', (string)$indexMethod->getReturnType());
    }

    public function testServiceKeepsOrmOwnershipInsideEavAndScopesEveryBusinessRow(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/Service/AttributeMetadataCatalog.php');
        self::assertIsString($source);
        self::assertStringContainsString('EavEntity::schema_fields_code', $source);
        self::assertStringContainsString('Set::schema_fields_eav_entity_id', $source);
        self::assertStringContainsString('Group::schema_fields_eav_entity_id', $source);
        self::assertStringContainsString('EavAttribute::schema_fields_eav_entity_id', $source);
        self::assertStringContainsString('Option::schema_fields_eav_entity_id', $source);
        self::assertStringContainsString('AttributeSetMetadata', $source);
        self::assertStringContainsString('compare_mode', $source);
        self::assertStringContainsString('attributeIndexByEntityCode', $source);
    }
}
