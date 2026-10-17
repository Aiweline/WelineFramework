<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Log\Error;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllServerErrorContractsPass().
 */
final class ServerErrorContractsTest extends TestCase
{
    public function testAllServerErrorContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [

        ];
    }

/**
 * @return array<string, array{0: string}>
 */
private function workerScriptProvider_WorkerFatalRequestContext(): array
{
    return ['http' => ['bin/worker.php'], 'ssl' => ['bin/worker_ssl.php'], 'ssl_event' => ['bin/worker_ssl_event.php']];
}
}
