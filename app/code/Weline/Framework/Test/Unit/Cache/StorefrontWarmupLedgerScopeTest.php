<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupProgressLedger;

/** 每站点独立进度账本；default 复用旧文件保证续跑不丢进度。 */
final class StorefrontWarmupLedgerScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!\defined('BP')) {
            \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);
        }
    }

    public function testDefaultScopeReusesLegacyPath(): void
    {
        $default = new StorefrontWarmupProgressLedger();
        $explicit = new StorefrontWarmupProgressLedger('default');

        self::assertSame('default', $default->scope());
        self::assertSame($default->path(), $explicit->path());
        self::assertStringEndsWith('var/cache/storefront_warmup_progress.json', $default->path());
    }

    public function testNamedScopeGetsItsOwnPath(): void
    {
        $ledger = new StorefrontWarmupProgressLedger('grocery');

        self::assertSame('grocery', $ledger->scope());
        self::assertStringEndsWith('var/cache/storefront_warmup_progress.grocery.json', $ledger->path());
        self::assertNotSame((new StorefrontWarmupProgressLedger())->path(), $ledger->path());
    }

    public function testScopeKeySanitizesToSafeFilename(): void
    {
        $ledger = new StorefrontWarmupProgressLedger('../evil/../x?y');

        self::assertStringEndsWith('var/cache/storefront_warmup_progress.evil.x.y.json', $ledger->path());
        self::assertSame('evil.x.y', $ledger->scope());
    }

    public function testTwoScopesDoNotShareState(): void
    {
        $a = new StorefrontWarmupProgressLedger('scope_a_contract');
        $b = new StorefrontWarmupProgressLedger('scope_b_contract');
        $pa = $a->path();
        $pb = $b->path();
        @\unlink($pa);
        @\unlink($pb);
        try {
            $sa = $a->read();
            $sa['ok'] = 111;
            $sa['phase'] = StorefrontWarmupProgressLedger::PHASE_D_LOCS;
            $a->write($sa);

            self::assertSame(111, $a->read()['ok']);
            self::assertSame(0, $b->read()['ok']);
            self::assertSame(StorefrontWarmupProgressLedger::PHASE_A_HOMEPAGE, $b->read()['phase']);
        } finally {
            @\unlink($pa);
            @\unlink($pb);
        }
    }
}
