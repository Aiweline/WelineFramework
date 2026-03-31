<?php

declare(strict_types=1);

namespace Agent\CursorSupervisor\Test\Unit\Service;

use Agent\CursorSupervisor\Service\InteractiveShellService;
use PHPUnit\Framework\TestCase;

final class InteractiveShellServiceTest extends TestCase
{
    public function testToPowershellSingleQuotedEscapesInnerQuotes(): void
    {
        $value = "E:\\path\\it's\\ok";
        $escaped = InteractiveShellService::toPowershellSingleQuoted($value);

        self::assertSame("'E:\\path\\it''s\\ok'", $escaped);
    }
}
