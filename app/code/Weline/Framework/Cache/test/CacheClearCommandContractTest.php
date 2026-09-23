<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Console\Cache\Clear;
use Weline\Framework\Cache\Scanner;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Runtime\RuntimeControlBroadcasterInterface;

/**
 * Guard: cache:clear must consume Scanner pools/total API, not the retired app/framework map.
 */
final class CacheClearCommandContractTest extends TestCase
{
    public function testNonForceSkipsPermanentPools(): void
    {
        $cleared = [];
        $scanner = $this->createMock(Scanner::class);
        $scanner->method('getCaches')->willReturn([
            'pools' => [
                ['identity' => 'view', 'permanent' => false, 'tip' => '视图缓存', 'stats' => []],
                ['identity' => 'router', 'permanent' => true, 'tip' => '路由缓存', 'stats' => []],
            ],
            'total' => 2,
        ]);
        $scanner->method('clearPool')->willReturnCallback(function (string $identity) use (&$cleared): bool {
            $cleared[] = $identity;

            return true;
        });

        $command = new Clear($scanner, $this->printingStub(), $this->broadcasterStub());
        $command->execute(['cache:clear']);

        $this->assertSame(['view'], $cleared);
    }

    public function testForceClearsPermanentPools(): void
    {
        $cleared = [];
        $scanner = $this->createMock(Scanner::class);
        $scanner->method('getCaches')->willReturn([
            'pools' => [
                ['identity' => 'view', 'permanent' => false, 'tip' => '', 'stats' => []],
                ['identity' => 'router', 'permanent' => true, 'tip' => '', 'stats' => []],
            ],
            'total' => 2,
        ]);
        $scanner->method('clearPool')->willReturnCallback(function (string $identity) use (&$cleared): bool {
            $cleared[] = $identity;

            return true;
        });

        $command = new Clear($scanner, $this->printingStub(), $this->broadcasterStub());
        $command->execute(['cache:clear', '-f']);

        $this->assertSame(['view', 'router'], $cleared);
    }

    private function printingStub(): Printing
    {
        $printing = $this->createMock(Printing::class);
        $printing->method('progressBar');
        $printing->method('doneIcon');
        $printing->method('coloredText');
        $printing->method('infoIcon');
        $printing->method('note');
        $printing->method('warning');
        $printing->method('successIcon');

        return $printing;
    }

    private function broadcasterStub(): RuntimeControlBroadcasterInterface
    {
        return new class implements RuntimeControlBroadcasterInterface {
            public function cacheClear(?string $instanceName = null): array
            {
                return ['success' => true, 'completed' => true, 'message' => 'ok'];
            }

            public function cacheClearAndWait(?string $instanceName = null, float $timeout = 5.0): array
            {
                return ['success' => true, 'completed' => true, 'message' => 'ok'];
            }

            public function maintenanceMode(): ?bool
            {
                return null;
            }

            public function setMaintenanceMode(bool $enabled): array
            {
                return ['success' => true, 'completed' => true, 'message' => 'ok'];
            }
        };
    }
}
