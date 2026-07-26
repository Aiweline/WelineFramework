<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Weline\Visitor\Service\PixelRetentionService;
use Weline\Visitor\Service\Report\PixelQueryRouter;

/**
 * F04b：留存分析（简版；日队列；热短窗）。
 */
final class PixelRetentionServiceTest extends TestCase
{
    private PixelRetentionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PixelRetentionService(new PixelQueryRouter());
    }

    public function testResolveVisitorKeyPrefersUserIdThenSoftFingerprint(): void
    {
        self::assertSame('u:42', $this->service->resolveVisitorKey(['user_id' => 42, 'ip' => '1.1.1.1', 'user_agent' => 'A']));
        $anonA = $this->service->resolveVisitorKey(['user_id' => 0, 'ip' => '1.1.1.1', 'user_agent' => 'Chrome']);
        $anonB = $this->service->resolveVisitorKey(['user_id' => 0, 'ip' => '1.1.1.1', 'user_agent' => 'Chrome']);
        $anonC = $this->service->resolveVisitorKey(['user_id' => 0, 'ip' => '2.2.2.2', 'user_agent' => 'Chrome']);
        self::assertSame($anonA, $anonB);
        self::assertNotSame($anonA, $anonC);
        self::assertStringStartsWith('a:', $anonA);
        self::assertSame('', $this->service->resolveVisitorKey(['user_id' => 0, 'ip' => '', 'user_agent' => '']));
    }

    public function testVisitorActivityDaysCollapseAndPreferUserId(): void
    {
        $rows = [
            ['user_id' => 7, 'ip' => '9.9.9.9', 'user_agent' => 'X', 'created_at' => '2026-07-20 10:00:00'],
            ['user_id' => 7, 'ip' => '8.8.8.8', 'user_agent' => 'Y', 'created_at' => '2026-07-21 10:00:00'],
            ['user_id' => 0, 'ip' => '1.1.1.1', 'user_agent' => 'Chrome', 'created_at' => '2026-07-20 11:00:00'],
            ['user_id' => 0, 'ip' => '1.1.1.1', 'user_agent' => 'Chrome', 'created_at' => '2026-07-20 12:00:00'],
            ['user_id' => 0, 'ip' => '', 'user_agent' => '', 'created_at' => '2026-07-20 13:00:00'],
        ];
        $days = $this->service->visitorActivityDaysFromRows($rows);
        self::assertSame(['2026-07-20', '2026-07-21'], $days['u:7']);
        $anon = $this->service->resolveVisitorKey(['user_id' => 0, 'ip' => '1.1.1.1', 'user_agent' => 'Chrome']);
        self::assertSame(['2026-07-20'], $days[$anon]);
        self::assertCount(2, $days);
    }

    public function testComputeCohortRetentionAndMeasurableOffsets(): void
    {
        $from = new DateTimeImmutable('2026-07-20 00:00:00');
        $to = new DateTimeImmutable('2026-07-22 23:59:59');
        $visitorDays = [
            // 7/20 队列：D0+D1 回访；D2 未回
            'u:1' => ['2026-07-20', '2026-07-21'],
            // 7/20 队列：仅 D0
            'u:2' => ['2026-07-20'],
            // 7/21 队列：D0+D1（相对队列日）
            'u:3' => ['2026-07-21', '2026-07-22'],
            // 7/22 队列：仅 Day0 可观测（D1 超出窗）
            'u:4' => ['2026-07-22'],
        ];

        $result = $this->service->computeFromVisitorDays($visitorDays, $from, $to, 2);
        self::assertSame(4, $result['total_visitors']);
        self::assertSame(2, $result['returning_visitors']);
        self::assertSame(0.5, $result['returning_rate']);

        // D1：可观测队列 = 7/20(2人)+7/21(1人)=3；留存 = u1+u3 = 2 → 2/3
        self::assertSame(3, $result['d1_eligible']);
        self::assertSame(2, $result['d1_retained']);
        self::assertSame(0.6667, $result['d1_rate']);

        $byDate = [];
        foreach ($result['cohorts'] as $cohort) {
            $byDate[$cohort['cohort_date']] = $cohort;
        }
        self::assertSame(2, $byDate['2026-07-20']['size']);
        self::assertSame([2, 1, 0], $byDate['2026-07-20']['retained']);
        self::assertSame([1.0, 0.5, 0.0], $byDate['2026-07-20']['rates']);

        self::assertSame(1, $byDate['2026-07-22']['size']);
        self::assertSame(1.0, $byDate['2026-07-22']['rates'][0]);
        self::assertNull($byDate['2026-07-22']['rates'][1], 'D1 超出窗不可观测');
        self::assertNull($byDate['2026-07-22']['retained'][1]);
    }

    public function testBuildForWebsiteUsesInjectedRunnerAndClamps(): void
    {
        $seen = null;
        $result = $this->service->buildForWebsite(
            8,
            new DateTimeImmutable('2026-01-01 00:00:00'),
            new DateTimeImmutable('2026-01-20 23:59:59'),
            static function (int $websiteId, DateTimeImmutable $from, DateTimeImmutable $to) use (&$seen): array {
                $seen = [
                    $websiteId,
                    $from->format('Y-m-d'),
                    $to->format('Y-m-d'),
                ];

                return [
                    ['user_id' => 1, 'ip' => '', 'user_agent' => '', 'created_at' => '2026-01-14 10:00:00'],
                    ['user_id' => 1, 'ip' => '', 'user_agent' => '', 'created_at' => '2026-01-15 10:00:00'],
                    ['user_id' => 2, 'ip' => '', 'user_agent' => '', 'created_at' => '2026-01-14 11:00:00'],
                ];
            }
        );

        self::assertTrue($result['window_clamped']);
        self::assertSame(8, $seen[0] ?? null);
        self::assertSame('2026-01-14', $seen[1] ?? null);
        self::assertSame('2026-01-20', $seen[2] ?? null);
        self::assertSame(2, $result['total_visitors']);
        self::assertSame(1, $result['returning_visitors']);
        self::assertSame(0.5, $result['d1_rate']);
        self::assertSame('', $result['error']);
    }

    public function testSqlSelectsIdentityFieldsWithScanCap(): void
    {
        $from = new DateTimeImmutable('2026-07-20 00:00:00');
        $to = new DateTimeImmutable('2026-07-26 23:59:59');
        [$sql, $params] = $this->service->buildActivitySql(3, $from, $to);

        self::assertStringContainsString('user_id', $sql);
        self::assertStringContainsString('user_agent', $sql);
        self::assertStringContainsString(' AS ip', $sql);
        self::assertStringContainsString('ORDER BY', $sql);
        self::assertStringContainsString('LIMIT ' . PixelRetentionService::MAX_SCAN_ROWS, $sql);
        self::assertStringNotContainsString('browser_info', $sql);
        self::assertStringNotContainsString('session_id', $sql, '留存用访客键，不按会话');
        self::assertSame(3, $params[':website_id']);
    }

    public function testDetailWiresRetentionCardSeparatelyFromPath(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertFileExists($root . '/Service/PixelRetentionService.php');

        $controller = (string)\file_get_contents($root . '/Controller/Backend/PixelDashboard.php');
        self::assertStringContainsString('PixelRetentionService', $controller);
        self::assertStringContainsString('buildRetention', $controller);
        self::assertStringContainsString("'retention'", $controller);

        $detail = (string)\file_get_contents($root . '/view/templates/Backend/PixelDashboard/detail.phtml');
        self::assertStringContainsString('id="retention"', $detail);
        self::assertStringContainsString('留存分析', $detail);
        self::assertStringContainsString('次日留存', $detail);
        self::assertStringContainsString('队列日', $detail);
        self::assertStringContainsString('path-exploration', $detail, 'F04a 卡片仍在');
    }
}
