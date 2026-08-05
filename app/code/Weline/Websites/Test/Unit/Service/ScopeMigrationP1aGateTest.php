<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Console\Scope\MigrateP1a;
use Weline\Websites\Service\ScopeMigrationService;

/**
 * TEST-MIG-P1A-07 and TEST-MIG-P1A-08 plus shared-database apply gates.
 */
final class ScopeMigrationP1aGateTest extends TestCase
{
    public function testCliApplyRejectionReturnsNonZeroStatus(): void
    {
        /** @var MigrateP1a $command */
        $command = ObjectManager::getInstance(MigrateP1a::class);

        self::assertSame(2, $command->execute(['scope:migrate-p1a', 'apply']));
    }

    public function testApplyUsesCrossProcessCheckpointJournalStore(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/ScopeMigrationService.php'
        );

        self::assertStringContainsString(
            'MigrationCheckpointService::withDefaultStore(',
            $source,
            'MIG-P1A apply 必须持久化 checkpoint，供独立进程 journal-verify 使用。'
        );
        self::assertStringNotContainsString(
            'ObjectManager::getInstance(MigrationCheckpointService::class)',
            $source,
            '禁止退回仅进程内 checkpoint 的假绿实现。'
        );
    }

    public function testMigP1a07ApplyAndVerifyIncludeEavCutover(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/ScopeMigrationService.php'
        );

        self::assertStringContainsString('$eav = $eavMigration->apply();', $source);
        self::assertStringContainsString('$eav = $this->eavMigration()->verify();', $source);
        self::assertStringContainsString("'conservation_ok'", $source);
    }

    public function testMigP1a08RollbackRetainsAdditiveSchemaWithoutRelaxingCanonicalWrites(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/ScopeMigrationService.php'
        );

        self::assertStringContainsString("'additive_columns_retained' => true", $source);
        self::assertStringContainsString("'canonical_write_relaxed' => false", $source);
        self::assertStringNotContainsString('DROP COLUMN', $source);
    }

    public function testApplyWithoutIsolatedDatabaseIsRejected(): void
    {
        /** @var ScopeMigrationService $svc */
        $svc = ObjectManager::getInstance(ScopeMigrationService::class);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('mig_p1a_requires_isolated_database');
        $svc->apply(null);
    }

    public function testApplyOnSharedWelineNameIsRejected(): void
    {
        /** @var ScopeMigrationService $svc */
        $svc = ObjectManager::getInstance(ScopeMigrationService::class);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('migration_db_denied');
        $svc->apply([
            'type' => 'pgsql',
            'hostname' => '127.0.0.1',
            'hostport' => '5432',
            'database' => 'weline',
            'username' => 'weline',
            'password' => '',
        ]);
    }
}
