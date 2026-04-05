<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\ChildControl;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
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

    public function testResolveReadyDelayMillisecondsUsesRoleSpecificEnv(): void
    {
        \putenv('WLS_E2E_WORKER_READY_DELAY_MS=4500');
        \putenv('WLS_E2E_MAINTENANCE_READY_DELAY_MS=1200');
        try {
            $this->assertSame(4500, SubprocessControlKernel::resolveReadyDelayMilliseconds(ControlMessage::ROLE_WORKER));
            $this->assertSame(1200, SubprocessControlKernel::resolveReadyDelayMilliseconds(ControlMessage::ROLE_MAINTENANCE));
            $this->assertSame(0, SubprocessControlKernel::resolveReadyDelayMilliseconds(ControlMessage::ROLE_DISPATCHER));
        } finally {
            \putenv('WLS_E2E_WORKER_READY_DELAY_MS');
            \putenv('WLS_E2E_MAINTENANCE_READY_DELAY_MS');
        }
    }

    public function testResolveReadyDelayMillisecondsClampsInvalidValues(): void
    {
        \putenv('WLS_E2E_WORKER_READY_DELAY_MS=-5');
        \putenv('WLS_E2E_MAINTENANCE_READY_DELAY_MS=999999');
        try {
            $this->assertSame(0, SubprocessControlKernel::resolveReadyDelayMilliseconds(ControlMessage::ROLE_WORKER));
            $this->assertSame(60000, SubprocessControlKernel::resolveReadyDelayMilliseconds(ControlMessage::ROLE_MAINTENANCE));
        } finally {
            \putenv('WLS_E2E_WORKER_READY_DELAY_MS');
            \putenv('WLS_E2E_MAINTENANCE_READY_DELAY_MS');
        }
    }
}

