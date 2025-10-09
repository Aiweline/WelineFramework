<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2025/10/09
 */

namespace Weline\Ai\Controller\Backend;

use Weline\Ai\Model\AiUsageLog;
use Weline\Ai\Model\AiModel;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;

/**
 * 商业洞察报表后台控制器
 * 
 * 功能：
 * - 使用量统计
 * - 成本分析
 * - 趋势图表
 * - 用户分析
 */
#[Acl('Weline_Ai::ai_business_insights', '商业洞察报表', 'mdi-chart-line', '商业洞察报表', 'Weline_Ai::ai')]
class Insights extends BackendController
{
    /**
     * @var AiUsageLog
     */
    private AiUsageLog $usageLog;

    /**
     * @var AiModel
     */
    private AiModel $aiModel;

    /**
     * 构造函数
     * 
     * @param AiUsageLog $usageLog
     * @param AiModel $aiModel
     */
    public function __construct(
        AiUsageLog $usageLog,
        AiModel $aiModel
    ) {
        $this->usageLog = $usageLog;
        $this->aiModel = $aiModel;
    }

    /**
     * 洞察报表首页
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_insights_view', '查看洞察报表', 'mdi-view-dashboard', '查看洞察报表')]
    public function index(): string
    {
        try {
            $dateRange = $this->request->getGet('range', '7'); // 默认最近7天
            $startDate = strtotime("-{$dateRange} days");
            $endDate = time();

            // 获取总体统计
            $stats = $this->getOverallStats($startDate, $endDate);
            
            // 获取模型使用统计
            $modelStats = $this->getModelStats($startDate, $endDate);
            
            // 获取每日趋势
            $dailyTrend = $this->getDailyTrend($startDate, $endDate);
            
            // 获取热门场景
            $topScenarios = $this->getTopScenarios($startDate, $endDate);

            $this->assign('stats', $stats);
            $this->assign('model_stats', $modelStats);
            $this->assign('daily_trend', $dailyTrend);
            $this->assign('top_scenarios', $topScenarios);
            $this->assign('date_range', $dateRange);

            return $this->fetch();

        } catch (\Exception $e) {
            $this->messageManager->addError(__('加载报表失败：%1', $e->getMessage()));
            return $this->fetch();
        }
    }

    /**
     * 获取总体统计
     * 
     * @param int $startDate
     * @param int $endDate
     * @return array
     */
    private function getOverallStats(int $startDate, int $endDate): array
    {
        try {
            $logs = $this->usageLog->reset()
                ->where('created_time', '>=', $startDate)
                ->where('created_time', '<=', $endDate)
                ->select()
                ->fetch();

            $totalRequests = count($logs->getItems());
            $totalTokens = 0;
            $totalCost = 0;
            $totalInputTokens = 0;
            $totalOutputTokens = 0;

            foreach ($logs->getItems() as $log) {
                $totalTokens += $log->getData('total_tokens') ?? 0;
                $totalCost += $log->getData('total_cost') ?? 0;
                $totalInputTokens += $log->getData('prompt_tokens') ?? 0;
                $totalOutputTokens += $log->getData('completion_tokens') ?? 0;
            }

            return [
                'total_requests' => $totalRequests,
                'total_tokens' => $totalTokens,
                'total_input_tokens' => $totalInputTokens,
                'total_output_tokens' => $totalOutputTokens,
                'total_cost' => number_format($totalCost, 2),
                'avg_tokens_per_request' => $totalRequests > 0 ? round($totalTokens / $totalRequests) : 0,
                'avg_cost_per_request' => $totalRequests > 0 ? number_format($totalCost / $totalRequests, 4) : '0.0000',
            ];

        } catch (\Exception $e) {
            return [
                'total_requests' => 0,
                'total_tokens' => 0,
                'total_input_tokens' => 0,
                'total_output_tokens' => 0,
                'total_cost' => '0.00',
                'avg_tokens_per_request' => 0,
                'avg_cost_per_request' => '0.0000',
            ];
        }
    }

    /**
     * 获取模型使用统计
     * 
     * @param int $startDate
     * @param int $endDate
     * @return array
     */
    private function getModelStats(int $startDate, int $endDate): array
    {
        try {
            $logs = $this->usageLog->reset()
                ->where('created_time', '>=', $startDate)
                ->where('created_time', '<=', $endDate)
                ->select()
                ->fetch();

            $modelStats = [];

            foreach ($logs->getItems() as $log) {
                $modelCode = $log->getData('model_code');
                
                if (!isset($modelStats[$modelCode])) {
                    $modelStats[$modelCode] = [
                        'model_code' => $modelCode,
                        'vendor' => $log->getData('vendor'),
                        'requests' => 0,
                        'tokens' => 0,
                        'cost' => 0,
                    ];
                }

                $modelStats[$modelCode]['requests']++;
                $modelStats[$modelCode]['tokens'] += $log->getData('total_tokens') ?? 0;
                $modelStats[$modelCode]['cost'] += $log->getData('total_cost') ?? 0;
            }

            // 按请求数排序
            usort($modelStats, function($a, $b) {
                return $b['requests'] - $a['requests'];
            });

            return array_slice($modelStats, 0, 10); // 返回前10个

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 获取每日趋势
     * 
     * @param int $startDate
     * @param int $endDate
     * @return array
     */
    private function getDailyTrend(int $startDate, int $endDate): array
    {
        try {
            $logs = $this->usageLog->reset()
                ->where('created_time', '>=', $startDate)
                ->where('created_time', '<=', $endDate)
                ->select()
                ->fetch();

            $dailyData = [];

            foreach ($logs->getItems() as $log) {
                $date = date('Y-m-d', $log->getData('created_time'));
                
                if (!isset($dailyData[$date])) {
                    $dailyData[$date] = [
                        'date' => $date,
                        'requests' => 0,
                        'tokens' => 0,
                        'cost' => 0,
                    ];
                }

                $dailyData[$date]['requests']++;
                $dailyData[$date]['tokens'] += $log->getData('total_tokens') ?? 0;
                $dailyData[$date]['cost'] += $log->getData('total_cost') ?? 0;
            }

            // 按日期排序
            ksort($dailyData);

            return array_values($dailyData);

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 获取热门场景
     * 
     * @param int $startDate
     * @param int $endDate
     * @return array
     */
    private function getTopScenarios(int $startDate, int $endDate): array
    {
        try {
            $logs = $this->usageLog->reset()
                ->where('created_time', '>=', $startDate)
                ->where('created_time', '<=', $endDate)
                ->where('scenario_code', 'IS NOT', null)
                ->select()
                ->fetch();

            $scenarioStats = [];

            foreach ($logs->getItems() as $log) {
                $scenarioCode = $log->getData('scenario_code');
                
                if (empty($scenarioCode)) {
                    continue;
                }
                
                if (!isset($scenarioStats[$scenarioCode])) {
                    $scenarioStats[$scenarioCode] = [
                        'scenario_code' => $scenarioCode,
                        'requests' => 0,
                        'tokens' => 0,
                    ];
                }

                $scenarioStats[$scenarioCode]['requests']++;
                $scenarioStats[$scenarioCode]['tokens'] += $log->getData('total_tokens') ?? 0;
            }

            // 按请求数排序
            usort($scenarioStats, function($a, $b) {
                return $b['requests'] - $a['requests'];
            });

            return array_slice($scenarioStats, 0, 5); // 返回前5个

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 导出报表
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_insights_export', '导出报表', 'mdi-download', '导出报表')]
    public function export(): string
    {
        // TODO: 实现报表导出功能（CSV/Excel）
        return $this->jsonResponse([
            'success' => false,
            'message' => __('导出功能开发中...')
        ]);
    }

    /**
     * JSON响应
     * 
     * @param array $data
     * @return string
     */
    private function jsonResponse(array $data): string
    {
        $this->response->setHeader('Content-Type', 'application/json');
        return json_encode($data);
    }
}

