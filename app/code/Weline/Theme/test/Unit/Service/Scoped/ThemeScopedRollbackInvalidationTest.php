<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Scoped;

use PHPUnit\Framework\TestCase;

final class ThemeScopedRollbackInvalidationTest extends TestCase
{
    public function testRollbackPublishesTheWholeBatchBeforeItsTransactionCommits(): void
    {
        $result = $this->probe('commit');
        self::assertNull($result['error']);
        self::assertCount(1, $result['events']);
        $event = $result['events'][0];
        self::assertTrue($event['active']);
        self::assertSame(10, $event['projections']);
        self::assertSame(0, $event['remaining_pointers']);
        self::assertSame(2, $event['batches']);
        self::assertSame('publish', $event['change']['resource']['action']);
        self::assertSame('theme_scoped_release_batch_rollback', $event['change']['origin']['entry']);
        self::assertContains('global/storefront/theme', $event['change']['impact']['namespaces']);
        self::assertContains('website/shop/theme', $event['change']['impact']['namespaces']);
        self::assertSame(1, $result['generation']);
        self::assertSame(0, $result['remaining_pointers']);
        self::assertSame(2, $result['batches']);
    }

    public function testCriticalChangedFailureRollsBackPointersReceiptAndVersionTogether(): void
    {
        $result = $this->probe('fail');
        self::assertSame('fixture_changed_failed', $result['error']);
        self::assertCount(1, $result['events']);
        self::assertTrue($result['events'][0]['active']);
        self::assertSame(0, $result['generation']);
        self::assertSame(10, $result['remaining_pointers']);
        self::assertSame(1, $result['batches']);
        self::assertSame(0, $result['projections']);
    }

    private function probe(string $mode): array
    {
        $command = [PHP_BINARY, __DIR__ . '/Fixtures/theme_rollback_invalidation.php', $mode];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error . $output);
        $result = json_decode($output, true);
        self::assertIsArray($result, $error . $output);
        return $result;
    }
}
