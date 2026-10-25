<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * 默认主题纯度守卫。
 *
 * 统一规范：默认主题只放机制与默认信息；不得出现具体设计主题的资产、模板、服务、命名。
 * 规范正文：app/code/Weline/Theme/doc/theme-unified-spec.md
 */
final class ThemeDefaultPurityTest extends TestCase
{
    private const FORBIDDEN_TOKENS = [
        'hanfu',
        'daocharms',
        'grocery',
        'playinginchina',
        'changan',
        'textile-heritage',
        'textileheritage',
        'widget-hanfu',
    ];

    private const SCAN_DIRS = [
        'view/theme',
        'view/statics/css/widgets',
        'view/statics/js/widgets',
        'view/statics/images',
        'view/prototypes',
    ];

    private const SCAN_FILES = [
        'extends/module/Weline_Widget/Weline_Theme/widget.php',
    ];

    private const TEXT_EXTENSIONS = ['php', 'phtml', 'css', 'js', 'json', 'html', 'htm', 'svg', 'txt'];

    public function testDefaultThemeHasNoDesignThemeContentOrNaming(): void
    {
        $themeRoot = dirname(__DIR__, 2);
        $violations = [];

        foreach (self::SCAN_DIRS as $relativeDir) {
            $dir = $themeRoot . '/' . $relativeDir;
            if (!is_dir($dir)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $path = $file->getPathname();
                $relative = ltrim(str_replace($themeRoot, '', $path), '/\\');
                $this->collectNameViolations($relative, $violations);
                if (!in_array(strtolower($file->getExtension()), self::TEXT_EXTENSIONS, true)) {
                    continue;
                }
                $contents = (string) file_get_contents($path);
                $this->collectContentViolations($relative, $contents, $violations);
            }
        }

        foreach (self::SCAN_FILES as $relativeFile) {
            $path = $themeRoot . '/' . $relativeFile;
            if (!is_file($path)) {
                continue;
            }
            $this->collectNameViolations($relativeFile, $violations);
            $this->collectContentViolations($relativeFile, (string) file_get_contents($path), $violations);
        }

        self::assertSame([], $violations, "默认主题出现设计主题内容/命名：\n" . implode("\n", $violations));
    }

    public function testDesignThemesDoNotOverrideCoreThemeCssOrJs(): void
    {
        $designRoot = dirname(__DIR__, 6) . '/app/design';
        if (!is_dir($designRoot)) {
            self::markTestSkipped('app/design not present');
        }

        $violations = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($designRoot, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $normalized = str_replace('\\', '/', $file->getPathname());
            if (preg_match('#/assets/(?:css/theme\.css|js/theme\.js)$#', $normalized) === 1) {
                $violations[] = $normalized;
            }
        }

        self::assertSame([], $violations, "设计主题禁止同 key 覆盖全局入口：\n" . implode("\n", $violations));
    }

    /** @param list<string> $violations */
    private function collectNameViolations(string $relative, array &$violations): void
    {
        $lower = strtolower($relative);
        foreach (self::FORBIDDEN_TOKENS as $token) {
            if (str_contains($lower, $token)) {
                $violations[] = $relative . ' :: filename contains "' . $token . '"';
            }
        }
    }

    /** @param list<string> $violations */
    private function collectContentViolations(string $relative, string $contents, array &$violations): void
    {
        $lines = preg_split('/\R/', $contents) ?: [];
        foreach ($lines as $index => $line) {
            $lower = strtolower($line);
            foreach (self::FORBIDDEN_TOKENS as $token) {
                if (str_contains($lower, $token)) {
                    $violations[] = $relative . ':' . ($index + 1) . ' :: contains "' . $token . '"';
                }
            }
        }
    }
}
