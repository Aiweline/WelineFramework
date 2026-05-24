<?php
declare(strict_types=1);

namespace Weline\Visitor\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\MessageManager;
use Weline\Visitor\Model\Pixel;
use Weline\Visitor\Service\PixelStatisticsService;

/**
 * 像素统计面板控制器
 * 
 * 功能：
 * - 像素数据统计展示
 * - 实时数据监控
 * - 站点统计
 */
#[Acl('Weline_Visitor::pixel_dashboard', '像素统计面板', 'mdi-chart-line', '像素统计面板', 'Weline_Backend::pixel_dashboard')]
class PixelDashboard extends BackendController
{
    /**
     * 像素统计面板首页
     * 
     * @return string
     */
    #[Acl('Weline_Visitor::pixel_dashboard_index', '查看像素统计', 'mdi-chart-line', '查看像素统计')]
    public function index(): string
    {
        try {
            $dashboard = PixelStatisticsService::getEventListeningDashboard($this->getDashboardRequestFilters());
            $this->assignDashboardData($dashboard);
            
            return $this->fetch();
            
        } catch (\Exception $e) {
            MessageManager::error((string)__('加载像素统计失败：%{1}', [$e->getMessage()]));
            $this->assignDashboardData($this->getEmptyDashboardData());
            return $this->fetch();
        }
    }
    
    /**
     * 站点详情页面
     * 
     * @return string
     */
    #[Acl('Weline_Visitor::pixel_dashboard_detail', '查看站点详情', 'mdi-chart-line', '查看站点详情')]
    public function detail(): string
    {
        try {
            $websiteIdRaw = $this->request->getParam('websiteId') ?? $this->request->getGet('websiteId');
            if ($websiteIdRaw === null || $websiteIdRaw === '' || $websiteIdRaw === 'all' || !is_numeric($websiteIdRaw) || (int)$websiteIdRaw < 0) {
                MessageManager::error((string)__('站点ID无效'));
                return $this->redirect('*/pixel_dashboard/index');
            }

            $websiteId = (int)$websiteIdRaw;
            $filters = $this->getDashboardRequestFilters();
            $filters['websiteId'] = (string)$websiteId;
            $filters = array_filter($filters, static fn($value): bool => $value !== null && $value !== '');
            return $this->redirect('*/pixel_dashboard/index', $filters);
            
        } catch (\Exception $e) {
            MessageManager::error((string)__('加载站点详情失败：%{1}', [$e->getMessage()]));
            return $this->redirect('*/pixel_dashboard/index');
        }
    }
    
    /**
     * 获取实时数据（AJAX接口）
     * 
     * @return string
     */
    #[Acl('Weline_Visitor::pixel_dashboard_realtime', '查看像素实时数据', 'mdi-chart-line', '查看像素实时数据')]
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
    #[Acl('Weline_Visitor::pixel_dashboard_business_value', '查看像素商业价值', 'mdi-chart-line', '查看像素商业价值')]
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
    #[Acl('Weline_Visitor::pixel_dashboard_daily_comparison', '查看像素每日对比', 'mdi-chart-line', '查看像素每日对比')]
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
    #[Acl('Weline_Visitor::pixel_dashboard_event_stats', '查看像素事件统计', 'mdi-chart-line', '查看像素事件统计')]
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
     * 数据导出功能
     * 
     * @return string
     */
    #[Acl('Weline_Visitor::pixel_dashboard_export', '导出像素数据', 'mdi-download', '导出像素数据')]
    public function export(): string
    {
        try {
            $filters = PixelStatisticsService::normalizeDashboardFilters($this->getDashboardRequestFilters());
            $websiteId = $filters['website_id'];
            $startDate = $filters['start_date'];
            $endDate = $filters['end_date'];
            $event = $filters['event'];
            $format = $this->request->getParam('format') ?? $this->request->getGet('format') ?? 'csv';
            
            $model = w_obj(Pixel::class)->reset();
            
            if ($websiteId !== null) {
                $model->where(Pixel::schema_fields_WEBSITE_ID, $websiteId);
            }

            if ($event !== null) {
                $model->where(Pixel::schema_fields_EVENT, $event);
            }

            $model->where(Pixel::schema_fields_CREATED_AT, $startDate, '>=');
            $model->where(Pixel::schema_fields_CREATED_AT, $endDate, '<=');
            
            // 限制导出数量
            $limit = 10000;
            $data = $model->limit($limit)->select()->fetchArray();
            
            if ($format === 'json') {
                return $this->success(__('导出数据成功'), $data);
            }
            
            // CSV格式
            header('Content-Type: text/csv; charset=UTF-8');
            $filename = 'pixel_data_' . date('Y-m-d') . ($websiteId !== null ? '_site_' . $websiteId : '') . ($event !== null ? '_event_' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', $event) : '') . '.csv';
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            
            $output = fopen('php://output', 'w');
            
            // 添加BOM以支持Excel中文显示
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // 写入表头
            if (!empty($data)) {
                $headers = array_keys($data[0]);
                fputcsv($output, $headers);
                
                // 写入数据
                foreach ($data as $row) {
                    $orderedRow = [];
                    foreach ($headers as $header) {
                        $orderedRow[] = $row[$header] ?? '';
                    }
                    fputcsv($output, $orderedRow);
                }
            } else {
                $defaultHeaders = [
                    'pixel_id', 'url', 'module', 'name', 'referer', 'source',
                    'user_id', 'user_agent', 'ip', 'event', 'website_id',
                    'lang', 'currency', 'value', 'browser_info', 'cron_deal', 'created_at'
                ];
                fputcsv($output, $defaultHeaders);
            }
            
            fclose($output);
            return '';
            
        } catch (\Exception $e) {
            return $this->error(__('导出数据失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getDashboardRequestFilters(): array
    {
        return [
            'websiteId' => $this->request->getParam('websiteId') ?? $this->request->getGet('websiteId'),
            'event' => $this->request->getParam('event') ?? $this->request->getGet('event'),
            'range' => $this->request->getParam('range') ?? $this->request->getGet('range'),
            'startDate' => $this->request->getParam('startDate') ?? $this->request->getGet('startDate'),
            'endDate' => $this->request->getParam('endDate') ?? $this->request->getGet('endDate'),
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
        $this->assign('realtime_rows', $dashboard['realtime_rows'] ?? []);
        $this->assign('recent_events', $dashboard['recent_events'] ?? []);
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
            'realtime_rows' => [],
            'recent_events' => [],
        ];
    }
}

