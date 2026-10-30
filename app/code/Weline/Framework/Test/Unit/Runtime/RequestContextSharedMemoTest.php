<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;

/**
 * Request-shared memo: one store per logical request, kept across Fibers of that
 * request (Context copies), dropped by cleanup(), invalidated by generation.
 */
final class RequestContextSharedMemoTest extends TestCase
{
    protected function setUp(): void
    {
        Runtime::setMode('wls');
        if (Context::hasCurrent()) {
            Context::leave();
        }
        RequestContext::cleanup();
        Context::enter(new Context([
            'runtime' => ['request_context' => ['initialized' => true]],
        ]));
        RequestContext::setId('shared-memo-test');
    }

    protected function tearDown(): void
    {
        RequestContext::overrideSharedMemoEnabled(null);
        RequestContext::cleanup();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        Runtime::resetModeCache();
    }

    public function testSharedComputesOncePerRequest(): void
    {
        $calls = 0;
        $a = RequestContext::shared('framework.shared.demo.v1', static function () use (&$calls): string {
            ++$calls;

            return 'once';
        });
        $b = RequestContext::shared('framework.shared.demo.v1', static function () use (&$calls): string {
            ++$calls;

            return 'twice';
        });

        self::assertSame('once', $a);
        self::assertSame('once', $b);
        self::assertSame(1, $calls);
        self::assertTrue(RequestContext::sharedHas('framework.shared.demo.v1'));
    }

    public function testSharedMemoSurvivesFiberContextCopyOfSameRequest(): void
    {
        RequestContext::sharedSet('framework.shared.fiber.v1', 'parent');

        $seen = [];
        $fiber = new \Fiber(static function () use (&$seen): void {
            // A child Fiber of the same request reads through a copied Context.
            $seen[] = RequestContext::sharedGet('framework.shared.fiber.v1');
            RequestContext::sharedSet('framework.shared.fiber.v1', 'child');
        });
        $fiber->start();

        self::assertSame(['parent'], $seen);
        // The child write is visible to the rest of the same request.
        self::assertSame('child', RequestContext::sharedGet('framework.shared.fiber.v1'));
    }

    public function testGenerationBumpInvalidatesEarlierKeys(): void
    {
        $before = 'framework.shared.gen.v1.g' . RequestContext::sharedGeneration('framework.shared.gen');
        RequestContext::sharedSet($before, 'stale');

        RequestContext::sharedBumpGeneration('framework.shared.gen');

        $after = 'framework.shared.gen.v1.g' . RequestContext::sharedGeneration('framework.shared.gen');
        self::assertNotSame($before, $after);
        self::assertFalse(RequestContext::sharedHas($after));
        self::assertSame('stale', RequestContext::sharedGet($before));
    }

    public function testCleanupDropsSharedMemoForNextRequest(): void
    {
        RequestContext::sharedSet('framework.shared.cleanup.v1', 'first-request');
        RequestContext::cleanup();

        Context::enter(new Context([
            'runtime' => ['request_context' => ['initialized' => true]],
        ]));
        RequestContext::setId('shared-memo-test-next');

        self::assertFalse(RequestContext::sharedHas('framework.shared.cleanup.v1'));
        self::assertNull(RequestContext::sharedGet('framework.shared.cleanup.v1'));
    }

    public function testSharedMemoRequiresRequestId(): void
    {
        RequestContext::cleanup();
        Context::leave();
        Context::enter(new Context());

        // No request id: shared memo must stay inert (CLI / bootstrap safety).
        RequestContext::sharedSet('framework.shared.no-request.v1', 'value');
        self::assertFalse(RequestContext::sharedHas('framework.shared.no-request.v1'));
        self::assertSame(0, RequestContext::sharedBumpGeneration('framework.shared.no-request'));
    }

    public function testSingleOversizedValueIsNotMemoised(): void
    {
        // C1: one oversized value (e.g. a large config JSON) must never be pinned
        // in request memory.
        RequestContext::sharedSet('framework.shared.oversize.v1', \str_repeat('x', 300 * 1024));

        self::assertFalse(RequestContext::sharedHas('framework.shared.oversize.v1'));
        $stats = RequestContext::sharedMemoStats();
        self::assertSame(1, $stats['single_value_too_large'] ?? 0);
    }

    public function testTotalByteBudgetDropsWholeScope(): void
    {
        // C1: per-request byte budget (8 MiB) — overflow drops the scope, which is
        // always semantically safe (only costs one extra read).
        $chunk = \str_repeat('y', 240 * 1024);
        for ($i = 0; $i < 40; $i++) {
            RequestContext::sharedSet('framework.shared.bytes.v1.' . $i, $chunk);
        }

        $stats = RequestContext::sharedMemoStats();
        self::assertGreaterThanOrEqual(1, $stats['overflow_drop'] ?? 0, 'byte budget must drop the scope');
        // Post-drop sets keep filling a fresh scope; the budget must stay bounded.
        self::assertLessThan(40, (int)($stats['entries'] ?? 0));
        self::assertLessThanOrEqual(8 * 1024 * 1024, (int)($stats['bytes'] ?? 0));
    }

    public function testStatsCountHitsMissesAndSets(): void
    {
        // C3: observability — "SQL got smaller" must be attributable.
        self::assertFalse(RequestContext::sharedHas('framework.shared.stats.v1'));
        RequestContext::sharedSet('framework.shared.stats.v1', 'v');
        self::assertTrue(RequestContext::sharedHas('framework.shared.stats.v1'));

        $stats = RequestContext::sharedMemoStats();
        self::assertGreaterThanOrEqual(1, $stats['misses'] ?? 0);
        self::assertGreaterThanOrEqual(1, $stats['hits'] ?? 0);
        self::assertGreaterThanOrEqual(1, $stats['sets'] ?? 0);
        self::assertGreaterThan(0, (int)($stats['entries'] ?? 0));
        self::assertGreaterThan(0, (int)($stats['bytes'] ?? 0));
    }

    public function testKillSwitchMakesMemoInert(): void
    {
        // C2: `wls.request_memo.enabled=false` equivalent — store goes inert without
        // any code rollback; callers fall back to their normal DB read.
        RequestContext::overrideSharedMemoEnabled(false);
        try {
            RequestContext::sharedSet('framework.shared.kill.v1', 'v');
            self::assertFalse(RequestContext::sharedHas('framework.shared.kill.v1'));
            self::assertNull(RequestContext::sharedGet('framework.shared.kill.v1'));
            self::assertSame(0, RequestContext::sharedBumpGeneration('framework.shared.kill'));
        } finally {
            RequestContext::overrideSharedMemoEnabled(null);
        }

        RequestContext::sharedSet('framework.shared.kill.v1', 'v');
        self::assertTrue(RequestContext::sharedHas('framework.shared.kill.v1'));
    }

    public function testFileKillSwitchIsLiveWithoutReload(): void
    {
        // C2 (real live switch): `generated/framework/request_memo.disabled` makes
        // long-running workers stop using the memo within ~1s, no reload/compile.
        $dir = BP . 'generated/framework';
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0775, true);
        }
        $marker = $dir . '/request_memo.disabled';
        try {
            \file_put_contents($marker, '1');
            RequestContext::overrideSharedMemoEnabled(null); // drop the 1s flag cache
            RequestContext::sharedSet('framework.shared.filekill.v1', 'v');
            self::assertFalse(RequestContext::sharedHas('framework.shared.filekill.v1'));
            self::assertNull(RequestContext::sharedGet('framework.shared.filekill.v1'));
        } finally {
            @\unlink($marker);
            RequestContext::overrideSharedMemoEnabled(null);
        }

        RequestContext::sharedSet('framework.shared.filekill.v1', 'v');
        self::assertTrue(RequestContext::sharedHas('framework.shared.filekill.v1'));
    }
}
