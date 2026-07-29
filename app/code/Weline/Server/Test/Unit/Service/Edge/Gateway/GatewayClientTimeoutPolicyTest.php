<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Server\Service\Edge\Gateway\GatewayClient;

final class GatewayClientTimeoutPolicyTest extends TestCase
{
    public function testRepairResponseCoversThePublicationProbeWindow(): void
    {
        $client = new GatewayClient(timeoutSeconds: 2.0);

        self::assertSame(90.0, $this->responseTimeout($client, 'admin', 'repair'));
        self::assertSame(2.0, $this->responseTimeout($client, 'admin', 'status'));
        self::assertSame(2.0, $this->responseTimeout($client, 'project', 'repair'));
    }

    private function responseTimeout(
        GatewayClient $client,
        string $channel,
        string $operation,
    ): float {
        $method = new ReflectionMethod(GatewayClient::class, 'responseTimeoutSeconds');
        return (float)$method->invoke($client, $channel, $operation);
    }
}
