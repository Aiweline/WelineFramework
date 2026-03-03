<?php
declare(strict_types=1);

namespace Weline\Server\Test\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Contract\ServiceCommand;

class ServiceCommandTest extends TestCase
{
    public function testBuild(): void
    {
        $command = new ServiceCommand(
            script: 'bin/worker.php',
            arguments: ['--port=10443', '--instance=default'],
        );

        $built = $command->build();

        $this->assertStringContainsString(PHP_BINARY, $built);
        $this->assertStringContainsString('worker.php', $built);
        $this->assertStringContainsString('--port=10443', $built);
        $this->assertStringContainsString('--instance=default', $built);
    }

    public function testGetAbsoluteScriptRelative(): void
    {
        $command = new ServiceCommand(script: 'bin/worker.php');

        $absolute = $command->getAbsoluteScript();

        $this->assertStringStartsWith(BP, $absolute);
        $this->assertStringContainsString('worker.php', $absolute);
    }

    public function testGetAbsoluteScriptAbsolute(): void
    {
        $absolutePath = '/usr/local/bin/worker.php';
        $command = new ServiceCommand(script: $absolutePath);

        $this->assertEquals($absolutePath, $command->getAbsoluteScript());
    }

    public function testGetWorkingDir(): void
    {
        $command1 = new ServiceCommand(script: 'bin/worker.php');
        $this->assertEquals(BP, $command1->getWorkingDir());

        $customDir = '/custom/dir';
        $command2 = new ServiceCommand(script: 'bin/worker.php', workingDir: $customDir);
        $this->assertEquals($customDir, $command2->getWorkingDir());
    }

    public function testGetProcessName(): void
    {
        $command = new ServiceCommand(
            script: 'bin/worker.php',
            processName: 'weline-wls-worker-1',
        );

        $this->assertEquals('weline-wls-worker-1', $command->getProcessName());
    }

    public function testGetEnvironment(): void
    {
        $customEnv = ['CUSTOM_VAR' => 'value'];
        $command = new ServiceCommand(
            script: 'bin/worker.php',
            environment: $customEnv,
        );

        $env = $command->getEnvironment();

        $this->assertArrayHasKey('CUSTOM_VAR', $env);
        $this->assertEquals('value', $env['CUSTOM_VAR']);
    }
}
