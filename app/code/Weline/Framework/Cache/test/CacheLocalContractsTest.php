<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Console\Cache\Clear;
use Weline\Framework\Cache\Scanner;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Runtime\RuntimeControlBroadcasterInterface;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllCacheLocalContractsPass().
 */
final class CacheLocalContractsTest extends TestCase
{
    public function testAllCacheLocalContractsPass(): void
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
            'CacheClearCommand::testNonForceSkipsPermanentPools' => function () {
            $this->assert_CacheClearCommand_testNonForceSkipsPermanentPools();
        },
            'CacheClearCommand::testDoesNotSoftPullThemeStorefrontFpcWarmerFqcn' => function () {
            $this->assert_CacheClearCommand_testDoesNotSoftPullThemeStorefrontFpcWarmerFqcn();
        },
            'CacheClearCommand::testForceClearsPermanentPools' => function () {
            $this->assert_CacheClearCommand_testForceClearsPermanentPools();
        },
        ];
    }

private function assert_CacheClearCommand_testNonForceSkipsPermanentPools(): void
{
    $cleared = [];
    $scanner = $this->createMock(Scanner::class);
    $scanner->method('getCaches')->willReturn(['pools' => [['identity' => 'view', 'permanent' => false, 'tip' => '视图缓存', 'stats' => []], ['identity' => 'router', 'permanent' => true, 'tip' => '路由缓存', 'stats' => []]], 'total' => 2]);
    $scanner->method('clearPool')->willReturnCallback(function (string $identity) use (&$cleared): bool {
        $cleared[] = $identity;
        return true;
    });
    $command = new Clear($scanner, $this->printingStub_CacheClearCommand(), $this->broadcasterStub_CacheClearCommand());
    $command->execute(['cache:clear']);
    $this->assertSame(['view'], $cleared);
}

private function assert_CacheClearCommand_testDoesNotSoftPullThemeStorefrontFpcWarmerFqcn(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework/Cache' . '/Console/Cache/Clear.php');
    self::assertStringNotContainsString('StorefrontFpcWarmer', $source);
    self::assertStringContainsString('StorefrontWarmupOrchestrator', $source);
    self::assertStringContainsString('runAfterClear', $source);
    self::assertStringNotContainsString('warmUp(\'fpc\')', $source);
    self::assertStringContainsString('warmUp(\'website\')', $source);
    self::assertStringContainsString("warmUp('weline_theme_layout_entity_published_projection')", $source);
    self::assertStringContainsString("warmUp('product')", $source);
    self::assertStringContainsString("warmUp('search')", $source);
    self::assertStringNotContainsString('SearchProjectionScopeCacheWarmer', $source);
    self::assertStringNotContainsString('HomepageShelfOffersLocaleCacheWarmer', $source);
    self::assertStringNotContainsString('SearchQueryResultTipCacheWarmer', $source);
    self::assertStringNotContainsString('PlpFilterOffersLightCacheWarmer', $source);
    // Chrome reseeds via fresh subprocess (pool arg) — not in-process warmUp FQCN.
    self::assertTrue(\str_contains($source, "warmUp('weline_theme_storefront_chrome')") || \str_contains($source, 'cache:warm --pool=weline_theme_storefront_chrome'), 'Clear must reseed chrome pool via warmUp or cache:warm subprocess');
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
    // W6 / P-orch-warm-honesty: machine markers + fail-closed exit when fpc:warmed=0.
    // Ready/maintenance ready ≠ FPC HIT; chrome gate_ok must not fake mix-ready.
    self::assertStringContainsString('fpc_warm_ok=', $source);
    self::assertStringContainsString('storefront_mix_ready=', $source);
    self::assertStringContainsString('禁止假绿', $source);
    self::assertStringContainsString('chrome/袋 gate_ok 不可冒充整页 FPC', $source);
    self::assertMatchesRegularExpression('/return \$fpcWarmOk \? 0 : 2;/', $source);
    self::assertStringContainsString('no Theme FQCN soft-pull', $source);
}

private function assert_CacheClearCommand_testForceClearsPermanentPools(): void
{
    $cleared = [];
    $scanner = $this->createMock(Scanner::class);
    $scanner->method('getCaches')->willReturn(['pools' => [['identity' => 'view', 'permanent' => false, 'tip' => '', 'stats' => []], ['identity' => 'router', 'permanent' => true, 'tip' => '', 'stats' => []]], 'total' => 2]);
    $scanner->method('clearPool')->willReturnCallback(function (string $identity) use (&$cleared): bool {
        $cleared[] = $identity;
        return true;
    });
    $command = new Clear($scanner, $this->printingStub_CacheClearCommand(), $this->broadcasterStub_CacheClearCommand());
    $command->execute(['cache:clear', '-f']);
    $this->assertSame(['view', 'router'], $cleared);
}

private function printingStub_CacheClearCommand(): Printing
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

private function broadcasterStub_CacheClearCommand(): RuntimeControlBroadcasterInterface
{
    return new class implements RuntimeControlBroadcasterInterface
    {
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
