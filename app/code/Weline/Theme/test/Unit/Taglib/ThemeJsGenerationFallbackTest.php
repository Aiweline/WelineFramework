<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Taglib\ThemeJs;

/**
 * 主题更新代次根（/static/tu-*）被清理后，烘焙/运行期必须回退到规范路径，避免 404。
 */
final class ThemeJsGenerationFallbackTest extends TestCase
{
    private const GEN = '/static/tu-20261021-194828-b77618/Weline/hanfu/Weline/Theme/view/theme/frontend/assets/js/hanfu-product-docks.js?v=1.1.21-20261020';
    private const CANONICAL = '/static/Weline/hanfu/Weline/Theme/view/theme/frontend/assets/js/hanfu-product-docks.js';

    public function testKeepsGenerationUrlWhenFileExists(): void
    {
        $exists = static fn(string $path): bool => true;
        self::assertSame(self::GEN, ThemeJs::fallbackGenerationUrl(self::GEN, $exists));
    }

    public function testFallsBackToCanonicalWhenGenerationFileMissing(): void
    {
        $exists = static fn(string $path): bool => !\str_contains($path, '/tu-');
        $result = ThemeJs::fallbackGenerationUrl(self::GEN, $exists);
        self::assertStringStartsWith('/static/', $result);
        self::assertStringNotContainsString('194828', $result, '不得继续引用已清理的旧代次');
        self::assertStringContainsString('hanfu-product-docks.js', $result);
        self::assertSame(1, \substr_count($result, '?v='), 'query 不得重复拼接');
        self::assertStringEndsWith('?v=1.1.21-20261020', $result);
    }

    public function testKeepsUrlWhenNeitherPathExists(): void
    {
        $exists = static fn(string $path): bool => false;
        self::assertSame(self::GEN, ThemeJs::fallbackGenerationUrl(self::GEN, $exists));
    }

    public function testNonGenerationUrlIsUntouched(): void
    {
        $exists = static fn(string $path): bool => false;
        self::assertSame(self::CANONICAL, ThemeJs::fallbackGenerationUrl(self::CANONICAL, $exists));
    }
}
