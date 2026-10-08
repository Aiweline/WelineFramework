<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;

final class ApplyOriginNoStoreWlsFpcBypassContractTest extends TestCase
{
    public function testServerListensSharedCacheForbiddenAndMarksWlsFpcBypass(): void
    {
        $root = dirname(__DIR__, 3);
        $eventXml = (string)file_get_contents($root . '/etc/event.xml');
        $observer = (string)file_get_contents($root . '/Observer/ApplyOriginNoStoreWlsFpcBypass.php');

        self::assertStringContainsString('Weline_Framework::response::shared_cache_forbidden', $eventXml);
        self::assertStringContainsString('ApplyOriginNoStoreWlsFpcBypass', $eventXml);
        self::assertStringContainsString('STATUS_BYPASS', $observer);
        self::assertStringContainsString("setHeader('X-Weline-FPC', 'BYPASS')", $observer);
        self::assertStringContainsString("setHeader('X-Wls-Fpc-Status', 'BYPASS')", $observer);
        self::assertStringContainsString('\\bno-store\\b', $observer);
    }
}
