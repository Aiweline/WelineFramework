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
    // 预热已从 cache:clear 移除：清缓存只做失效，预热统一交给 cron 定时任务。
    self::assertStringNotContainsString('StorefrontFpcWarmer', $source);
    self::assertStringNotContainsString('StorefrontWarmupOrchestrator', $source);
    self::assertStringNotContainsString('runAfterClear', $source);
    self::assertStringNotContainsString('warmStorefrontFpcAfterClear', $source);
    self::assertStringNotContainsString('warmUp(', $source);
    self::assertStringNotContainsString('cache:warm --pool=', $source);
    self::assertStringContainsString('预热由 cron 定时任务按构建世代执行', $source);
    self::assertTrue(
        \is_file('/Users/weline/Project/Official/框架/app/code/Weline/Framework/Cron/StorefrontWarmup.php'),
        '预热必须由 cron 定时任务承接'
    );
    self::assertMatchesRegularExpression('/return \$fpcWarmOk \? 0 : 2;/', $source);
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
        public function reloadAsync(?string $instanceName = null, string $reloadType = 'code', float $timeout = 5.0): array
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
