<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\System\Process;

use PHPUnit\Framework\TestCase;
use Weline\Framework\System\Process\Processer;

final class ProcesserWindowsForegroundWindowTitleTest extends TestCase
{
    public function testExplicitWindowTitleOverridesManagedProcessName(): void
    {
        $method = new \ReflectionMethod(Processer::class, 'resolveWindowsForegroundWindowTitle');
        $method->setAccessible(true);

        $title = $method->invoke(
            null,
            'php bin/w server:start default --master-only --frontend --name=weline-wls-master-default --window-title=weline-wls-master-default-frontend'
        );

        self::assertSame('weline-wls-master-default-frontend', $title);
    }

    public function testWindowTitleFallsBackToManagedProcessName(): void
    {
        $method = new \ReflectionMethod(Processer::class, 'resolveWindowsForegroundWindowTitle');
        $method->setAccessible(true);

        $title = $method->invoke(
            null,
            'php worker.php --name=weline-wls-worker-default-1 --frontend'
        );

        self::assertSame('weline-wls-worker-default-1', $title);
    }

    public function testExplicitWindowTitleDoesNotOverrideManagedTaskName(): void
    {
        $command = 'php bin/w server:start default --master-only --frontend --name=weline-wls-master-default --window-title=weline-wls-master-default-frontend';

        self::assertSame('weline-wls-master-default', Processer::getTaskName($command));
    }

    public function testForegroundPhpStartScriptDoesNotUseCmdWrapper(): void
    {
        $method = new \ReflectionMethod(Processer::class, 'writeWindowsForegroundPhpStartScript');
        $method->setAccessible(true);

        $scriptPath = $method->invoke(
            null,
            'php.exe',
            ['bin/w', 'server:start', 'default', '--master-only', '--frontend'],
            'E:\\WelineFramework\\DEV-workspace',
            'weline-wls-master-default-frontend'
        );

        self::assertIsString($scriptPath);
        try {
            $script = (string) \file_get_contents($scriptPath);
            self::assertStringNotContainsString('cmd.exe', $script);
            self::assertStringNotContainsString('cmd /d /c', $script);
            self::assertStringContainsString('Start-Process @startArgs', $script);
            self::assertStringContainsString("FilePath = \$phpExe", $script);
            self::assertStringContainsString("WindowStyle = 'Normal'", $script);
        } finally {
            if (\is_file((string) $scriptPath)) {
                @\unlink((string) $scriptPath);
            }
        }
    }

    public function testWindowsBatchCreateScriptUsesPowerShellStartProcessDirectly(): void
    {
        $method = new \ReflectionMethod(Processer::class, 'buildWindowsBatchCreateScript');
        $method->setAccessible(true);

        $script = $method->invoke(null, [
            [
                'key' => 'worker-1',
                'php' => 'php.exe',
                'arguments' => 'bin/worker.php 127.0.0.1 16895 default --name=weline-wls-worker-default-1',
                'argument_list' => ['bin/worker.php', '127.0.0.1', '16895', 'default', '--name=weline-wls-worker-default-1'],
                'process_name' => 'weline-wls-worker-default-1',
                'cwd' => 'E:\\WelineFramework\\DEV-workspace',
                'enable_log' => true,
                'block' => false,
                'foreground' => true,
            ],
            [
                'key' => 'session',
                'php' => 'php.exe',
                'arguments' => 'app/code/Weline/Server/bin/session_server.php 127.0.0.1 26422 default',
                'argument_list' => ['app/code/Weline/Server/bin/session_server.php', '127.0.0.1', '26422', 'default'],
                'process_name' => 'weline-wls-session-default',
                'cwd' => 'E:\\WelineFramework\\DEV-workspace',
                'enable_log' => true,
                'block' => false,
                'foreground' => false,
            ],
        ], 'C:\\Temp\\weline-result.txt', 'C:\\Temp\\weline-error.txt');

        self::assertIsString($script);
        self::assertStringNotContainsString('cmd.exe', $script);
        self::assertStringNotContainsString('cmd /d /c', $script);
        self::assertStringContainsString('Start-Process @startArgs', $script);
        self::assertStringContainsString("WindowStyle = 'Normal'", $script);
        self::assertStringContainsString("WindowStyle = 'Hidden'", $script);
    }
}
