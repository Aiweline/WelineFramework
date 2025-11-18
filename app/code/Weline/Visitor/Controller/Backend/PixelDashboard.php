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
            
            foreach ($websiteIds as $websiteId) {
                $summary = Pixel::getWebsiteSummary($websiteId);
                $websiteStats[$websiteId] = $summary;
                
                // 累计统计
                $stats['total_count'] = ($stats['total_count'] ?? 0) + $summary['total_count'];
                $stats['un_deal_count'] = ($stats['un_deal_count'] ?? 0) + $summary['un_deal_count'];
                $stats['dealed_count'] = ($stats['dealed_count'] ?? 0) + $summary['dealed_count'];
            }
            
            // 获取最近7天的趋势数据
            $trends = $this->getTrends();
            
            $this->assign('stats', $stats);
            $this->assign('website_stats', $websiteStats);
            $this->assign('website_ids', $websiteIds);
            $this->assign('trends', $trends);
            
            return $this->fetch();
            
        } catch (\Exception $e) {
            Message::error(__('加载像素统计失败：%{1}', $e->getMessage()));
            $this->assign('stats', ['total_count' => 0, 'un_deal_count' => 0, 'dealed_count' => 0]);
            $this->assign('website_stats', []);
            $this->assign('website_ids', []);
            $this->assign('trends', []);
            return $this->fetch();
        }
    }
    
    /**
     * 获取趋势数据（最近7天）
     * 
     * @return array
     */
    private function getTrends(): array
    {
        $trends = [];
        $endDate = date('Y-m-d H:i:s');
        $startDate = date('Y-m-d H:i:s', strtotime('-7 days'));
        
        $websiteIds = Pixel::getAllWebsiteIds();
        
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $dayStart = $date . ' 00:00:00';
            $dayEnd = $date . ' 23:59:59';
            
            $dayCount = 0;
            foreach ($websiteIds as $websiteId) {
                $dayStats = Pixel::getWebsiteStatsByDateRange($websiteId, $dayStart, $dayEnd);
                $dayCount += $dayStats['total_count'] ?? 0;
            }
            
            $trends[] = [
                'date' => $date,
                'count' => $dayCount,
            ];
        }
        
        return $trends;
    }
}

