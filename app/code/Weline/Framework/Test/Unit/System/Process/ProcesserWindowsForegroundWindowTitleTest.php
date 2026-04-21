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
}
