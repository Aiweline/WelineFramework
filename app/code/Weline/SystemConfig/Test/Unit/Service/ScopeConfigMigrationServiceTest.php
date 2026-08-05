<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\SystemConfig\Service\ScopeConfigMigrationService;
use Weline\SystemConfig\Service\SystemConfigScopeResolver;

/**
 * TEST-MIG-P1B-01, TEST-MIG-P1B-02 and TEST-MIG-P1B-07:
 * 确定映射 / 裸 default 冲突 / 短写永不恢复。
 */
final class ScopeConfigMigrationServiceTest extends TestCase
{
    private ScopeConfigMigrationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ScopeConfigMigrationService(new SystemConfigScopeResolver());
    }

    public function testBareDefaultConflictsWithoutWritingZeroWebsite(): void
    {
        $map = $this->service->mapLegacyScope('default');
        self::assertSame(ScopeConfigMigrationService::STATUS_CONFLICT, $map['status']);
        self::assertSame(ScopeConfigMigrationService::REASON_AMBIGUOUS_BARE_DEFAULT, $map['reason']);
        self::assertNull($map['target']);
    }

    public function testWebsiteAndStoreShortScopesMapDeterministically(): void
    {
        $website = $this->service->mapLegacyScope('shop');
        self::assertSame(ScopeConfigMigrationService::STATUS_MAPPED, $website['status']);
        self::assertSame('shop.default.default', $website['target']);

        $store = $this->service->mapLegacyScope('shop.main');
        self::assertSame(ScopeConfigMigrationService::STATUS_MAPPED, $store['status']);
        self::assertSame('shop.main.default', $store['target']);
    }

    public function testThreeSegmentAlreadyCanonical(): void
    {
        $map = $this->service->mapLegacyScope('shop.main.app');
        self::assertSame(ScopeConfigMigrationService::STATUS_ALREADY, $map['status']);
        self::assertSame('shop.main.app', $map['target']);
    }

    public function testThemeStoreModeSuffixPreserved(): void
    {
        $map = $this->service->mapLegacyScope('shop.main~test');
        self::assertSame(ScopeConfigMigrationService::STATUS_MAPPED, $map['status']);
        self::assertSame('shop.main.default~test', $map['target']);
    }

    public function testRollbackKeepsShortScopeWriteForbidden(): void
    {
        $result = $this->service->rollback(null);
        self::assertTrue($result['ok']);
        self::assertTrue($result['short_scope_write_forbidden']);
        self::assertFalse($result['short_scope_write_restored']);
        self::assertFalse($result['canonical_write_relaxed']);
    }

    public function testSharedApplyRequiresIsolatedDatabase(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('mig_p1b_requires_isolated_database');
        $this->service->apply(null);
    }
}
