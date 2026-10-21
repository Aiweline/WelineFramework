<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\Resumable\Runner\RuntimeProcessIdentity;
use Weline\Server\Service\Runtime\ResumableTaskWatchdogGateway;

final class ResumableTaskWatchdogGatewayTest extends TestCase
{
    public function testCanonicalProcessNameRepairsLegacyGenerationMismatch(): void
    {
        $taskId = 'task-62009c7dff945482cfe0fb8573530ffa';
        $canonical = RuntimeProcessIdentity::buildProcessName($taskId, 3);
        $previousGeneration = RuntimeProcessIdentity::buildProcessName($taskId, 2);

        self::assertSame($canonical, $this->canonical($taskId, 3, $previousGeneration));
        self::assertSame($canonical, $this->canonical($taskId, 3, ''));
        self::assertSame($canonical, $this->canonical($taskId, 3, $canonical));
    }

    private function canonical(string $taskId, int $generation, string $persisted): string
    {
        $method = new \ReflectionMethod(ResumableTaskWatchdogGateway::class, 'canonicalProcessName');

        return (string) $method->invoke(null, $taskId, $generation, $persisted);
    }
}
