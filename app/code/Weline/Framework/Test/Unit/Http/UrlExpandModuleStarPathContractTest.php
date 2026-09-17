<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;

final class UrlExpandModuleStarPathContractTest extends TestCase
{
    public function testBackendUrlExpandsStarViaHelper(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Http/Url.php',
        );
        self::assertStringContainsString('function expandModuleStarPath', $source);
        self::assertStringContainsString('expandModuleStarPath($path)', $source);
        self::assertStringContainsString('env.php', $source);
        self::assertStringContainsString('缺 frontName', $source);
    }
}
