<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Visitor\Console\Pixel\ArchiveMigrate;
use Weline\Visitor\Console\Pixel\HotRetention;
use Weline\Visitor\Service\PixelArchiveMigrateService;
use Weline\Visitor\Service\Report\PixelQueryRouter;
use Weline\Visitor\Service\VisitorTrackingConfig;

/**
 * G10：保留配置键默认值、SystemConfig 表单与 CLI 接线契约。
 */
final class VisitorTrackingConfigRetentionRuntimeTest extends TestCase
{
    public function testRuntimeConfigExposesRetentionDefaults(): void
    {
        $config = new VisitorTrackingConfig();

        // 无框架 bootstrap 时 getRuntimeConfig 可能触达 ObjectManager；getter 仅读配置 map（失败回退默认）。
        self::assertSame(365, $config->getHotRetentionDays());
        self::assertSame(1095, $config->getWarmRetentionDays());
        self::assertTrue($config->isColdArchiveEnabled());
        self::assertSame(
            VisitorTrackingConfig::DEFAULT_RETENTION_HOT_DAYS,
            PixelQueryRouter::DEFAULT_HOT_RETENTION_DAYS
        );
        self::assertSame(
            VisitorTrackingConfig::DEFAULT_RETENTION_WARM_DAYS,
            PixelQueryRouter::DEFAULT_WARM_RETENTION_DAYS
        );
        self::assertSame(
            'visitor/tracking/retention_hot_days',
            VisitorTrackingConfig::CONFIG_KEY_RETENTION_HOT_DAYS
        );
    }

    public function testWarmDaysNeverBelowHotDays(): void
    {
        $config = new VisitorTrackingConfig();
        $ref = new \ReflectionClass(VisitorTrackingConfig::class);
        $method = $ref->getMethod('normalizeWarmRetentionDays');
        $method->setAccessible(true);

        self::assertSame(500, $method->invoke($config, 100, 500));
        self::assertSame(1095, $method->invoke($config, 1095, 365));
    }

    public function testTrackingFormWiresRetentionKeys(): void
    {
        $root = dirname(__DIR__, 3);
        $tpl = (string)\file_get_contents(
            $root . '/extends/module/Weline_SystemConfig/Config/backend/tracking.phtml'
        );
        self::assertStringContainsString('visitor/tracking/retention_hot_days', $tpl);
        self::assertStringContainsString('visitor/tracking/retention_warm_days', $tpl);
        self::assertStringContainsString('visitor/tracking/cold_archive_enabled', $tpl);
        self::assertStringContainsString('visitor_retention', $tpl);
        self::assertStringContainsString('热明细保留天数', $tpl);
        self::assertStringContainsString('温聚合可查天数', $tpl);
        self::assertStringContainsString('启用冷归档', $tpl);

        self::assertSame(
            'visitor/tracking/retention_hot_days',
            VisitorTrackingConfig::CONFIG_KEY_RETENTION_HOT_DAYS
        );
        self::assertSame(
            'visitor/tracking/retention_warm_days',
            VisitorTrackingConfig::CONFIG_KEY_RETENTION_WARM_DAYS
        );
        self::assertSame(
            'visitor/tracking/cold_archive_enabled',
            VisitorTrackingConfig::CONFIG_KEY_COLD_ARCHIVE_ENABLED
        );
    }

    public function testBodyEndFallbackIncludesRetention(): void
    {
        $root = dirname(__DIR__, 3);
        $source = (string)\file_get_contents(
            $root . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml'
        );
        self::assertStringContainsString("'retention' => [", $source);
        self::assertStringContainsString("'hotDays' => 365", $source);
        self::assertStringContainsString("'warmDays' => 1095", $source);
        self::assertStringContainsString("'coldArchiveEnabled' => true", $source);
    }

    public function testCliHelpMentionsConfigDefaults(): void
    {
        $hot = new HotRetention();
        $help = $hot->help();
        self::assertIsArray($help);
        $notes = implode("\n", $help['notes'] ?? []);
        self::assertStringContainsString('retention_hot_days', $notes);
        self::assertStringContainsString('cold_archive_enabled', $notes);

        $archive = new ArchiveMigrate();
        $archiveHelp = $archive->help();
        self::assertIsArray($archiveHelp);
        $archiveNotes = implode("\n", $archiveHelp['notes'] ?? []);
        self::assertStringContainsString('retention_hot_days', $archiveNotes);
    }

    public function testArchiveMigrateAcceptsHotDaysOption(): void
    {
        $service = new PixelArchiveMigrateService();
        $opts = $service->normalizeOptions([
            'website_id' => 1,
            'hot_days' => 100,
            'limit' => 10,
        ]);
        self::assertSame(1, $opts['website_id']);
        // before ≈ now-100d
        $before = new \DateTimeImmutable($opts['before'], new \DateTimeZone('UTC'));
        $expected = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-100 days');
        self::assertLessThanOrEqual(2, abs($before->getTimestamp() - $expected->getTimestamp()));
    }
}
