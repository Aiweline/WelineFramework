<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

require_once __DIR__ . '/stop_test_bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Start;

final class StartCrossProjectIsolationTest extends TestCase
{
    public function testStartupInspectionPreservesForeignProjectScopeForCallerDecision(): void
    {
        [$listener, $port] = $this->createListener();
        try {
            $inspect = $this->createStartWithInspect([
                'in_use' => true,
                'pid' => 11111,
                'pid_running' => true,
                'is_weline' => true,
                'state' => 'weline',
                'pname' => '--name=weline-wls-dispatcher-default-pAAAAAAAA',
                'scope' => 'pAAAAAAAA',
            ])->inspect($port);

            self::assertTrue((bool)($inspect['in_use'] ?? false));
            self::assertSame('pAAAAAAAA', $inspect['scope'] ?? null);
        } finally {
            \fclose($listener);
        }
    }

    public function testStartupInspectionPreservesOwnProjectScopeForCallerDecision(): void
    {
        [$listener, $port] = $this->createListener();
        try {
            $inspect = $this->createStartWithInspect([
                'in_use' => true,
                'pid' => 22222,
                'pid_running' => true,
                'is_weline' => true,
                'state' => 'weline',
                'pname' => '--name=weline-wls-dispatcher-default-pBBBBBBBB',
                'scope' => 'pBBBBBBBB',
            ])->inspect($port);

            self::assertTrue((bool)($inspect['is_weline'] ?? false));
            self::assertSame('pBBBBBBBB', $inspect['scope'] ?? null);
        } finally {
            \fclose($listener);
        }
    }

    public function testStartupInspectionPreservesUnknownOwnerWithoutGrantingAuthority(): void
    {
        [$listener, $port] = $this->createListener();
        try {
            $inspect = $this->createStartWithInspect([
                'in_use' => true,
                'pid' => 33333,
                'pid_running' => true,
                'is_weline' => false,
                'state' => 'unknown',
                'pname' => 'nginx',
                'scope' => '',
            ])->inspect($port);

            self::assertTrue((bool)($inspect['in_use'] ?? false));
            self::assertFalse((bool)($inspect['is_weline'] ?? true));
            self::assertSame('', $inspect['scope'] ?? null);
        } finally {
            \fclose($listener);
        }
    }

    /**
     * @param array<string,mixed> $inspect
     */
    private function createStartWithInspect(array $inspect): Start
    {
        return new class($inspect) extends Start {
            /** @param array<string,mixed> $inspect */
            public function __construct(private readonly array $inspect)
            {
            }

            /**
             * @return array<string,mixed>
             */
            public function inspect(int $port): array
            {
                return $this->inspectStartupPortIfOccupied($port);
            }

            protected function inspectPortOccupantWithHistory(int $port): array
            {
                unset($port);
                return $this->inspect;
            }
        };
    }

    /**
     * @return array{0: resource, 1: int}
     */
    private function createListener(): array
    {
        $listener = \stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($listener, $errorMessage);
        $address = (string)\stream_socket_get_name($listener, false);
        $separator = \strrpos($address, ':');
        self::assertNotFalse($separator);
        $port = (int)\substr($address, $separator + 1);
        self::assertGreaterThanOrEqual(9502, $port);
        self::assertNotSame(9501, $port);

        return [$listener, $port];
    }
}
