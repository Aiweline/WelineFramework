<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class StorefrontQuickLoginScriptRetiredTest extends TestCase
{
    // Theme/test/Unit/View → 6 = app（与 Css 目录同为 5 层深）。
    private function appPath(string $rel): string
    {
        return \dirname(__DIR__, 6) . '/' . $rel;
    }

    public function testNoDesignThemePrintsRetiredQuickLoginScript(): void
    {
        $root = $this->appPath('design');
        if (!\is_dir($root)) {
            self::markTestSkipped('/app/design 是本地资产（.gitignore:315），本机无设计主题时跳过');
        }

        $offenders = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getFilename() !== 'default.phtml') {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            if (!\str_contains($path, '/partials/footer/')) {
                continue;
            }
            $src = (string)\file_get_contents($file->getPathname());
            if (\str_contains($src, 'data-storefront-float-quick-login')
                || \str_contains($src, 'floatQuickJsUrl')) {
                $offenders[] = $path;
            }
        }

        self::assertSame([], $offenders, "以下设计主题仍在打印退役脚本：\n" . \implode("\n", $offenders));
    }
}
