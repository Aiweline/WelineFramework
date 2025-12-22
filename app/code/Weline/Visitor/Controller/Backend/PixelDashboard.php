<?php
declare(strict_types=1);

namespace Weline\Visitor\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Manager\Message;
use Weline\Visitor\Model\Pixel;

/**
 * 像素统计面板控制器
 * 
 * 功能：
 * - 像素数据统计展示
 * - 实时数据监控
 * - 站点统计
 */
#[Acl('Weline_Backend::pixel_dashboard', '像素统计面板', 'mdi-chart-line', '像素统计面板', 'Weline_Backend::dashboard')]
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
            /** @var Pixel $pixelModel */
            $pixelModel = ObjectManager::getInstance(Pixel::class);
            
            // 获取所有站点ID
            $websiteIds = Pixel::getAllWebsiteIds();
            
            // 获取统计数据
            $stats = [];
            $websiteStats = [];
            $totalValue = 0;
            $allEvents = [];
            
            foreach ($websiteIds as $websiteId) {
                $summary = Pixel::getWebsiteSummary($websiteId);
                $websiteStats[$websiteId] = $summary;
                
                // 累计统计
                $stats['total_count'] = ($stats['total_count'] ?? 0) + $summary['total_count'];
                $stats['un_deal_count'] = ($stats['un_deal_count'] ?? 0) + $summary['un_deal_count'];
                $stats['dealed_count'] = ($stats['dealed_count'] ?? 0) + $summary['dealed_count'];
                
                // 累计总价值
                $pixels = Pixel::getPixelsByWebsiteId($websiteId);
                foreach ($pixels as $pixel) {
                    $totalValue += (float)($pixel[Pixel::fields_VALUE] ?? 0);
                }
                
                // 收集所有事件
                foreach ($summary['event_list'] ?? [] as $event) {
                    if (!isset($allEvents[$event])) {
                        $allEvents[$event] = 0;
                    }
                    $allEvents[$event] += $summary['events'][$event] ?? 0;
                }
            }
            
            // 按事件数量排序，获取Top 10
            arsort($allEvents);
            $topEvents = array_slice($allEvents, 0, 10, true);
            
            // 获取最近7天的趋势数据
            $trends = $this->getTrends(7, null);
            
            // 获取最近24小时的实时数据（用于显示当前状态）
            $realtimeData = [];
            if (!empty($websiteIds)) {
                try {
                    $firstWebsiteId = $websiteIds[0];
                    $realtimeData = Pixel::getDashboardData($firstWebsiteId, 10, 24);
                } catch (\Exception $e) {
                    // 忽略实时数据获取错误
                }
            }
            
            $stats['total_value'] = $totalValue;
            $stats['event_types'] = count($allEvents);
            
            $this->assign('stats', $stats);
            $this->assign('website_stats', $websiteStats);
            $this->assign('website_ids', $websiteIds);
            $this->assign('trends', $trends);
            $this->assign('top_events', $topEvents);
            $this->assign('realtime_data', $realtimeData);
            
            return $this->fetch();
            
        } catch (\Exception $e) {
            Message::error(__('加载像素统计失败：%{1}', $e->getMessage()));
            $this->assign('stats', ['total_count' => 0, 'un_deal_count' => 0, 'dealed_count' => 0, 'total_value' => 0, 'event_types' => 0]);
            $this->assign('website_stats', []);
            $this->assign('website_ids', []);
            $this->assign('trends', []);
            $this->assign('top_events', []);
            $this->assign('realtime_data', []);
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
            $websiteId = (int)($this->request->getParam('websiteId') ?? $this->request->getGet('websiteId') ?? 0);
            
            if ($websiteId <= 0) {
                Message::error(__('站点ID无效'));
                return $this->redirect('*/pixel_dashboard/index');
            }
            
            // 获取站点统计摘要
            $summary = Pixel::getWebsiteSummary($websiteId);
            
            // 获取最近30天的商业价值数据
            $businessValue = Pixel::getBusinessValueByPeriod($websiteId, 'daily', null, null);
            
            // 获取最近7天的每日对比数据
            $dailyComparison = Pixel::getDailyComparisonData($websiteId, 7);
            
            // 获取实时大屏数据
            $dashboardData = Pixel::getDashboardData($websiteId, 10, 24);
            
            $this->assign('website_id', $websiteId);
            $this->assign('summary', $summary);
            $this->assign('business_value', $businessValue);
            $this->assign('daily_comparison', $dailyComparison);
            $this->assign('dashboard_data', $dashboardData);
            
            return $this->fetch();
            
        } catch (\Exception $e) {
            Message::error(__('加载站点详情失败：%{1}', $e->getMessage()));
            return $this->redirect('*/pixel_dashboard/index');
        }
    }
    
    /**
     * 获取实时数据（AJAX接口）
     * 
     * @return string
     */
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
                    ->where(Pixel::fields_WEBSITE_ID, $websiteId)
                    ->where(Pixel::fields_EVENT, $event);
                
                if ($startDate) {
                    $model->where(Pixel::fields_CREATED_AT, $startDate, '>=');
                }
                if ($endDate) {
                    $model->where(Pixel::fields_CREATED_AT, $endDate, '<=');
                }
                
                $count = (int)$model->count();
                
                // 计算总价值
                $valueModel = w_obj(Pixel::class)->reset()
                    ->where(Pixel::fields_WEBSITE_ID, $websiteId)
                    ->where(Pixel::fields_EVENT, $event);
                
                if ($startDate) {
                    $valueModel->where(Pixel::fields_CREATED_AT, $startDate, '>=');
                }
                if ($endDate) {
                    $valueModel->where(Pixel::fields_CREATED_AT, $endDate, '<=');
                }
                
                $pixels = $valueModel->select()->fetchArray();
                $totalValue = 0;
                foreach ($pixels as $pixel) {
                    $totalValue += (float)($pixel[Pixel::fields_VALUE] ?? 0);
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
    public function export(): string
    {
        try {
            $websiteId = (int)($this->request->getParam('websiteId') ?? $this->request->getGet('websiteId') ?? 0);
            $startDate = $this->request->getParam('startDate') ?? $this->request->getGet('startDate');
            $endDate = $this->request->getParam('endDate') ?? $this->request->getGet('endDate');
            $format = $this->request->getParam('format') ?? $this->request->getGet('format') ?? 'csv';
            
            $model = w_obj(Pixel::class)->reset();
            
            if ($websiteId > 0) {
                $model->where(Pixel::fields_WEBSITE_ID, $websiteId);
            }
            
            if ($startDate) {
                $model->where(Pixel::fields_CREATED_AT, $startDate, '>=');
            }
            if ($endDate) {
                $model->where(Pixel::fields_CREATED_AT, $endDate, '<=');
            }
            
            // 限制导出数量
            $limit = 10000;
            $data = $model->limit($limit)->select()->fetchArray();
            
            if ($format === 'json') {
                return $this->success(__('导出数据成功'), $data);
            }
            
            // CSV格式
            header('Content-Type: text/csv; charset=UTF-8');
            $filename = 'pixel_data_' . date('Y-m-d') . ($websiteId > 0 ? '_site_' . $websiteId : '') . '.csv';
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
     * 获取趋势数据（支持自定义时间范围）
     * 
     * @param int|null $days 天数，默认7天
     * @param int|null $websiteId 站点ID，null表示所有站点
     * @return array
     */
    private function getTrends(?int $days = null, ?int $websiteId = null): array
    {
        $days = $days ?? 7;
        $trends = [];
        $endDate = date('Y-m-d H:i:s');
        $startDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $websiteIds = $websiteId !== null ? [$websiteId] : Pixel::getAllWebsiteIds();
        
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $dayStart = $date . ' 00:00:00';
            $dayEnd = $date . ' 23:59:59';
            
            $dayCount = 0;
            $dayValue = 0;
            
            foreach ($websiteIds as $siteId) {
                $dayStats = Pixel::getWebsiteStatsByDateRange($siteId, $dayStart, $dayEnd);
                $dayCount += $dayStats['total_count'] ?? 0;
                
                // 计算当天的总价值
                $pixels = Pixel::getPixelsByWebsiteId($siteId, [
                    Pixel::fields_CREATED_AT => [
                        'operator' => '>=',
                        'value' => $dayStart
                    ]
                ]);
                
                $pixels = array_filter($pixels, function($pixel) use ($dayEnd) {
                    return ($pixel[Pixel::fields_CREATED_AT] ?? '') <= $dayEnd;
                });
                
                foreach ($pixels as $pixel) {
                    $dayValue += (float)($pixel[Pixel::fields_VALUE] ?? 0);
                }
            }
            
            $trends[] = [
                'date' => $date,
                'count' => $dayCount,
                'value' => $dayValue
            ];
        }
        
        return $trends;
    }
}

