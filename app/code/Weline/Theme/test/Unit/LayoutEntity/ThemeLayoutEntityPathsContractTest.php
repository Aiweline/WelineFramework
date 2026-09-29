<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths;

final class ThemeLayoutEntityPathsContractTest extends TestCase
{
    public function testDistinctScopesAndStoreModesCannotShareDirectories(): void
    {
        $paths = new ThemeLayoutEntityPaths('/tmp/theme-layout-entities-contract/');
        $owners = [
            ['shop.eu.default', 'normal'], ['shop_eu.default', 'normal'],
            ['Shop.eu.default', 'normal'], ['shop.eu.default__test', 'normal'],
            ['shop.eu.default', 'test'], ['shop.eu.default', 'Test'],
        ];
        $directories = [];
        foreach ($owners as [$scope, $mode]) {
            $identity = new ThemeVersionIdentity(3, $scope, $mode, 'frontend', 10, 'formal', 2);
            $directories[] = strtolower($paths->ownerDir($identity));
        }
        self::assertCount(count($owners), array_unique($directories));
    }

    public function testLayoutOptionsTargetsAndNestedTypesRemainSeparate(): void
    {
        $paths = new ThemeLayoutEntityPaths('/tmp/theme-layout-entities-contract/');
        $formal = new ThemeVersionIdentity(3, 'default.default.default', 'normal', 'frontend', 10, 'formal', 2);
        $draft = $formal->withVersion(10, 'draft', 2);
        $plain = $paths->pageLayoutPhtml($formal, 'account/login');
        self::assertStringEndsWith('/v10/pages/layouts/account/login/default.phtml', $plain);
        self::assertStringEndsWith('/draft/pages/layouts/account/login/default.phtml', $paths->pageLayoutPhtml($draft, 'account/login'));
        self::assertNotSame($plain, $paths->pageLayoutPhtml($formal, 'account/login', 'compact'));
        self::assertNotSame($plain, $paths->pageLayoutPhtml($formal, 'account/login', 'default', 'product', 12));
        self::assertNotSame($paths->pageLayoutPhtml($formal, 'account/login', 'default', 'product', 12), $paths->pageLayoutPhtml($formal, 'account/login', 'default', 'product', 13));
    }

    public function testPartialOptionsArePreserved(): void
    {
        $paths = new ThemeLayoutEntityPaths('/tmp/theme-layout-entities-contract/');
        $identity = new ThemeVersionIdentity(3, 'default.default.default', 'normal', 'frontend', 10, 'formal', 2);
        self::assertTrue(method_exists($paths, 'partialPhtml'), 'The shared partial loader needs a type/option path.');
        self::assertStringEndsWith('/v10/theme/partials/header/compact.phtml', $paths->partialPhtml($identity, 'header', 'compact'));
        self::assertNotSame($paths->partialPhtml($identity, 'header'), $paths->partialPhtml($identity, 'header', 'compact'));
    }
    public function testLongestLegalScopeCanBeCreatedAndEnumerated(): void
    {
        $root = sys_get_temp_dir() . '/weline-path-' . bin2hex(random_bytes(6)) . '/theme-layout-entities';
        $paths = new ThemeLayoutEntityPaths($root);
        $scope = str_repeat('W', 255) . '.' . str_repeat('s', 64) . '.' . str_repeat('c', 64);
        $identity = new ThemeVersionIdentity(3, $scope, 'normal', 'frontend', 10, 'formal', 2);
        $path = $paths->pageLayoutPhtml($identity, 'homepage');
        try {
            self::assertTrue(@mkdir(dirname($path), 0770, true), 'Legal scope must fit filesystem component limits.');
            self::assertSame(2, file_put_contents($path, 'ok'));
            self::assertSame('ok', file_get_contents($path));
            $versions = $paths->listVersionModeDirectories();
            self::assertCount(1, $versions);
            self::assertSame(10, $versions[0]['theme_version_id']);
            self::assertSame($scope, $versions[0]['canonical_scope']);
            self::assertSame('normal', $versions[0]['store_mode']);
        } finally {
            if (is_dir($root)) {
                $paths->purgeAllEntities();
                @rmdir(dirname($root));
            }
        }
    }
}
