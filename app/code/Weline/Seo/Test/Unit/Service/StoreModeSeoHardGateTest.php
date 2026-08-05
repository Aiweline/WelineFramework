<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Seo\Service\StoreModeSeoHardGate;

/**
 * TEST-P1D-03：dev/test 强制 noindex/nositemap；normal 不硬拦。
 */
final class StoreModeSeoHardGateTest extends TestCase
{
    private StoreModeSeoHardGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new StoreModeSeoHardGate();
    }

    public function testDevAndTestForceNoIndex(): void
    {
        self::assertTrue($this->gate->isHardNoIndexMode(ScopeIdentity::MODE_DEV));
        self::assertTrue($this->gate->isHardNoIndexMode(ScopeIdentity::MODE_TEST));
        self::assertFalse($this->gate->isHardNoIndexMode(ScopeIdentity::MODE_NORMAL));
    }

    public function testHardGateOverridesExplicitIndexRobots(): void
    {
        $ctx = $this->gate->applyToPageContext(['robots' => 'index,follow'], ScopeIdentity::MODE_TEST);
        self::assertSame(StoreModeSeoHardGate::FORCED_ROBOTS_META, $ctx['robots']);
        self::assertTrue($ctx['_robots_hard_gate']);
    }

    public function testNormalModeKeepsConfiguredRobots(): void
    {
        $ctx = $this->gate->applyToPageContext(['robots' => 'index,follow'], ScopeIdentity::MODE_NORMAL);
        self::assertSame('index,follow', $ctx['robots']);
        self::assertArrayNotHasKey('_robots_hard_gate', $ctx);
    }

    public function testForcedRobotsTxtDisallowsAllAndOmitsSitemap(): void
    {
        $txt = $this->gate->forceRobotsTxt();
        self::assertStringContainsString('Disallow: /', $txt);
        self::assertStringNotContainsString('Sitemap:', $txt);
    }

    public function testForcedSitemapIsEmptyUrlset(): void
    {
        $result = $this->gate->forceEmptySitemap();
        self::assertSame(200, $result['status']);
        self::assertStringContainsString('<urlset', $result['body']);
        self::assertStringNotContainsString('<url>', $result['body']);
        self::assertStringContainsString('nositemap', $result['body']);
    }
}
