<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\App;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Framework\App\Env;
use Weline\Framework\System\Console\System\Install;

final class DefaultDatabaseContractTest extends TestCase
{
    public function testPrimaryRuntimeDefaultsToPostgreSql(): void
    {
        self::assertSame('pgsql', Env::default_CONFIG['db']['default']);
        self::assertSame('pgsql', Env::default_CONFIG['db']['master']['type']);
        self::assertSame('5432', Env::default_CONFIG['db']['master']['hostport']);
    }

    public function testSqliteRemainsSandboxOnlyDefault(): void
    {
        self::assertSame('sqlite', Env::default_CONFIG['sandbox_db']['default']);
        self::assertSame('sqlite', Env::default_CONFIG['sandbox_db']['master']['type']);
        self::assertArrayHasKey('path', Env::default_CONFIG['sandbox_db']['master']);
    }

    public function testDebugRuntimeStillUsesPrimaryPostgreSqlUntilSandboxIsExplicitlyEnabled(): void
    {
        $script = <<<'PHP'
define('BP', %s);
define('DEBUG', true);
define('SANDBOX', false);
require BP . 'app/autoload.php';
require BP . 'app/code/Weline/Framework/Common/functions.php';
$env = \Weline\Framework\App\Env::getInstance()->reload();
echo $env->getDbConfig()['master']['type'] ?? '';
PHP;
        $script = \sprintf($script, \var_export(BP, true));
        $output = [];
        $exitCode = 0;
        \exec(\escapeshellarg(PHP_BINARY) . ' -r ' . \escapeshellarg($script), $output, $exitCode);

        self::assertSame(0, $exitCode);
        self::assertSame('pgsql', \implode("\n", $output));

        $env = Env::getInstance()->reload();
        self::assertFalse($env->isSandboxMode());
        self::assertSame('pgsql', $env->getDbConfig()['master']['type'] ?? null);

        $env->enableSandboxMode(__METHOD__);
        try {
            self::assertSame('sqlite', $env->getDbConfig()['master']['type'] ?? null);
        } finally {
            $env->disableSandboxMode();
        }

        self::assertSame('pgsql', $env->getDbConfig()['master']['type'] ?? null);
    }

    public function testInstallHelpPublishesPostgreSqlDefaultAndSqliteBoundary(): void
    {
        $reflection = new ReflectionClass(Install::class);
        /** @var Install $command */
        $command = $reflection->newInstanceWithoutConstructor();
        $help = $command->help();

        self::assertIsString($help);
        self::assertStringContainsString('默认：pgsql', $help);
        self::assertStringContainsString('PostgreSQL 是默认开发与生产数据库', $help);
        self::assertStringContainsString('SQLite 仅用于显式沙盒或隔离开发', $help);
    }
}
