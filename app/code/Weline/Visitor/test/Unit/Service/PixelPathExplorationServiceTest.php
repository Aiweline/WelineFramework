<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Weline\Visitor\Service\PixelLandingDeviceDerivation;
use Weline\Visitor\Service\PixelPathExplorationService;
use Weline\Visitor\Service\Report\PixelQueryRouter;

/**
 * F04a：路径探索（简版；落地 → 次页，限深 3；热短窗）。
 */
final class PixelPathExplorationServiceTest extends TestCase
{
    private PixelPathExplorationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PixelPathExplorationService(
            new PixelQueryRouter(),
            new PixelLandingDeviceDerivation()
        );
    }

    public function testOnlyPageEventsCount(): void
    {
        self::assertTrue($this->service->isPageEvent('page_view'));
        self::assertTrue($this->service->isPageEvent('Page_Enter'));
        self::assertFalse($this->service->isPageEvent('view_item'));
        self::assertFalse($this->service->isPageEvent('cta_click'));
        self::assertFalse($this->service->isPageEvent(''));
    }

    public function testSessionPathsNormalizeOrderDedupeAndLimitDepth(): void
    {
        $rows = [
            // s1：乱序 + 相邻重复 + 超限深（第 4 页应被截断）
            ['session_id' => 's1', 'event' => 'page_view', 'url' => 'https://x.test/b?utm=1', 'created_at' => '2026-07-25 10:01:00', 'pixel_id' => 2],
            ['session_id' => 's1', 'event' => 'page_view', 'url' => 'https://x.test/a#hash', 'created_at' => '2026-07-25 10:00:00', 'pixel_id' => 1],
            ['session_id' => 's1', 'event' => 'page_enter', 'url' => 'https://x.test/b', 'created_at' => '2026-07-25 10:02:00', 'pixel_id' => 3],
            ['session_id' => 's1', 'event' => 'page_view', 'url' => 'https://x.test/c', 'created_at' => '2026-07-25 10:03:00', 'pixel_id' => 4],
            ['session_id' => 's1', 'event' => 'page_view', 'url' => 'https://x.test/d', 'created_at' => '2026-07-25 10:04:00', 'pixel_id' => 5],
            // s2：单页；非页面事件与空会话不计
            ['session_id' => 's2', 'event' => 'page_view', 'url' => '/only', 'created_at' => '2026-07-25 11:00:00', 'pixel_id' => 6],
            ['session_id' => 's2', 'event' => 'view_item', 'url' => '/product', 'created_at' => '2026-07-25 11:01:00', 'pixel_id' => 7],
            ['session_id' => '', 'event' => 'page_view', 'url' => '/ghost', 'created_at' => '2026-07-25 11:02:00', 'pixel_id' => 8],
        ];

        $paths = $this->service->sessionPathsFromRows($rows);
        self::assertSame(['/a', '/b', '/c'], $paths['s1'], '按时间排序、query/hash 去除、相邻重复折叠、限深 3');
        self::assertSame(['/only'], $paths['s2']);
        self::assertArrayNotHasKey('', $paths);
    }

    public function testComputeAggregatesLandingsNextAndTopPaths(): void
    {
        $sessionPaths = [
            's1' => ['/', '/pricing'],
            's2' => ['/', '/pricing'],
            's3' => ['/', '/about'],
            's4' => ['/'],
            's5' => ['/blog', '/blog/post-1', '/pricing'],
        ];

        $result = $this->service->computeFromSessionPaths($sessionPaths);
        self::assertSame(5, $result['total_sessions']);
        self::assertSame(1, $result['bounced_sessions']);
        self::assertSame(PixelPathExplorationService::MAX_DEPTH, $result['max_depth']);

        $landings = [];
        foreach ($result['landings'] as $landing) {
            $landings[$landing['path']] = $landing;
        }
        self::assertSame(4, $landings['/']['sessions']);
        self::assertSame(1, $landings['/']['exits']);
        self::assertSame('/pricing', $landings['/']['next'][0]['path']);
        self::assertSame(2, $landings['/']['next'][0]['sessions']);
        self::assertSame(0.5, $landings['/']['next'][0]['rate']);
        self::assertSame(1, $landings['/blog']['sessions']);
        self::assertSame(0, $landings['/blog']['exits']);

        self::assertSame('/ → /pricing', $result['top_paths'][0]['path']);
        self::assertSame(2, $result['top_paths'][0]['sessions']);
        $allPathKeys = array_column($result['top_paths'], 'path');
        self::assertContains('/blog → /blog/post-1 → /pricing', $allPathKeys);
        self::assertContains('/', $allPathKeys, '单页会话也算一条路径');
    }

    public function testTopLimitsAreApplied(): void
    {
        $sessionPaths = [];
        for ($i = 1; $i <= 30; $i++) {
            $sessionPaths['s' . $i] = ['/landing-' . $i, '/next-' . $i];
        }

        $result = $this->service->computeFromSessionPaths($sessionPaths, 3, 2, 4);
        self::assertCount(3, $result['landings']);
        self::assertCount(4, $result['top_paths']);
        self::assertSame(30, $result['total_sessions']);
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
                    ['session_id' => 's1', 'event' => 'page_view', 'url' => '/a', 'created_at' => '2026-01-15 10:00:00', 'pixel_id' => 1],
                    ['session_id' => 's1', 'event' => 'page_view', 'url' => '/b', 'created_at' => '2026-01-15 10:01:00', 'pixel_id' => 2],
                    ['session_id' => 's2', 'event' => 'page_view', 'url' => '/a', 'created_at' => '2026-01-15 11:00:00', 'pixel_id' => 3],
                ];
            }
        );

        self::assertTrue($result['window_clamped']);
        self::assertSame(8, $seen[0] ?? null);
        // 热短窗 7 天：from 钳到 to-6 天
        self::assertSame('2026-01-14', $seen[1] ?? null);
        self::assertSame('2026-01-20', $seen[2] ?? null);
        self::assertSame(2, $result['total_sessions']);
        self::assertSame(1, $result['bounced_sessions']);
        self::assertSame('/a', $result['landings'][0]['path']);
        self::assertSame(2, $result['landings'][0]['sessions']);
        self::assertSame('', $result['error']);
    }

    public function testSqlSelectsOnlyPageEventsWithScanCapAndOrder(): void
    {
        $from = new DateTimeImmutable('2026-07-20 00:00:00');
        $to = new DateTimeImmutable('2026-07-26 23:59:59');
        [$sql, $params] = $this->service->buildPageEventSql(3, $from, $to);

        self::assertStringContainsString("'page_view'", $sql);
        self::assertStringContainsString("'page_enter'", $sql);
        self::assertStringContainsString('ORDER BY', $sql);
        self::assertStringContainsString('LIMIT ' . PixelPathExplorationService::MAX_SCAN_ROWS, $sql);
        self::assertStringNotContainsString('view_item', $sql);
        self::assertStringNotContainsString('browser_info', $sql, '简版只用 url 扁平列，不拉大字段');
        self::assertSame(3, $params[':website_id']);
        self::assertSame('2026-07-20 00:00:00', $params[':start_date']);
        self::assertSame('2026-07-26 23:59:59', $params[':end_date']);
    }

    public function testDetailWiresPathExplorationCard(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertFileExists($root . '/Service/PixelPathExplorationService.php');

        $controller = (string)\file_get_contents($root . '/Controller/Backend/PixelDashboard.php');
        self::assertStringContainsString('PixelPathExplorationService', $controller);
        self::assertStringContainsString('path_exploration', $controller);
        self::assertStringContainsString('buildPathExploration', $controller);

        $detail = (string)\file_get_contents($root . '/view/templates/Backend/PixelDashboard/detail.phtml');
        self::assertStringContainsString('path-exploration', $detail);
        self::assertStringContainsString('路径探索', $detail);
        self::assertStringContainsString('Top 落地页 → 次页', $detail);
        self::assertStringContainsString('Top 路径序列', $detail);
    }
}
