<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime\Async;

use PHPUnit\Framework\TestCase;
use Weline\Server\Runtime\Async\AsyncBizAdapters;

final class AsyncBizAdaptersTest extends TestCase
{
    public function testDispatchReturnsCallbackResult(): void
    {
        $adapter = new AsyncBizAdapters();
        $result = $adapter->dispatch(static fn(): string => 'ok');
        self::assertSame('ok', $result);
    }

    public function testFileGetContentsWithYieldReadsTempFile(): void
    {
        $path = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'wls_async_biz_' . \bin2hex(\random_bytes(6)) . '.txt';
        self::assertNotFalse(\file_put_contents($path, 'payload'));
        try {
            $data = AsyncBizAdapters::fileGetContentsWithYield($path);
            self::assertSame('payload', $data);
        } finally {
            @\unlink($path);
        }
    }

    public function testFileGetContentsWithYieldReturnsFalseForMissingFile(): void
    {
        $path = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'wls_async_biz_missing_' . \bin2hex(\random_bytes(8));
        self::assertFalse(AsyncBizAdapters::fileGetContentsWithYield($path));
    }
}

