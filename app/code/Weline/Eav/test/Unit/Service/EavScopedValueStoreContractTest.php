<?php

declare(strict_types=1);

namespace Weline\Eav\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Eav\Api\Attribute\AttributeRecord;
use Weline\Eav\Api\Attribute\EntityAttributeStoreInterface;
use Weline\Eav\Api\Entity\EntityDefinitionInterface;
use Weline\Eav\Service\EavScopeMigrationService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * TEST-P1B-03（存储契约）：typed write + cleared + legacy 空值不猜 cleared。
 *
 * 依赖：至少一个已注册 EAV 实体与属性；ensure-columns 已可跑。
 */
final class EavScopedValueStoreContractTest extends TestCase
{
    public function testMigrationApplyRejectsSharedDatabase(): void
    {
        /** @var EavScopeMigrationService $svc */
        $svc = ObjectManager::getInstance(EavScopeMigrationService::class);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('migration_db_denied');
        $svc->apply();
    }

    public function testHelpListsTypedColumns(): void
    {
        /** @var EavScopeMigrationService $svc */
        $svc = ObjectManager::getInstance(EavScopeMigrationService::class);
        $help = $svc->help();
        self::assertContains('scope_kind', $help['columns']);
        self::assertContains('is_cleared', $help['columns']);
    }

    public function testTypedExplicitClearedAndLegacyEmptyNotCleared(): void
    {
        $fixture = $this->resolveWritableFixture();
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
        $owner = is_int($ownerId) ? (int)(\sprintf('%d', \crc32($dedupeOwner) % 100000000) + 900000000) : $dedupeOwner;

        try {
            $global = ScopeIdentity::global();
            $otherWebsite = ScopeIdentity::website(7, 'site-seven');
            $unrelatedWebsite = ScopeIdentity::website(8, 'site-eight');

            $store->writeScopedValue($entity, $owner, $attribute, $global, 'global-value', '');
            self::assertSame(
                'global-value',
                $store->readValue($entity, $owner, $attribute),
                '迁移后的 typed global 行必须继续被旧 readValue 读取。'
            );

            $store->writeScopedValue($entity, $owner, $attribute, $otherWebsite, 'site-seven-value', 'zh_Hans_CN');
            $crossSite = $store->readScopedValue(
                $entity,
                $owner,
                $attribute,
                $unrelatedWebsite,
                'zh_Hans_CN',
            );
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
                $store->clearScopedValue(
                    $entity,
                    $owner,
                    $attribute,
                    ScopeIdentity::website(7, 'site-seven'),
                    'zh_Hans_CN',
                );
            } catch (\Throwable) {
            }
        }
    }

    /**
     * @return array{0:EntityDefinitionInterface,1:AttributeRecord,2:int|string}|null
     */
    private function resolveWritableFixture(): ?array
    {
        /** @var EntityAttributeStoreInterface $store */
        $store = ObjectManager::getInstance(EntityAttributeStoreInterface::class);
        $entityModel = ObjectManager::getInstance(\Weline\Eav\Model\EavEntity::class);
        $entities = (clone $entityModel)->clear()->select()->fetch()->getItems();
        foreach ($entities as $entityRow) {
            if (!$entityRow instanceof \Weline\Eav\Model\EavEntity) {
                continue;
            }
            $code = (string)$entityRow->getCode();
            if ($code === '') {
                continue;
            }
            $definition = new class ($code, (string)$entityRow->getName(), (string)$entityRow->getEavEntityIdFieldType(), (int)$entityRow->getEavEntityIdFieldLength()) implements EntityDefinitionInterface {
                public function __construct(
                    private readonly string $code,
                    private readonly string $name,
                    private readonly string $idType,
                    private readonly int $idLength,
                ) {
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
}
