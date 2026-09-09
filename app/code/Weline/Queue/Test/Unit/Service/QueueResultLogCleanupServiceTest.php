<?php
declare(strict_types=1);

namespace Weline\Queue\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Queue\Service\QueueResultLogCleanupService;

final class QueueResultLogCleanupServiceTest extends TestCase
{
    public function testBoundTrailKeepsShortProcessSummary(): void
    {
        $service = (new \ReflectionClass(QueueResultLogCleanupService::class))
            ->newInstanceWithoutConstructor();

        $short = 'ok';
        self::assertSame($short, $service->boundTrail($short, 8192));

        $huge = str_repeat('x', 20000) . "\nTAIL";
        $bounded = $service->boundTrail($huge, 8192);
        self::assertLessThanOrEqual(8192, strlen($bounded));
        self::assertStringContainsString('process trail kept', $bounded);
        self::assertStringEndsWith('TAIL', $bounded);
    }

    public function testCleanupCommandSurface(): void
    {
        $console = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Console/Queue/CleanupResultLogs.php'
        );
        $service = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/QueueResultLogCleanupService.php'
        );

        self::assertStringContainsString('cleanupOversized', $console);
        self::assertStringContainsString('queue:cleanup-result-logs', $console);
        self::assertStringContainsString('DEFAULT_MAX_BYTES = 8192', $service);
        self::assertStringContainsString('setProcess', $service);
        self::assertStringContainsString('setResult', $service);
    }
}
