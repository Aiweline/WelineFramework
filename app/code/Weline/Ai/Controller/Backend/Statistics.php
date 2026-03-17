<?php

declare(strict_types=1);

namespace Weline\Ai\Controller\Backend;

use Weline\Admin\Controller\BaseController;
use Weline\Ai\Service\UsageStatisticsService;
use Weline\Framework\Acl\Acl;

/**
 * AI 使用量统计
 * 每日/每周/每月/每年汇总，按模型倒序排名（tokens 最多在前）
 *
 * @package Weline_Ai
 */
#[Acl('Weline_Ai::ai_statistics', 'AI使用统计', 'mdi-chart-box-outline', 'AI使用量统计面板', 'Weline_Backend::ai_group')]
class Statistics extends BaseController
{
    /**
     * 统计页（第一个 Tab：统计面板）
     */
    #[Acl('Weline_Ai::ai_statistics_index', '查看AI使用统计', 'mdi-chart-line', '查看AI使用量统计')]
    public function index()
    {
        if ($this->request->getParam('embed') === '1') {
            $this->layoutType = 'default.blank';
        }
        $period = $this->request->getGet('period', UsageStatisticsService::PERIOD_DAY);
        if (!in_array($period, [
            UsageStatisticsService::PERIOD_DAY,
            UsageStatisticsService::PERIOD_WEEK,
            UsageStatisticsService::PERIOD_MONTH,
            UsageStatisticsService::PERIOD_YEAR,
        ], true)) {
            $period = UsageStatisticsService::PERIOD_DAY;
        }
        $service = \Weline\Framework\Manager\ObjectManager::getInstance(UsageStatisticsService::class);
        $this->assign('activeTab', 'statistics');
        $this->assign('period', $period);
        $this->assign('summary', $service->getPeriodSummary($period));
        $this->assign('modelRanking', $service->getModelRanking($period));
        $this->assign('periodRange', $service->getPeriodRange($period));
        return $this->fetch();
    }

    /**
     * 获取统计数据（JSON，供 Hook 或前端刷新）
     */
    public function getStats()
    {
        $period = $this->request->getParam('period', UsageStatisticsService::PERIOD_DAY);
        if (!in_array($period, [
            UsageStatisticsService::PERIOD_DAY,
            UsageStatisticsService::PERIOD_WEEK,
            UsageStatisticsService::PERIOD_MONTH,
            UsageStatisticsService::PERIOD_YEAR,
        ], true)) {
            $period = UsageStatisticsService::PERIOD_DAY;
        }
        $service = \Weline\Framework\Manager\ObjectManager::getInstance(UsageStatisticsService::class);
        $data = $service->getStatsForPanel($period);
        return $this->fetchJson($data);
    }
}
