<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;

final class EavManagerTaglibContractTest extends TestCase
{
    public function testEavManagerTaglibExposesEntityCodeAttribute(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Taglib/EavManager.php',
        );

        self::assertStringContainsString("return 'eav:manager';", $source);
        self::assertStringContainsString("'entity-code'", $source);
        self::assertStringContainsString('templates/Backend/Manager/surface.phtml', $source);
        self::assertStringContainsString('resolveEntityScope', $source);
        self::assertStringContainsString('tag_self_close_with_attrs', $source);
        self::assertStringContainsString('function document()', $source);
    }
}
