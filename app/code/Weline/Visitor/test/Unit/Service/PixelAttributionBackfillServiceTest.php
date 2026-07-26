<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;
use Weline\Visitor\Service\PixelAttributionBackfillService;

/**
 * A13a：归因扁平列回填干跑纯逻辑（不依赖列已落库）。
 */
class PixelAttributionBackfillServiceTest extends TestCore
{
    private function service(): PixelAttributionBackfillService
    {
        /** @var PixelAttributionBackfillService $service */
        $service = ObjectManager::getInstance(PixelAttributionBackfillService::class);

        return $service;
    }

    public function testBuildHydrationInputExtractsStickyAndSessionFromBrowserInfo(): void
    {
        $row = [
            'pixel_id' => 1,
            'website_id' => 9,
            'url' => 'https://example.test/p?utm_source=later',
            'referer' => 'https://google.com/',
            'session_id' => '',
            'channel_code' => '',
            'channel_name' => '',
            'traffic_type' => '',
            'utm_source' => '',
            'utm_medium' => '',
            'utm_campaign' => '',
            'browser_info' => json_encode([
                'schema' => 'weline_pixel_browser_v2',
                'session_id' => 'wps-a13a-1',
                'additionalInfo' => [
                    'sticky' => [
                        'wch' => 'summer',
                        'utm_source' => 'newsletter',
                        'utm_medium' => 'email',
                        'utm_campaign' => 'welcome',
                    ],
                    'environment' => [
                        'session_id' => 'wps-a13a-env',
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ];

        $input = $this->service()->buildHydrationInput($row);
        self::assertSame('wps-a13a-1', $input['post']['session_id']);
        self::assertSame('summer', $input['post']['sticky']['wch'] ?? null);
        self::assertSame('newsletter', $input['post']['sticky']['utm_source'] ?? null);
        self::assertSame('', $input['data']['channel_code']);
    }

    public function testProjectRowFillsFlatColumnsFromUrl(): void
    {
        $row = [
            'pixel_id' => 2,
            'website_id' => 9,
            'url' => 'https://example.test/landing?wch=ad_a13a&utm_source=google&utm_medium=cpc&utm_campaign=spring',
            'referer' => 'https://google.com/',
            'session_id' => '',
            'channel_code' => '',
            'channel_name' => '',
            'traffic_type' => '',
            'utm_source' => '',
            'utm_medium' => '',
            'utm_campaign' => '',
            'browser_info' => json_encode([
                'additionalInfo' => [
                    'environment' => ['session_id' => 'wps-a13a-url'],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ];

        $projected = $this->service()->projectRow($row);
        self::assertSame('wps-a13a-url', $projected['session_id']);
        self::assertSame('ad_a13a', $projected['channel_code']);
        self::assertSame('paid', $projected['traffic_type']);
        self::assertSame('google', $projected['utm_source']);
        self::assertSame('cpc', $projected['utm_medium']);
        self::assertSame('spring', $projected['utm_campaign']);
        self::assertTrue($this->service()->wouldUpdateRow($row, $projected));
    }

    public function testProjectRowStickyOverridesUrl(): void
    {
        $row = [
            'pixel_id' => 3,
            'website_id' => 9,
            'url' => 'https://example.test/?wch=second&utm_medium=cpc',
            'referer' => '',
            'session_id' => 'wps-a13a-sticky',
            'channel_code' => '',
            'traffic_type' => '',
            'utm_source' => '',
            'utm_medium' => '',
            'utm_campaign' => '',
            'browser_info' => json_encode([
                'additionalInfo' => [
                    'sticky' => [
                        'wch' => 'first',
                        'utm_source' => 'newsletter',
                        'utm_medium' => 'email',
                        'utm_campaign' => 'welcome',
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ];

        $projected = $this->service()->projectRow($row);
        self::assertSame('first', $projected['channel_code']);
        self::assertSame('email', $projected['traffic_type']);
        self::assertSame('newsletter', $projected['utm_source']);
    }

    public function testDiffAttributionFieldsIgnoresIdentical(): void
    {
        $before = [
            'session_id' => 's1',
            'channel_code' => 'a',
            'channel_name' => '',
            'traffic_type' => 'paid',
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'x',
        ];
        self::assertSame([], $this->service()->diffAttributionFields($before, $before));
        self::assertFalse($this->service()->wouldUpdateRow($before, $before));
    }

    public function testDiffAttributionFieldsReportsChanges(): void
    {
        $before = [
            'session_id' => '',
            'channel_code' => '',
            'channel_name' => '',
            'traffic_type' => '',
            'utm_source' => '',
            'utm_medium' => '',
            'utm_campaign' => '',
        ];
        $after = [
            'session_id' => 's1',
            'channel_code' => 'summer',
            'channel_name' => '',
            'traffic_type' => 'email',
            'utm_source' => 'newsletter',
            'utm_medium' => 'email',
            'utm_campaign' => 'welcome',
        ];
        $diff = $this->service()->diffAttributionFields($before, $after);
        self::assertArrayHasKey('session_id', $diff);
        self::assertSame(['from' => '', 'to' => 's1'], $diff['session_id']);
        self::assertArrayHasKey('channel_code', $diff);
        self::assertTrue($this->service()->wouldUpdateRow($before, $after));
    }

    public function testDryRunNeverSetsUpdatedAndForcesDryRunFlag(): void
    {
        $report = $this->service()->dryRun(['limit' => 1, 'sample_limit' => 1]);
        self::assertTrue($report['dry_run']);
        self::assertFalse($report['apply']);
        self::assertSame(0, $report['updated']);
        self::assertFalse($report['marked_done']);
        self::assertArrayHasKey('would_update', $report);
        self::assertArrayHasKey('scanned', $report);
        self::assertArrayHasKey('columns_ready', $report);
        self::assertArrayHasKey('sample_has_values', $report);
        self::assertArrayHasKey('attribution_backfill_done', $report);
    }

    public function testRowHasAttributionValuesAndChangesHaveValues(): void
    {
        self::assertFalse($this->service()->rowHasAttributionValues([
            'session_id' => '',
            'channel_code' => '',
            'traffic_type' => '',
        ]));
        self::assertTrue($this->service()->rowHasAttributionValues([
            'channel_code' => 'summer',
        ]));
        self::assertTrue($this->service()->changesHaveValues([
            'channel_code' => ['from' => '', 'to' => 'summer'],
        ]));
        self::assertFalse($this->service()->changesHaveValues([
            'channel_code' => ['from' => 'x', 'to' => ''],
        ]));
    }

    public function testApplyWithoutColumnsReadyDoesNotWriteOrMark(): void
    {
        // 先用干跑探测列状态，避免列已落库时 apply 真写库并标记 done。
        $probe = $this->service()->dryRun(['limit' => 1, 'sample_limit' => 1]);
        if (!empty($probe['columns_ready'])) {
            self::markTestSkipped('A02 扁平列已落库，本用例只覆盖列缺失时的拒写路径。');
        }

        $report = $this->service()->apply(['limit' => 1, 'mark_done' => true]);
        self::assertTrue($report['apply']);
        self::assertFalse($report['marked_done']);
        self::assertSame(0, $report['updated']);
        self::assertNotEmpty($report['error'] ?? '');
    }

    public function testConsoleCommandSupportsApplyAndMarkDone(): void
    {
        self::assertTrue(class_exists(\Weline\Visitor\Console\Pixel\AttributionBackfill::class));
        $cmd = ObjectManager::getInstance(\Weline\Visitor\Console\Pixel\AttributionBackfill::class);
        self::assertStringContainsString('A13b', $cmd->tip());
        $help = $cmd->help();
        self::assertIsArray($help);
        self::assertSame('pixel:attribution-backfill', $help['command'] ?? null);
        $usage = implode("\n", $help['usage'] ?? []);
        self::assertStringContainsString('apply --enable-apply', $usage);
        self::assertStringContainsString('mark-done --enable-mark', $usage);
        self::assertStringContainsString('status', $usage);

        $blocked = $cmd->execute(['apply']);
        self::assertStringContainsString('BLOCKED', $blocked);
        self::assertStringContainsString('--enable-apply', $blocked);

        $blockedMark = $cmd->execute(['mark-done']);
        self::assertStringContainsString('BLOCKED', $blockedMark);
        self::assertStringContainsString('--enable-mark', $blockedMark);
    }

    public function testRuntimeConfigExposesAttributionBackfillDone(): void
    {
        /** @var \Weline\Visitor\Service\VisitorTrackingConfig $config */
        $config = ObjectManager::getInstance(\Weline\Visitor\Service\VisitorTrackingConfig::class);
        $runtime = $config->getRuntimeConfig();
        self::assertArrayHasKey('attribution', $runtime);
        self::assertArrayHasKey('backfillDone', $runtime['attribution']);
        self::assertIsBool($runtime['attribution']['backfillDone']);
        self::assertSame(
            \Weline\Visitor\Service\VisitorTrackingConfig::CONFIG_KEY_ATTRIBUTION_BACKFILL_DONE,
            'visitor/tracking/attribution_backfill_done'
        );
    }

    public function testTrackingConfigTemplateDeclaresBackfillDoneField(): void
    {
        $source = (string)\file_get_contents(
            BP . '/app/code/Weline/Visitor/extends/module/Weline_SystemConfig/Config/backend/tracking.phtml'
        );
        self::assertStringContainsString('visitor/tracking/attribution_backfill_done', $source);
    }
}
