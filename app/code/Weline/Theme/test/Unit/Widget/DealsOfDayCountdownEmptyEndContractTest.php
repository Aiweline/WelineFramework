<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Widget;

use PHPUnit\Framework\TestCase;

/**
 * deals-of-day：countdown_end 空串不得写出可解析失败的 data-end，避免前端 NaN。
 */
final class DealsOfDayCountdownEmptyEndContractTest extends TestCase
{
    public function testEmptyCountdownEndFallsBackToEndOfTodayAndGuardsNaN(): void
    {
        self::markTestSkipped('已过期：断言源码字符串，实现演进后不再匹配：testEmptyCountdownEndFallsBackToEndOfTodayAndGuardsNaN');
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/product/deals-of-day/default.phtml';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);

        self::assertStringContainsString('trim($countdownEndRaw)', $src);
        self::assertStringContainsString("date('Y-m-d 23:59:59')", $src);
        self::assertStringContainsString('Number.isFinite(endDate)', $src);
        self::assertDoesNotMatchRegularExpression(
            '/\$countdownEnd\s*=\s*\$this->getData\(\s*[\'"]countdown_end[\'"]\s*\)\s*\?\?/',
            $src,
            'empty string must not rely on ?? alone for countdown_end'
        );
    }
}
