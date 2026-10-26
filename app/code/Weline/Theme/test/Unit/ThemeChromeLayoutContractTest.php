<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 自定义主题页头页尾强契约：
 * - 所有店面布局必须通过 Theme Partials 渲染 header/footer；
 * - 布局不得写死默认 Theme / 旧 Frontend public chrome；
 * - 主题一旦自带 chrome partial，就必须是布局实际消费的那一份。
 */
final class ThemeChromeLayoutContractTest extends TestCase
{
    private const DESIGN_ROOT = __DIR__ . '/../../../../../design';
    private const CHROME_BLOCK = 'Weline\\Theme\\Block\\Partials';

    public function testEveryCustomThemeLayoutRendersChromeThroughThemePartials(): void
    {
        $failures = [];
        foreach ($this->themeLayoutFiles() as $path) {
            $relative = $this->relativeThemePath($path);
            if (preg_match('#/(blank|test|_disabled)/#', $relative) === 1) {
                continue;
            }
            if (str_ends_with($relative, '/guide/size-guide.phtml')) {
                // Size-guide is an embedded partial page, not a standalone layout.
                continue;
            }
            $content = (string)file_get_contents($path);
            if (!str_contains($content, self::CHROME_BLOCK)) {
                $failures[] = $relative . ' [missing Theme Partials block]';
                continue;
            }
            if (!str_contains($content, 'type="header"')) {
                $failures[] = $relative . ' [missing header]';
            }
            if (!str_contains($content, 'type="footer"')) {
                $failures[] = $relative . ' [missing footer]';
            }
        }

        self::assertSame([], $failures, "Layouts must render theme-owned chrome:\n" . implode("\n", $failures));
    }

    public function testCustomThemeLayoutsDoNotHardcodeForeignChrome(): void
    {
        $forbidden = [
            'Weline_Theme::theme/frontend/partials/header/default.phtml',
            'Weline_Theme::theme/frontend/partials/footer/default.phtml',
            'Weline_Frontend::templates/public/header.phtml',
            'Weline_Frontend::templates/public/footer.phtml',
        ];
        $failures = [];
        foreach ($this->themeLayoutFiles() as $path) {
            $content = (string)file_get_contents($path);
            foreach ($forbidden as $snippet) {
                if (str_contains($content, $snippet)) {
                    $failures[] = $this->relativeThemePath($path) . ' -> ' . $snippet;
                }
            }
        }

        self::assertSame([], $failures, "Layouts must not hardcode foreign chrome:\n" . implode("\n", $failures));
    }

    public function testModuleLayoutsNeverHardcodeChrome(): void
    {
        $failures = [];
        $designRoot = realpath(self::DESIGN_ROOT) ?: self::DESIGN_ROOT;
        $root = dirname($designRoot, 2);
        $moduleRoot = $root . '/app/code';
        if (!is_dir($moduleRoot)) {
            self::markTestSkipped('app/code is not available in this test layout.');
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($moduleRoot, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if (!$file->isFile() || !str_ends_with($path, '.phtml') || !str_contains($path, '/view/theme/frontend/layouts/')) {
                continue;
            }
            $content = (string)file_get_contents($path);
            foreach (['header', 'footer'] as $type) {
                if (str_contains($content, 'type="' . $type . '"')
                    && !str_contains($content, self::CHROME_BLOCK)
                ) {
                    $failures[] = str_replace($root . '/', '', $path) . ' [' . $type . ']';
                }
            }
        }

        self::assertSame([], $failures, "Module layouts must render chrome through Theme Partials:\n" . implode("\n", $failures));
    }

    public function testHanfuThemeOwnsItsHeaderAndFooterChrome(): void
    {
        $theme = self::DESIGN_ROOT . '/Weline/hanfu/frontend';
        $header = $theme . '/partials/header/default.phtml';
        $footer = $theme . '/partials/footer/default.phtml';

        self::assertFileExists($header);
        self::assertFileExists($footer);
        self::assertStringContainsString('data-testid="ca-header"', (string)file_get_contents($header));
        self::assertStringContainsString('ca-footer', (string)file_get_contents($footer));
    }

    /**
     * @return list<string>
     */
    private function themeLayoutFiles(): array
    {
        $files = [];
        foreach (glob(self::DESIGN_ROOT . '/*/*', GLOB_ONLYDIR) ?: [] as $theme) {
            foreach (['frontend/layouts', 'view/theme/frontend/layouts'] as $relative) {
                $directory = $theme . '/' . $relative;
                if (!is_dir($directory)) {
                    continue;
                }
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
                );
                foreach ($iterator as $file) {
                    if ($file->isFile() && $file->getExtension() === 'phtml') {
                        $files[] = $file->getPathname();
                    }
                }
            }
        }

        return $files;
    }

    private function relativeThemePath(string $path): string
    {
        return str_replace(self::DESIGN_ROOT . '/', '', $path);
    }
}
