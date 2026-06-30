<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\App;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App as FrameworkApp;
use Weline\Framework\App\Env;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeInterface;

final class EnvRuntimeConfigPersistenceTest extends TestCase
{
    private string $envPath = '';
    private string $originalEnvContent = '';

    protected function setUp(): void
    {
        $this->envPath = Env::path_ENV_FILE;
        $this->originalEnvContent = \is_file($this->envPath)
            ? (string) \file_get_contents($this->envPath)
            : "<?php return [];";
    }

    protected function tearDown(): void
    {
        \file_put_contents($this->envPath, $this->originalEnvContent);
        Env::getInstance()->reload();
        Env::refreshMaintenanceCache();
        Runtime::resetModeCache();
    }

    public function testRuntimeOverridesDoNotLeakIntoPersistedEnvWrites(): void
    {
        \file_put_contents($this->envPath, <<<'PHP'
<?php return [
    'system' => ['maintenance' => false],
    'wls' => ['host' => '127.0.0.1'],
];
PHP);

        $env = Env::getInstance()->reload();
        $env->applyRuntimeConfig([
            'wls' => [
                'session' => [
                    'port' => 19975,
                    'token_file_name' => 'session_server.instance-a.token',
                ],
                'memory_service' => [
                    'port' => 19976,
                    'token_file_name' => 'memory_server.instance-a.token',
                ],
            ],
        ]);

        self::assertSame('session_server.instance-a.token', $env->getConfig('wls.session.token_file_name'));
        self::assertSame('memory_server.instance-a.token', $env->getConfig('wls.memory_service.token_file_name'));

        self::assertTrue($env->setConfig('system.maintenance', true));

        /** @var array<string, mixed> $persisted */
        $persisted = include $this->envPath;
        self::assertTrue((bool) ($persisted['system']['maintenance'] ?? false));
        self::assertFalse(isset($persisted['wls']['session']));
        self::assertFalse(isset($persisted['wls']['memory_service']));

        self::assertSame('session_server.instance-a.token', $env->getConfig('wls.session.token_file_name'));
        self::assertSame('memory_server.instance-a.token', $env->getConfig('wls.memory_service.token_file_name'));
    }

    public function testQueueSchedulerDefaultsExposeConcurrencyConfig(): void
    {
        \file_put_contents($this->envPath, <<<'PHP'
<?php return [
    'system' => ['maintenance' => false],
];
PHP);

        $env = Env::getInstance()->reload();

        self::assertSame(4, $env->getConfig('queue.cron.max_concurrent'));
        self::assertSame('512M', $env->getConfig('queue.worker.memory_limit'));
    }

    public function testAppEnvWritesFalseyValuesInsteadOfTreatingThemAsReads(): void
    {
        \file_put_contents($this->envPath, <<<'PHP'
<?php return [];
PHP);
        Env::getInstance()->reload();

        self::assertTrue(FrameworkApp::Env('codex.falsey_bool', false));
        self::assertFalse(FrameworkApp::Env('codex.falsey_bool'));

        self::assertTrue(FrameworkApp::Env('codex.falsey_zero', 0));
        self::assertSame(0, FrameworkApp::Env('codex.falsey_zero'));

        self::assertTrue(FrameworkApp::Env('codex.falsey_empty_string', ''));
        self::assertSame('', FrameworkApp::Env('codex.falsey_empty_string'));
    }

    public function testRuntimeMaintenanceModeDoesNotPersistEnvFile(): void
    {
        \file_put_contents($this->envPath, <<<'PHP'
<?php return [
    'system' => ['maintenance' => false],
];
PHP);

        $env = Env::getInstance()->reload();
        $env->setRuntimeMaintenanceMode(true);

        self::assertTrue((bool) $env->getConfig('system.maintenance'));

        /** @var array<string, mixed> $persisted */
        $persisted = include $this->envPath;
        self::assertFalse((bool) ($persisted['system']['maintenance'] ?? false));
    }

    public function testRuntimeMaintenanceModeStaysPinnedForCurrentWlsProcess(): void
    {
        \file_put_contents($this->envPath, <<<'PHP'
<?php return [
    'system' => ['maintenance' => false],
];
PHP);

        $env = Env::getInstance()->reload();
        Runtime::setMode(RuntimeInterface::MODE_WLS);

        $env->setRuntimeMaintenanceMode(true);
        Env::refreshMaintenanceCache();
        self::assertTrue((bool) $env->getConfig('system.maintenance'));

        $env->setRuntimeMaintenanceMode(false);
        Env::refreshMaintenanceCache();
        self::assertFalse((bool) $env->getConfig('system.maintenance'));
    }
}
