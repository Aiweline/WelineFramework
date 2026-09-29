<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths;

final class ThemeLayoutEntityRetiredTreeCleanupTest extends TestCase
{
    public function testCleanupRetiresEveryOldReadableTreeAndPreservesNewScopeTrees(): void
    {
        $this->withTree(function (ThemeLayoutEntityPaths $paths): void {
            $new = $paths->pageLayoutPhtml(new ThemeVersionIdentity(1, 'default.__store__.__channel__', 'normal', 'frontend', 10, 'formal', 2), 'homepage');
            $this->put($new, 'new-source');
            $legacy = [
                '901/frontend/test/v27/theme/chrome.phtml',
                '1/frontend/default_store_default/v6/pages/layouts/homepage.phtml',
                '1/frontend/s-default.default.default/m-normal/v1/pages/layouts/activity/default.phtml',
                '1/backend/' . str_repeat('a', 64) . '/tv12/draft/page.phtml',
            ];
            foreach ($legacy as $path) { $this->put($paths->root() . $path, 'retired-shell'); }

            self::assertGreaterThan(4, $paths->purgeLegacyDerivedTrees());
            foreach ($legacy as $path) { self::assertFileDoesNotExist($paths->root() . $path); }
            self::assertSame('new-source', file_get_contents($new));
            self::assertSame(0, $paths->purgeLegacyDerivedTrees());
        });
    }

    public function testNamedThemeCleanupDoesNotTouchAnotherTheme(): void
    {
        $this->withTree(function (ThemeLayoutEntityPaths $paths): void {
            foreach ([1, 2] as $themeId) {
                $this->put($paths->root() . $themeId . '/frontend/default_default_default/v1/page.phtml', 'legacy-' . $themeId);
                $this->put($paths->pageLayoutPhtml(new ThemeVersionIdentity($themeId, 'default.__store__.__channel__', 'normal', 'frontend', 10, 'formal', 2), 'homepage'), 'current-' . $themeId);
            }

            self::assertGreaterThan(0, $paths->purgeLegacyDerivedTrees(1));
            self::assertDirectoryDoesNotExist($paths->root() . '1/frontend/default_default_default');
            self::assertSame('legacy-2', file_get_contents($paths->root() . '2/frontend/default_default_default/v1/page.phtml'));
            foreach ([1, 2] as $themeId) {
                self::assertSame('current-' . $themeId, file_get_contents($paths->pageLayoutPhtml(new ThemeVersionIdentity($themeId, 'default.__store__.__channel__', 'normal', 'frontend', 10, 'formal', 2), 'homepage')));
            }
        });
    }

    private function put(string $path, string $bytes): void
    {
        if (!is_dir(dirname($path))) { mkdir(dirname($path), 0770, true); }
        file_put_contents($path, $bytes);
    }

    private function withTree(callable $test): void
    {
        $parent = sys_get_temp_dir() . '/weline-retired-tree-' . bin2hex(random_bytes(6));
        $paths = new ThemeLayoutEntityPaths($parent . '/theme-layout-entities');
        try { $test($paths); }
        finally {
            if (is_dir($paths->root())) { $paths->purgeAllEntities(); }
            @rmdir($parent);
        }
    }
}
