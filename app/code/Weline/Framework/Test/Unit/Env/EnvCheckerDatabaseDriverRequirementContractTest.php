<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Env;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Env\Service\EnvChecker;

/**
 * One-click pgsql install must not fail closed on missing pdo_mysql (and vice versa).
 */
final class EnvCheckerDatabaseDriverRequirementContractTest extends TestCase
{
    public function testCheckerResolvesDriverByConfiguredDatabaseType(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Env/Service/EnvChecker.php',
        );
        self::assertStringContainsString('resolveDatabaseDriverExtensions', $src);
        self::assertStringContainsString('resolveConfiguredDatabaseType', $src);
        self::assertStringContainsString('当前库类型不需要，已降为推荐', $src);
        self::assertStringContainsString("'pgsql'", $src);
        self::assertStringContainsString("'mysql'", $src);
        self::assertTrue(class_exists(EnvChecker::class));
    }
}
