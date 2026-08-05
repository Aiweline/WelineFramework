<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ConfigScopeSource;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\SystemConfig\Service\SystemConfigLockService;
use Weline\SystemConfig\Service\SystemConfigScopeResolver;

/**
 * TEST-P1C-05 子集：lock suppression 元数据、后代判定、解析跳过 suppressed、unlock 不复活语义（纯逻辑）。
 */
final class SystemConfigLockServiceTest extends TestCase
{
    private SystemConfigScopeResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new SystemConfigScopeResolver();
    }

    public function testIsRowSuppressedReadsMetadata(): void
    {
        self::assertFalse(SystemConfigLockService::isRowSuppressed(null));
        self::assertFalse(SystemConfigLockService::isRowSuppressed([
            SystemConfig::schema_fields_METADATA => '{}',
        ]));
        self::assertTrue(SystemConfigLockService::isRowSuppressed([
            SystemConfig::schema_fields_METADATA => \json_encode([
                SystemConfigLockService::META_SUPPRESSED_BY => 42,
            ]),
        ]));
        self::assertTrue(SystemConfigLockService::isRowSuppressed([
            SystemConfig::schema_fields_METADATA => [
                SystemConfigLockService::META_SUPPRESSED_BY => 7,
            ],
        ]));
    }

    public function testDescendantScopeTree(): void
    {
        $global = SystemConfig::SCOPE_GLOBAL;
        $website = 'shop.default.default';
        $website0 = 'default.' . SystemConfigScopeResolver::WEBSITE_DEFAULT_SENTINEL . '.default';
        $store = 'shop.main.default';
        $channel = 'shop.main.app';

        self::assertTrue(SystemConfigLockService::isDescendantScope($global, $website));
        self::assertTrue(SystemConfigLockService::isDescendantScope($global, $store));
        self::assertTrue(SystemConfigLockService::isDescendantScope($website, $store));
        self::assertTrue(SystemConfigLockService::isDescendantScope($website, $channel));
        self::assertTrue(SystemConfigLockService::isDescendantScope($store, $channel));
        self::assertFalse(SystemConfigLockService::isDescendantScope($store, $website));
        self::assertFalse(SystemConfigLockService::isDescendantScope($channel, $store));
        self::assertFalse(SystemConfigLockService::isDescendantScope($website, $website0));
        self::assertFalse(SystemConfigLockService::isDescendantScope($website0, 'other.main.default'));
        self::assertTrue(SystemConfigLockService::isDescendantScope($website0, 'default.main.default'));
    }

    public function testResolveSkipsSuppressedAndFallsBack(): void
    {
        $identity = ScopeIdentity::store(0, 'shop', 'main', ScopeIdentity::MODE_NORMAL);
        $website = $this->resolver->toStorageScope(ScopeIdentity::website(0, 'shop'));
        $store = $this->resolver->toStorageScope($identity);
        $records = [
            SystemConfigScopeResolver::recordKey(SystemConfig::SCOPE_GLOBAL) => [
                SystemConfigScopeResolver::KEY_VALUE => 'global-v',
                SystemConfigScopeResolver::KEY_VERSION => 1,
            ],
            SystemConfigScopeResolver::recordKey($website) => [
                SystemConfigScopeResolver::KEY_VALUE => 'website-locked',
                SystemConfigScopeResolver::KEY_VERSION => 3,
                SystemConfigScopeResolver::KEY_METADATA => [
                    SystemConfigLockService::META_ACTIVE_LOCK => 99,
                ],
            ],
            SystemConfigScopeResolver::recordKey($store) => [
                SystemConfigScopeResolver::KEY_VALUE => 'store-weak',
                SystemConfigScopeResolver::KEY_VERSION => 2,
                SystemConfigScopeResolver::KEY_METADATA => [
                    SystemConfigLockService::META_SUPPRESSED_BY => 99,
                ],
            ],
        ];

        $result = $this->resolver->resolveForIdentity($records, $identity);
        self::assertSame('website-locked', $result->value);
        self::assertSame(ConfigScopeSource::KIND_FALLBACK, $result->source->sourceKind);
        self::assertTrue($result->source->locked);
        self::assertFalse($result->source->suppressed);
        self::assertSame($website, $result->source->storageScope);
    }

    public function testResolveAfterUnlockStillSkipsSuppressedUntilRestore(): void
    {
        // unlock 只清父行 active_lock；子行 suppressed 仍在 → 继续回落到父/全局
        $identity = ScopeIdentity::channel(0, 'shop', 'main', 'app', ScopeIdentity::MODE_NORMAL);
        $store = 'shop.main.default';
        $channel = 'shop.main.app';
        $records = [
            SystemConfigScopeResolver::recordKey(SystemConfig::SCOPE_GLOBAL) => [
                SystemConfigScopeResolver::KEY_VALUE => 'global-v',
            ],
            SystemConfigScopeResolver::recordKey($store) => [
                SystemConfigScopeResolver::KEY_VALUE => 'store-v',
                SystemConfigScopeResolver::KEY_METADATA => [], // unlocked parent
            ],
            SystemConfigScopeResolver::recordKey($channel) => [
                SystemConfigScopeResolver::KEY_VALUE => 'channel-suppressed',
                SystemConfigScopeResolver::KEY_METADATA => [
                    SystemConfigLockService::META_SUPPRESSED_BY => 12,
                ],
            ],
        ];
        $result = $this->resolver->resolveForIdentity($records, $identity);
        self::assertSame('store-v', $result->value);
        self::assertSame($store, $result->source->storageScope);
        self::assertFalse($result->source->locked);
    }

    public function testExpectedVersionConflictShapeIsDocumented(): void
    {
        // CAS 形状与 saveScopeConfig / lockScope 对齐：conflicts[].expected_version + current_version
        $conflict = [
            'success' => false,
            'status' => 'conflict',
            'conflicts' => [[
                'key' => 'demo/key',
                'scope' => 'shop.main.default',
                'expected_version' => 3,
                'current_version' => 4,
            ]],
        ];
        self::assertSame('conflict', $conflict['status']);
        self::assertSame(3, $conflict['conflicts'][0]['expected_version']);
        self::assertSame(4, $conflict['conflicts'][0]['current_version']);
    }
}
