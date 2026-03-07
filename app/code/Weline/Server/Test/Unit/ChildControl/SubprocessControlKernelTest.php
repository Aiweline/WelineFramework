<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\ChildControl;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ChildControl\MasterOrphanGuard;
use Weline\Server\IPC\ChildControl\SubprocessControlKernel;

final class SubprocessControlKernelTest extends TestCase
{
    public function testResolveControlPortFromInstanceFile(): void
    {
        $instanceName = 'ut-kernel-port';
        $instanceDir = BP . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'instances';
        $instanceFile = $instanceDir . DIRECTORY_SEPARATOR . $instanceName . '.json';
        if (!\is_dir($instanceDir)) {
            @\mkdir($instanceDir, 0777, true);
        }

        \file_put_contents($instanceFile, \json_encode(['control_port' => 19091]));
        try {
            $resolved = SubprocessControlKernel::resolveControlPort($instanceName, 0);
            $this->assertSame(19091, $resolved);
            $this->assertSame(18888, SubprocessControlKernel::resolveControlPort($instanceName, 18888));
        } finally {
            @\unlink($instanceFile);
        }
    }

    public function testMasterOrphanGuardShortCircuit(): void
    {
        $guard = new MasterOrphanGuard();

        $this->assertFalse($guard->shouldExit(0, false, false, 'UT'));
        $this->assertFalse($guard->shouldExit(1234, false, true, 'UT'));
    }
}

