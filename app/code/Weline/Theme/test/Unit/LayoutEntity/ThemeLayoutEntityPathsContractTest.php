<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths;

/**
 * Source-string + behavioral contract for version-isolated entity disk tree.
 */
final class ThemeLayoutEntityPathsContractTest extends TestCase
{
    public function testPathSegmentsMatchVersionIsolatedLayout(): void
    {
        $path = \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityPaths.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);

        self::assertStringContainsString("ROOT_SEGMENT = 'theme-layout-entities'", $src);
        self::assertStringContainsString("SCHEMA_BINDING = 'theme-layout-entity.v3'", $src);
        self::assertStringContainsString("'tv'", $src);
        self::assertStringContainsString("'formal'", $src);
        self::assertStringContainsString("'draft'", $src);
        self::assertStringContainsString("'structures'", $src);
        self::assertStringContainsString("'bindings'", $src);
        self::assertStringContainsString("'rendered'", $src);
        self::assertStringContainsString("'layout.phtml'", $src);
        self::assertStringContainsString("'shell.phtml'", $src);
        self::assertStringContainsString('function scopeKey', $src);
        self::assertStringContainsString('function identityKey', $src);
        self::assertStringContainsString('function purgeAllEntities', $src);
        self::assertStringContainsString("hash('sha256'", $src);

        self::assertStringNotContainsString('pageCurrentJson', $src);
        self::assertStringNotContainsString('pageStructureOrRelease', $src);
        self::assertStringNotContainsString("return 'r'", $src);
        self::assertStringNotContainsString('substr($identityHash, 0, 16)', $src);
        self::assertStringNotContainsString('sha1($scope)', $src);
    }

    public function testScopeKeyIsFullSha256OfScopeAndStoreMode(): void
    {
        $paths = new ThemeLayoutEntityPaths();
        $key = $paths->scopeKey('default.__store__.__channel__', 'normal');
        self::assertSame(64, \strlen($key));
        self::assertSame(
            \hash('sha256', "default.__store__.__channel__\0normal"),
            $key,
        );
        self::assertNotSame(
            $paths->scopeKey('default.__store__.__channel__', 'normal'),
            $paths->scopeKey('default.__store__.__channel__', 'test'),
        );
    }

    public function testIdentityKeyRequiresFullHashAndVersionDirsIsolate(): void
    {
        $paths = new ThemeLayoutEntityPaths();
        $hash = \hash('sha256', 'homepage');
        self::assertSame($hash, $paths->identityKey($hash));

        $a = new ThemeVersionIdentity(3, 'default.default.default', 'normal', 'frontend', 10, 'formal', 2);
        $b = new ThemeVersionIdentity(3, 'default.default.default', 'normal', 'frontend', 11, 'formal', 2);
        $draft = new ThemeVersionIdentity(3, 'default.default.default', 'normal', 'frontend', 10, 'draft', 2);

        $pathA = $paths->pagePhtml($a, $hash, \hash('sha256', 'struct'));
        $pathB = $paths->pagePhtml($b, $hash, \hash('sha256', 'struct'));
        $pathDraft = $paths->pagePhtml($draft, $hash, \hash('sha256', 'struct'));

        self::assertStringContainsString('/frontend/', $pathA);
        self::assertStringContainsString('/tv10/formal/', $pathA);
        self::assertStringContainsString('/tv11/formal/', $pathB);
        self::assertStringContainsString('/tv10/draft/', $pathDraft);
        self::assertNotSame($pathA, $pathB);
        self::assertNotSame($pathA, $pathDraft);
        self::assertStringContainsString('/pages/' . $hash . '/', $pathA);
        self::assertStringNotContainsString('/current.json', $pathA);
    }

    public function testTruncatedIdentityRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ThemeLayoutEntityPaths())->identityKey(\substr(\hash('sha256', 'x'), 0, 16));
    }
}
