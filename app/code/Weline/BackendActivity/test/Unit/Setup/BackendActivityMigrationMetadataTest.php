<?php

declare(strict_types=1);

namespace Weline\BackendActivity\Test\Unit\Setup;

use PHPUnit\Framework\TestCase;
use Weline\BackendActivity\Model\BackendActivityLog;
use Weline\BackendActivity\Setup\Db\Migration\AddBackendActivityBusinessContext20260702V111;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

require_once BP . 'app/code/Weline/BackendActivity/Model/BackendActivityLog.php';
require_once BP
    . 'app/code/Weline/BackendActivity/Setup/Db/Migration/'
    . 'add_backend_activity_business_context_20260702-v1.1.1.php';

final class BackendActivityMigrationMetadataTest extends TestCase
{
    public function testBusinessContextMigrationLocksTheCanonicalActivityLogTable(): void
    {
        self::assertSame('backend_activity_log', BackendActivityLog::schema_table);
        self::assertSame(
            ['backend_activity_log'],
            (new AddBackendActivityBusinessContext20260702V111())->getAffectedTables(),
        );
    }
}
