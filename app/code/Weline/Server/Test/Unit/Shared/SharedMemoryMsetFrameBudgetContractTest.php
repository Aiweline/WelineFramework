<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Shared;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Phrase\MsWriteBudget;
use Weline\Server\Session\Server\SessionProtocol;
use Weline\Server\Shared\Service\SharedMemoryService;

/**
 * ACC-W3-MS-CHUNK（Server 面）：mset/set 拒大帧；预算与 Phrase MsWriteBudget 对齐。
 */
final class SharedMemoryMsetFrameBudgetContractTest extends TestCase
{
    public function testEncodedRequestExceedsMaxFrameApiExists(): void
    {
        self::assertTrue(\method_exists(SessionProtocol::class, 'encodedRequestExceedsMaxFrame'));
        self::assertSame(MsWriteBudget::FRAME_BUDGET_BYTES, SessionProtocol::maxFrameBytes());
        self::assertFalse(SessionProtocol::encodedRequestExceedsMaxFrame('ping', []));
        self::assertTrue(SessionProtocol::encodedRequestExceedsMaxFrame('set', [
            'sid' => 'x',
            'key' => 'k',
            'val' => \str_repeat('a', SessionProtocol::maxFrameBytes()),
            'ttl' => 1,
        ]));
    }

    public function testMsetSourcePrefLightsAndSplitsOversizedBatches(): void
    {
        $src = (string)\file_get_contents(
            (string)(new \ReflectionClass(SharedMemoryService::class))->getFileName()
        );
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
