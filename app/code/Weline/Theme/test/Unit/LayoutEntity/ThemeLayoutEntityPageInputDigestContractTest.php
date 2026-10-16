<?php
declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPageInputDigest;

final class ThemeLayoutEntityPageInputDigestContractTest extends TestCase
{
    private string $origin;

    protected function setUp(): void
    {
        parent::setUp();
        ThemeLayoutEntityPageInputDigest::resetRequestCache();
        $this->origin = sys_get_temp_dir() . '/weline-page-input-digest-' . bin2hex(random_bytes(4)) . '.phtml';
        file_put_contents($this->origin, "<!-- origin-a -->\n");
    }

    protected function tearDown(): void
    {
        ThemeLayoutEntityPageInputDigest::resetRequestCache();
        if (is_file($this->origin)) {
            @unlink($this->origin);
        }
        parent::tearDown();
    }

    public function testMatchesStoredWhenOriginUnchanged(): void
    {
        $digest = ThemeLayoutEntityPageInputDigest::forOrigin($this->origin);
        self::assertNotSame('', $digest);
        self::assertTrue(ThemeLayoutEntityPageInputDigest::matchesStored($digest, $this->origin));
    }

    public function testMismatchWhenOriginBytesChange(): void
    {
        $digest = ThemeLayoutEntityPageInputDigest::forOrigin($this->origin);
        file_put_contents($this->origin, "<!-- origin-b -->\n");
        self::assertFalse(ThemeLayoutEntityPageInputDigest::matchesStored($digest, $this->origin));
    }

    public function testEmptyStoredDigestIsStale(): void
    {
        self::assertFalse(ThemeLayoutEntityPageInputDigest::matchesStored('', $this->origin));
        self::assertFalse(ThemeLayoutEntityPageInputDigest::matchesStored('   ', $this->origin));
    }

    public function testMaterializerAndBakeAndGateShareHelper(): void
    {
        $root = dirname(__DIR__, 3) . '/Service/LayoutEntity';
        $materializer = (string)file_get_contents($root . '/ThemeLayoutEntityMaterializer.php');
        $bake = (string)file_get_contents($root . '/ThemeLayoutEntityBakeCoordinator.php');
        $gate = (string)file_get_contents($root . '/ThemeLayoutEntityRequestSolidifyGate.php');
        self::assertStringContainsString('ThemeLayoutEntityPageInputDigest::forOrigin', $materializer);
        self::assertStringContainsString(
            "'input_digest' => ThemeLayoutEntityPageInputDigest::forOrigin(\$origin)",
            $materializer,
        );
        self::assertGreaterThanOrEqual(
            2,
            substr_count($materializer, 'ThemeLayoutEntityPageInputDigest::forOrigin'),
            'page + chrome (and deps) must write input_digest',
        );
        self::assertStringContainsString('ThemeLayoutEntityPageInputDigest::matchesStored', $bake);
        self::assertStringContainsString('ThemeLayoutEntityPageInputDigest::planDigest', $bake);
        self::assertStringContainsString('chromePartialDiskStaleOrRaw', $bake);
        self::assertStringContainsString('rematerializeChromeIfStale', $bake);
        self::assertStringContainsString('discoverStaleChromePartialPairs', $bake);
        self::assertStringContainsString('ThemeLayoutEntityPageInputDigest::matchesStored', $gate);
        self::assertStringContainsString('design_origin_stale_fallback_original', $gate);
        self::assertStringContainsString('design_chrome_origin_stale_fallback_original', $gate);
        $queue = (string)file_get_contents($root . '/ThemeLayoutEntitySolidifyQueue.php');
        self::assertStringContainsString('rematerializeChromeIfStale', $queue);
        $cli = dirname(__DIR__, 3) . '/Console/Theme/LayoutSolidifyStatus.php';
        self::assertFileExists($cli);
        $cliSrc = (string)file_get_contents($cli);
        self::assertStringContainsString('theme:layout-solidify-status', $cliSrc);
        self::assertStringContainsString('ThemeLayoutEntityPageInputDigest::matchesStored', $cliSrc);
        // Debt #1: upgrade/pipeline must heal draft chrome even when page pending is empty / skipped.
        self::assertStringContainsString('rematerializeChromeIfStale', $bake);
        self::assertGreaterThanOrEqual(
            3,
            substr_count($bake, 'rematerializeChromeIfStale'),
            'BakeCoordinator: method + solidifyPendingIdentity + empty-pending collect heal (+ pipeline post-pass)',
        );
        self::assertStringContainsString('toVersionIdentity()', $bake);
        self::assertStringContainsString('Pages unchanged / inherit-skipped', $bake);
        self::assertStringContainsString("'/draft/'", $cliSrc);
        self::assertStringContainsString("'draft'", $cliSrc);
        self::assertStringContainsString("'formal'", $cliSrc);
    }
}
