<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Start;

final class StartForceSwitchStopArgsTest extends TestCase
{
    public function testBuildStopExistingServerArgsAddsFastLocalFlagWhenRequested(): void
    {
        $start = new Start();

        $args = $this->invokeProtected($start, 'buildStopExistingServerArgs', 'default', true);

        self::assertTrue((bool) ($args['fast-local'] ?? false));
        self::assertTrue((bool) ($args['force'] ?? false));
        self::assertTrue((bool) ($args['f'] ?? false));
        self::assertSame('default', $args[1] ?? null);
    }

    public function testBuildStopExistingServerArgsKeepsDefaultStopCallForNormalRestart(): void
    {
        $start = new Start();

        $args = $this->invokeProtected($start, 'buildStopExistingServerArgs', 'default', false);

        self::assertArrayNotHasKey('fast-local', $args);
        self::assertTrue((bool) ($args['force'] ?? false));
        self::assertTrue((bool) ($args['f'] ?? false));
    }

    private function invokeProtected(object $object, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$args);
    }
}
