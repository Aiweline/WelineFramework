<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Storefront full-page layouts must hang seo::footer so WelinePanel can registerTab(seo).
 */
final class SeoFooterLayoutCoverageContractTest extends TestCase
{
    public function testThemeFrontendLayoutsAllHangSeoFooter(): void
    {
        $root = dirname(__DIR__, 4) . '/Theme/view/theme/frontend/layouts';
        $this->assertDirectoryExists($root);
        $missing = $this->layoutsMissingSeoFooter($root);
        self::assertSame([], $missing, "Theme layouts missing seo::footer:\n" . implode("\n", $missing));
    }

    public function testModuleStorefrontLayoutsHangSeoFooter(): void
    {
        $welineRoot = dirname(__DIR__, 4);
        $candidates = [
            $welineRoot . '/Product/view/theme/frontend/layouts',
            $welineRoot . '/Customer/view/theme/frontend/layouts',
        ];
        $missing = [];
        foreach ($candidates as $root) {
            if (!is_dir($root)) {
                continue;
            }
            foreach ($this->layoutsMissingSeoFooter($root) as $rel) {
                $missing[] = $rel;
            }
        }
        self::assertSame([], $missing, "Module layouts missing seo::footer:\n" . implode("\n", $missing));
    }

    public function testAccountInteriorLayoutsDoNotHangSeoFooter(): void
    {
        $customerRoot = dirname(__DIR__, 4) . '/Customer/view/theme/frontend/layouts';
        $interior = [
            $customerRoot . '/account/default.phtml',
            $customerRoot . '/account/dashboard.phtml',
            $customerRoot . '/account/challenge.phtml',
            $customerRoot . '/account/logout/default.phtml',
            $customerRoot . '/account/orders/default.phtml',
            $customerRoot . '/account/profile/default.phtml',
        ];
        foreach ($interior as $path) {
            self::assertFileExists($path);
            self::assertStringNotContainsString(
                'seo::footer',
                (string) file_get_contents($path),
                $path . ' should not register SEO panel inside account center'
            );
        }

        $authShell = $customerRoot . '/account/auth.phtml';
        self::assertFileExists($authShell);
        self::assertStringContainsString(
            'seo::footer',
            (string) file_get_contents($authShell),
            $authShell . ' login/register shell may keep SEO panel'
        );
    }

    /**
     * @return list<string>
     */
    private function layoutsMissingSeoFooter(string $root): array
    {
        $missing = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'phtml') {
                continue;
            }
            $path = $file->getPathname();
            // Mini-cart drawer preview is not a crawlable storefront page shell.
            if (str_contains($path, '/mini-cart/')) {
                continue;
            }
            // Logged-in account center interiors do not need SEO panel bootstrap.
            // Keep login/register shells (account/auth).
            if ($this->isAccountInteriorLayout($path)) {
                continue;
            }
            $src = (string) file_get_contents($path);
            if (!preg_match('/<\/body>/i', $src)) {
                continue;
            }
            if (!str_contains($src, 'seo::footer')) {
                $missing[] = $path;
            }
        }
        sort($missing);

        return $missing;
    }

    private function isAccountInteriorLayout(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);
        if (str_contains($normalized, '/layouts/account/auth.phtml')
            || str_contains($normalized, '/layouts/account/login/')
            || str_contains($normalized, '/layouts/account/register/')
            || str_contains($normalized, '/layouts/account/forgot-password/')
            || str_contains($normalized, '/layouts/account/set-password/')
            || str_contains($normalized, '/layouts/account/social-login/')
        ) {
            return false;
        }

        return (bool) preg_match('#/layouts/account(/|$)#', $normalized);
    }
}
