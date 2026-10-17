<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Composer;

use PHPUnit\Framework\TestCase;
use ReflectionClass as ReflectionClass;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPageInputDigest;
use Weline\Theme\Service\ThemeBrandResolver;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllComposerContractsPass().
 */
final class ComposerContractsTest extends TestCase
{
    public function testAllComposerContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'ComposerPathSymlink::testWelinePathPackagesAreSymlinksIntoAppCode' => function () {
            $this->assert_ComposerPathSymlink_testWelinePathPackagesAreSymlinksIntoAppCode();
        },
            'ComposerPathSymlink::testThemeReflectionClassesResolveOntoAppCodeTree' => function () {
            $this->assert_ComposerPathSymlink_testThemeReflectionClassesResolveOntoAppCodeTree();
        },
            'ComposerPathSymlink::testAppCodeTreeHasNoModuleSelfReferentialSymlinks' => function () {
            $this->assert_ComposerPathSymlink_testAppCodeTreeHasNoModuleSelfReferentialSymlinks();
        },
            'ComposerPathSymlink::testVendorWelineHasNoOrphanDirectoriesOutsideLockPathSet' => function () {
            $this->assert_ComposerPathSymlink_testVendorWelineHasNoOrphanDirectoriesOutsideLockPathSet();
        },
            'ComposerPathSymlink::testCaptchaAndShippingAuthorityResolveOntoAppCode' => function () {
            $this->assert_ComposerPathSymlink_testCaptchaAndShippingAuthorityResolveOntoAppCode();
        },
            'ComposerPathSymlink::testLockPathPackagesDeclareTransportOptionsSymlinkTrue' => function () {
            $this->assert_ComposerPathSymlink_testLockPathPackagesDeclareTransportOptionsSymlinkTrue();
        },
        ];
    }

private function assert_ComposerPathSymlink_testWelinePathPackagesAreSymlinksIntoAppCode(): void
{
    $bp = $this->repositoryRoot_ComposerPathSymlink();
    $samples = ['vendor/weline/module-theme' => 'app/code/Weline/Theme', 'vendor/weline/framework' => 'app/code/Weline/Framework', 'vendor/weline/module-i18n' => 'app/code/Weline/I18n'];
    foreach ($samples as $vendorRel => $appRel) {
        $vendorPath = $bp . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $vendorRel);
        $appPath = $bp . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $appRel);
        self::assertDirectoryExists($appPath, "authority missing: {$appRel}");
        self::assertTrue(\is_link($vendorPath), "{$vendorRel} must be a symlink (not a mirrored directory copy)");
        self::assertDirectoryExists($vendorPath, "{$vendorRel} symlink target missing");
        $vendorReal = \realpath($vendorPath);
        $appReal = \realpath($appPath);
        self::assertNotFalse($vendorReal);
        self::assertNotFalse($appReal);
        self::assertSame($appReal, $vendorReal, "{$vendorRel} realpath must equal {$appRel}");
    }
}

private function assert_ComposerPathSymlink_testThemeReflectionClassesResolveOntoAppCodeTree(): void
{
    $themeReal = \realpath($this->repositoryRoot_ComposerPathSymlink() . '/app/code/Weline/Theme');
    self::assertNotFalse($themeReal);
    $brandFile = \realpath((new ReflectionClass(ThemeBrandResolver::class))->getFileName() ?: '');
    $digestFile = \realpath((new ReflectionClass(ThemeLayoutEntityPageInputDigest::class))->getFileName() ?: '');
    self::assertNotFalse($brandFile, 'ThemeBrandResolver file unresolved');
    self::assertNotFalse($digestFile, 'ThemeLayoutEntityPageInputDigest file unresolved');
    self::assertSame(0, \strpos($brandFile, $themeReal . DIRECTORY_SEPARATOR), 'ThemeBrandResolver must load from app/code Theme tree, got ' . $brandFile);
    self::assertSame(0, \strpos($digestFile, $themeReal . DIRECTORY_SEPARATOR), 'ThemeLayoutEntityPageInputDigest must load from app/code Theme tree, got ' . $digestFile);
}

private function assert_ComposerPathSymlink_testAppCodeTreeHasNoModuleSelfReferentialSymlinks(): void
{
    $codeRoot = $this->repositoryRoot_ComposerPathSymlink() . '/app/code';
    self::assertDirectoryExists($codeRoot);
    $found = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($codeRoot, \FilesystemIterator::SKIP_DOTS));
    /** @var \SplFileInfo $file */
    foreach ($iterator as $file) {
        $path = $file->getPathname();
        if (\is_link($path)) {
            $found[] = $path;
        }
    }
    self::assertSame([], $found, 'app/code must not contain symlinks (compile constraint); vendor→app is the allowed direction');
}

/**
 * UC-O1 / UC-O2: every vendor/weline/* entry must be a lock path symlink; forbid orphan directories.
 */
private function assert_ComposerPathSymlink_testVendorWelineHasNoOrphanDirectoriesOutsideLockPathSet(): void
{
    $bp = $this->repositoryRoot_ComposerPathSymlink();
    $vendorWeline = $bp . '/vendor/weline';
    self::assertDirectoryExists($vendorWeline);
    $lockPathNames = $this->lockPathPackageBasenames_ComposerPathSymlink($bp);
    self::assertNotSame([], $lockPathNames, 'composer.lock must list path packages');
    $orphans = [];
    $nonSymlinks = [];
    foreach (\scandir($vendorWeline) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $vendorWeline . '/' . $entry;
        if (!\is_dir($path) && !\is_link($path)) {
            continue;
        }
        if (!\is_link($path)) {
            $nonSymlinks[] = $entry;
        }
        $composerName = $this->guessWelineComposerName_ComposerPathSymlink($entry);
        if (!isset($lockPathNames[$composerName])) {
            $orphans[] = $entry . (\is_link($path) ? ' (symlink)' : ' (directory)');
        }
    }
    self::assertSame([], $nonSymlinks, 'vendor/weline entries must be symlinks; orphan/mirrored directories forbidden: ' . \implode(', ', $nonSymlinks));
    self::assertSame([], $orphans, 'vendor/weline must not contain packages outside composer.lock path set: ' . \implode(', ', $orphans));
}

/**
 * UC-O3: Captcha/Shipping authority lives under app/code (PSR-4), not vendor orphans.
 */
private function assert_ComposerPathSymlink_testCaptchaAndShippingAuthorityResolveOntoAppCode(): void
{
    $bp = $this->repositoryRoot_ComposerPathSymlink();
    $samples = [\Weline\Captcha\Service\CaptchaService::class => 'app/code/Weline/Captcha', \Weline\Shipping\Service\ShippingFacade::class => 'app/code/Weline/Shipping'];
    foreach ($samples as $class => $appRel) {
        $appReal = \realpath($bp . '/' . $appRel);
        self::assertNotFalse($appReal, "authority missing: {$appRel}");
        $file = \realpath((new ReflectionClass($class))->getFileName() ?: '');
        self::assertNotFalse($file, "{$class} file unresolved");
        self::assertSame(0, \strpos($file, $appReal . DIRECTORY_SEPARATOR), "{$class} must load from {$appRel}, got {$file}");
    }
    self::assertDirectoryDoesNotExist($bp . '/vendor/weline/module-captcha');
    self::assertDirectoryDoesNotExist($bp . '/vendor/weline/module-shipping');
}

/**
 * UC-C2 contract: lock path packages carry transport-options.symlink=true (reinstall lifecycle, not update --lock).
 */
private function assert_ComposerPathSymlink_testLockPathPackagesDeclareTransportOptionsSymlinkTrue(): void
{
    $bp = $this->repositoryRoot_ComposerPathSymlink();
    $lock = \json_decode((string) \file_get_contents($bp . '/composer.lock'), true);
    self::assertIsArray($lock);
    $missing = [];
    foreach ($lock['packages'] ?? [] as $package) {
        if (($package['dist']['type'] ?? '') !== 'path') {
            continue;
        }
        $name = (string) ($package['name'] ?? '');
        if ($name === '' || !\str_starts_with($name, 'weline/')) {
            continue;
        }
        $symlink = $package['transport-options']['symlink'] ?? null;
        if ($symlink !== true) {
            $missing[] = $name;
        }
    }
    self::assertSame([], $missing, 'path packages must declare package-root transport-options.symlink=true: ' . \implode(', ', $missing));
}

/**
 * @return array<string, true>
 */
private function lockPathPackageBasenames_ComposerPathSymlink(string $bp): array
{
    $lock = \json_decode((string) \file_get_contents($bp . '/composer.lock'), true);
    self::assertIsArray($lock);
    $names = [];
    foreach ($lock['packages'] ?? [] as $package) {
        if (($package['dist']['type'] ?? '') !== 'path') {
            continue;
        }
        $name = (string) ($package['name'] ?? '');
        if ($name === '' || !\str_starts_with($name, 'weline/')) {
            continue;
        }
        $names[$name] = true;
    }
    return $names;
}

private function guessWelineComposerName_ComposerPathSymlink(string $vendorBasename): string
{
    if ($vendorBasename === 'framework' || $vendorBasename === 'base') {
        return 'weline/' . $vendorBasename;
    }
    return 'weline/' . $vendorBasename;
}

private function repositoryRoot_ComposerPathSymlink(): string
{
    return '/Users/weline/Project/Official/框架';
}
}
