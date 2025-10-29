<?php

declare(strict_types=1);

namespace Weline\Ai\Controller\Backend;

use Weline\Admin\Controller\BaseController;
use Weline\Ai\Model\Provider\Account;
use Weline\Ai\Model\Provider\UsageRecord;
use Weline\Ai\Service\Provider\AccountService;
use Weline\Framework\Http\Cookie;
use Weline\Framework\View\Data\DataInterface;
use Weline\Framework\Acl\Acl;

/**
 * Provider Account Management Controller
 * 
 * @package Weline_Ai
 */
#[Acl('Weline_Ai::ai_provider_account', 'AI供应商账户', 'mdi-account-key', 'AI供应商账户管理', 'Weline_Ai::ai')]
class Provider extends BaseController
{
    /**
     * @var AccountService
     */
    private AccountService $accountService;

    /**
     * 初始化
     */
    public function __construct(
        Cookie $cookie,
        DataInterface $assign,
        AccountService $accountService
    ) {
        parent::__construct($cookie, $assign);
        $this->accountService = $accountService;
    }

    /**
     * 账户列表页面
     */
    #[Acl('Weline_Ai::ai_provider_list', '查看供应商账户', 'mdi-view-list', '查看供应商账户列表')]
    public function index()
    {
        $this->assign->setTitle(__('AI供应商账户管理'));
        $this->assign->setData('providers', $this->accountService->getSupportedProviders());
        return $this->fetch();
    }

    /**
     * 获取账户列表数据
     */
    public function getList()
    {
        try {
            $page = (int)($this->request->getParam('page') ?: 1);
            $limit = (int)($this->request->getParam('limit') ?: 20);
            $search = $this->request->getParam('search');
            $providerCode = $this->request->getParam('provider_code');

            /** @var Account $accountModel */
            $accountModel = Account::instance();
            
            if ($search) {
                $accountModel->where(Account::fields_ACCOUNT_NAME . ' LIKE ?', "%{$search}%");
            }
            
            if ($providerCode) {
                $accountModel->where(Account::fields_PROVIDER_CODE, $providerCode);
            }

            $total = $accountModel->count();
            $accounts = $accountModel->order(Account::fields_CREATED_AT, 'DESC')
                ->pagination($page, $limit)
                ->select()
                ->fetchOrigin();

            // 格式化数据
            foreach ($accounts as &$account) {
                $account['provider_name'] = $this->accountService->getSupportedProviders()[$account['provider_code']]['name'] ?? $account['provider_code'];
                $account['balance_formatted'] = $account['currency'] . ' ' . number_format((float)$account['balance'], 2);
                $account['total_spent_formatted'] = $account['currency'] . ' ' . number_format((float)$account['total_spent'], 2);
                $account['connection_status_text'] = $this->getConnectionStatusText($account['connection_status']);
                $account['created_at_formatted'] = date('Y-m-d H:i:s', (int)$account['created_at']);
                
                // 隐藏敏感信息
                if (!empty($account['api_key'])) {
                    $account['api_key_masked'] = substr($account['api_key'], 0, 6) . str_repeat('*', 20) . substr($account['api_key'], -4);
                }
            }

            return $this->fetchJson([
                'success' => true,
                'data' => $accounts,
                'total' => $total,
                'page' => $page,
                'limit' => $limit
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * 添加账户页面
     */
    public function add()
    {
        $this->assign->setTitle(__('添加供应商账户'));
        $this->assign->setData('providers', $this->accountService->getSupportedProviders());
        return $this->fetch('edit');
    }

    /**
     * 编辑账户页面
     */
    public function edit()
    {
        try {
            $id = (int)$this->request->getParam('id');
            if (!$id) {
                throw new \Exception('账户ID无效');
            }

            /** @var Account $account */
            $account = Account::instance()->load($id);
            if (!$account->getId()) {
                throw new \Exception('账户不存在');
            }

            $this->assign->setTitle(__('编辑供应商账户'));
            $this->assign->setData('providers', $this->accountService->getSupportedProviders());
            $this->assign->setData('account', $account);
            return $this->fetch();
        } catch (\Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
            return $this->redirect('*/backend/provider');
        }
    }

    /**
     * 保存账户
     */
    public function save()
    {
        try {
            $data = $this->request->getParams();
            $id = (int)($data['id'] ?? 0);

            /** @var Account $account */
            $account = Account::getInstance();
            if ($id > 0) {
                $account->load($id);
                if (!$account->getId()) {
                    throw new \Exception('账户不存在');
                }
            }

            // 验证必填字段
            if (empty($data['provider_code'])) {
                throw new \Exception('请选择供应商');
            }
            if (empty($data['account_name'])) {
                throw new \Exception('请输入账户名称');
            }
            if (empty($data['api_key']) && !$account->getId()) {
                throw new \Exception('请输入API密钥');
            }

            // 设置基本信息
            $account->setData(Account::fields_PROVIDER_CODE, $data['provider_code']);
            $account->setData(Account::fields_ACCOUNT_NAME, $data['account_name']);
            
            // 只有当提供新的API密钥时才更新
            if (!empty($data['api_key'])) {
                $account->setEncryptedApiKey($data['api_key']);
            }
            
            // 设置其他字段
            if (!empty($data['api_secret'])) {
                $account->setData(Account::fields_API_SECRET, $data['api_secret']);
            }
            
            $account->setData(Account::fields_BASE_URL, $data['base_url'] ?? '');
            $account->setData(Account::fields_BALANCE, (float)($data['balance'] ?? 0));
            $account->setData(Account::fields_CURRENCY, $data['currency'] ?? 'USD');
            $account->setData(Account::fields_IS_ACTIVE, (int)($data['is_active'] ?? 0));
            
            // 处理代理配置
            if (!empty($data['proxy_enabled'])) {
                $proxyConfig = [
                    'enabled' => true,
                    'type' => $data['proxy_type'] ?? 'http',
                    'host' => $data['proxy_host'] ?? '',
                    'port' => $data['proxy_port'] ?? '',
                    'username' => $data['proxy_username'] ?? '',
                    'password' => $data['proxy_password'] ?? ''
                ];
                $account->setData(Account::fields_PROXY_CONFIG, json_encode($proxyConfig));
            } else {
                $account->setData(Account::fields_PROXY_CONFIG, null);
            }

            // 保存时间戳
            if (!$account->getId()) {
                $account->setData(Account::fields_CREATED_AT, time());
                $account->setData(Account::fields_CONNECTION_STATUS, Account::STATUS_PENDING);
            }
            $account->setData(Account::fields_UPDATED_AT, time());

            $account->save();

            // 如果设置为默认账户
            if (!empty($data['is_default'])) {
                $this->accountService->setDefaultAccount($account);
            }

            return $this->fetchJson([
                'success' => true,
                'message' => '账户保存成功',
                'account_id' => $account->getId()
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * 删除账户
     */
    public function delete()
    {
        try {
            $id = (int)$this->request->getParam('id');
            if (!$id) {
                throw new \Exception('账户ID无效');
            }

            /** @var Account $account */
            $account = Account::instance()->load($id);
            if (!$account->getId()) {
                throw new \Exception('账户不存在');
            }

            // 检查是否有使用记录
            /** @var UsageRecord $usageRecord */
            $usageRecord = UsageRecord::instance();
            $hasUsage = $usageRecord->where(UsageRecord::fields_ACCOUNT_ID, $id)->count() > 0;
            
            if ($hasUsage) {
                throw new \Exception('该账户有使用记录，无法删除');
            }

            $account->delete();

            return $this->fetchJson([
                'success' => true,
                'message' => '账户删除成功'
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * 测试连接
     */
    public function testConnection()
    {
        try {
            $id = (int)$this->request->getParam('id');
            if (!$id) {
                throw new \Exception('账户ID无效');
            }

            /** @var Account $account */
            $account = Account::instance()->load($id);
            if (!$account->getId()) {
                throw new \Exception('账户不存在');
            }

            $result = $this->accountService->testConnection($account);
            
            return $this->fetchJson($result);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * 切换激活状态
     */
    public function toggleActive()
    {
        try {
            $id = (int)$this->request->getParam('id');
            if (!$id) {
                throw new \Exception('账户ID无效');
            }

            /** @var Account $account */
            $account = Account::instance()->load($id);
            if (!$account->getId()) {
                throw new \Exception('账户不存在');
            }

            $isActive = !$account->getData(Account::fields_IS_ACTIVE);
            
            // 如果要激活账户，先检查连接状态
            if ($isActive && $account->getData(Account::fields_CONNECTION_STATUS) !== Account::STATUS_SUCCESS) {
                throw new \Exception('请先测试连接成功后再激活账户');
            }

            $account->setData(Account::fields_IS_ACTIVE, $isActive ? 1 : 0);
            $account->setData(Account::fields_UPDATED_AT, time());
            $account->save();

            return $this->fetchJson([
                'success' => true,
                'message' => $isActive ? '账户已激活' : '账户已停用',
                'is_active' => $isActive
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * 使用记录页面
     */
    public function usage()
    {
        $accountId = (int)$this->request->getParam('account_id');
        $this->assign->setTitle(__('使用记录'));
        $this->assign->setData('account_id', $accountId);
        
        if ($accountId) {
            /** @var Account $account */
            $account = Account::getInstance()->load($accountId);
            if ($account->getId()) {
                $this->assign->setData('account', $account);
            }
        }
        
        return $this->fetch();
    }

    /**
     * 获取使用记录数据
     */
    public function getUsageList()
    {
        try {
            $page = (int)($this->request->getParam('page') ?: 1);
            $limit = (int)($this->request->getParam('limit') ?: 20);
            $accountId = (int)$this->request->getParam('account_id');
            $dateFrom = $this->request->getParam('date_from');
            $dateTo = $this->request->getParam('date_to');

            /** @var UsageRecord $usageModel */
            $usageModel = UsageRecord::instance();
            
            if ($accountId) {
                $usageModel->where(UsageRecord::fields_ACCOUNT_ID, $accountId);
            }
            
            if ($dateFrom) {
                $usageModel->where(UsageRecord::fields_CREATED_AT . ' >= ?', strtotime($dateFrom));
            }
            
            if ($dateTo) {
                $usageModel->where(UsageRecord::fields_CREATED_AT . ' <= ?', strtotime($dateTo . ' 23:59:59'));
            }

            $total = $usageModel->count();
            $records = $usageModel->order(UsageRecord::fields_CREATED_AT, 'DESC')
                ->pagination($page, $limit)
                ->select()
                ->fetchOrigin();

            // 格式化数据
            foreach ($records as &$record) {
                $record['created_at_formatted'] = date('Y-m-d H:i:s', (int)$record['created_at']);
                $record['total_cost_formatted'] = $record['currency'] . ' ' . number_format((float)$record['total_cost'], 6);
                $record['request_time_formatted'] = $record['request_time'] ? number_format($record['request_time'] / 1000, 2) . 's' : '-';
            }

            // 计算统计信息
            $stats = $this->calculateUsageStats($accountId, $dateFrom, $dateTo);

            return $this->fetchJson([
                'success' => true,
                'data' => $records,
                'total' => $total,
                'stats' => $stats,
                'page' => $page,
                'limit' => $limit
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * 计算使用统计
     */
    private function calculateUsageStats($accountId = null, $dateFrom = null, $dateTo = null): array
    {
        /** @var UsageRecord $usageModel */
        $usageModel = UsageRecord::getInstance();
        
        if ($accountId) {
            $usageModel->where(UsageRecord::fields_ACCOUNT_ID, $accountId);
        }
        
        if ($dateFrom) {
            $usageModel->where(UsageRecord::fields_CREATED_AT . ' >= ?', strtotime($dateFrom));
        }
        
        if ($dateTo) {
            $usageModel->where(UsageRecord::fields_CREATED_AT . ' <= ?', strtotime($dateTo . ' 23:59:59'));
        }
        
        $stats = $usageModel->fields([
            'total_requests' => 'COUNT(*)',
            'total_tokens' => 'SUM(' . UsageRecord::fields_TOTAL_TOKENS . ')',
            'total_cost' => 'SUM(' . UsageRecord::fields_TOTAL_COST . ')',
            'avg_cost' => 'AVG(' . UsageRecord::fields_TOTAL_COST . ')',
            'success_count' => 'SUM(CASE WHEN ' . UsageRecord::fields_STATUS . ' = "success" THEN 1 ELSE 0 END)',
            'failed_count' => 'SUM(CASE WHEN ' . UsageRecord::fields_STATUS . ' = "failed" THEN 1 ELSE 0 END)'
        ])->find()->fetch();
        
        return [
            'total_requests' => (int)$stats['total_requests'],
            'total_tokens' => (int)$stats['total_tokens'],
            'total_cost' => number_format((float)$stats['total_cost'], 6),
            'avg_cost' => number_format((float)$stats['avg_cost'], 6),
            'success_rate' => $stats['total_requests'] > 0 
                ? round($stats['success_count'] / $stats['total_requests'] * 100, 2) 
                : 0
        ];
    }

    /**
     * 获取连接状态文本
     */
    private function getConnectionStatusText($status): string
    {
        return match ($status) {
            Account::STATUS_SUCCESS => '<span class="badge bg-success">已连接</span>',
            Account::STATUS_FAILED => '<span class="badge bg-danger">连接失败</span>',
            default => '<span class="badge bg-warning">待测试</span>'
        };
    }
}
