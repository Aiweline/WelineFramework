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
        self::assertStringContainsString("path-global", $content);
        self::assertStringContainsString('lock-root-global', $content);
        self::assertStringContainsString('{website}', $content);
        self::assertStringContainsString('wscMediaPath', $content);
        self::assertStringContainsString("'identity_root' => 'config'", $content);
        self::assertStringContainsString("'identity_code' => \$fieldKey", $content);
        self::assertStringContainsString("'identity_scope' => \$wscIdentityScope", $content);
        self::assertStringContainsString("'strong_ref' => '1'", $content);
    }
}
