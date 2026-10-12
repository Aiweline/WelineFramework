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
        self::assertStringContainsString('ThemeLayoutEntityPageInputDigest::matchesStored', $bake);
        self::assertStringContainsString('ThemeLayoutEntityPageInputDigest::planDigest', $bake);
        self::assertStringContainsString('ThemeLayoutEntityPageInputDigest::matchesStored', $gate);
        self::assertStringContainsString('design_origin_stale_fallback_original', $gate);
    }
}
