<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\App;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;

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
}
