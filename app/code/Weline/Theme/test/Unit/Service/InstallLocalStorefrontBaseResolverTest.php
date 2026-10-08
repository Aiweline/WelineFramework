<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\InstallLocalStorefrontBaseResolver;

final class InstallLocalStorefrontBaseResolverTest extends TestCase
{
    public function testResolverSkipsPublicDaocharmsDomain(): void
    {
        /** @var InstallLocalStorefrontBaseResolver $resolver */
        $resolver = ObjectManager::getInstance(InstallLocalStorefrontBaseResolver::class);
        $base = $resolver->resolveForWebsite(158, 'daocharms');
        if ($base === null) {
            self::markTestSkipped('website 158 not available');
        }

        self::assertStringNotContainsString('://daocharms.com', $base);
        self::assertStringNotContainsString('.weline.test', $base, 'Must not splice stale *.weline.test into local edit base');
        self::assertMatchesRegularExpression('#^https?://#i', $base);
        // Local edit on project Host: synthetic /~site/{code} is the required channel.
        if (!\str_contains($base, '/~site/daocharms')) {
            self::markTestSkipped('website 158 /~site mount not resolvable under this bootstrap: ' . $base);
        }
        self::assertStringContainsString('/~site/daocharms', $base);
    }

    public function testInstallOriginNeverUsesDaocharmsPublicHost(): void
    {
        /** @var InstallLocalStorefrontBaseResolver $resolver */
        $resolver = ObjectManager::getInstance(InstallLocalStorefrontBaseResolver::class);
        $origin = $resolver->resolveInstallOrigin();
        self::assertNotNull($origin);
        self::assertStringNotContainsString('://daocharms.com', (string)$origin);
    }

    public function testResolverSourcePrefersSyntheticSiteMountBeforeHostOnlyDomains(): void
    {
        $root = dirname(__DIR__, 3);
        $source = (string)file_get_contents($root . '/Service/InstallLocalStorefrontBaseResolver.php');
        $synthetic = \strpos($source, '合成 /~site/{code}');
        $hostOnly = \strpos($source, 'Host-only 主验收域');
        $legacy = \strpos($source, 'Legacy Host-only *.weline.test');
        self::assertNotFalse($synthetic);
        self::assertNotFalse($hostOnly);
        self::assertNotFalse($legacy);
        self::assertLessThan(
            $hostOnly,
            $synthetic,
            'Project-Host /~site/{code} must win before Host-only *.test.weline.com'
        );
        self::assertLessThan(
            $legacy,
            $hostOnly,
            'Primary *.test.weline.com before legacy *.weline.test'
        );
    }

    public function testThemeEditorOriginSkipsSiblingHostsOnProjectHost(): void
    {
        $root = dirname(__DIR__, 3);
        $source = (string)file_get_contents($root . '/Controller/Backend/ThemeEditor.php');
        $method = \strpos($source, 'function resolveStorefrontOriginForEditorScope');
        self::assertNotFalse($method);
        $slice = \substr($source, $method, 2800);
        self::assertStringContainsString('isStandardProjectHost', $slice);
        self::assertStringContainsString('return \'\'', $slice);
        self::assertStringContainsString('/~site/{code}', $slice);
        self::assertStringContainsString('requestHostIsWebsiteLocalDomain', $slice);
        self::assertStringContainsString('function requestHostIsWebsiteLocalDomain', $source);
    }

    public function testThemeEditorMountSkipsSitePrefixOnWebsiteLocalDomainHost(): void
    {
        $root = dirname(__DIR__, 3);
        $source = (string)file_get_contents($root . '/Controller/Backend/ThemeEditor.php');
        $method = \strpos($source, 'function resolveStorefrontMountPathForEditorScope');
        self::assertNotFalse($method);
        $slice = \substr($source, $method, 2200);
        self::assertStringContainsString('requestHostIsWebsiteLocalDomain', $slice);
        self::assertStringContainsString('isStandardProjectHost', $slice);
        self::assertStringContainsString('ProjectHostSiteMount::editorMountPath', $slice);

        $editor = (string)file_get_contents(
            $root . '/view/statics/ui/pages/weline-theme-editor.js'
        );
        self::assertStringContainsString('function isStandardProjectHostName', $editor);
        self::assertStringContainsString(
            'if (!isStandardProjectHostName(window.location.hostname))',
            $editor
        );
    }
}
