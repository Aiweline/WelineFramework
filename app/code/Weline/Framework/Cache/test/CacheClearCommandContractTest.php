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

    public function testDoesNotSoftPullThemeStorefrontFpcWarmerFqcn(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__) . '/Console/Cache/Clear.php'
        );
        self::assertStringNotContainsString('StorefrontFpcWarmer', $source);
        self::assertStringContainsString('warmUp(\'fpc\')', $source);
        self::assertStringContainsString('warmUp(\'website\')', $source);
        self::assertStringContainsString(
            "warmUp('weline_theme_layout_entity_published_projection')",
            $source,
        );
        self::assertStringContainsString("warmUp('product')", $source);
        self::assertStringNotContainsString('SearchProjectionScopeCacheWarmer', $source);
        self::assertStringNotContainsString('HomepageShelfOffersLocaleCacheWarmer', $source);
        self::assertStringContainsString(
            "warmUp('weline_theme_storefront_chrome')",
            $source,
        );
        self::assertStringNotContainsString('StorefrontChromeLocaleCacheWarmer', $source);
        // Multi-warmer pools must print Registry details (not one aggregate count).
        self::assertStringContainsString('function reportWarmUpDetails', $source);
        self::assertStringContainsString("\$result['details']", $source);
        self::assertStringContainsString('warmed_l2_hit', $source);
        self::assertStringContainsString('缓存预热 %{1} warmed=%{2}', $source);
        // Partial Clear warm must not be printed as unqualified success.
        self::assertStringContainsString('warm_partial=1', $source);
        self::assertStringContainsString('host=unresolved', $source);
        self::assertStringContainsString('gate_miss:', $source);
        self::assertStringContainsString('->warning($line)', $source);
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
