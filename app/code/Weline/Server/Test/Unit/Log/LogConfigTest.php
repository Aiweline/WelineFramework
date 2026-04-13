<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Log;

use PHPUnit\Framework\TestCase;
use Weline\Server\Log\LogConfig;

final class LogConfigTest extends TestCase
{
    public function testAutoStdoutDefaultsToEnabled(): void
    {
        self::assertTrue(LogConfig::resolveStdoutEnabled('auto', false, false));
    }

    public function testExplicitFalseDisablesStdout(): void
    {
        self::assertFalse(LogConfig::resolveStdoutEnabled(false, true, true));
        self::assertFalse(LogConfig::resolveStdoutEnabled('false', true, true));
        self::assertFalse(LogConfig::resolveStdoutEnabled('0', true, true));
        self::assertFalse(LogConfig::resolveStdoutEnabled(0, true, true));
    }

    public function testExplicitTrueKeepsStdoutEnabled(): void
    {
        self::assertTrue(LogConfig::resolveStdoutEnabled(true, false, false));
        self::assertTrue(LogConfig::resolveStdoutEnabled('true', false, false));
        self::assertTrue(LogConfig::resolveStdoutEnabled(1, false, false));
    }
}
