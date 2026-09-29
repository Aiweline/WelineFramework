<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ThemeLayoutEntityUpgradeFailureBoundaryTest extends TestCase
{
    public static function failures(): iterable
    {
        yield 'real candidate write error' => ['write','theme_layout_candidate_write_failed'];
        yield 'candidate compilation error' => ['compile','fixture_candidate_compile_error'];
        yield 'website still exists including ID zero' => ['existing-owner','system_config_website_scope_not_found'];
    }

    #[DataProvider('failures')]
    public function testGenerationFailureStopsBeforeDeletingOldArtifacts(string $scenario, string $error): void
    {
        $result = $this->scenario($scenario);
        self::assertSame($error, $result['error']);
        self::assertTrue($result['old_source_exists']);
        self::assertTrue($result['sidecar_exists']);
    }

    public function testKnownHistoricalMissingSourceRemainsReportedWithoutBlockingRetirement(): void
    {
        $result = $this->scenario('historical');
        self::assertNull($result['error']);
        self::assertFalse($result['old_source_exists']);
        self::assertFalse($result['sidecar_exists']);
        self::assertSame('historical_resource_snapshot_missing', $result['unmapped'][0]['reason']);
    }

    public function testMissingHistoricalOwnerIsReportedOnlyAfterCatalogConfirmsAbsence(): void
    {
        $result = $this->scenario('missing-owner');
        self::assertNull($result['error']);
        self::assertFalse($result['old_source_exists']);
        self::assertSame('historical_scope_owner_missing', $result['unmapped'][0]['reason']);
        self::assertSame('fixture.store.channel', $result['unmapped'][0]['canonical_scope']);
        self::assertSame(1, $result['unmapped'][0]['version_id']);
        self::assertSame('fixture', $result['unmapped'][0]['website_code']);
    }

    private function scenario(string $scenario): array
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/upgrade-failure-boundary.php') . ' ' . escapeshellarg($scenario) . ' 2>&1', $output, $exit);
        self::assertSame(0, $exit, implode("\n", $output));
        return json_decode(implode("\n", $output),true,flags:JSON_THROW_ON_ERROR);
    }
}
