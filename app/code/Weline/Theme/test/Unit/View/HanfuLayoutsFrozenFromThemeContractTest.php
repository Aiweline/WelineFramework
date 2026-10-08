<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Hanfu design theme freezes a full copy of Theme frontend layouts so Theme
 * default edits no longer silently change Hanfu storefront pages.
 *
 * Copy-only: Theme source layouts must remain present (never deleted by freeze).
 */
final class HanfuLayoutsFrozenFromThemeContractTest extends TestCase
{
    private function themeLayoutsDir(): string
    {
        return dirname(__DIR__, 3) . '/view/theme/frontend/layouts';
    }

    private function hanfuLayoutsDir(): string
    {
        return dirname(__DIR__, 6) . '/design/Weline/hanfu/frontend/layouts';
    }

    /**
     * @return list<string>
     */
    private function listRelativePhtml(string $base): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'phtml') {
                continue;
            }
            $rel = substr($file->getPathname(), strlen($base) + 1);
            $out[] = str_replace('\\', '/', $rel);
        }
        sort($out);

        return $out;
    }

    public function testHanfuOwnsEveryThemeFrontendLayoutPathWithoutDeletingTheme(): void
    {
        $themeDir = $this->themeLayoutsDir();
        $hanfuDir = $this->hanfuLayoutsDir();
        self::assertDirectoryExists($themeDir);
        self::assertDirectoryExists($hanfuDir);

        $themeRels = $this->listRelativePhtml($themeDir);
        self::assertNotEmpty($themeRels);

        foreach ($themeRels as $rel) {
            self::assertFileExists($themeDir . '/' . $rel, 'Theme source must remain: ' . $rel);
            self::assertFileExists($hanfuDir . '/' . $rel, 'Hanfu freeze missing: ' . $rel);
        }
    }

    public function testHanfuPolicySetIncludesAmazonShellPages(): void
    {
        $hanfuPolicy = $this->hanfuLayoutsDir() . '/policy';
        foreach ([
            'accessibility.phtml',
            'cookie.phtml',
            'default.phtml',
            'disclaimer.phtml',
            'privacy.phtml',
            'refund.phtml',
            'shipping.phtml',
            'term-condition.phtml',
        ] as $file) {
            $path = $hanfuPolicy . '/' . $file;
            self::assertFileExists($path, $file);
        }

        // Newly frozen named pages keep Theme amazon-policy shell at freeze time.
        $privacy = (string)file_get_contents($hanfuPolicy . '/privacy.phtml');
        self::assertStringContainsString('amazon-policy', $privacy);
        self::assertStringContainsString('siteBrandLate', $privacy);
    }
}
