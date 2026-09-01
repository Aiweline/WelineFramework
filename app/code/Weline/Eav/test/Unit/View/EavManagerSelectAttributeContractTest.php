<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class EavManagerSelectAttributeContractTest extends TestCase
{
    public function testSelectAttributeModeSupportsMultiPick(): void
    {
        $script = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/eav-manager-native.js',
        );

        self::assertStringContainsString('selectionPicks', $script);
        self::assertStringContainsString('data-w-eav-pick', $script);
        self::assertStringContainsString('toggleAttributePick', $script);
        self::assertStringContainsString('weline:eav:reload-tree', $script);
    }
}
