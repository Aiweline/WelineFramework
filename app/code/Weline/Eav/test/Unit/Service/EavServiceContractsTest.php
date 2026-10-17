<?php

declare(strict_types=1);

namespace Weline\Eav\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionClass as ReflectionClass;
use Weline\Eav\Api\Attribute\AttributeRecord;
use Weline\Eav\Api\Attribute\EntityAttributeStoreInterface;
use Weline\Eav\Api\Entity\EntityDefinitionInterface;
use Weline\Eav\Api\Metadata\AttributeMetadataCatalogInterface;
use Weline\Eav\Service\AttributeMetadataCatalog;
use Weline\Eav\Service\EavScopeMigrationService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * Aggregated contract suite (merged from 4 *ContractTest files).
 * Observe one overall pass/fail via testAllEavServiceContractsPass().
 */
final class EavServiceContractsTest extends TestCase
{
    public function testAllEavServiceContractsPass(): void
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
            'AttributeMetadataCatalog::testPublicContractAndModuleBindingAreStable' => function () {
            $this->assert_AttributeMetadataCatalog_testPublicContractAndModuleBindingAreStable();
        },
            'AttributeMetadataCatalog::testServiceKeepsOrmOwnershipInsideEavAndScopesEveryBusinessRow' => function () {
            $this->assert_AttributeMetadataCatalog_testServiceKeepsOrmOwnershipInsideEavAndScopesEveryBusinessRow();
        },
            'AttributeMetadataProcessCache::testCatalogOwnsProcessBag' => function () {
            $this->assert_AttributeMetadataProcessCache_testCatalogOwnsProcessBag();
        },
            'AttributeMetadataProcessCache::testProcessCacheResetterIsRegistered' => function () {
            $this->assert_AttributeMetadataProcessCache_testProcessCacheResetterIsRegistered();
        },
            'EavScopedValueStore::testMigrationApplyRejectsSharedDatabase' => function () {
            $this->assert_EavScopedValueStore_testMigrationApplyRejectsSharedDatabase();
        },
            'EavScopedValueStore::testHelpListsTypedColumns' => function () {
            $this->assert_EavScopedValueStore_testHelpListsTypedColumns();
        },
            'EavScopedValueStore::testTypedExplicitClearedAndLegacyEmptyNotCleared' => function () {
            $this->assert_EavScopedValueStore_testTypedExplicitClearedAndLegacyEmptyNotCleared();
        },
            'EntityAttributeStoreFreshInstallRegister::testProvisionRegistersMissingEntityFromDefinition' => function () {
            $this->assert_EntityAttributeStoreFreshInstallRegister_testProvisionRegistersMissingEntityFromDefinition();
        },
        ];
    }

private function assert_AttributeMetadataCatalog_testPublicContractAndModuleBindingAreStable(): void
{
    $method = new \ReflectionMethod(AttributeMetadataCatalogInterface::class, 'catalog');
    self::assertSame(EntityDefinitionInterface::class, (string) $method->getParameters()[0]->getType());
    self::assertSame('array', (string) $method->getReturnType());
    $module = require '/Users/weline/Project/Official/框架/app/code/Weline/Eav' . '/etc/module.php';
    self::assertSame(AttributeMetadataCatalog::class, $module['provides'][AttributeMetadataCatalogInterface::class] ?? null);
    self::assertTrue((new ReflectionClass(AttributeMetadataCatalog::class))->implementsInterface(AttributeMetadataCatalogInterface::class));
    $indexMethod = new \ReflectionMethod(AttributeMetadataCatalogInterface::class, 'attributeIndexByEntityCode');
    self::assertSame('array', (string) $indexMethod->getReturnType());
}

private function assert_AttributeMetadataCatalog_testServiceKeepsOrmOwnershipInsideEavAndScopesEveryBusinessRow(): void
{
    $source = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Eav' . '/Service/AttributeMetadataCatalog.php');
    self::assertIsString($source);
    self::assertStringContainsString('EavEntity::schema_fields_code', $source);
    self::assertStringContainsString('Set::schema_fields_eav_entity_id', $source);
    self::assertStringContainsString('Group::schema_fields_eav_entity_id', $source);
    self::assertStringContainsString('EavAttribute::schema_fields_eav_entity_id', $source);
    self::assertStringContainsString('Option::schema_fields_eav_entity_id', $source);
    self::assertStringContainsString('Placement::schema_fields_eav_entity_id', $source);
    self::assertStringContainsString('AttributeSetMetadata', $source);
    self::assertStringContainsString('compare_mode', $source);
    self::assertStringContainsString('attributeIndexByEntityCode', $source);
}

private function assert_AttributeMetadataProcessCache_testCatalogOwnsProcessBag(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Eav' . '/Service/AttributeMetadataCatalog.php');
    self::assertStringContainsString('$processBag', $src);
    self::assertStringContainsString('function clearProcessCache', $src);
}

private function assert_AttributeMetadataProcessCache_testProcessCacheResetterIsRegistered(): void
{
    $module = require '/Users/weline/Project/Official/框架/app/code/Weline/Eav' . '/etc/module.php';
    self::assertSame(\Weline\Eav\Api\Runtime\ProcessCacheResetter::class, $module['provides']['process_cache_resetter.Weline_Eav'] ?? null);
}

private function assert_EavScopedValueStore_testMigrationApplyRejectsSharedDatabase(): void
{
    /** @var EavScopeMigrationService $svc */
    $svc = ObjectManager::getInstance(EavScopeMigrationService::class);
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('migration_db_denied');
    $svc->apply();
}

private function assert_EavScopedValueStore_testHelpListsTypedColumns(): void
{
    /** @var EavScopeMigrationService $svc */
    $svc = ObjectManager::getInstance(EavScopeMigrationService::class);
    $help = $svc->help();
    self::assertContains('scope_kind', $help['columns']);
    self::assertContains('is_cleared', $help['columns']);
}

private function assert_EavScopedValueStore_testTypedExplicitClearedAndLegacyEmptyNotCleared(): void
{
    $fixture = $this->resolveWritableFixture_EavScopedValueStore();
    if ($fixture === null) {
        $this->markTestSkipped('无可用 EAV 实体/属性 fixture，跳过存储契约');
    }
    /** @var EavScopeMigrationService $migration */
    $migration = ObjectManager::getInstance(EavScopeMigrationService::class);
    $migration->ensureColumns();
    /** @var EntityAttributeStoreInterface $store */
    $store = ObjectManager::getInstance(EntityAttributeStoreInterface::class);
    [$entity, $attribute, $ownerId] = $fixture;
    $website = ScopeIdentity::website(0, 'default');
    $storeScope = ScopeIdentity::store(0, 'default', 'main', ScopeIdentity::MODE_NORMAL);
    $dedupeOwner = 'p1b005-' . \uniqid('', true);
    // 用负数字符串 owner 隔离测试行（多数 entity_id 为 int/string 宽松）
    $owner = is_int($ownerId) ? (int) (\sprintf('%d', \crc32($dedupeOwner) % 100000000) + 900000000) : $dedupeOwner;
    try {
        $global = ScopeIdentity::global();
        $otherWebsite = ScopeIdentity::website(7, 'site-seven');
        $unrelatedWebsite = ScopeIdentity::website(8, 'site-eight');
        $store->writeScopedValue($entity, $owner, $attribute, $global, 'global-value', '');
        self::assertSame('global-value', $store->readValue($entity, $owner, $attribute), '迁移后的 typed global 行必须继续被旧 readValue 读取。');
        $store->writeScopedValue($entity, $owner, $attribute, $otherWebsite, 'site-seven-value', 'zh_Hans_CN');
        $crossSite = $store->readScopedValue($entity, $owner, $attribute, $unrelatedWebsite, 'zh_Hans_CN');
        self::assertSame('global-value', $crossSite->value);
        self::assertSame('', $crossSite->resolvedScope, '不得跨站读取 site-seven 的值。');
        $store->writeScopedValue($entity, $owner, $attribute, $website, 'website-value', '');
        $store->writeScopedValue($entity, $owner, $attribute, $storeScope, 'store-value', 'zh_Hans_CN');
        $hit = $store->readScopedValue($entity, $owner, $attribute, $storeScope, 'zh_Hans_CN');
        self::assertTrue($hit->isExplicit());
        self::assertSame('store-value', $hit->value);
        $store->clearScopedValue($entity, $owner, $attribute, $storeScope, 'zh_Hans_CN');
        $cleared = $store->readScopedValue($entity, $owner, $attribute, $storeScope, 'zh_Hans_CN');
        self::assertTrue($cleared->isCleared());
        self::assertNull($cleared->value);
        // legacy：空串不是 cleared
        $store->replaceValue($entity, $owner, $attribute, '');
        $legacy = $store->readValue($entity, $owner, $attribute);
        self::assertSame('', $legacy);
        $scopedAfterLegacy = $store->readScopedValue($entity, $owner, $attribute, $website, '');
        self::assertTrue($scopedAfterLegacy->isExplicit() || $scopedAfterLegacy->isCleared() || $scopedAfterLegacy->source === 'inherit');
        // legacy 空不得被 typed 解析当成 website cleared
        if ($scopedAfterLegacy->isCleared()) {
            self::fail('legacy 空值被误判为 cleared');
        }
    } finally {
        // best-effort cleanup via replaceValue + clear both scopes
        try {
            $store->replaceValue($entity, $owner, $attribute, []);
        } catch (\Throwable) {
        }
        try {
            $store->clearScopedValue($entity, $owner, $attribute, $website, '');
        } catch (\Throwable) {
        }
        try {
            $store->clearScopedValue($entity, $owner, $attribute, $storeScope, 'zh_Hans_CN');
        } catch (\Throwable) {
        }
        try {
            $store->clearScopedValue($entity, $owner, $attribute, ScopeIdentity::global(), '');
            $store->clearScopedValue($entity, $owner, $attribute, ScopeIdentity::website(7, 'site-seven'), 'zh_Hans_CN');
        } catch (\Throwable) {
        }
    }
}

/**
 * @return array{0:EntityDefinitionInterface,1:AttributeRecord,2:int|string}|null
 */
private function resolveWritableFixture_EavScopedValueStore(): ?array
{
    /** @var EntityAttributeStoreInterface $store */
    $store = ObjectManager::getInstance(EntityAttributeStoreInterface::class);
    $entityModel = ObjectManager::getInstance(\Weline\Eav\Model\EavEntity::class);
    $entities = (clone $entityModel)->clear()->select()->fetch()->getItems();
    foreach ($entities as $entityRow) {
        if (!$entityRow instanceof \Weline\Eav\Model\EavEntity) {
            continue;
        }
        $code = (string) $entityRow->getCode();
        if ($code === '') {
            continue;
        }
        $definition = new class($code, (string) $entityRow->getName(), (string) $entityRow->getEavEntityIdFieldType(), (int) $entityRow->getEavEntityIdFieldLength()) implements EntityDefinitionInterface
        {
            public function __construct(private readonly string $code, private readonly string $name, private readonly string $idType, private readonly int $idLength)
            {
            }
            public function getEntityCode(): string
            {
                return $this->code;
            }
            public function getEntityName(): string
            {
                return $this->name;
            }
            public function getEntityFieldIdType(): string
            {
                return $this->idType;
            }
            public function getEntityFieldIdLength(): int
            {
                return $this->idLength;
            }
        };
        try {
            $attrs = $store->getAttributes($definition);
        } catch (\Throwable) {
            continue;
        }
        foreach ($attrs as $attr) {
            if ($attr->multiple) {
                continue;
            }
            return [$definition, $attr, 1];
        }
    }
    return null;
}

private function assert_EntityAttributeStoreFreshInstallRegister_testProvisionRegistersMissingEntityFromDefinition(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Eav' . '/Service/EntityAttributeStore.php');
    self::assertStringContainsString('ensureRegisteredFromDefinition', $src);
    self::assertStringContainsString('Fresh install: Module Install may run before UpgradeDefaultAttribute', $src);
    self::assertMatchesRegularExpression('/provisionValueTables\([^)]*\)\s*:\s*void\s*\{[^}]*ensureRegisteredFromDefinition/s', $src);
}
}
