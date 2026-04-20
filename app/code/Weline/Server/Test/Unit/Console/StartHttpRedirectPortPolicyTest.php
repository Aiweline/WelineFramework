<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Start;

final class StartHttpRedirectPortPolicyTest extends TestCase
{
    public function testFrameworkOwnedHttpRedirectPortIsAutoReleased(): void
    {
        $start = new Start();

        self::assertTrue($this->invokeProtected($start, 'shouldAutoReleaseHttpRedirectPortOccupant', ['is_weline' => true]));
        self::assertFalse($this->invokeProtected($start, 'shouldAutoReleaseHttpRedirectPortOccupant', ['is_weline' => false]));
        self::assertFalse($this->invokeProtected($start, 'shouldAutoReleaseHttpRedirectPortOccupant', []));
    }

    public function testFrameworkOwnedHttpRedirectPortAcceptsResolvedOwnerWhenInspectorMisses(): void
    {
        $start = new Start();

        self::assertTrue($this->invokeProtected(
            $start,
            'isFrameworkOwnedHttpRedirectPortOccupant',
            ['is_weline' => false],
            'default'
        ));
        self::assertFalse($this->invokeProtected(
            $start,
            'isFrameworkOwnedHttpRedirectPortOccupant',
            ['is_weline' => false],
            null
        ));
    }

    public function testFrameworkOwnedHttpRedirectReleaseUsesForcedFrameworkCleanupPath(): void
    {
        $start = new class extends Start {
            public array $capturedArgs = [];
            public bool $checkResult = true;

            public function release(string $host, int $port, string $instanceName = 'default'): bool
            {
                return $this->releaseFrameworkOwnedHttpRedirectPort($host, $port, $instanceName);
            }

            protected function checkAndReleasePort(string $host, int $port, bool $forceRelease = false, string $label = 'Port', string $instanceName = 'default'): bool
            {
                $this->capturedArgs = [$host, $port, $forceRelease, $label, $instanceName];

                return $this->checkResult;
            }
        };

        self::assertTrue($start->release('0.0.0.0', 80, 'default'));
        self::assertSame(
            ['0.0.0.0', 80, true, 'HTTP Redirect', 'default'],
            $start->capturedArgs
        );
    }

    private function invokeProtected(object $object, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$args);
    }
}
