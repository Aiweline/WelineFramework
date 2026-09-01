<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\App;

use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Weline\Framework\App\Env;
use Weline\Framework\Test\TestCore;

/**
 * CLI user gates must use process effective identity, not script-file ownership.
 */
final class EnvProcessUserContractTest extends TestCore
{
    protected function tearDown(): void
    {
        $this->clearCachedUser();
        parent::tearDown();
    }

    public function testUserMatchesPosixEffectiveAccountWhenAvailable(): void
    {
        if (!\function_exists('posix_geteuid') || !\function_exists('posix_getpwuid')) {
            self::markTestSkipped('posix identity APIs unavailable');
        }

        $this->clearCachedUser();
        $info = \posix_getpwuid((int) \posix_geteuid());
        self::assertIsArray($info);
        $expected = (string) ($info['name'] ?? '');
        self::assertNotSame('', $expected);

        self::assertSame($expected, Env::user());
        self::assertSame($expected, Env::user());
    }

    public function testResolveProcessUserNameDoesNotPreferScriptFileOwnerOverPosix(): void
    {
        if (!\function_exists('posix_geteuid') || !\function_exists('posix_getpwuid')) {
            self::markTestSkipped('posix identity APIs unavailable');
        }

        $method = new ReflectionMethod(Env::class, 'resolveProcessUserName');
        $method->setAccessible(true);
        $resolved = $method->invoke(null);
        $posixName = (string) (\posix_getpwuid((int) \posix_geteuid())['name'] ?? '');

        self::assertSame($posixName, $resolved);
        self::assertNotSame('', $resolved);
    }

    public function testUserImplementationRejectsGetCurrentUserAsPrimaryIdentitySource(): void
    {
        $source = \file_get_contents(BP . 'app/code/Weline/Framework/App/Env.php');
        self::assertIsString($source);

        self::assertMatchesRegularExpression(
            '/function user\(\): string\s*\{.*?resolveProcessUserName\(\)/s',
            $source,
            'Env::user() must resolve via resolveProcessUserName()'
        );
        self::assertDoesNotMatchRegularExpression(
            '/function user\(\): string\s*\{[^}]*get_current_user\s*\(/s',
            $source
        );
        self::assertStringContainsString('posix_geteuid', $source);
    }

    public function testCheckUserSourceDoesNotHardRefuseWindowsElevation(): void
    {
        $source = \file_get_contents(BP . 'app/code/Weline/Framework/App/Env.php');
        self::assertIsString($source);
        self::assertStringContainsString('isElevatedPrivilegedProcess', $source);
        self::assertDoesNotMatchRegularExpression(
            '/function check_user\(\): void\s*\{[^}]*isElevatedPrivilegedProcess\(\)/s',
            $source,
            'Env::check_user() must not hard-refuse Windows elevation; Cli/DeployUserCommandRunner drops privileges instead'
        );
    }

    private function clearCachedUser(): void
    {
        $property = new ReflectionProperty(Env::class, 'user');
        $property->setAccessible(true);
        $property->setValue(null, '');
    }
}
