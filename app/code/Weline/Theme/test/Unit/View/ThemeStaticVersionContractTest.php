<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 默认主题（Theme 模块 view/theme 前后端）静态资源版本契约：
 *
 * - 模板里禁止裸写 `/static/...` 资源 URL（必须经 PublicStaticUrl / StaticBuildId / <theme:css> 等解析）；
 * - 主题 CSS 禁止绝对 `/static/...`（相对路径会随构建前缀自动版本化）；
 * - UI 图标 sprite 必须继承模块脚本的版本查询。
 *
 * 违反即失败，防止新主题开发绕开 /static/{buildId} 契约。
 */
final class ThemeStaticVersionContractTest extends TestCase
{
    public function testDefaultThemeTemplatesDoNotHardcodeStaticUrls(): void
    {
        $offenders = [];
        foreach (['frontend', 'backend'] as $area) {
            $root = BP . 'app/code/Weline/Theme/view/theme/' . $area;
            if (!\is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $ext = \strtolower($file->getExtension());
                if ($ext !== 'phtml' && $ext !== 'php') {
                    continue;
                }
                $source = (string)\file_get_contents($file->getPathname());
                if (\preg_match('#[\'"](/static/[^\'"]+)[\'"]#', $source, $m) === 1) {
                    $offenders[] = \str_replace(BP, '', $file->getPathname()) . ' → ' . $m[1];
                }
            }
        }

        self::assertSame([], $offenders, "默认主题模板不得裸写 /static 资源 URL：\n" . \implode("\n", $offenders));
    }

    public function testThemeCssDoesNotHardcodeAbsoluteStaticUrls(): void
    {
        $offenders = [];
        $root = BP . 'app/code/Weline/Theme/view/statics';
        if (\is_dir($root)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (!$file->isFile() || \strtolower($file->getExtension()) !== 'css') {
                    continue;
                }
                $source = (string)\file_get_contents($file->getPathname());
                if (\preg_match_all('#url\(\s*[\'"]?/static/#i', $source, $m) > 0) {
                    $offenders[] = \str_replace(BP, '', $file->getPathname());
                }
            }
        }

        self::assertSame([], $offenders, "主题 CSS 不得绝对引用 /static（相对路径会随构建代次版本化）：\n" . \implode("\n", $offenders));
    }

    public function testThemeRuntimeJsDoesNotHardcodeProductionStaticBase(): void
    {
        $offenders = [];
        $root = BP . 'app/code/Weline/Theme/view';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || \strtolower($file->getExtension()) !== 'js') {
                continue;
            }
            $relative = \str_replace(BP, '', $file->getPathname());
            if (\str_contains($relative, '/test/')) {
                continue;
            }
            foreach (\file($file->getPathname()) ?: [] as $lineNo => $line) {
                $trimmed = \ltrim($line);
                if ($trimmed === '' || \str_starts_with($trimmed, '//') || \str_starts_with($trimmed, '*') || \str_starts_with($trimmed, '/*')) {
                    continue;
                }
                if (\preg_match('#return\s+[\'"`]/static/#', $line) === 1
                    || \preg_match("#/static/'\s*\+\s*m\[#", $line) === 1
                ) {
                    $offenders[] = $relative . ':' . ($lineNo + 1);
                }
            }
        }

        self::assertSame([], $offenders, "主题运行时 JS 不得直接产出裸 /static/（用 runtimeConfig.staticBaseUrl）：\n" . \implode("\n", $offenders));
    }

    public function testUiIconSpriteInheritsModuleAssetVersion(): void
    {
        foreach ([
            'app/code/Weline/Theme/view/statics/ui/weline-ui.js',
            'app/code/Weline/Theme/view/ui/js/weline-ui.js',
        ] as $relative) {
            $source = (string)\file_get_contents(BP . $relative);
            self::assertMatchesRegularExpression(
                "/iconSpriteUrl[^\\n]*weline-icons\\.svg' \\+ new URL\\(import\\.meta\\.url\\)\\.search/",
                $source,
                $relative . ' 的图标 sprite 必须继承 import.meta.url 的版本查询',
            );
        }
    }
}
