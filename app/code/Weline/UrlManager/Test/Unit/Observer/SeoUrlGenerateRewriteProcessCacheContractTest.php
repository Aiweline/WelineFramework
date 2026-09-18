<?php

declare(strict_types=1);

namespace Weline\UrlManager\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;

final class SeoUrlGenerateRewriteProcessCacheContractTest extends TestCase
{
    public function testInvalidateCachesClearsProcessAndMentionsSharedPool(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Observer/SeoUrlGenerateRewrite.php');
        self::assertStringContainsString('private static array $processCache', $src);
        self::assertStringContainsString('function clearProcessCache', $src);
        self::assertStringContainsString('function invalidateCaches', $src);
        self::assertStringContainsString("w_cache('url_rewrite')->clear()", $src);

        $modelSrc = (string)file_get_contents(dirname(__DIR__, 3) . '/Model/UrlRewrite.php');
        self::assertStringContainsString('invalidateSeoRewriteCaches', $modelSrc);
        self::assertStringContainsString('function save_after', $modelSrc);
        self::assertStringContainsString('function delete_after', $modelSrc);
    }
}
