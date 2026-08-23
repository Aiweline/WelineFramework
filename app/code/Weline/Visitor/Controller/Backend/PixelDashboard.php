<?php
declare(strict_types=1);

namespace Weline\Visitor\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\MessageManager;
use Weline\Visitor\Model\Pixel;
use Weline\Visitor\Service\PixelAnalyticsInsightService;
use Weline\Visitor\Service\PixelColdArchiveQueryService;
use Weline\Visitor\Service\PixelEcommerceFunnelService;
use Weline\Visitor\Service\PixelEcommerceItemPerformanceService;
use Weline\Visitor\Service\PixelEcommercePurchaseRevenueService;
use Weline\Visitor\Service\PixelPathExplorationService;
use Weline\Visitor\Service\PixelRetentionService;
use Weline\Visitor\Service\PixelStatisticsService;
use Weline\Visitor\Service\Report\PixelDetailReportTabService;

/**
 * 像素统计面板控制器
 * 
 * 功能：
 * - 像素数据统计展示
 * - 实时数据监控
 * - 站点统计
 */
#[Acl('Weline_Visitor::pixel_dashboard', '像素统计', 'chart', '像素统计', 'Weline_Backend::data_tools_group')]
class PixelDashboard extends BackendController
{
    /**
     * 像素统计面板首页
     * 
     * @return string
     */
    #[Acl('Weline_Visitor::pixel_dashboard_index', '查看像素统计', 'chart', '查看像素统计')]
    public function index(): string
    {
        try {
            $dashboard = PixelStatisticsService::getEventListeningDashboard($this->getDashboardRequestFilters());
            $this->assignDashboardData($dashboard);
            $this->assignWebsiteSelectOptions();

            return $this->fetch();
            
        } catch (\Exception $e) {
            MessageManager::error((string)__('加载像素统计失败：%{1}', [$e->getMessage()]));
            $this->assignDashboardData($this->getEmptyDashboardData());
            $this->assignWebsiteSelectOptions();
            return $this->fetch();
        }
    }
    
    /**
     * 站点详情页面（GA4 风格洞察报表）
     */
    #[Acl('Weline_Visitor::pixel_dashboard_detail', '查看站点详情', 'chart', '查看站点详情')]
    public function detail(): string
    {
        try {
            $filters = $this->getDashboardRequestFilters();
            $websiteIdRaw = $this->request->getParam('websiteId')
                ?? $this->request->getGet('websiteId')
                ?? $this->request->getParam('website_id')
                ?? $this->request->getGet('website_id')
                ?? ($filters['websiteId'] ?? null);

            if ($websiteIdRaw === null || $websiteIdRaw === '' || $websiteIdRaw === 'all' || !is_numeric($websiteIdRaw) || (int)$websiteIdRaw < 0) {
                MessageManager::error((string)__('请选择站点后再查看详情报表'));
                return $this->redirect('*/pixel-dashboard/index', array_filter($filters, static fn($v) => $v !== null && $v !== ''));
            }

            $websiteId = (int)$websiteIdRaw;
            $filters['websiteId'] = (string)$websiteId;
            $filters = array_filter($filters, static fn($value): bool => $value !== null && $value !== '');

            $dashboard = PixelStatisticsService::getEventListeningDashboard($filters);
            /** @var PixelAnalyticsInsightService $insight */
            $insight = w_obj(PixelAnalyticsInsightService::class);
            $report = $insight->buildReport($filters);

            // D07：detail 引擎 Tab（D07a–D07f：catalog 六个预设全部挂完）
            $reportTabs = $this->buildDetailReportTabs($filters, $websiteId);
            $requestedTab = trim((string)($this->request->getParam('report_tab')
                ?? $this->request->getGet('report_tab')
                ?? ''));
            $activeReportTab = PixelDetailReportTabService::FIRST_TAB_CODE;
            foreach ($reportTabs as $tab) {
                if (($tab['code'] ?? '') === $requestedTab) {
                    $activeReportTab = $requestedTab;
                    break;
                }
            }

            $this->assignDashboardData($dashboard);
            $this->assign('website_id', $websiteId);
            $this->assign('insight', $report);
            $this->assign('engagement', $report['engagement'] ?? []);
            $this->assign('insight_pages', $report['pages'] ?? []);
            $this->assign('insight_devices', $report['devices'] ?? []);
            $this->assign('insight_screens', $report['screens'] ?? []);
            $this->assign('insight_sources', $report['sources'] ?? []);
            $this->assign('insight_browsers', $report['browsers'] ?? []);
            $this->assign('insight_recent', $report['recent_events'] ?? []);
            $this->assign('report_tabs', $reportTabs);
            $this->assign('active_report_tab', $activeReportTab);
            $this->assignWebsiteSelectOptions();
            // F01：字典电商四步漏斗（热；与 B12 渠道营销简漏斗隔离）
            $this->assign('ecommerce_funnel', $this->buildEcommerceFunnel($filters, $websiteId));
            // F02：购成 / 收入（仅购买类 value）
            $this->assign('ecommerce_revenue', $this->buildEcommerceRevenue($filters, $websiteId));
            // F03：商品表现（items 展开）
            $this->assign('ecommerce_items', $this->buildEcommerceItems($filters, $websiteId));
            // F04a：路径探索（简版：落地 → 次页，限深 3）
            $this->assign('path_exploration', $this->buildPathExploration($filters, $websiteId));
            // F04b：留存分析（简版：日队列 Day0–Day6）
            $this->assign('retention', $this->buildRetention($filters, $websiteId));

            return $this->fetch();
        } catch (\Exception $e) {
            MessageManager::error((string)__('加载站点详情失败：%{1}', [$e->getMessage()]));
            return $this->redirect('*/pixel-dashboard/index');
        }
    }

    /**
     * F01：站点详情报表电商漏斗（热短窗）。
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function buildEcommerceFunnel(array $filters, int $websiteId): array
    {
        $empty = [
            'website_id' => $websiteId,
            'from' => '',
            'to' => '',
            'window_clamped' => false,
            'steps' => [],
            'step1_sessions' => 0,
            'scored_sessions' => 0,
            'error' => '',
        ];
        try {
            $start = trim((string)($filters['start_date'] ?? ''));
            $end = trim((string)($filters['end_date'] ?? ''));
            if ($start === '' || $end === '') {
                $empty['error'] = 'missing date range';

                return $empty;
            }

            /** @var PixelEcommerceFunnelService $funnelService */
            $funnelService = w_obj(PixelEcommerceFunnelService::class);
            $funnel = $funnelService->buildForWebsite($websiteId, $start, $end);
            if ($funnel['error'] !== '') {
                MessageManager::warning((string)__('电商漏斗暂不可用：%{1}', [$funnel['error']]));
            }

            return $funnel;
        } catch (\Throwable $throwable) {
            $empty['error'] = $throwable->getMessage();
            MessageManager::warning((string)__('电商漏斗暂不可用：%{1}', [$empty['error']]));

            return $empty;
        }
    }

    /**
     * F02：站点详情报表购成与收入（热短窗；仅购买类）。
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function buildEcommerceRevenue(array $filters, int $websiteId): array
    {
        $empty = [
            'website_id' => $websiteId,
            'from' => '',
            'to' => '',
            'window_clamped' => false,
            'purchases' => 0,
            'purchase_revenue' => 0.0,
            'avg_order_value' => 0.0,
            'purchase_sessions' => 0,
            'view_item_sessions' => 0,
            'purchase_rate_from_view_item' => 0.0,
            'non_purchase_value_ignored' => 0.0,
            'by_channel' => [],
            'by_day' => [],
            'error' => '',
        ];
        try {
            $start = trim((string)($filters['start_date'] ?? ''));
            $end = trim((string)($filters['end_date'] ?? ''));
            if ($start === '' || $end === '') {
                $empty['error'] = 'missing date range';

                return $empty;
            }

            /** @var PixelEcommercePurchaseRevenueService $revenueService */
            $revenueService = w_obj(PixelEcommercePurchaseRevenueService::class);
            $revenue = $revenueService->buildForWebsite($websiteId, $start, $end);
            if ($revenue['error'] !== '') {
                MessageManager::warning((string)__('购成收入暂不可用：%{1}', [$revenue['error']]));
            }

            return $revenue;
        } catch (\Throwable $throwable) {
            $empty['error'] = $throwable->getMessage();
            MessageManager::warning((string)__('购成收入暂不可用：%{1}', [$empty['error']]));

            return $empty;
        }
    }

    /**
     * F03：站点详情报表商品表现（items 展开；热短窗）。
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function buildEcommerceItems(array $filters, int $websiteId): array
    {
        $empty = [
            'website_id' => $websiteId,
            'from' => '',
            'to' => '',
            'window_clamped' => false,
            'items' => [],
            'item_count' => 0,
            'error' => '',
        ];
        try {
            $start = trim((string)($filters['start_date'] ?? ''));
            $end = trim((string)($filters['end_date'] ?? ''));
            if ($start === '' || $end === '') {
                $empty['error'] = 'missing date range';

                return $empty;
            }

            /** @var PixelEcommerceItemPerformanceService $itemService */
            $itemService = w_obj(PixelEcommerceItemPerformanceService::class);
            $report = $itemService->buildForWebsite($websiteId, $start, $end);
            if ($report['error'] !== '') {
                MessageManager::warning((string)__('商品表现暂不可用：%{1}', [$report['error']]));
            }

            return $report;
        } catch (\Throwable $throwable) {
            $empty['error'] = $throwable->getMessage();
            MessageManager::warning((string)__('商品表现暂不可用：%{1}', [$empty['error']]));

            return $empty;
        }
    }

    /**
     * F04a：站点详情报表路径探索（简版；热短窗；落地 → 次页限深 3）。
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function buildPathExploration(array $filters, int $websiteId): array
    {
        $empty = [
            'website_id' => $websiteId,
            'from' => '',
            'to' => '',
            'window_clamped' => false,
            'total_sessions' => 0,
            'bounced_sessions' => 0,
            'landings' => [],
            'top_paths' => [],
            'max_depth' => PixelPathExplorationService::MAX_DEPTH,
            'error' => '',
        ];
        try {
            $start = trim((string)($filters['start_date'] ?? ''));
            $end = trim((string)($filters['end_date'] ?? ''));
            if ($start === '' || $end === '') {
                $empty['error'] = 'missing date range';

                return $empty;
            }

            /** @var PixelPathExplorationService $pathService */
            $pathService = w_obj(PixelPathExplorationService::class);
            $report = $pathService->buildForWebsite($websiteId, $start, $end);
            if ($report['error'] !== '') {
                MessageManager::warning((string)__('路径探索暂不可用：%{1}', [$report['error']]));
            }

            return $report;
        } catch (\Throwable $throwable) {
            $empty['error'] = $throwable->getMessage();
            MessageManager::warning((string)__('路径探索暂不可用：%{1}', [$empty['error']]));

            return $empty;
        }
    }

    /**
     * F04b：站点详情报表留存分析（简版；热短窗；日队列）。
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function buildRetention(array $filters, int $websiteId): array
    {
        $empty = [
            'website_id' => $websiteId,
            'from' => '',
            'to' => '',
            'window_clamped' => false,
            'total_visitors' => 0,
            'returning_visitors' => 0,
            'returning_rate' => 0.0,
            'd1_rate' => 0.0,
            'd1_eligible' => 0,
            'd1_retained' => 0,
            'offsets' => [],
            'cohorts' => [],
            'offset_summary' => [],
            'error' => '',
        ];
        try {
            $start = trim((string)($filters['start_date'] ?? ''));
            $end = trim((string)($filters['end_date'] ?? ''));
            if ($start === '' || $end === '') {
                $empty['error'] = 'missing date range';

                return $empty;
            }

            /** @var PixelRetentionService $retentionService */
            $retentionService = w_obj(PixelRetentionService::class);
            $report = $retentionService->buildForWebsite($websiteId, $start, $end);
            if ($report['error'] !== '') {
                MessageManager::warning((string)__('留存分析暂不可用：%{1}', [$report['error']]));
            }

            return $report;
        } catch (\Throwable $throwable) {
            $empty['error'] = $throwable->getMessage();
            MessageManager::warning((string)__('留存分析暂不可用：%{1}', [$empty['error']]));

            return $empty;
        }
    }

    /**
     * D07：构建 detail 已挂载报表 Tab（D07a–D07f：catalog 六个预设全部挂完）。
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    private function buildDetailReportTabs(array $filters, int $websiteId): array
    {
        try {
            $start = trim((string)($filters['start_date'] ?? ''));
            $end = trim((string)($filters['end_date'] ?? ''));
            if ($start === '' || $end === '') {
                return [];
            }

            $from = new \DateTimeImmutable($start);
            $to = new \DateTimeImmutable($end);
            $tabService = w_obj(PixelDetailReportTabService::class);

            $rowProvider = static function (array $ctx) use ($filters, $websiteId): array {
                $scoped = $filters;
                $scoped['website_id'] = $websiteId;
                $route = $ctx['route'] ?? [];
                if (isset($route['from'], $route['to'])) {
                    $scoped['start_date'] = (string)$route['from'];
                    $scoped['end_date'] = (string)$route['to'];
                }

                return PixelStatisticsService::fetchHotReportEventRows($scoped, 20000);
            };

            return $tabService->buildMountedTabs($from, $to, $websiteId, $rowProvider);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * C01–C03：像素事件明细 list（短横线路由；热表分页 + 归因筛选表单）。
     */
    #[Acl('Weline_Visitor::pixel_dashboard_list', '查看像素事件列表', 'table', '查看像素事件明细列表')]
    public function list(): string
    {
        $filters = $this->getDashboardRequestFilters();
        $page = (int)($this->request->getParam('page') ?? $this->request->getGet('page') ?? 1);
        $pageSize = (int)($this->request->getParam('pageSize')
            ?? $this->request->getGet('pageSize')
            ?? PixelStatisticsService::LIST_DEFAULT_PAGE_SIZE);

        $result = PixelStatisticsService::getDashboardEventListPage($filters, $page, $pageSize);
        if ($result['error'] !== '') {
            MessageManager::warning((string)__('事件列表暂不可用：%{1}', [$result['error']]));
        }

        $displayFilters = $result['filters'];
        if ($displayFilters === []) {
            $displayFilters = $this->buildListDisplayFilters($filters);
        }

        $this->assign('page_title', (string)__('像素事件列表'));
        $this->assign('rows', $result['rows']);
        $this->assign('filters', $displayFilters);
        $this->assign('traffic_type_options', \Weline\Visitor\Model\PixelChannel::TRAFFIC_TYPES);
        $this->assignWebsiteSelectOptions();
        $this->assign('pagination', [
            'page' => $result['page'],
            'page_size' => $result['page_size'],
            'page_count' => $result['page_count'],
            'total' => $result['total'],
        ]);
        $this->assign('list_ready', true);
        $this->assign('list_error', $result['error']);

        return $this->fetch('list');
    }

    /**
     * G09：冷归档明细 list（显式入口；必选站点 + ≤31 天 + 分页）。
     */
    #[Acl('Weline_Visitor::pixel_dashboard_archive_list', '查看冷归档像素事件', 'circle', '查看冷归档像素事件明细')]
    public function archiveList(): string
    {
        $filters = $this->getDashboardRequestFilters();
        $page = (int)($this->request->getParam('page') ?? $this->request->getGet('page') ?? 1);
        $pageSize = (int)($this->request->getParam('pageSize')
            ?? $this->request->getGet('pageSize')
            ?? PixelColdArchiveQueryService::DEFAULT_PAGE_SIZE);

        /** @var PixelColdArchiveQueryService $coldQuery */
        $coldQuery = w_obj(PixelColdArchiveQueryService::class);
        $result = $coldQuery->queryPage($filters, $page, $pageSize);
        if ($result['error'] !== '') {
            MessageManager::warning((string)__('冷归档列表不可用：%{1}', [$this->translateColdArchiveError($result['error'])]));
        }

        $displayFilters = $result['filters'];
        if (($displayFilters['website_id'] ?? null) === null && ($displayFilters['website_id_raw'] ?? '') === '') {
            $displayFilters = array_merge($this->buildListDisplayFilters($filters), [
                'page' => $result['page'],
                'page_size' => $result['page_size'],
            ]);
        }

        $this->assign('page_title', (string)__('冷归档事件列表'));
        $this->assign('rows', $result['rows']);
        $this->assign('filters', $displayFilters);
        $this->assign('traffic_type_options', \Weline\Visitor\Model\PixelChannel::TRAFFIC_TYPES);
        $this->assignWebsiteSelectOptions();
        $this->assign('pagination', [
            'page' => $result['page'],
            'page_size' => $result['page_size'],
            'page_count' => $result['page_count'],
            'total' => $result['total'],
        ]);
        $this->assign('list_ready', true);
        $this->assign('list_error', $result['error']);
        $this->assign('max_window_days', PixelColdArchiveQueryService::MAX_WINDOW_DAYS);

        return $this->fetch('archive_list');
    }

    /**
     * 将冷查服务英文拒绝码转为可读文案。
     */
    private function translateColdArchiveError(string $error): string
    {
        if (str_contains($error, 'requires website_id')) {
            return (string)__('请选择站点后再查询冷归档（website_id=0 为系统默认站）');
        }
        if (str_contains($error, 'window exceeds')) {
            return (string)__('冷归档查询时间范围不能超过 %{1} 天', [(string)PixelColdArchiveQueryService::MAX_WINDOW_DAYS]);
        }

        return $error;
    }
    
    /**
     * 获取实时数据（AJAX接口）
     * 
     * @return string
     */
    #[Acl('Weline_Visitor::pixel_dashboard_realtime', '查看像素实时数据', 'chart', '查看像素实时数据')]
    public function getRealtimeData(): string
    {
        try {
            $websiteId = (int)($this->request->getParam('websiteId') ?? $this->request->getGet('websiteId') ?? 0);
            $interval = (int)($this->request->getParam('interval') ?? $this->request->getGet('interval') ?? 10);
            $hours = (int)($this->request->getParam('hours') ?? $this->request->getGet('hours') ?? 24);
            
            if (!in_array($interval, [10, 30])) {
                $interval = 10;
            }
            
            $data = Pixel::getDashboardData($websiteId, $interval, $hours);
            
            return $this->success(__('获取实时数据成功'), $data);
            
        } catch (\Exception $e) {
            return $this->error(__('获取实时数据失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }
    
    /**
     * 获取商业价值分析数据（AJAX接口）
     * 
     * @return string
     */
    #[Acl('Weline_Visitor::pixel_dashboard_business_value', '查看像素商业价值', 'chart', '查看像素商业价值')]
    public function getBusinessValue(): string
    {
        try {
            $websiteId = (int)($this->request->getParam('websiteId') ?? $this->request->getGet('websiteId') ?? 0);
            $period = $this->request->getParam('period') ?? $this->request->getGet('period') ?? 'daily';
            $startDate = $this->request->getParam('startDate') ?? $this->request->getGet('startDate');
            $endDate = $this->request->getParam('endDate') ?? $this->request->getGet('endDate');
            
            $allowedPeriods = ['daily', 'weekly', 'monthly', 'quarterly', 'yearly'];
            if (!in_array($period, $allowedPeriods)) {
                return $this->error(__('时间维度参数错误，支持：%{1}', [implode(', ', $allowedPeriods)]), '', 400);
            }
            
            $data = Pixel::getBusinessValueByPeriod($websiteId, $period, $startDate, $endDate);
            
            return $this->success(__('获取商业价值分析成功'), $data);
            
        } catch (\Exception $e) {
            return $this->error(__('获取商业价值分析失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }
    
    /**
     * 获取每日对比数据（AJAX接口）
     * 
     * @return string
     */
    #[Acl('Weline_Visitor::pixel_dashboard_daily_comparison', '查看像素每日对比', 'chart', '查看像素每日对比')]
    public function getDailyComparison(): string
    {
        try {
            $websiteId = (int)($this->request->getParam('websiteId') ?? $this->request->getGet('websiteId') ?? 0);
            $days = (int)($this->request->getParam('days') ?? $this->request->getGet('days') ?? 7);
            
            $data = Pixel::getDailyComparisonData($websiteId, $days);
            
            return $this->success(__('获取每日对比数据成功'), $data);
            
        } catch (\Exception $e) {
            return $this->error(__('获取每日对比数据失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }
    
    /**
     * 获取事件统计详情（AJAX接口）
     * 
     * @return string
     */
    #[Acl('Weline_Visitor::pixel_dashboard_event_stats', '查看像素事件统计', 'chart', '查看像素事件统计')]
    public function getEventStats(): string
    {
        try {
            $websiteId = (int)($this->request->getParam('websiteId') ?? $this->request->getGet('websiteId') ?? 0);
            $startDate = $this->request->getParam('startDate') ?? $this->request->getGet('startDate');
            $endDate = $this->request->getParam('endDate') ?? $this->request->getGet('endDate');
            
            // 获取事件列表
            $eventList = Pixel::getEventsByWebsiteId($websiteId);
            
            // 获取每个事件的统计
            $eventStats = [];
            foreach ($eventList as $event) {
                $model = w_obj(Pixel::class)->reset()
                    ->where(Pixel::schema_fields_WEBSITE_ID, $websiteId)
                    ->where(Pixel::schema_fields_EVENT, $event);
                
                if ($startDate) {
                    $model->where(Pixel::schema_fields_CREATED_AT, $startDate, '>=');
                }
                if ($endDate) {
                    $model->where(Pixel::schema_fields_CREATED_AT, $endDate, '<=');
                }
                
                $count = (int)$model->count();
                
                // 计算总价值
                $valueModel = w_obj(Pixel::class)->reset()
                    ->where(Pixel::schema_fields_WEBSITE_ID, $websiteId)
                    ->where(Pixel::schema_fields_EVENT, $event);
                
                if ($startDate) {
                    $valueModel->where(Pixel::schema_fields_CREATED_AT, $startDate, '>=');
                }
                if ($endDate) {
                    $valueModel->where(Pixel::schema_fields_CREATED_AT, $endDate, '<=');
                }
                
                $pixels = $valueModel->select()->fetchArray();
                $totalValue = 0;
                foreach ($pixels as $pixel) {
                    $totalValue += (float)($pixel[Pixel::schema_fields_VALUE] ?? 0);
                }
                
                $eventStats[] = [
                    'event' => $event,
                    'count' => $count,
                    'total_value' => $totalValue,
                    'avg_value' => $count > 0 ? round($totalValue / $count, 2) : 0
                ];
            }
            
            // 按数量排序
            usort($eventStats, function($a, $b) {
                return $b['count'] - $a['count'];
            });
            
            return $this->success(__('获取事件统计成功'), [
                'website_id' => $websiteId,
                'events' => $eventStats,
                'total_events' => count($eventStats)
            ]);
            
        } catch (\Exception $e) {
            return $this->error(__('获取事件统计失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }
    
    /**
     * C04：数据导出（与 list 同筛选条件，含渠道/UTM 归因列）。
     */
    #[Acl('Weline_Visitor::pixel_dashboard_export', '导出像素数据', 'download', '导出像素数据')]
    public function export(): string
    {
        try {
            $requestFilters = $this->getDashboardRequestFilters();
            $format = $this->request->getParam('format') ?? $this->request->getGet('format') ?? 'csv';

            $result = PixelStatisticsService::getDashboardEventExportRows($requestFilters);
            if ($result['error'] !== '') {
                return $this->error(__('导出数据失败：%{1}', [$result['error']]), '', 500);
            }

            $rows = $result['rows'];
            $columns = $result['columns'];

            if ($format === 'json') {
                return $this->success(__('导出数据成功'), $rows);
            }

            $filters = $result['filters'];
            $websiteId = $filters['website_id'];
            $event = $filters['event'];
            $channelCode = (string)($filters['channel_code'] ?? '');

            header('Content-Type: text/csv; charset=UTF-8');
            $filename = 'pixel_data_' . date('Y-m-d')
                . ($websiteId !== null ? '_site_' . $websiteId : '')
                . ($event !== null ? '_event_' . $this->slugForFilename($event) : '')
                . ($channelCode !== '' ? '_channel_' . $this->slugForFilename($channelCode) : '')
                . '.csv';
            header('Content-Disposition: attachment; filename="' . $filename . '"');

            $output = fopen('php://output', 'w');

            // BOM 让 Excel 正确识别 UTF-8
            fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($output, $columns);
            foreach ($rows as $row) {
                $orderedRow = [];
                foreach ($columns as $column) {
                    $orderedRow[] = $row[$column] ?? '';
                }
                fputcsv($output, $orderedRow);
            }

            fclose($output);

            return '';
        } catch (\Exception $e) {
            return $this->error(__('导出数据失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }

    private function slugForFilename(string $value): string
    {
        return (string)preg_replace('/[^a-zA-Z0-9_-]+/', '_', $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function getDashboardRequestFilters(): array
    {
        $pick = function (string $key) {
            return $this->request->getParam($key) ?? $this->request->getGet($key);
        };

        return [
            'websiteId' => $pick('websiteId'),
            'event' => $pick('event'),
            'range' => $pick('range'),
            'startDate' => $pick('startDate'),
            'endDate' => $pick('endDate'),
            // C03a：query 透传；表单 UI 见 C03
            'channel_code' => $pick('channel_code') ?? $pick('channelCode'),
            'traffic_type' => $pick('traffic_type') ?? $pick('trafficType'),
            'utm_source' => $pick('utm_source') ?? $pick('utmSource'),
            'utm_medium' => $pick('utm_medium') ?? $pick('utmMedium'),
            'utm_campaign' => $pick('utm_campaign') ?? $pick('utmCampaign'),
        ];
    }

    /**
     * C03：归一化失败时仍回填表单展示值（不抛错）。
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function buildListDisplayFilters(array $raw): array
    {
        try {
            $attr = PixelStatisticsService::normalizeAttributionFilters($raw);
        } catch (\Throwable) {
            $attr = [
                'channel_code' => null,
                'traffic_type' => null,
                'utm_source' => null,
                'utm_medium' => null,
                'utm_campaign' => null,
            ];
        }

        $event = trim((string)($raw['event'] ?? ''));
        $range = trim((string)($raw['range'] ?? '30d'));
        if ($range === '') {
            $range = '30d';
        }
        $startDay = trim((string)($raw['startDate'] ?? $raw['start_date'] ?? ''));
        $endDay = trim((string)($raw['endDate'] ?? $raw['end_date'] ?? ''));

        return [
            'website_id' => null,
            'website_id_raw' => trim((string)($raw['websiteId'] ?? $raw['website_id'] ?? '')),
            'event' => $event !== '' ? $event : null,
            'range' => $range,
            'start_date' => $startDay !== '' ? $startDay . ' 00:00:00' : '',
            'end_date' => $endDay !== '' ? $endDay . ' 23:59:59' : '',
            'start_day' => $startDay,
            'end_day' => $endDay,
            'day_count' => 0,
            'channel_code' => $attr['channel_code'],
            'traffic_type' => $attr['traffic_type'],
            'utm_source' => $attr['utm_source'],
            'utm_medium' => $attr['utm_medium'],
            'utm_campaign' => $attr['utm_campaign'],
        ];
    }

    /**
     * @param array<string, mixed> $dashboard
     * @return void
     */
    private function assignDashboardData(array $dashboard): void
    {
        $this->assign('dashboard', $dashboard);
        $this->assign('filters', $dashboard['filters'] ?? []);
        $this->assign('website_options', $dashboard['website_options'] ?? []);
        $this->assign('event_options', $dashboard['event_options'] ?? []);
        $this->assign('summary', $dashboard['summary'] ?? []);
        $this->assign('trend', $dashboard['trend'] ?? []);
        $this->assign('event_rows', $dashboard['event_rows'] ?? []);
        $this->assign('site_rows', $dashboard['site_rows'] ?? []);
        $this->assign('source_rows', $dashboard['source_rows'] ?? []);
        $this->assign('channel_rows', $dashboard['channel_rows'] ?? []);
        $this->assign('realtime_rows', $dashboard['realtime_rows'] ?? []);
        $this->assign('recent_events', $dashboard['recent_events'] ?? []);
    }

    private function assignWebsiteSelectOptions(): void
    {
        $options = PixelStatisticsService::buildWebsiteSelectOptions();
        $this->assign('website_select_options', $options);
        $this->assign(
            'website_select_options_json',
            \json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function getEmptyDashboardData(): array
    {
        $filters = PixelStatisticsService::normalizeDashboardFilters([]);
        return [
            'filters' => $filters,
            'website_options' => [],
            'event_options' => [],
            'summary' => [
                'total_events' => 0,
                'active_sites' => 0,
                'event_types' => 0,
                'active_users' => 0,
                'total_value' => 0.0,
                'avg_value' => 0.0,
                'un_deal_count' => 0,
                'dealed_count' => 0,
                'value_event_count' => 0,
                'previous_total_events' => 0,
                'event_change' => 0.0,
                'events_per_user' => 0.0,
                'value_event_rate' => 0.0,
                'processed_rate' => 0.0,
                'first_seen' => null,
                'last_seen' => null,
            ],
            'trend' => [],
            'event_rows' => [],
            'site_rows' => [],
            'source_rows' => [],
            'channel_rows' => [],
            'realtime_rows' => [],
            'recent_events' => [],
        ];
    }
}
