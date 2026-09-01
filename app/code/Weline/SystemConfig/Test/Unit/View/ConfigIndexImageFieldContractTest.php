<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class ConfigIndexImageFieldContractTest extends TestCase
{
    public function testConfigCenterRendersImageAndFilePickers(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/backend/config/index.phtml';
        $content = (string) file_get_contents($path);

        self::assertStringContainsString("in_array(\$fieldType, ['image', 'file'], true)", $content);
        self::assertStringContainsString('Weline\\MediaManager\\Block\\WelineMedia::class', $content);
        self::assertStringContainsString('framework_view_process_block', $content);
        self::assertStringContainsString("__('选择图片')", $content);
    }
}
