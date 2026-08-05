<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime\Resumable;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\Resumable\Runner\RuntimeProcessIdentity;
use Weline\Framework\Runtime\Resumable\Runner\RuntimeRunnerInvocation;

final class RuntimeRunnerInvocationTest extends TestCase
{
    public function testCurrentProcessIdentityFallsBackToCanonicalArgvWhenLiveInspectionIsDenied(): void
    {
        $identity = RuntimeProcessIdentity::forTask(
            'task-runtime-invocation-test',
            3,
            'launch-runtime-invocation-test',
        );
        $invocation = new RuntimeRunnerInvocation($identity, 'runner-runtime-invocation-test');

        $started = $invocation->withCurrentProcessIdentity(
            static fn (int $_pid): string => '',
        );

        self::assertSame(getmypid(), $started->process->pid);
        self::assertNotSame('', $started->process->liveCommand);
        self::assertStringContainsString('runtime:task:run', $started->process->liveCommand);
        self::assertStringContainsString('--task-id=task-runtime-invocation-test', $started->process->liveCommand);
        self::assertStringContainsString('--generation=3', $started->process->liveCommand);
        self::assertStringContainsString('--runner-id=runner-runtime-invocation-test', $started->process->liveCommand);
        self::assertStringContainsString('--name=' . $identity->processName, $started->process->liveCommand);
        self::assertStringContainsString('--launch-id=launch-runtime-invocation-test', $started->process->liveCommand);
    }
}
