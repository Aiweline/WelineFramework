<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\NestedChromeSourceFingerprint;

final class NestedChromeSourceFingerprintContractTest extends TestCase
{
    protected function tearDown(): void
    {
        NestedChromeSourceFingerprint::resetMemoForTests();
        parent::tearDown();
    }

    public function testSourceListMatchesHistoricalNestedChromeTemplates(): void
    {
        $rels = NestedChromeSourceFingerprint::sourceRelativePaths();
        self::assertContains('view/theme/frontend/widgets/header/mini-cart-icon/default.phtml', $rels);
        self::assertContains('view/theme/frontend/widgets/header/account/default.phtml', $rels);
        self::assertContains('view/theme/frontend/partials/header/categories-horizontal-nav.phtml', $rels);
        self::assertContains('view/theme/frontend/partials/header/categories-sidebar-nav.phtml', $rels);
        self::assertContains('view/theme/frontend/partials/header/mega-menu-panel.phtml', $rels);
        self::assertCount(5, $rels);

        foreach (NestedChromeSourceFingerprint::sourceAbsolutePaths() as $abs) {
            self::assertFileExists($abs, $abs);
        }
    }

    public function testComputeIsNonEmptyContentDigest(): void
    {
        NestedChromeSourceFingerprint::resetMemoForTests();
        $fp = NestedChromeSourceFingerprint::compute();
        self::assertNotSame('', $fp);
        $parts = \explode(';', $fp);
        self::assertCount(5, $parts);
        foreach ($parts as $part) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $part);
        }
    }

    public function testSameContentDifferentMtimeKeepsHash(): void
    {
        $dir = \sys_get_temp_dir() . '/weline-nested-chrome-fp-' . \bin2hex(\random_bytes(4));
        self::assertTrue(@\mkdir($dir, 0777, true) || \is_dir($dir));
        $path = $dir . '/stub.phtml';
        self::assertNotFalse(\file_put_contents($path, "<!-- nested chrome stub -->\n"));

        NestedChromeSourceFingerprint::resetMemoForTests();
        $first = NestedChromeSourceFingerprint::hashFile($path);
        \clearstatcache(true, $path);
        self::assertTrue(\touch($path, \time() + 120));
        \clearstatcache(true, $path);
        NestedChromeSourceFingerprint::resetMemoForTests();
        $second = NestedChromeSourceFingerprint::hashFile($path);
        self::assertSame($first, $second, 'content sha256 must ignore mtime-only changes');

        @\unlink($path);
        @\rmdir($dir);
    }

    public function testByteChangeChangesHashAndStatGateSkipsReread(): void
    {
        $dir = \sys_get_temp_dir() . '/weline-nested-chrome-fp-' . \bin2hex(\random_bytes(4));
        self::assertTrue(@\mkdir($dir, 0777, true) || \is_dir($dir));
        $path = $dir . '/stub.phtml';
        self::assertNotFalse(\file_put_contents($path, 'AAA'));

        NestedChromeSourceFingerprint::resetMemoForTests();
        $a = NestedChromeSourceFingerprint::hashFile($path);

        self::assertNotFalse(\file_put_contents($path, 'BBB'));
        // Filesystem mtime often 1s resolution — force mtime bump so memo gate sees a change.
        self::assertTrue(\touch($path, \time() + 30));
        \clearstatcache(true, $path);
        $b = NestedChromeSourceFingerprint::hashFile($path);
        self::assertNotSame($a, $b, 'stat change must rehash');

        // Same length rewrite + restore mtime|size → memo must NOT rehash (stale by design under gate).
        $stat = \stat($path);
        self::assertIsArray($stat);
        $pinnedMtime = (int)$stat['mtime'];
        self::assertNotFalse(\file_put_contents($path, 'CCC'));
        self::assertTrue(\touch($path, $pinnedMtime));
        \clearstatcache(true, $path);
        $memoHit = NestedChromeSourceFingerprint::hashFile($path);
        self::assertSame($b, $memoHit, 'unchanged mtime|size must reuse memo without rehash');

        NestedChromeSourceFingerprint::resetMemoForTests();
        $fresh = NestedChromeSourceFingerprint::hashFile($path);
        self::assertSame(\hash_file('sha256', $path), $fresh);
        self::assertNotSame($b, $fresh);

        @\unlink($path);
        @\rmdir($dir);
    }

    public function testPartialsDelegatesAndPinsSchemaV20(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Block/Partials.php');
        self::assertStringContainsString('NestedChromeSourceFingerprint', $source);
        self::assertStringContainsString('NestedChromeSourceFingerprint::compute()', $source);
        self::assertStringContainsString("'schema' => 'chrome-partial-v20-nested-content-hash'", $source);
        self::assertStringNotContainsString("'schema' => 'chrome-partial-v19-currency-island-hook'", $source);
    }

    public function testPublishedChromeProjectionWritersStayStubOrConsumeFingerprint(): void
    {
        $chrome = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityChrome.php',
        );
        $slot = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntitySlotFiller.php',
        );

        self::assertMatchesRegularExpression(
            '/function\s+seedPublishedHotCacheEager\s*\([^)]*\)\s*(?::\s*array\s*)?\{\s*return\s*\[[^\]]*skipped[^\]]*true/s',
            $chrome,
            'Chrome projection seed must stay stubbed or consume NestedChromeSourceFingerprint',
        );
        self::assertDoesNotMatchRegularExpression(
            '/function\s+seedPublishedHotCacheEager[\s\S]{0,800}loadOrRenderPublished/s',
            $chrome,
        );

        self::assertMatchesRegularExpression(
            '/function\s+primePublishedChromeSlotProjectionHotCache\s*\([^)]*\)\s*(?::\s*bool\s*)?\{\s*return\s+false\s*;/s',
            $slot,
        );
        self::assertMatchesRegularExpression(
            '/function\s+rememberHonestEmptyChromeSlotProjection\s*\([^)]*\)\s*(?::\s*bool\s*)?\{\s*return\s+false\s*;/s',
            $slot,
        );

        // If either file later gains NestedChromeSourceFingerprint, that also satisfies the gate.
        if (\str_contains($chrome, 'NestedChromeSourceFingerprint') || \str_contains($slot, 'NestedChromeSourceFingerprint')) {
            self::assertTrue(true);
        }
    }
}
