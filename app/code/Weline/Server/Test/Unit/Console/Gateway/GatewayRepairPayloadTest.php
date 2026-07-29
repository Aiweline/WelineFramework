<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console\Gateway;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Server\Console\Server\Gateway\Repair;

final class GatewayRepairPayloadTest extends TestCase
{
    public function testClockAcceptanceUsesTheExactSecurityExemptionPayload(): void
    {
        self::assertSame(
            ['accept_clock' => true],
            $this->payload(['accept-clock' => true, 'json' => true]),
        );
    }

    public function testUnselectedRepairFlagsAreNotSerializedAsFalse(): void
    {
        self::assertSame([], $this->payload(['json' => true]));
        self::assertSame(
            [
                'accept_storage_recovery' => true,
                'retry_h3' => true,
            ],
            $this->payload([
                'accept-storage' => true,
                'retry-h3' => true,
                'json' => true,
            ]),
        );
    }

    /** @return array<string, true> */
    private function payload(array $args): array
    {
        $method = new ReflectionMethod(Repair::class, 'repairPayload');
        return (array)$method->invoke(null, $args);
    }
}
