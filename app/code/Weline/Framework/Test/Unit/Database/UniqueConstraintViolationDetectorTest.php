<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Adapter\Pgsql\PgsqlIndexName;
use Weline\Framework\Database\Exception\UniqueConstraintViolationDetector;

final class UniqueConstraintViolationDetectorTest extends TestCase
{
    private UniqueConstraintViolationDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new UniqueConstraintViolationDetector();
    }

    public function testExactSqliteCompositeColumnsMatch(): void
    {
        $exception = new \RuntimeException(
            'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: '
            . 'weline_websites_store.website_id, weline_websites_store.code',
        );

        self::assertTrue($this->detector->matchesExactColumns(
            $exception,
            'uk_website_store_code',
            'weline_websites_store',
            ['website_id', 'code'],
        ));
    }

    public function testSqliteConflictWithSameFirstColumnIsNotTargetConflict(): void
    {
        $exception = new \RuntimeException(
            'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: '
            . 'weline_websites_store.website_id, weline_websites_store.name',
        );

        self::assertFalse($this->detector->matchesExactColumns(
            $exception,
            'uk_website_store_code',
            'weline_websites_store',
            ['website_id', 'code'],
        ));
    }

    public function testPostgresKeyFallbackRequiresTheCompleteOrderedColumnList(): void
    {
        $matching = new \RuntimeException(
            'SQLSTATE[23505]: Unique violation: Key (website_id, code)=(7, default) already exists.',
        );
        $other = new \RuntimeException(
            'SQLSTATE[23505]: Unique violation: Key (website_id, name)=(7, Default) already exists.',
        );

        self::assertTrue($this->detector->matchesExactColumns(
            $matching,
            'uk_website_store_code',
            'weline_websites_store',
            ['website_id', 'code'],
        ));
        self::assertFalse($this->detector->matchesExactColumns(
            $other,
            'uk_website_store_code',
            'weline_websites_store',
            ['website_id', 'code'],
        ));
    }

    public function testNamedTargetConstraintRemainsAuthoritative(): void
    {
        $exception = new \RuntimeException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '7-default' "
            . "for key 'uk_website_store_code'",
        );

        self::assertTrue($this->detector->matchesExactColumns(
            $exception,
            'uk_website_store_code',
            'weline_websites_store',
            ['website_id', 'code'],
        ));
    }

    public function testPostgresCanonicalAndLegacyLongPhysicalNamesMatch(): void
    {
        $table = 'weline_frontend_worker_credential_guard';
        $constraint = 'uk_worker_credential_guard_bucket';
        $candidates = PgsqlIndexName::candidates($table, $constraint);

        self::assertCount(3, $candidates);
        foreach ($candidates as $physicalName) {
            $exception = new \RuntimeException(
                'SQLSTATE[23505]: Unique violation: duplicate key value violates unique constraint "'
                . $physicalName . '"',
            );
            self::assertTrue($this->detector->matches(
                $exception,
                $constraint,
                $table,
                'bucket_key',
            ));
        }
    }
}
