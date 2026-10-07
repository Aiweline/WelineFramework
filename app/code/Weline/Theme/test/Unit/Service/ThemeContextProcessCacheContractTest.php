<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class ThemeContextProcessCacheContractTest extends TestCase
{
    public function testThemeContextServiceOwnsRuntimeProcessBag(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/ThemeContextService.php');
        self::assertStringContainsString('$processThemeRows', $src);
        self::assertStringContainsString('function clearProcessCache', $src);
        self::assertStringContainsString("str_starts_with(\$key, 'editor|')", $src);
        self::assertStringContainsString("str_starts_with(\$key, 'token|')", $src);
    }

    public function testProcessCacheResetterClearsThemeContext(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Api/Runtime/ProcessCacheResetter.php');
        self::assertStringContainsString('ThemeContextService::clearProcessCache()', $src);
    }
}
