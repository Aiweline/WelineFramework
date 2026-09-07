<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Global（全部站点 / default.default.default）必须可编辑保存，不能整层只读。
 */
final class ConfigCenterGlobalEditableContractTest extends TestCase
{
    public function testGlobalScopeIsWritableBaselineNotReadOnly(): void
    {
        $path = \dirname(__DIR__, 3) . '/view/templates/backend/config/index.phtml';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);

        self::assertStringContainsString('$isGlobalScope = $targetKind === \'global\'', $src);
        self::assertStringNotContainsString('$scopeReadOnly = $targetKind === \'global\'', $src);
        self::assertStringNotContainsString('Global 层仅查看继承值', $src);
        self::assertStringContainsString('Global 为全站基线配置', $src);
        self::assertStringContainsString('$fieldDisabled = $isLocked || $isInherited', $src);
        self::assertStringContainsString('<?php if (!empty($template[\'fields\'])): ?>', $src);
        self::assertDoesNotMatchRegularExpression(
            '/if\s*\(\s*!empty\(\$template\[\'fields\'\]\)\s*&&\s*!\$scopeReadOnly\s*\)/',
            $src,
            'Save actions must not be gated behind Global read-only.',
        );
    }
}
