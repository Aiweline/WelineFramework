<?php

declare(strict_types=1);

namespace Agent\CursorSupervisor\Test\Unit\Console\Cursor\Supervisor;

use Agent\CursorSupervisor\Console\Cursor\Supervisor\Watchdog;
use PHPUnit\Framework\TestCase;

final class WatchdogOptionResolverTest extends TestCase
{
    public function testResolveDocSyncOptionDefaultsToTrue(): void
    {
        self::assertTrue(Watchdog::resolveDocSyncOption([], []));
    }

    public function testResolveDocSyncOptionSupportsExplicitEnableFlag(): void
    {
        self::assertTrue(Watchdog::resolveDocSyncOption(['doc-sync' => 1], []));
        self::assertTrue(Watchdog::resolveDocSyncOption([], ['doc-sync' => true]));
    }

    public function testResolveDocSyncOptionSupportsDisableFlag(): void
    {
        self::assertFalse(Watchdog::resolveDocSyncOption(['no-doc-sync' => 1], []));
        self::assertFalse(Watchdog::resolveDocSyncOption([], ['no-doc-sync' => true]));
    }

    public function testResolveAutoTriggerOptionDefaultsToTrue(): void
    {
        self::assertTrue(Watchdog::resolveAutoTriggerOption([], []));
    }

    public function testResolveAutoTriggerOptionSupportsExplicitEnableFlag(): void
    {
        self::assertTrue(Watchdog::resolveAutoTriggerOption(['auto-trigger' => 1], []));
        self::assertTrue(Watchdog::resolveAutoTriggerOption([], ['auto-trigger' => true]));
    }

    public function testResolveAutoTriggerOptionSupportsDisableFlag(): void
    {
        self::assertFalse(Watchdog::resolveAutoTriggerOption(['no-auto-trigger' => 1], []));
        self::assertFalse(Watchdog::resolveAutoTriggerOption([], ['no-auto-trigger' => true]));
    }
}
