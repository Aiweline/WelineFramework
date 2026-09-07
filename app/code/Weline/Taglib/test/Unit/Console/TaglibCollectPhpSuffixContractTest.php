<?php

declare(strict_types=1);

namespace Weline\Taglib\Test\Unit\Console;

use PHPUnit\Framework\TestCase;

final class TaglibCollectPhpSuffixContractTest extends TestCase
{
    public function testCollectStripsPhpSuffixWithoutRtrimCharacterMask(): void
    {
        $path = dirname(__DIR__, 3) . '/Console/Taglib/Collect.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);

        self::assertStringNotContainsString("rtrim(\$tag, '.php')", $src);
        self::assertStringContainsString("str_ends_with(\$tag, '.php')", $src);
        self::assertStringContainsString('substr($tag, 0, -4)', $src);
    }
}
