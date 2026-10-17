<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\App;

use PHPUnit\Framework\TestCase;
use ReflectionClass as ReflectionClass;
use ReflectionMethod as ReflectionMethod;
use ReflectionProperty as ReflectionProperty;
use Weline\Framework\App\Env;
use Weline\Framework\App\State;
use Weline\Framework\System\Console\System\Install;
use Weline\Framework\Test\ProjectRoot;
use Weline\Framework\Test\TestCore;

/**
 * Aggregated contract suite (merged from 4 *ContractTest files).
 * Observe one overall pass/fail via testAllAppContractsPass().
 */
final class AppContractsTest extends TestCase
{
    public function testAllAppContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'DefaultDatabase::testPrimaryRuntimeDefaultsToPostgreSql' => function () {
            $this->assert_DefaultDatabase_testPrimaryRuntimeDefaultsToPostgreSql();
        },
            'DefaultDatabase::testSqliteRemainsSandboxOnlyDefault' => function () {
            $this->assert_DefaultDatabase_testSqliteRemainsSandboxOnlyDefault();
        },
            'DefaultDatabase::testDebugRuntimeStillUsesPrimaryPostgreSqlUntilSandboxIsExplicitlyEnabled' => function () {
            $this->assert_DefaultDatabase_testDebugRuntimeStillUsesPrimaryPostgreSqlUntilSandboxIsExplicitlyEnabled();
        },
            'DefaultDatabase::testInstallHelpPublishesPostgreSqlDefaultAndSqliteBoundary' => function () {
            $this->assert_DefaultDatabase_testInstallHelpPublishesPostgreSqlDefaultAndSqliteBoundary();
        },
            'EnvProcessUser::testUserMatchesPosixEffectiveAccountWhenAvailable' => function () {
            try {
                $this->assert_EnvProcessUser_testUserMatchesPosixEffectiveAccountWhenAvailable();
            } finally {
                $this->tearDown_EnvProcessUser();
            }
        },
            'EnvProcessUser::testResolveProcessUserNameDoesNotPreferScriptFileOwnerOverPosix' => function () {
            try {
                $this->assert_EnvProcessUser_testResolveProcessUserNameDoesNotPreferScriptFileOwnerOverPosix();
            } finally {
                $this->tearDown_EnvProcessUser();
            }
        },
            'EnvProcessUser::testUserImplementationRejectsGetCurrentUserAsPrimaryIdentitySource' => function () {
            try {
                $this->assert_EnvProcessUser_testUserImplementationRejectsGetCurrentUserAsPrimaryIdentitySource();
            } finally {
                $this->tearDown_EnvProcessUser();
            }
        },
            'EnvProcessUser::testCheckUserSourceDoesNotHardRefuseWindowsElevation' => function () {
            try {
                $this->assert_EnvProcessUser_testCheckUserSourceDoesNotHardRefuseWindowsElevation();
            } finally {
                $this->tearDown_EnvProcessUser();
            }
        },
            'ScopeBootstrapSessionDefer::testAppDefersEagerSessionOnScopeBootstrap' => function () {
            $this->assert_ScopeBootstrapSessionDefer_testAppDefersEagerSessionOnScopeBootstrap();
        },
            'StateLocalizationProviderDefaults::testResolveWebsiteDefaultsDoNotReferenceWebsiteData' => function () {
            $this->assert_StateLocalizationProviderDefaults_testResolveWebsiteDefaultsDoNotReferenceWebsiteData();
        },
            'StateLocalizationProviderDefaults::testProcessDefaultBagsClearTogether' => function () {
            $this->assert_StateLocalizationProviderDefaults_testProcessDefaultBagsClearTogether();
        },
        ];
    }

private function assert_DefaultDatabase_testPrimaryRuntimeDefaultsToPostgreSql(): void
{
    self::assertSame('pgsql', Env::default_CONFIG['db']['default']);
    self::assertSame('pgsql', Env::default_CONFIG['db']['master']['type']);
    self::assertSame('5432', Env::default_CONFIG['db']['master']['hostport']);
}

private function assert_DefaultDatabase_testSqliteRemainsSandboxOnlyDefault(): void
{
    self::assertSame('sqlite', Env::default_CONFIG['sandbox_db']['default']);
    self::assertSame('sqlite', Env::default_CONFIG['sandbox_db']['master']['type']);
    self::assertArrayHasKey('path', Env::default_CONFIG['sandbox_db']['master']);
}

private function assert_DefaultDatabase_testDebugRuntimeStillUsesPrimaryPostgreSqlUntilSandboxIsExplicitlyEnabled(): void
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

private function assert_DefaultDatabase_testInstallHelpPublishesPostgreSqlDefaultAndSqliteBoundary(): void
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

private function tearDown_EnvProcessUser(): void
{
    $this->clearCachedUser_EnvProcessUser();
    parent::tearDown();
}

private function assert_EnvProcessUser_testUserMatchesPosixEffectiveAccountWhenAvailable(): void
{
    if (!\function_exists('posix_geteuid') || !\function_exists('posix_getpwuid')) {
        self::markTestSkipped('posix identity APIs unavailable');
    }
    $this->clearCachedUser_EnvProcessUser();
    $info = \posix_getpwuid((int) \posix_geteuid());
    self::assertIsArray($info);
    $expected = (string) ($info['name'] ?? '');
    self::assertNotSame('', $expected);
    self::assertSame($expected, Env::user());
    self::assertSame($expected, Env::user());
}

private function assert_EnvProcessUser_testResolveProcessUserNameDoesNotPreferScriptFileOwnerOverPosix(): void
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

private function assert_EnvProcessUser_testUserImplementationRejectsGetCurrentUserAsPrimaryIdentitySource(): void
{
    $source = \file_get_contents(BP . 'app/code/Weline/Framework/App/Env.php');
    self::assertIsString($source);
    self::assertMatchesRegularExpression('/function user\(\): string\s*\{.*?resolveProcessUserName\(\)/s', $source, 'Env::user() must resolve via resolveProcessUserName()');
    self::assertDoesNotMatchRegularExpression('/function user\(\): string\s*\{[^}]*get_current_user\s*\(/s', $source);
    self::assertStringContainsString('posix_geteuid', $source);
}

private function assert_EnvProcessUser_testCheckUserSourceDoesNotHardRefuseWindowsElevation(): void
{
    $source = \file_get_contents(BP . 'app/code/Weline/Framework/App/Env.php');
    self::assertIsString($source);
    self::assertStringContainsString('isElevatedPrivilegedProcess', $source);
    self::assertDoesNotMatchRegularExpression('/function check_user\(\): void\s*\{[^}]*isElevatedPrivilegedProcess\(\)/s', $source, 'Env::check_user() must not hard-refuse Windows elevation; Cli/DeployUserCommandRunner drops privileges instead');
}

private function clearCachedUser_EnvProcessUser(): void
{
    $property = new ReflectionProperty(Env::class, 'user');
    $property->setAccessible(true);
    $property->setValue(null, '');
}

private function assert_ScopeBootstrapSessionDefer_testAppDefersEagerSessionOnScopeBootstrap(): void
{
    $src = (string) \file_get_contents(ProjectRoot::path() . 'app/code/Weline/Framework/App.php');
    self::assertStringContainsString('isFrontendScopeBootstrapRequestUri', $src);
    self::assertStringContainsString("'/api/frontend/scope-bootstrap'", $src);
    self::assertStringContainsString('eager-mint/reassert WELINE_CUSTOMER_SESSID', $src);
    self::assertMatchesRegularExpression('/if \(\$this->isFrontendScopeBootstrapRequestUri\(\$requestUri\)\) \{\s*return;/s', $src);
}

private function assert_StateLocalizationProviderDefaults_testResolveWebsiteDefaultsDoNotReferenceWebsiteData(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/App/State.php');
    self::assertStringNotContainsString('WebsiteData::getDefaultLanguage', $src);
    self::assertStringNotContainsString('WebsiteData::getDefaultCurrency', $src);
    self::assertStringNotContainsString('Weline\Websites\Data\WebsiteData', $src);
    self::assertStringContainsString('preferredDefaultLanguage', $src);
    self::assertStringContainsString('preferredDefaultCurrency', $src);
    self::assertStringContainsString('LocalizationProviderRegistry', $src);
    self::assertTrue(\method_exists(State::class, 'resolveWebsiteDefaultLanguage'));
    self::assertTrue(\method_exists(State::class, 'resolveWebsiteDefaultCurrency'));
    self::assertTrue(\method_exists(State::class, 'clearProcessLocalizationCaches'));
}

private function assert_StateLocalizationProviderDefaults_testProcessDefaultBagsClearTogether(): void
{
    State::clearProcessLocalizationCaches();
    $lang = new \ReflectionProperty(State::class, 'websiteDefaultLanguageByScope');
    $currency = new \ReflectionProperty(State::class, 'websiteDefaultCurrencyByScope');
    $lang->setValue(null, ['id:0' => 'zh_Hans_CN']);
    $currency->setValue(null, ['id:0' => 'CNY']);
    self::assertNotSame([], $lang->getValue(null));
    State::clearProcessLocalizationCaches();
    self::assertSame([], $lang->getValue(null));
    self::assertSame([], $currency->getValue(null));
}
}
