<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;

/**
 * wave8-8c / 8c2 / 8c3: deferred must prime header/builder HotCache bags before public first-hit.
 */
final class WlsRuntimeDeferredHotCacheBagPrimeContractTest extends TestCase
{
    public function testDeferredCriticalSealPrimesHotCacheBagsBeforeLocales(): void
    {
        $source = (string)\file_get_contents(BP . 'app/code/Weline/Framework/Runtime/WlsRuntime.php');

        self::assertStringContainsString('primeDeferredStorefrontHotCacheBags', $source);
        self::assertStringContainsString('hot_cache_bags_primed', $source);
        self::assertStringContainsString('PostResponseTaskQueue::drain', $source);
        self::assertStringContainsString(
            'StorefrontHotCacheBagWarmupProviderInterface::CAPABILITY_PREFIX',
            $source,
        );
        self::assertStringContainsString('invokeStorefrontHotCacheBagProviders', $source);
        self::assertStringContainsString('maybeCaptureHotCacheBagPrimeBeforeReset', $source);
        self::assertStringContainsString('WLS_PRIME_HOT_CACHE_BAGS', $source);
        self::assertStringContainsString('deferredHotCacheBagPrimePending', $source);
        self::assertStringNotContainsString("'X-WLS-Fpc-Bypass'", $source);
        self::assertStringContainsString('deferredHotCacheBagPrimeStage', $source);
        self::assertStringContainsString('wls.storefront_hot_cache_bag_prime.stage', $source);
        self::assertStringContainsString('shouldRunDeferredStorefrontPeerHotCacheBagHydrate', $source);
        self::assertStringContainsString('runDeferredStorefrontPeerHotCacheBagHydrate', $source);
        self::assertStringContainsString('peer_bags_hydrated', $source);
        self::assertStringContainsString("primeDeferredStorefrontHotCacheBags('peer_hydrate')", $source);
        self::assertStringContainsString('needsDeferredStorefrontWarmup', $source);
        self::assertStringContainsString(
            'Weline\\\\Product\\\\Api\\\\Runtime\\\\StorefrontHotCacheBagWarmupProvider',
            $source,
        );
        // Product must run before Theme so header chrome is MRU after catalog.full.
        $productFallbackPos = \strpos(
            $source,
            "'storefront_hot_cache_bag_warmup.Weline_Product'",
        );
        $themeFallbackPos = \strpos(
            $source,
            "'storefront_hot_cache_bag_warmup.Weline_Theme'",
            (int)$productFallbackPos + 1,
        );
        self::assertNotFalse($productFallbackPos);
        self::assertNotFalse($themeFallbackPos);
        self::assertTrue(
            $productFallbackPos < $themeFallbackPos,
            'Product bag provider must be ordered before Theme (header MRU)',
        );

        $methodStart = (int)\strpos($source, 'function runDeferredStorefrontCriticalWarmup');
        self::assertGreaterThan(0, $methodStart);

        $prePrimePos = \strpos($source, "primeDeferredStorefrontHotCacheBags('pre_critical')", $methodStart);
        self::assertNotFalse($prePrimePos);
        $criticalRunPos = \strpos($source, 'runStorefrontFpcWarmupInternal($criticalList', $methodStart);
        self::assertNotFalse($criticalRunPos);
        self::assertTrue(
            $prePrimePos < $criticalRunPos,
            'pre_critical bag prime must run before critical FPC seal (public race window)',
        );

        $sealPos = \strpos($source, "logDeferredStorefrontWarmupStage('critical_sealed'", $methodStart);
        self::assertNotFalse($sealPos);
        $primeCriticalPos = \strpos($source, "primeDeferredStorefrontHotCacheBags('critical')", $methodStart);
        self::assertNotFalse($primeCriticalPos);
        self::assertTrue(
            $criticalRunPos < $primeCriticalPos && $primeCriticalPos < $sealPos,
            'critical bag retouch must run after FPC seal and before critical_sealed log',
        );

        $heavyPos = \strpos($source, "primeDeferredStorefrontHotCacheBags('post_critical_heavy')", (int)$sealPos);
        self::assertNotFalse($heavyPos);
        self::assertTrue(
            $heavyPos > $sealPos,
            'heavy catalog seed must run after critical_sealed (out of first-request window)',
        );
        self::assertStringContainsString('awaitDeferredStorefrontIdleGate', $source);
        self::assertStringContainsString("awaitDeferredStorefrontIdleGate('post_critical_heavy')", $source);
        // P7 B′: locale idle-gate retained for explicit budget>0 path only.
        self::assertStringContainsString("awaitDeferredStorefrontIdleGate('locale_idle')", $source);
        self::assertStringContainsString('wls.worker.storefront_locale_idle_budget', $source);
        self::assertStringContainsString('locale_idle_skipped', $source);
        self::assertStringNotContainsString('$localeIdleBudget = $localeBudget', $source);
        self::assertStringContainsString("'idle_gate'", $source);
        self::assertStringContainsString('fiberHotCacheBagPrimeStates', $source);
        self::assertStringContainsString('beginHotCacheBagPrimeLatch', $source);
        self::assertStringContainsString('takeHotCacheBagPrimeCapture', $source);
        // HF-ED-P0-01 / P5 O2: call site + helper must land together — undefined method
        // during WLS drain/reload means stale worker bytecode, not a permanent design hole.
        self::assertStringContainsString('isHotCacheBagPrimePendingForCurrentFiber', $source);
        self::assertStringContainsString(
            'private function isHotCacheBagPrimePendingForCurrentFiber(): bool',
            $source,
        );
        $captureBody = $this->extractMethodBody($source, 'maybeCaptureHotCacheBagPrimeBeforeReset');
        self::assertStringContainsString('isHotCacheBagPrimePendingForCurrentFiber()', $captureBody);
        self::assertStringContainsString('capture_retry', $source);
        self::assertStringContainsString('db_span_count', $source);
        self::assertStringContainsString('wls_span_count', $source);

        $idleHeavyPos = \strpos($source, "awaitDeferredStorefrontIdleGate('post_critical_heavy')", (int)$sealPos);
        self::assertNotFalse($idleHeavyPos);
        self::assertTrue(
            $idleHeavyPos < $heavyPos,
            'idle-gate must run after critical_sealed and before post_critical_heavy',
        );

        $localeRunPos = \strpos($source, 'runStorefrontFpcWarmupInternal($localeList', (int)$heavyPos);
        self::assertNotFalse($localeRunPos);
        self::assertStringContainsString('runStorefrontFpcWarmupInternal($localeList, $hosts, true)', $source);
        $skippedPos = \strpos($source, "logDeferredStorefrontWarmupStage('locale_idle_skipped'", (int)$heavyPos);
        self::assertNotFalse($skippedPos);
        self::assertTrue(
            $skippedPos < $localeRunPos,
            'locale_idle_skipped (default budget=0) must precede locale FPC call site'
        );
        // P8 O1 / UC-post-locale: post_locale full prime only when locale SSR ran.
        self::assertStringContainsString('$localeSsrRan', $source);
        self::assertStringContainsString("logDeferredStorefrontWarmupStage('post_locale_skipped'", $source);
        self::assertStringContainsString("'locale_ssr_not_run'", $source);
        self::assertStringContainsString("'stage' => 'post_locale_skipped'", $source);
        $postLocaleSkippedPos = \strpos(
            $source,
            "logDeferredStorefrontWarmupStage('post_locale_skipped'",
            (int)$localeRunPos
        );
        self::assertNotFalse($postLocaleSkippedPos);
        $primeFinalPos = \strpos($source, "primeDeferredStorefrontHotCacheBags('post_locale')", (int)$localeRunPos);
        self::assertNotFalse($primeFinalPos);
        self::assertTrue($primeFinalPos > $localeRunPos);
        // Gated branch: localeSsrRan must appear before the post_locale prime call.
        $localeSsrRanPos = \strpos($source, '$localeSsrRan = $localeList !== []', (int)$heavyPos);
        self::assertNotFalse($localeSsrRanPos);
        self::assertTrue(
            $localeSsrRanPos < $primeFinalPos,
            'localeSsrRan gate must precede post_locale bag prime'
        );
        self::assertTrue(
            $postLocaleSkippedPos > $localeRunPos,
            'post_locale_skipped stage must follow locale FPC call site (skip/no-op path)'
        );
    }

    public function testBagWarmupInterfaceDeclaresCapabilityPrefix(): void
    {
        $path = BP . 'app/code/Weline/Framework/Runtime/StorefrontHotCacheBagWarmupProviderInterface.php';
        self::assertFileExists($path);
        $source = (string)\file_get_contents($path);
        self::assertStringContainsString(
            "CAPABILITY_PREFIX = 'storefront_hot_cache_bag_warmup.'",
            $source,
        );
        self::assertStringContainsString('primeCriticalBags', $source);
    }

    public function testBagPrimeFallsBackWhenCompiledRegistryLags(): void
    {
        $source = (string)\file_get_contents(BP . 'app/code/Weline/Framework/Runtime/WlsRuntime.php');
        $body = $this->extractMethodBody($source, 'invokeStorefrontHotCacheBagProviders');
        self::assertStringContainsString('class_exists($implementation)', $body);
        self::assertStringContainsString('no_bag_warmup_providers', $body);
        self::assertStringContainsString(
            'Weline\\\\Theme\\\\Api\\\\Runtime\\\\StorefrontHotCacheBagWarmupProvider',
            $body,
        );
    }

    private function extractMethodBody(string $source, string $method): string
    {
        $start = \strpos($source, 'function ' . $method . '(');
        self::assertNotFalse($start, $method . ' missing');
        $brace = \strpos($source, '{', $start);
        self::assertNotFalse($brace);
        $depth = 0;
        $len = \strlen($source);
        for ($i = $brace; $i < $len; $i++) {
            $ch = $source[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return \substr($source, $brace, $i - $brace + 1);
                }
            }
        }
        self::fail('unclosed method ' . $method);

        return '';
    }
}
