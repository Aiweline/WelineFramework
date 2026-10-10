<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ProcessCacheResetContext;

final class ProcessCacheResetContextSurfaceContractTest extends TestCase
{
    public function testTwoArgCacheClearDerivesAllHard(): void
    {
        $ctx = new ProcessCacheResetContext(ProcessCacheResetContext::REASON_CACHE_CLEAR, true);
        self::assertTrue($ctx->isExplicitCacheClear());
        self::assertTrue($ctx->allowsRegistryClear());
        self::assertFalse($ctx->isSoftContent());
        self::assertSame(ProcessCacheResetContext::SURFACE_ALL, $ctx->surface);
        self::assertSame(ProcessCacheResetContext::TIER_HARD, $ctx->tier);
    }

    public function testTwoArgSoftPressureDerivesContentSoft(): void
    {
        $ctx = new ProcessCacheResetContext(ProcessCacheResetContext::REASON_MEMORY_PRESSURE, false);
        self::assertTrue($ctx->isSoftContent());
        self::assertFalse($ctx->allowsRegistryClear());
    }

    public function testTwoArgAggressiveDerivesAllHard(): void
    {
        $ctx = new ProcessCacheResetContext(ProcessCacheResetContext::REASON_MEMORY_PRESSURE, true);
        self::assertTrue($ctx->allowsRegistryClear());
        self::assertFalse($ctx->isSoftContent());
    }
}
