<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Security;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Security\AttackScanWarningAnalyzer;

final class AttackScanWarningAnalyzerTest extends TestCase
{
    public function testEmptyStatsYieldNoWarnings(): void
    {
        $analyzer = new AttackScanWarningAnalyzer();
        $warnings = $analyzer->analyze([
            'total_attacks' => 0,
            'by_type' => [],
            'by_severity' => [],
            'top_ips' => [],
        ]);
        self::assertSame([], $warnings);
    }

    public function testPathScanAndRequestShapeTriggerCampaign(): void
    {
        $analyzer = new AttackScanWarningAnalyzer();
        $warnings = $analyzer->analyze([
            'by_type' => [
                'path_scan' => 8,
                'request_shape' => 2,
            ],
            'by_severity' => [],
            'top_ips' => [],
        ]);
        $ids = \array_column($warnings, 'id');
        self::assertContains('path_scan', $ids);
        self::assertContains('request_shape', $ids);
        self::assertContains('scan_campaign', $ids);
        $campaign = null;
        foreach ($warnings as $w) {
            if (($w['id'] ?? '') === 'scan_campaign') {
                $campaign = $w;
                break;
            }
        }
        self::assertNotNull($campaign);
        self::assertSame('danger', $campaign['severity']);
    }

    public function testUnknownQueryProbeFromRecentRows(): void
    {
        $analyzer = new AttackScanWarningAnalyzer();
        $rows = [];
        for ($i = 0; $i < 3; $i++) {
            $rows[] = [
                'uri' => '/index.php?wp-config=1&x=1',
                'reason' => 'probe',
            ];
        }
        $warnings = $analyzer->analyze(['by_type' => [], 'by_severity' => [], 'top_ips' => []], $rows);
        $ids = \array_column($warnings, 'id');
        self::assertContains('unknown_query_probe', $ids);
    }

    public function testEnrichStatisticsAttachesScanWarningsKey(): void
    {
        $analyzer = new AttackScanWarningAnalyzer();
        $enriched = $analyzer->enrichStatistics([
            'total_attacks' => 1,
            'by_type' => ['request_shape' => 1],
            'by_severity' => [],
            'top_ips' => [],
        ]);
        self::assertArrayHasKey('scan_warnings', $enriched);
        self::assertNotEmpty($enriched['scan_warnings']);
    }

    public function testTopIpConcentratedWarning(): void
    {
        $analyzer = new AttackScanWarningAnalyzer();
        $warnings = $analyzer->analyze([
            'by_type' => [],
            'by_severity' => [],
            'top_ips' => ['203.0.113.9' => 25],
        ]);
        $ids = \array_column($warnings, 'id');
        self::assertContains('concentrated_ip', $ids);
    }
}
