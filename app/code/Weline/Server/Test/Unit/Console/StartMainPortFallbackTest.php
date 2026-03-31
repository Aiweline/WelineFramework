<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Start;

final class StartMainPortFallbackTest extends TestCase
{
    public function testResolveOrphanMainPortFallbackKeepsDefaultFallbackPortAfterAutoDowngrade(): void
    {
        $start = new class extends Start {
            public bool $findAvailableMainPortCalled = false;

            public function resolve(int $port, bool $portExplicit, bool $autoDowngradedFromDefaultPort, array $inspect): int
            {
                return $this->resolveOrphanMainPortFallback(
                    $port,
                    $portExplicit,
                    $autoDowngradedFromDefaultPort,
                    $inspect
                );
            }

            protected function findAvailableMainPort(int $startPort, int $maxScan = 200): int
            {
                $this->findAvailableMainPortCalled = true;

                return 9982;
            }
        };

        $result = $start->resolve(
            9981,
            false,
            true,
            [
                'in_use' => true,
                'is_weline' => false,
                'state' => 'orphan',
            ]
        );

        self::assertSame(9981, $result);
        self::assertFalse($start->findAvailableMainPortCalled);
    }

    public function testResolveOrphanMainPortFallbackScansNextPortOutsideDefaultDowngradePath(): void
    {
        $start = new class extends Start {
            /** @var list<int> */
            public array $calledWith = [];

            public function resolve(int $port, bool $portExplicit, bool $autoDowngradedFromDefaultPort, array $inspect): int
            {
                return $this->resolveOrphanMainPortFallback(
                    $port,
                    $portExplicit,
                    $autoDowngradedFromDefaultPort,
                    $inspect
                );
            }

            protected function findAvailableMainPort(int $startPort, int $maxScan = 200): int
            {
                $this->calledWith = [$startPort, $maxScan];

                return 9983;
            }
        };

        $result = $start->resolve(
            9981,
            false,
            false,
            [
                'in_use' => true,
                'is_weline' => false,
                'state' => 'orphan',
            ]
        );

        self::assertSame(9983, $result);
        self::assertSame([9982, 200], $start->calledWith);
    }
}
