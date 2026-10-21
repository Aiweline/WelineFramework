<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Head JS 预算门禁（见 doc/Head-JS预算与申明加载方案.md）：
 *
 * - head 局部只允许 3 个 UI 启动件直出：prepaint / css-ready / ui（module）；
 * - 默认主题（Theme 模块）head 里的 <theme:js> 必须用 load（声明加载），禁止 defer 直出；
 * - 设计主题（app/design，若存在）的 head 直出脚本同样只允许这 3 个。
 */
final class ThemeHeadScriptBudgetTest extends TestCase
{
    private const ALLOWED_UI_BOOT = [
        'weline-theme-prepaint.js',
        'weline-css-ready.js',
        'weline-ui.js',
    ];

    public function testDefaultThemeHeadPartialsOnlyBootUiScripts(): void
    {
        $offenders = [];
        foreach (['frontend', 'backend'] as $area) {
            $root = BP . 'app/code/Weline/Theme/view/theme/' . $area . '/partials/head';
            if (!\is_dir($root)) {
                continue;
            }
            foreach ($this->phtmlFiles($root) as $file) {
                $source = (string)\file_get_contents($file);
                foreach ($this->externalScriptSrcs($source) as $src) {
                    if (!$this->isAllowedBoot($src)) {
                        $offenders[] = $this->rel($file) . ' → ' . $src;
                    }
                }
                if (\preg_match_all('/<theme:js\b[^>]*\bdefer\b/', $source, $m) > 0) {
                    $offenders[] = $this->rel($file) . ' → <theme:js defer> 必须改为 load（声明加载）';
                }
            }
        }

        self::assertSame([], $offenders, "默认主题 head 只允许 UI 启动件直出：\n" . \implode("\n", $offenders));
    }

    public function testDesignThemeHeadPartialsOnlyBootUiScripts(): void
    {
        $designRoot = BP . 'app/design';
        if (!\is_dir($designRoot)) {
            self::markTestSkipped('app/design 不在本机（设计主题不在本仓）');
        }
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($designRoot, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || !\str_ends_with(\str_replace('\\', '/', $file->getPathname()), 'partials/head/' . $file->getFilename())) {
                continue;
            }
            if (\strtolower($file->getExtension()) !== 'phtml') {
                continue;
            }
            $source = (string)\file_get_contents($file->getPathname());
            foreach ($this->externalScriptSrcs($source) as $src) {
                if (!$this->isAllowedBoot($src)) {
                    $offenders[] = $this->rel($file->getPathname()) . ' → ' . $src;
                }
            }
            if (\preg_match_all('/<theme:js\b[^>]*\bdefer\b/', $source, $m) > 0) {
                $offenders[] = $this->rel($file->getPathname()) . ' → <theme:js defer> 必须改为 load（声明加载）';
            }
        }

        self::assertSame([], $offenders, "设计主题 head 只允许 UI 启动件直出：\n" . \implode("\n", $offenders));
    }

    /** @return list<string> */
    private function externalScriptSrcs(string $source): array
    {
        $out = [];
        if (\preg_match_all('/<script\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\'][^>]*>/i', $source, $m) > 0) {
            foreach ($m[1] as $src) {
                $src = \trim((string)$src);
                if ($src !== '' && !\in_array($src, $out, true)) {
                    $out[] = $src;
                }
            }
        }

        return $out;
    }

    private function isAllowedBoot(string $src): bool
    {
        foreach (self::ALLOWED_UI_BOOT as $needle) {
            if (\str_contains($src, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function phtmlFiles(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && \strtolower($file->getExtension()) === 'phtml') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function rel(string $path): string
    {
        return \str_replace(BP, '', $path);
    }
}
