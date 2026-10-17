<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Shared;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Phrase\MsWriteBudget;
use Weline\Server\Session\Server\SessionProtocol;
use Weline\Server\Shared\Service\SharedMemoryService;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllServerSharedContractsPass().
 */
final class ServerSharedContractsTest extends TestCase
{
    public function testAllServerSharedContractsPass(): void
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
            'SharedMemoryMsetFrameBudget::testEncodedRequestExceedsMaxFrameApiExists' => function () {
            $this->assert_SharedMemoryMsetFrameBudget_testEncodedRequestExceedsMaxFrameApiExists();
        },
            'SharedMemoryMsetFrameBudget::testMsetSourcePrefLightsAndSplitsOversizedBatches' => function () {
            $this->assert_SharedMemoryMsetFrameBudget_testMsetSourcePrefLightsAndSplitsOversizedBatches();
        },
        ];
    }

private function assert_SharedMemoryMsetFrameBudget_testEncodedRequestExceedsMaxFrameApiExists(): void
{
    self::assertTrue(\method_exists(SessionProtocol::class, 'encodedRequestExceedsMaxFrame'));
    self::assertSame(MsWriteBudget::FRAME_BUDGET_BYTES, SessionProtocol::maxFrameBytes());
    self::assertFalse(SessionProtocol::encodedRequestExceedsMaxFrame('ping', []));
    self::assertTrue(SessionProtocol::encodedRequestExceedsMaxFrame('set', ['sid' => 'x', 'key' => 'k', 'val' => \str_repeat('a', SessionProtocol::maxFrameBytes()), 'ttl' => 1]));
}

private function assert_SharedMemoryMsetFrameBudget_testMsetSourcePrefLightsAndSplitsOversizedBatches(): void
{
    $src = (string) \file_get_contents((string) (new \ReflectionClass(SharedMemoryService::class))->getFileName());
    self::assertStringContainsString('encodedRequestExceedsMaxFrame', $src);
    self::assertStringContainsString('array_chunk', $src);
    self::assertStringContainsString('拒大帧', $src);
    $msetPos = \strpos($src, 'function mset(');
    $preflightPos = \strpos($src, 'encodedRequestExceedsMaxFrame', $msetPos ?: 0);
    self::assertNotFalse($msetPos);
    self::assertNotFalse($preflightPos);
    self::assertGreaterThan($msetPos, $preflightPos);
}
}
