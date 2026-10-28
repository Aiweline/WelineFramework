<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Ui;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 7) . '/app/bootstrap.php';

/**
 * 默认主题颜色基线对比度门禁（E11 / WCAG AA）。
 *
 * 只锁「默认基线」`view/theme/frontend/variables/_colors.css`：
 * 自定义主题可以重绑语义色，但必须在自己的色盘里重新满足 4.5:1，
 * 而不是继承一个不合格的基线。
 *
 * 权威：Theme/doc/theme-css-architecture-vs-style.md
 */
final class ContrastTokenTest extends TestCase
{
    private const MIN_RATIO = 4.5;

    /** @var array<string, string>|null */
    private static ?array $tokens = null;

    public static function textTokenProvider(): array
    {
        return [
            'text' => ['--color-text'],
            'text-primary' => ['--color-text-primary'],
            'text-body' => ['--color-text-body'],
            'text-secondary' => ['--color-text-secondary'],
            'text-tertiary' => ['--color-text-tertiary'],
            'text-subtle' => ['--color-text-subtle'],
            'text-muted' => ['--color-text-muted'],
            'text-placeholder' => ['--color-text-placeholder'],
            'link' => ['--color-link'],
        ];
    }

    #[DataProvider('textTokenProvider')]
    public function testTextTokensMeetAaContrastOnDefaultSurfaces(string $token): void
    {
        $tokens = self::tokens();
        self::assertArrayHasKey($token, $tokens, $token . ' must stay declared in the default color baseline.');

        $foreground = self::resolveColor($tokens, $token);
        self::assertNotNull($foreground, $token . ' must resolve to a hex color (no unresolved var() chain).');

        foreach (['--color-bg-canvas', '--color-bg-primary'] as $surfaceToken) {
            $background = self::resolveColor($tokens, $surfaceToken);
            self::assertNotNull($background, $surfaceToken . ' must resolve to a hex color.');

            $ratio = self::contrastRatio($foreground, $background);
            self::assertGreaterThanOrEqual(
                self::MIN_RATIO,
                $ratio,
                sprintf(
                    '%s (#%s) on %s (#%s) is %.2f:1, below WCAG AA %.1f:1. Darken the token.',
                    $token,
                    $foreground,
                    $surfaceToken,
                    $background,
                    $ratio,
                    self::MIN_RATIO
                )
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private static function tokens(): array
    {
        if (self::$tokens !== null) {
            return self::$tokens;
        }

        $file = BP . 'app/code/Weline/Theme/view/theme/frontend/variables/_colors.css';
        self::assertFileExists($file);
        $css = (string) file_get_contents($file);

        $tokens = [];
        preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;]+);/i', $css, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $key = strtolower(trim($match[1]));
            if (!isset($tokens[$key])) {
                $tokens[$key] = trim($match[2]);
            }
        }

        self::$tokens = $tokens;

        return self::$tokens;
    }

    /**
     * @param array<string, string> $tokens
     */
    private static function resolveColor(array $tokens, string $token, int $depth = 0): ?string
    {
        if ($depth > 8) {
            return null;
        }
        $value = $tokens[strtolower($token)] ?? null;
        if ($value === null) {
            return null;
        }
        if (preg_match('/^var\(\s*(--[a-z0-9-]+)\s*(?:,\s*([^)]+))?\)$/i', $value, $match)) {
            $resolved = self::resolveColor($tokens, $match[1], $depth + 1);
            if ($resolved === null && isset($match[2])) {
                return self::hex($match[2]);
            }
            return $resolved;
        }

        return self::hex($value);
    }

    private static function hex(string $value): ?string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^#([0-9a-f]{3})$/', $value, $match)) {
            $value = '#' . $match[1][0] . $match[1][0] . $match[1][1] . $match[1][1] . $match[1][2] . $match[1][2];
        }
        if (!preg_match('/^#([0-9a-f]{6})$/', $value, $match)) {
            return null;
        }

        return $match[1];
    }

    private static function contrastRatio(string $foreground, string $background): float
    {
        $lighter = max(self::luminance($foreground), self::luminance($background));
        $darker = min(self::luminance($foreground), self::luminance($background));

        return round(($lighter + 0.05) / ($darker + 0.05), 4);
    }

    private static function luminance(string $hex): float
    {
        $channels = [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
        $linear = array_map(static function (int $channel): float {
            $value = $channel / 255;

            return $value <= 0.03928
                ? $value / 12.92
                : (($value + 0.055) / 1.055) ** 2.4;
        }, $channels);

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }
}
