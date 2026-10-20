<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupHttpClient;
use Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupOrchestrator;
use Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupProgressLedger;

/**
 * Locale homepage roots must warm before the sitemap loc cursor.
 */
final class StorefrontWarmupPriorityHomesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!\defined('BP')) {
            \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);
        }
        if (!\defined('DS')) {
            \define('DS', \DIRECTORY_SEPARATOR);
        }
    }

    public function testLedgerNormalizesPriorityHomeQueue(): void
    {
        $ledger = new StorefrontWarmupProgressLedger();
        $normalize = new \ReflectionMethod($ledger, 'normalize');
        $normalize->setAccessible(true);

        $state = $normalize->invoke($ledger, [
            'phase' => StorefrontWarmupProgressLedger::PHASE_D_LOCS,
            'priority_homes' => ['/en_GB/', '/fr_FR/', 42, ''],
            'priority_home_cursor' => 1,
        ]);

        self::assertSame(['/en_GB/', '/fr_FR/', '42'], $state['priority_homes']);
        self::assertSame(1, $state['priority_home_cursor']);
        self::assertSame(StorefrontWarmupProgressLedger::PHASE_D_LOCS, $state['phase']);
    }

    public function testOrchestratorDrainsPriorityHomesBeforePhaseBatch(): void
    {
        $orchestrator = (string)\file_get_contents(
            (new \ReflectionClass(StorefrontWarmupOrchestrator::class))->getFileName() ?: ''
        );
        self::assertStringContainsString('priority_home_paths', $orchestrator);
        self::assertStringContainsString('PRIORITY_HOME_BUDGET_PER_SLICE', $orchestrator);
        self::assertStringContainsString('advancePriorityHomes', $orchestrator);
        self::assertStringContainsString('hasPendingPriorityHomes', $orchestrator);
        self::assertStringContainsString('priorityAttemptedThisSlice', $orchestrator);
        self::assertStringContainsString('getVisitor', $orchestrator);

        // Priority block must precede the phase batch dispatch in the slice loop.
        $priorityPos = \strpos($orchestrator, 'hasPendingPriorityHomes($state)');
        $batchPos = \strpos($orchestrator, '$batch = $this->advanceBatch($state, $batchN)');
        self::assertNotFalse($priorityPos);
        self::assertNotFalse($batchPos);
        self::assertLessThan($batchPos, $priorityPos);
    }

    public function testFallbackPrefersEnglishThenFirstNonDefault(): void
    {
        $orchestrator = new StorefrontWarmupOrchestrator();
        $select = new \ReflectionMethod($orchestrator, 'selectFallbackPriorityHome');
        $select->setAccessible(true);

        self::assertSame(
            ['/en_GB/'],
            $select->invoke($orchestrator, ['bg_BG', 'en_GB', 'en_US'], 'zh_Hans_CN')
        );
        self::assertSame(
            ['/en_US/'],
            $select->invoke($orchestrator, ['fr_FR', 'en_US'], 'fr_FR')
        );
        self::assertSame(
            ['/fr_FR/'],
            $select->invoke($orchestrator, ['fr_FR', 'de_DE'], 'zh_Hans_CN')
        );
        self::assertSame(
            [],
            $select->invoke($orchestrator, ['zh_Hans_CN'], 'zh_Hans_CN')
        );
    }

    public function testVisitorWarmDoesNotPierceOrBypass(): void
    {
        $client = (string)\file_get_contents(
            (new \ReflectionClass(StorefrontWarmupHttpClient::class))->getFileName() ?: ''
        );
        self::assertStringContainsString('public function getVisitor', $client);
        self::assertStringContainsString("'pierce' => false", $client);
        self::assertStringContainsString("'bypass' => false", $client);
    }
}
