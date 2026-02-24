<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Ai\Controller\Backend;

use Weline\Ai\Model\AiApiCallLog;
use Weline\Ai\Model\AiAssistant;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Model\AiUserBill;
use Weline\Ai\Model\AiApiKey;
use Weline\Ai\Model\Provider\Account;
use Weline\Ai\Model\Provider\UsageRecord;
use Weline\Ai\Service\Provider\AccountService;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Acl\Acl;

/**
 * AI运营概览仪表盘
 * 
 * 展示关键运营指标和趋势
 */
#[Acl('Weline_Ai::ai_dashboard', 'AI运营概览', 'mdi-view-dashboard', 'AI运营概览仪表盘', 'Weline_Ai::ai')]
class Dashboard extends BackendController
{
    /**
     * 概览页面
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_dashboard_index', '查看运营概览', 'mdi-chart-box', '查看运营概览')]
    public function index(): string
    {
        try {
            // 获取统计数据
            $stats = $this->getStats();
            
            // 获取趋势数据（最近7天）
            $trends = $this->getTrends();
            
            // 获取热门模型TOP5
            $topModels = $this->getTopModels();
            
            // 获取热门助手TOP5
            $topAssistants = $this->getTopAssistants();
            
            // 获取最近错误日志
            $recentErrors = $this->getRecentErrors();
            
            // 获取供应商账户统计
            $providerStats = $this->getProviderStats();
            
            // 获取供应商花费TOP5
            $topProviders = $this->getTopProviders();
            
            $this->assign('stats', $stats);
            $this->assign('trends', $trends);
            $this->assign('top_models', $topModels);
            $this->assign('top_assistants', $topAssistants);
            $this->assign('recent_errors', $recentErrors);
            $this->assign('provider_stats', $providerStats);
            $this->assign('top_providers', $topProviders);
            
            return $this->fetch();
            
        } catch (\Exception $e) {
            $this->assign('error', $e->getMessage());
            $this->assign('stats', $this->getDefaultStats());
            $this->assign('trends', []);
            $this->assign('top_models', []);
            $this->assign('top_assistants', []);
            $this->assign('recent_errors', []);
            $this->assign('provider_stats', []);
            $this->assign('top_providers', []);
            return $this->fetch();
        }
    }
    
    /**
     * 检查表是否存在（使用带前缀表名与 Connector::tableExist，兼容多数据库）
     */
    private function tableExists(string $tableName): bool
    {
        try {
            $conn = ObjectManager::getInstance(\Weline\Framework\Database\ConnectionFactory::class)->getConnector();
            $prefix = $conn->getConfigProvider()->getPrefix() ?? '';
            $fullName = ($prefix !== '' && !str_starts_with($tableName, $prefix)) ? $prefix . $tableName : $tableName;
            return $conn->tableExist($fullName);
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * 获取统计数据
     */
    private function getStats(): array
    {
        // 检查必要的表是否存在
        $hasCallLog = $this->tableExists('ai_api_call_log');
        $hasUserBill = $this->tableExists('ai_user_bill');
        
        // 如果关键表不存在，返回默认值
        if (!$hasCallLog) {
            return $this->getDefaultStats();
        }
        
        $apiCallLog = ObjectManager::getInstance(AiApiCallLog::class);
        $apiKey = ObjectManager::getInstance(AiApiKey::class);
        $assistant = ObjectManager::getInstance(AiAssistant::class);
        $model = ObjectManager::getInstance(AiModel::class);
        
        $now = time();
        $todayStart = strtotime('today');
        $weekStart = strtotime('monday this week');
        $monthStart = strtotime('first day of this month');
        
        // 今日调用统计
        try {
            $todayCalls = $apiCallLog->reset()
                ->where('created_at', '>=', $todayStart)
                ->select()
                ->fetch()
                ->count();
        } catch (\Exception $e) {
            $todayCalls = 0;
        }
        
        // 本周调用统计
        try {
            $weekCalls = $apiCallLog->reset()
                ->where('created_at', '>=', $weekStart)
                ->select()
                ->fetch()
                ->count();
        } catch (\Exception $e) {
            $weekCalls = 0;
        }
        
        // 本月调用统计
        try {
            $monthCalls = $apiCallLog->reset()
                ->where('created_at', '>=', $monthStart)
                ->select()
                ->fetch()
                ->count();
        } catch (\Exception $e) {
            $monthCalls = 0;
        }
        
        // 今日费用统计
        try {
            $todayCost = $this->calculateCost($apiCallLog->reset()
                ->where('created_at', '>=', $todayStart)
                ->select()
                ->fetch()
                ->getItems());
        } catch (\Exception $e) {
            $todayCost = 0;
        }
        
        // 本周费用统计
        try {
            $weekCost = $this->calculateCost($apiCallLog->reset()
                ->where('created_at', '>=', $weekStart)
                ->select()
                ->fetch()
                ->getItems());
        } catch (\Exception $e) {
            $weekCost = 0;
        }
        
        // 本月费用统计
        try {
            $monthCost = $this->calculateCost($apiCallLog->reset()
                ->where('created_at', '>=', $monthStart)
                ->select()
                ->fetch()
                ->getItems());
        } catch (\Exception $e) {
            $monthCost = 0;
        }
        
        // 活跃用户统计
        try {
            $activeUsers = $apiCallLog->reset()
                ->where('created_at', '>=', $todayStart)
                ->select()
                ->fetch()
                ->getItems();
            
            $uniqueUsers = [];
            foreach ($activeUsers as $log) {
                $userId = $log->getData('user_id');
                if ($userId) {
                    $uniqueUsers[$userId] = true;
                }
            }
            $todayActiveUsers = count($uniqueUsers);
        } catch (\Exception $e) {
            $todayActiveUsers = 0;
        }
        
        // 成功率统计
        try {
            $totalCalls = $apiCallLog->reset()
                ->where('created_at', '>=', $todayStart)
                ->select()
                ->fetch()
                ->count();
            
            $successCalls = $apiCallLog->reset()
                ->where('created_at', '>=', $todayStart)
                ->where('status', 'success')
                ->select()
                ->fetch()
                ->count();
            
            $successRate = $totalCalls > 0 ? round(($successCalls / $totalCalls) * 100, 2) : 0;
        } catch (\Exception $e) {
            $successRate = 0;
        }
        
        // 系统统计
        try {
            $totalModels = $model->reset()
                ->where('is_active', 1)
                ->select()
                ->fetch()
                ->count();
        } catch (\Exception $e) {
            $totalModels = 0;
        }
        
        try {
            $totalAssistants = $assistant->reset()
                ->where('is_active', 1)
                ->select()
                ->fetch()
                ->count();
        } catch (\Exception $e) {
            $totalAssistants = 0;
        }
        
        try {
            $totalApiKeys = $apiKey->reset()
                ->where('status', 'approved')
                ->select()
                ->fetch()
                ->count();
        } catch (\Exception $e) {
            $totalApiKeys = 0;
        }
        
        // 供应商账户统计
        try {
            $accountModel = ObjectManager::getInstance(Account::class);
            $totalProviderAccounts = $accountModel->reset()
                ->where(Account::fields_IS_ACTIVE, 1)
                ->select()
                ->count();
                
            $activeProviderAccounts = $accountModel->reset()
                ->where(Account::fields_IS_ACTIVE, 1)
                ->where(Account::fields_CONNECTION_STATUS, 'success')
                ->where(Account::fields_BALANCE, 0, '>')
                ->select()
                ->count();
        } catch (\Exception $e) {
            $totalProviderAccounts = 0;
            $activeProviderAccounts = 0;
        }
        
        // 供应商总花费（从使用记录统计）
        try {
            $usageRecord = ObjectManager::getInstance(UsageRecord::class);
            $providerTotalCost = $usageRecord->reset()
                ->fields(['total_cost' => 'SUM(' . UsageRecord::fields_TOTAL_COST . ')'])
                ->find()
                ->fetch();
            $providerTotalSpent = round((float)($providerTotalCost['total_cost'] ?? 0), 2);
            
            // 今日供应商花费
            $providerTodayCost = $usageRecord->reset()
                ->where(UsageRecord::fields_CREATED_AT, '>=', $todayStart)
                ->fields(['total_cost' => 'SUM(' . UsageRecord::fields_TOTAL_COST . ')'])
                ->find()
                ->fetch();
            $providerTodaySpent = round((float)($providerTodayCost['total_cost'] ?? 0), 2);
        } catch (\Exception $e) {
            $providerTotalSpent = 0;
            $providerTodaySpent = 0;
        }
        
        return [
            'today_calls' => $todayCalls,
            'week_calls' => $weekCalls,
            'month_calls' => $monthCalls,
            'today_cost' => $todayCost,
            'week_cost' => $weekCost,
            'month_cost' => $monthCost,
            'today_active_users' => $todayActiveUsers,
            'success_rate' => $successRate,
            'total_models' => $totalModels,
            'total_assistants' => $totalAssistants,
            'total_api_keys' => $totalApiKeys,
            'total_provider_accounts' => $totalProviderAccounts,
            'active_provider_accounts' => $activeProviderAccounts,
            'provider_total_spent' => $providerTotalSpent,
            'provider_today_spent' => $providerTodaySpent,
        ];
    }
    
    /**
     * 计算费用
     */
    private function calculateCost(array $logs): float
    {
        $totalCost = 0;
        foreach ($logs as $log) {
            $totalCost += (float)($log->getData('cost') ?? 0);
        }
        return round($totalCost, 2);
    }
    
    /**
     * 获取趋势数据（最近7天）
     */
    private function getTrends(): array
    {
        // 检查表是否存在
        if (!$this->tableExists('ai_api_call_log')) {
            return [];
        }
        
        try {
            $apiCallLog = ObjectManager::getInstance(AiApiCallLog::class);
            $trends = [];
            
            for ($i = 6; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-{$i} days"));
                $dayStart = strtotime($date);
                $dayEnd = strtotime($date . ' 23:59:59');
                
                try {
                    $calls = $apiCallLog->reset()
                        ->where('created_at', '>=', $dayStart)
                        ->where('created_at', '<=', $dayEnd)
                        ->select()
                        ->fetch()
                        ->getItems();
                    
                    $trends[] = [
                        'date' => $date,
                        'label' => date('m/d', strtotime($date)),
                        'calls' => count($calls),
                        'cost' => $this->calculateCost($calls),
                    ];
                } catch (\Exception $e) {
                    $trends[] = [
                        'date' => $date,
                        'label' => date('m/d', strtotime($date)),
                        'calls' => 0,
                        'cost' => 0,
                    ];
                }
            }
            
            return $trends;
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * 获取热门模型TOP5
     */
    private function getTopModels(): array
    {
        // 检查表是否存在
        if (!$this->tableExists('ai_api_call_log')) {
            return [];
        }
        
        try {
            $apiCallLog = ObjectManager::getInstance(AiApiCallLog::class);
            $model = ObjectManager::getInstance(AiModel::class);
            
            // 获取最近30天的调用记录
            $monthStart = strtotime('-30 days');
            $logs = $apiCallLog->reset()
                ->where('created_at', '>=', $monthStart)
                ->select()
                ->fetch()
                ->getItems();
        } catch (\Exception $e) {
            return [];
        }
        
        // 统计每个模型的使用次数
        $modelStats = [];
        foreach ($logs as $log) {
            $modelCode = $log->getData('model_code');
            if ($modelCode) {
                if (!isset($modelStats[$modelCode])) {
                    $modelStats[$modelCode] = [
                        'model_code' => $modelCode,
                        'calls' => 0,
                        'cost' => 0,
                    ];
                }
                $modelStats[$modelCode]['calls']++;
                $modelStats[$modelCode]['cost'] += (float)($log->getData('cost') ?? 0);
            }
        }
        
        // 排序并获取TOP5
        usort($modelStats, function($a, $b) {
            return $b['calls'] - $a['calls'];
        });
        
        $topModels = array_slice($modelStats, 0, 5);
        
        // 获取模型名称
        foreach ($topModels as &$stat) {
            $modelObj = $model->reset();
            $models = $modelObj->where('is_active', 1)->select()->fetch();
            
            foreach ($models->getItems() as $m) {
                $code = strtolower($m->getData('code') ?: '');
                $name = strtolower($m->getData('name'));
                $searchTerm = strtolower($stat['model_code']);
                
                if (empty($code)) {
                    $generatedCode = strtolower(str_replace([' ', '.'], ['_', '_'], $name));
                    if ($generatedCode === $searchTerm) {
                        $stat['model_name'] = $m->getData('name');
                        $stat['supplier'] = $m->getData('supplier');
                        break;
                    }
                }
                
                if ($code === $searchTerm || strpos($name, $searchTerm) !== false) {
                    $stat['model_name'] = $m->getData('name');
                    $stat['supplier'] = $m->getData('supplier');
                    break;
                }
            }
            
            if (!isset($stat['model_name'])) {
                $stat['model_name'] = $stat['model_code'];
                $stat['supplier'] = 'Unknown';
            }
            
            $stat['cost'] = round($stat['cost'], 2);
        }
        
        return $topModels;
    }
    
    /**
     * 获取热门助手TOP5
     */
    private function getTopAssistants(): array
    {
        try {
            $assistant = ObjectManager::getInstance(AiAssistant::class);
            
            // 获取使用次数最多的助手
            $assistants = $assistant->reset()
                ->where('is_active', 1)
                ->order('usage_count', 'DESC')
                ->limit(5)
                ->select()
                ->fetch()
                ->getItems();
        } catch (\Exception $e) {
            return [];
        }
        
        $topAssistants = [];
        foreach ($assistants as $asst) {
            $topAssistants[] = [
                'id' => $asst->getId(),
                'name' => $asst->getData('name'),
                'usage_count' => $asst->getData('usage_count') ?? 0,
                'is_rentable' => $asst->getData('is_rentable') ?? 0,
                'category' => $asst->getData('category') ?? 'other',
            ];
        }
        
        return $topAssistants;
    }
    
    /**
     * 获取最近错误日志（最近10条）
     */
    private function getRecentErrors(): array
    {
        // 检查表是否存在
        if (!$this->tableExists('ai_api_call_log')) {
            return [];
        }
        
        try {
            $apiCallLog = ObjectManager::getInstance(AiApiCallLog::class);
            
            $errors = $apiCallLog->reset()
                ->where('status', 'error')
                ->order('created_at', 'DESC')
                ->limit(10)
                ->select()
                ->fetch()
                ->getItems();
        } catch (\Exception $e) {
            return [];
        }
        
        $recentErrors = [];
        foreach ($errors as $error) {
            $recentErrors[] = [
                'id' => $error->getId(),
                'model_code' => $error->getData('model_code'),
                'error_message' => $error->getData('error_message') ?? 'Unknown error',
                'created_at' => $error->getData('created_at'),
                'user_id' => $error->getData('user_id'),
            ];
        }
        
        return $recentErrors;
    }
    
    /**
     * 获取默认统计数据（出错时使用）
     */
    private function getDefaultStats(): array
    {
        return [
            'today_calls' => 0,
            'week_calls' => 0,
            'month_calls' => 0,
            'today_cost' => 0,
            'week_cost' => 0,
            'month_cost' => 0,
            'today_active_users' => 0,
            'success_rate' => 0,
            'total_models' => 0,
            'total_assistants' => 0,
            'total_api_keys' => 0,
            'total_provider_accounts' => 0,
            'active_provider_accounts' => 0,
            'provider_total_spent' => 0,
            'provider_today_spent' => 0,
        ];
    }
    
    /**
     * 获取供应商账户统计
     */
    private function getProviderStats(): array
    {
        try {
            $accountService = ObjectManager::getInstance(AccountService::class);
            $providers = $accountService->getSupportedProviders();
            $stats = [];
            
            foreach ($providers as $code => $info) {
                $accountModel = ObjectManager::getInstance(Account::class);
                
                // 该供应商的账户数
                $totalAccounts = $accountModel->reset()
                    ->where(Account::fields_PROVIDER_CODE, $code)
                    ->select()
                    ->count();
                
                // 活跃账户数
                $activeAccounts = $accountModel->reset()
                    ->where(Account::fields_PROVIDER_CODE, $code)
                    ->where(Account::fields_IS_ACTIVE, 1)
                    ->where(Account::fields_CONNECTION_STATUS, 'success')
                    ->where(Account::fields_BALANCE, 0, '>')
                    ->select()
                    ->count();
                
                // 总余额
                $balanceResult = $accountModel->reset()
                    ->where(Account::fields_PROVIDER_CODE, $code)
                    ->fields(['total_balance' => 'SUM(' . Account::fields_BALANCE . ')'])
                    ->find()
                    ->fetch();
                $totalBalance = round((float)($balanceResult['total_balance'] ?? 0), 2);
                
                // 总花费
                $usageRecord = ObjectManager::getInstance(UsageRecord::class);
                $spentResult = $usageRecord->reset()
                    ->where(UsageRecord::fields_PROVIDER_CODE, $code)
                    ->fields(['total_spent' => 'SUM(' . UsageRecord::fields_TOTAL_COST . ')'])
                    ->find()
                    ->fetch();
                $totalSpent = round((float)($spentResult['total_spent'] ?? 0), 2);
                
                $stats[] = [
                    'code' => $code,
                    'name' => $info['name'],
                    'total_accounts' => $totalAccounts,
                    'active_accounts' => $activeAccounts,
                    'total_balance' => $totalBalance,
                    'total_spent' => $totalSpent,
                ];
            }
            
            return $stats;
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * 获取供应商花费TOP5
     */
    private function getTopProviders(): array
    {
        try {
            $usageRecord = ObjectManager::getInstance(UsageRecord::class);
            $accountService = ObjectManager::getInstance(AccountService::class);
            
            // 获取最近30天的数据
            $monthStart = strtotime('-30 days');
            
            // 按供应商分组统计
            $results = $usageRecord->reset()
                ->where(UsageRecord::fields_CREATED_AT, '>=', $monthStart)
                ->fields([
                    'provider_code' => UsageRecord::fields_PROVIDER_CODE,
                    'total_cost' => 'SUM(' . UsageRecord::fields_TOTAL_COST . ')',
                    'total_requests' => 'COUNT(*)',
                    'total_tokens' => 'SUM(' . UsageRecord::fields_TOTAL_TOKENS . ')'
                ])
                ->group(UsageRecord::fields_PROVIDER_CODE)
                ->order('total_cost DESC')
                ->limit(5)
                ->select()
                ->fetchArray();
            
            $providers = $accountService->getSupportedProviders();
            $topProviders = [];
            
            foreach ($results as $row) {
                $providerCode = $row['provider_code'];
                $topProviders[] = [
                    'code' => $providerCode,
                    'name' => $providers[$providerCode]['name'] ?? $providerCode,
                    'total_cost' => round((float)$row['total_cost'], 2),
                    'total_requests' => (int)$row['total_requests'],
                    'total_tokens' => (int)$row['total_tokens'],
                ];
            }
            
            return $topProviders;
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * JSON响应
     */
    private function jsonResponse(array $data): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return json_encode($data);
    }
}

