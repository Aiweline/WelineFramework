<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Deploy;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Framework\App\Env;
use Weline\Framework\App\System;
use Weline\Framework\Console\Console\Deploy\Mode\Set as ModeSet;

/**
 * plan_id=env-deploy-key：Mode\Set 须写 system.deploy（与 Env::system('deploy') / DEV 同源）。
 * 使用 env 沙箱：setUp 备份、tearDown 还原，勿污染用户 env。
 */
final class ModeSetSystemDeployKeyContractTest extends TestCase
{
    private string $envPath = '';

    private string $originalEnvContent = '';

    protected function setUp(): void
    {
        $this->envPath = Env::path_ENV_FILE;
        $this->originalEnvContent = \is_file($this->envPath)
            ? (string)\file_get_contents($this->envPath)
            : "<?php return [];";
    }

    protected function tearDown(): void
    {
        \file_put_contents($this->envPath, $this->originalEnvContent);
        Env::getInstance()->reload();
    }

    public function testPersistDeployModeWritesSystemDeployAuthoritativeKey(): void
    {
        \file_put_contents($this->envPath, <<<'PHP'
<?php return [
    'system' => ['deploy' => 'dev'],
    'deploy' => 'dev',
];
PHP);
        Env::getInstance()->reload();
        self::assertSame('dev', Env::system('deploy'));

        $modeSet = $this->newModeSetWithoutConstructor();
        self::assertTrue($modeSet->persistDeployMode('prod'));

        self::assertSame('prod', Env::system('deploy'));
        self::assertSame('prod', Env::getInstance()->getConfig('system.deploy'));
        self::assertSame('prod', Env::getInstance()->getConfig('deploy'));

        /** @var array<string, mixed> $persisted */
        $persisted = include $this->envPath;
        self::assertSame('prod', $persisted['system']['deploy'] ?? null);
        self::assertSame('prod', $persisted['deploy'] ?? null);
    }

    public function testPersistDeployModeRejectsInvalidType(): void
    {
        $modeSet = $this->newModeSetWithoutConstructor();
        self::assertFalse($modeSet->persistDeployMode('staging'));
    }

    public function testModeSetSourceUsesSystemDeployNotOnlyTopLevel(): void
    {
        $src = (string)\file_get_contents(
            BP . 'app/code/Weline/Framework/Console/Console/Deploy/Mode/Set.php'
        );
        self::assertStringContainsString("setConfig('system.deploy'", $src);
        self::assertStringContainsString('persistDeployMode', $src);
        self::assertStringContainsString("Env::system('deploy')", $src);
    }

    private function newModeSetWithoutConstructor(): ModeSet
    {
        $ref = new ReflectionClass(ModeSet::class);
        /** @var ModeSet $instance */
        $instance = $ref->newInstanceWithoutConstructor();
        $systemProp = $ref->getProperty('system');
        $systemProp->setValue($instance, $this->createMock(System::class));

        return $instance;
    }
}
