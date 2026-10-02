<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Router;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Router\FpcDiag;

/**
 * FPC 决策诊断的启用契约。
 *
 * 未启用时必须完全静默（热路径零开销）；启用后每条事件一行 NDJSON，
 * 便于在真实请求里取到「为什么没缓存」，而不必靠猜响应头。
 */
final class FpcDiagTest extends TestCase
{
    private string $logPath = '';

    protected function setUp(): void
    {
        $this->logPath = \sys_get_temp_dir() . '/fpc-diag-test-' . \getmypid() . '.log';
        @\unlink($this->logPath);
        $_GET = [];
        $_COOKIE = [];
        FpcDiag::useLogPath($this->logPath);
        FpcDiag::reset();
    }

    protected function tearDown(): void
    {
        @\unlink($this->logPath);
        $_GET = [];
        $_COOKIE = [];
        FpcDiag::useLogPath(null);
        FpcDiag::reset();
    }

    public function testIsSilentUntilArmed(): void
    {
        FpcDiag::reset();
        FpcDiag::event('can_publish', ['allowed' => false]);

        self::assertFalse(FpcDiag::isArmed(), '默认不得启用');
        self::assertFileDoesNotExist($this->logPath, '未启用时不得产生任何日志');
    }

    public function testArmedByQueryFlagAndWritesNdjsonLine(): void
    {
        $_GET['__fpcdiag'] = '1';
        FpcDiag::armFromRequest('/category/hanfu?__fpcdiag=1');

        self::assertTrue(FpcDiag::isArmed());

        FpcDiag::event('storefront_context_fence', ['failure_code' => 'storefront_scope_incomplete']);
        FpcDiag::event('can_publish', ['allowed' => false, 'gate' => 'response_not_shareable']);

        self::assertFileExists($this->logPath);
        $lines = \array_values(\array_filter(\explode("\n", (string)\file_get_contents($this->logPath))));
        self::assertCount(2, $lines);

        $first = \json_decode($lines[0], true);
        self::assertIsArray($first);
        self::assertSame('storefront_context_fence', $first['event']);
        self::assertSame('storefront_scope_incomplete', $first['data']['failure_code']);
        self::assertSame(1, $first['seq']);

        $second = \json_decode($lines[1], true);
        self::assertSame(2, $second['seq'], '序号必须递增，便于按请求内顺序阅读');
        self::assertSame('response_not_shareable', $second['data']['gate']);
    }

    public function testQueryFlagWithZeroKeepsItDisarmed(): void
    {
        FpcDiag::armFromRequest('/category/hanfu?__fpcdiag=0');

        self::assertFalse(FpcDiag::isArmed(), '__fpcdiag=0 必须保持关闭');
    }
}
