<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Runtime\DirectReloadSurgeIdAllocator;

final class DirectReloadSurgeIdAllocatorTest extends TestCase
{
    /** @dataProvider allocations */
    public function testAllocatesOutsideTheCurrentWorkerIdNamespace(
        int $maximumExistingId,
        int $expectedStart,
    ): void {
        self::assertSame(
            $expectedStart,
            DirectReloadSurgeIdAllocator::startInstanceId($maximumExistingId),
        );
    }

    /** @return iterable<string,array{int,int}> */
    public static function allocations(): iterable
    {
        yield 'empty' => [0, 1100];
        yield 'below canonical floor' => [99, 1100];
        yield 'canonical floor' => [100, 1100];
        yield 'above floor' => [101, 1101];
        yield 'large generation' => [4000, 5000];
    }
}
