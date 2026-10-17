<?php

declare(strict_types=1);

namespace Weline\SessionManager\Test\Unit\Service;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllSessionManagerServiceContractsPass().
 */
final class SessionManagerServiceContractsTest extends TestCase
{
    public function testAllSessionManagerServiceContractsPass(): void
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
            'RequestDeviceMetadataProvider::testProcessLivedProviderResolvesTheCurrentRequestAtCallTime' => function () {
            $this->assert_RequestDeviceMetadataProvider_testProcessLivedProviderResolvesTheCurrentRequestAtCallTime();
        },
        ];
    }

private function assert_RequestDeviceMetadataProvider_testProcessLivedProviderResolvesTheCurrentRequestAtCallTime(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/SessionManager' . '/Service/RequestDeviceMetadataProvider.php';
    $source = (string) file_get_contents($path);
    self::assertStringNotContainsString('private readonly Request $request', $source);
    self::assertStringContainsString('ObjectManager::getInstance(Request::class)', $source);
}
}
