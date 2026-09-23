<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;

/**
 * Deferred adopt must mirror proof into WorkerReadinessState so status_report
 * can refresh Master homepage_fpc (server:status must not stay on fail-open).
 * B5: adopt before L1 eviction; prefer sample full_uri; / then /products order.
 * wave7-7c: force `/products`, critical_sealed before locales, retouch L1.
 */
final class WlsRuntimeAdoptHomepageFpcMetaContractTest extends TestCase
{
    public function testAdoptCallsWorkerReadinessMarkBusinessHomepageHot(): void
    {
        $source = \file_get_contents(BP . 'app/code/Weline/Framework/Runtime/WlsRuntime.php');
        self::assertIsString($source);
        self::assertStringContainsString(
            'publishAdoptedHomepageFpcProofToWorkerReadiness',
            $source,
        );
        self::assertStringContainsString(
            'WorkerReadinessState::markBusinessHomepageHot',
            $source,
        );
        self::assertStringContainsString(
            'homepage-fpc:deferred-warmup:adopted',
            $source,
        );
    }

    public function testDeferredWarmupAdoptsHomepageImmediatelyAndPrefersCatalogSlot(): void
    {
        $source = (string)\file_get_contents(BP . 'app/code/Weline/Framework/Runtime/WlsRuntime.php');
        self::assertStringContainsString(
            "if (\$path === '/' && !(bool)(\$this->readyGateHomepageFpcProof['hit'] ?? false))",
            $source,
        );
        self::assertStringContainsString('candidate_uris', $source);
        self::assertStringContainsString("\$sample['full_uri']", $source);

        $orderPos = \strpos($source, 'Hot-path reserve: `/` + directory representative `/products`');
        self::assertNotFalse($orderPos);
        $localeBudgetPos = \strpos($source, '$localeBudget = \\max(0, $budget - \\count($criticalList))', $orderPos);
        self::assertNotFalse($localeBudgetPos);
        self::assertGreaterThan($orderPos, $localeBudgetPos);
    }

    public function testDeferredWarmupSkipsLocalesAfterConsecutiveProbeFailures(): void
    {
        $source = (string)\file_get_contents(BP . 'app/code/Weline/Framework/Runtime/WlsRuntime.php');
        self::assertStringContainsString('$localeFailSkipThreshold = 2', $source);
        self::assertStringContainsString('$skipRemainingLocales = true', $source);
        self::assertStringContainsString("\$isCriticalPath = (\$path === '/' || \$path === '/products')", $source);
    }

    public function testDeferredWarmupForcesProductsAndSealsCriticalBeforeLocales(): void
    {
        $source = (string)\file_get_contents(BP . 'app/code/Weline/Framework/Runtime/WlsRuntime.php');
        self::assertStringContainsString("\$paths['/products'] = '/products';", $source);
        self::assertStringContainsString('critical_sealed', $source);
        self::assertStringContainsString('retouchDeferredCriticalProcessL1', $source);
        // wls-perf B: near-virgin localeBudget hard-sealed to 0 (was min(..., 2)).
        self::assertStringContainsString('$localeBudget = 0;', $source);
        self::assertStringContainsString('locale_deferred_paths', $source);
        // P7 B′: default locale idle budget=0 → skipped; begin only when env budget>0.
        self::assertStringContainsString('wls.worker.storefront_locale_idle_budget', $source);
        self::assertStringContainsString('locale_idle_skipped', $source);
        self::assertStringContainsString('locale_idle_begin', $source);
        self::assertStringNotContainsString('$localeIdleBudget = $localeBudget', $source);
        self::assertStringContainsString("primeDeferredStorefrontHotCacheBags('post_critical_heavy')", $source);
        self::assertStringNotContainsString('$localeBudget = \\min($localeBudget, 2);', $source);

        $sealPos = \strpos($source, "logDeferredStorefrontWarmupStage('critical_sealed'");
        self::assertNotFalse($sealPos);
        $heavyPos = \strpos($source, "primeDeferredStorefrontHotCacheBags('post_critical_heavy')", (int)$sealPos);
        self::assertNotFalse($heavyPos);
        $idleHeavyPos = \strpos($source, "awaitDeferredStorefrontIdleGate('post_critical_heavy')", (int)$sealPos);
        self::assertNotFalse($idleHeavyPos);
        $localeSkippedPos = \strpos($source, "logDeferredStorefrontWarmupStage('locale_idle_skipped'", (int)$heavyPos);
        self::assertNotFalse($localeSkippedPos);
        $localeIdlePos = \strpos($source, "logDeferredStorefrontWarmupStage('locale_idle_begin'", (int)$heavyPos);
        self::assertNotFalse($localeIdlePos);
        $idleLocalePos = \strpos($source, "awaitDeferredStorefrontIdleGate('locale_idle')", (int)$heavyPos);
        self::assertNotFalse($idleLocalePos);
        $localeRunPos = \strpos($source, 'runStorefrontFpcWarmupInternal($localeList', (int)$heavyPos);
        self::assertNotFalse($localeRunPos);
        self::assertTrue(
            $localeRunPos > $sealPos,
            'locale warmup call site must remain after critical_sealed stage'
        );
        self::assertTrue(
            $heavyPos > $sealPos,
            'post_critical_heavy bag prime must run after critical_sealed'
        );
        self::assertTrue(
            $idleHeavyPos < $heavyPos,
            'idle-gate must precede post_critical_heavy'
        );
        self::assertTrue(
            $idleLocalePos < $localeIdlePos,
            'locale idle-gate must precede locale_idle_begin (budget>0 path)'
        );
        self::assertTrue(
            $localeSkippedPos < $localeRunPos,
            'locale_idle_skipped (default budget=0) must precede locale FPC call site'
        );
        // P8 O1 / UC-post-locale: skip path must log post_locale_skipped; full prime gated.
        self::assertStringContainsString('$localeSsrRan', $source);
        self::assertStringContainsString("logDeferredStorefrontWarmupStage('post_locale_skipped'", $source);
        self::assertStringContainsString("'locale_ssr_not_run'", $source);
        $postLocaleSkippedPos = \strpos(
            $source,
            "logDeferredStorefrontWarmupStage('post_locale_skipped'",
            (int)$localeRunPos
        );
        self::assertNotFalse($postLocaleSkippedPos);
        $primeFinalPos = \strpos(
            $source,
            "primeDeferredStorefrontHotCacheBags('post_locale')",
            (int)$localeRunPos
        );
        self::assertNotFalse($primeFinalPos);
        self::assertTrue(
            $primeFinalPos > $localeRunPos,
            'post_locale retouch call site must remain after locale FPC (gated by localeSsrRan)'
        );
    }
}
