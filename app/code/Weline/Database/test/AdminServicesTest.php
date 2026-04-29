<?php
declare(strict_types=1);

namespace Weline\Database\test;

use Weline\Database\Model\DatabaseAdminAuditLog;
use Weline\Database\Service\Admin\SqlGuardService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;

class AdminServicesTest extends TestCore
{
    public function testSqlGuardAnalyzeSelect(): void
    {
        $guard = new SqlGuardService();
        $analysis = $guard->analyze('select * from users limit 1');

        $this->assertSame('SELECT', $analysis['statement_type']);
        $this->assertFalse($analysis['is_write']);
        $this->assertFalse($analysis['is_high_risk']);
    }

    public function testSqlGuardAnalyzeDrop(): void
    {
        $guard = new SqlGuardService();
        $analysis = $guard->analyze('DROP TABLE test_table');

        $this->assertSame('DROP', $analysis['statement_type']);
        $this->assertTrue($analysis['is_write']);
        $this->assertTrue($analysis['is_high_risk']);
    }

    public function testSqlGuardWriteNeedsConfirmation(): void
    {
        $guard = new SqlGuardService();
        $this->expectException(\RuntimeException::class);
        $guard->assertWriteConfirmation('UPDATE users SET name="x"', false, '');
    }

    public function testSqlGuardHighRiskNeedsPhrase(): void
    {
        $guard = new SqlGuardService();
        $this->expectException(\RuntimeException::class);
        $guard->assertWriteConfirmation('DROP TABLE users', true, 'WRONG');
    }

    public function testAuditModelInitialization(): void
    {
        $model = ObjectManager::getInstance(DatabaseAdminAuditLog::class);
        $this->assertInstanceOf(DatabaseAdminAuditLog::class, $model);
        $this->assertSame('weline_database_admin_audit_log', DatabaseAdminAuditLog::schema_table);
    }
}
