<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Reload;

final class ReloadCommandInstanceParseTest extends TestCase
{
    public function testParseInstanceNameFromPositionalArgument(): void
    {
        $reload = new Reload();

        $instance = $this->invokeProtected($reload, 'parseInstanceName', [
            0 => 'server:reload',
            1 => 'test',
        ]);

        self::assertSame('test', $instance);
    }

    public function testParseInstanceNameFromNamedKeyCompatibilityShape(): void
    {
        $reload = new Reload();

        $instance = $this->invokeProtected($reload, 'parseInstanceName', [
            'test' => true,
        ]);

        self::assertSame('test', $instance);
    }

    public function testParseInstanceNamePrioritizesExplicitInstanceOption(): void
    {
        $reload = new Reload();

        $instance = $this->invokeProtected($reload, 'parseInstanceName', [
            'instance' => 'api',
            'test' => true,
        ]);

        self::assertSame('api', $instance);
    }

    public function testParseInstanceNameIgnoresRestartShortFlagCompatibilityShape(): void
    {
        $reload = new Reload();

        $instance = $this->invokeProtected($reload, 'parseInstanceName', [
            'r' => true,
        ]);

        self::assertSame('default', $instance);
    }

    public function testParseInstanceNameIgnoresRestartLongFlagCompatibilityShape(): void
    {
        $reload = new Reload();

        $instance = $this->invokeProtected($reload, 'parseInstanceName', [
            'restart' => true,
        ]);

        self::assertSame('default', $instance);
    }

    private function invokeProtected(object $object, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$args);
    }
}
