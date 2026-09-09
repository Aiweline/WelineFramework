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
        $root = dirname(__DIR__, 4) . '/Theme/view/theme/frontend/layouts';
        $interior = [
            $root . '/account/default.phtml',
            $root . '/account/dashboard.phtml',
            $root . '/account/challenge.phtml',
            $root . '/account_profile/default.phtml',
            $root . '/account_orders/default.phtml',
            $root . '/account_logout/default.phtml',
        ];
        foreach ($interior as $path) {
            self::assertFileExists($path);
            self::assertStringNotContainsString(
                'seo::footer',
                (string) file_get_contents($path),
                $path . ' should not register SEO panel inside account center'
            );
        }

        foreach ([
            $root . '/account/auth.phtml',
            $root . '/account_auth/default.phtml',
        ] as $path) {
            self::assertFileExists($path);
            self::assertStringContainsString(
                'seo::footer',
                (string) file_get_contents($path),
                $path . ' login/register shell may keep SEO panel'
            );
        }
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
            // Keep login/register shells (account/auth, account_auth).
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
            || str_contains($normalized, '/layouts/account_auth/')
        ) {
            return false;
        }

        return (bool) preg_match(
            '#/layouts/(account|account_profile|account_orders|account_logout)(/|$)#',
            $normalized
        );
    }
}
