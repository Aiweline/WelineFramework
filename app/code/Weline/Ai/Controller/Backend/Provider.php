<?php

declare(strict_types=1);

namespace Weline\Ai\Controller\Backend;

use Weline\Framework\App\Controller\BackendPageController;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Model\Provider\Account;
use Weline\Ai\Model\Provider\UsageRecord;
use Weline\Ai\Service\Provider\AccountService;
use Weline\Ai\Service\Provider\ModelListingProviderInterface;
use Weline\Ai\Service\Provider\VendorConfigManager;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\ObjectManager;

/**
 * Provider Account Management Controller
 * 
 * @package Weline_Ai
 */
#[Acl('Weline_Ai::ai_provider_account', 'AI供应商账户', 'mdi-account-key', 'AI供应商账户管理', 'Weline_Backend::ai_group')]
class Provider extends BackendPageController
{
    /**
     * @var AccountService
     */
    private AccountService $accountService;

    /**
     * 初始化
     */
    public function __construct(
        AccountService $accountService
    ) {
        $this->accountService = $accountService;
    }

    private function normalizeProviderCode(string $providerCode): string
    {
        return strtolower(trim($providerCode));
    }

    private function normalizeProviderBaseUrl(string $providerCode, string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '') {
            return '';
        }
        if ($this->normalizeProviderCode($providerCode) === 'vectorengine') {
            $baseUrl = str_replace('://api.vectorengine.ai', '://api.vectorengine.cn', $baseUrl);
            foreach (['/chat/completions', '/completions', '/embeddings', '/images/generations', '/models'] as $suffix) {
                if (str_ends_with($baseUrl, $suffix)) {
                    $baseUrl = substr($baseUrl, 0, -strlen($suffix));
                    break;
                }
            }
            if (!preg_match('#/v\d+(?:beta)?$#', $baseUrl)) {
                $baseUrl .= '/v1';
            }
        }
        return $baseUrl;
    }

    private function normalizeAccountBaseUrlForOutput(array $accountData): array
    {
        if (array_key_exists('base_url', $accountData)) {
            $accountData['base_url'] = $this->normalizeProviderBaseUrl(
                (string)($accountData['provider_code'] ?? ''),
                (string)$accountData['base_url']
            );
        }
        return $accountData;
    }

    private function normalizeAccountModelBaseUrl(Account $account): void
    {
        $account->setData(
            Account::schema_fields_BASE_URL,
            $this->normalizeProviderBaseUrl(
                (string)$account->getData(Account::schema_fields_PROVIDER_CODE),
                (string)$account->getData(Account::schema_fields_BASE_URL)
            )
        );
    }

    /**
     * 账户列表页面
     */
    #[Acl('Weline_Ai::ai_provider_list', '查看供应商账户', 'mdi-view-list', '查看供应商账户列表')]
    public function index()
    {
        if ($this->request->getParam('embed') === '1') {
            $this->layoutType = 'default.blank';
        }
        $this->assign('title', __('AI供应商账户管理'));
        $this->assign('providers', $this->accountService->getSupportedProviders());
        $this->assign('embed', ($this->request->getParam('embed') === '1' || $this->request->getParam('embed') === true));
        $this->assign('activeTab', 'account');
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
            $providerCode = $this->normalizeProviderCode((string)$this->request->getParam('provider_code'));

            /** @var Account $accountModel */
            $accountModel = ObjectManager::getInstance(Account::class);
            
            if ($search) {
                $accountModel->where(Account::schema_fields_ACCOUNT_NAME , "%{$search}%", 'like');
            }
            
            if ($providerCode) {
                $accountModel->where(Account::schema_fields_PROVIDER_CODE, $providerCode);
            }

            $total = $accountModel->count();
            
            // 重新创建模型实例以避免状态污染
            $accountModel = ObjectManager::getInstance(Account::class);
            if ($search) {
                $accountModel->where(Account::schema_fields_ACCOUNT_NAME, "%{$search}%", 'like');
            }
            if ($providerCode) {
                $accountModel->where(Account::schema_fields_PROVIDER_CODE, $providerCode);
            }
            
            $accounts = $accountModel->order(Account::schema_fields_CREATED_AT, 'DESC')
                ->pagination($page, $limit)
                ->select()
                ->fetch()
                ->getItems();
            // 格式化数据
            $formattedAccounts = [];
            foreach ($accounts as $account) {
                try {
                    // 确保账户数据是数组格式
                    $accountData = is_object($account) ? $account->getData() : $account;
                    
                    // 设置默认值
                    $accountData['balance'] = $accountData['balance'] ?? 0;
                    $accountData['currency'] = $accountData['currency'] ?? 'USD';
                    $accountData['total_spent'] = $accountData['total_spent'] ?? 0;
                    $accountData['connection_status'] = $accountData['connection_status'] ?? 'pending';
                    $accountData['created_at'] = $accountData['created_at'] ?? time();
                    $accountData['provider_code'] = $this->normalizeProviderCode((string)($accountData['provider_code'] ?? ''));
                    $accountData = $this->normalizeAccountBaseUrlForOutput($accountData);
                    
                    // 格式化数据
                    $accountData['provider_name'] = $this->accountService->getSupportedProviders()[$accountData['provider_code']]['name'] ?? $accountData['provider_code'];
                    $accountData['balance_formatted'] = $accountData['currency'] . ' ' . number_format((float)$accountData['balance'], 2);
                    $accountData['total_spent_formatted'] = $accountData['currency'] . ' ' . number_format((float)$accountData['total_spent'], 2);
                    $accountData['connection_status_text'] = $this->getConnectionStatusText($accountData['connection_status']);
                    $accountData['created_at_formatted'] = date('Y-m-d H:i:s', (int)$accountData['created_at']);
                    
                    // 隐藏敏感信息
                    if (!empty($accountData['api_key'])) {
                        $accountData['api_key_masked'] = substr($accountData['api_key'], 0, 6) . str_repeat('*', 20) . substr($accountData['api_key'], -4);
                    } else {
                        $accountData['api_key_masked'] = '*******';
                    }
                    
                    $formattedAccounts[] = $accountData;
                } catch (\Exception $e) {
                    // 如果格式化失败，记录错误但继续处理其他记录
                    w_log_error("格式化账户数据失败: " . $e->getMessage());
                    continue;
                }
            }

            return $this->fetchJson([
                'success' => true,
                'data' => $formattedAccounts,
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
     * 获取供应商账户列表（供模型表单账户选择，支持 search-select）
     * 参数：provider_code 必填，q 可选搜索关键词
     */
    public function getAccountsForSelect()
    {
        $providerCode = $this->normalizeProviderCode((string)($this->request->getParam('provider_code') ?? $this->request->getGet('provider_code') ?? ''));
        $q = (string)($this->request->getParam('q') ?? $this->request->getGet('q') ?? '');
        $limit = (int)($this->request->getParam('limit') ?? 50);

        if ($providerCode === '') {
            return $this->fetchJson(['success' => true, 'data' => []]);
        }

        /** @var Account $accountModel */
        $accountModel = ObjectManager::getInstance(Account::class)
            ->where(Account::schema_fields_PROVIDER_CODE, $providerCode);
        if ($q !== '') {
            $accountModel->where(Account::schema_fields_ACCOUNT_NAME, "%{$q}%", 'like');
        }
        $accounts = $accountModel->order(Account::schema_fields_IS_DEFAULT, 'DESC')
            ->order(Account::schema_fields_CREATED_AT, 'DESC')
            ->limit($limit)
            ->select()
            ->fetch()
            ->getItems();

        $providers = $this->accountService->getSupportedProviders();
        $providerName = $providers[$providerCode]['name'] ?? $providerCode;
        $data = [];
        foreach ($accounts as $acc) {
            $arr = is_object($acc) ? $acc->getData() : $acc;
            $status = ((int)($arr[Account::schema_fields_IS_ACTIVE] ?? 0) === 1) ? 'enabled' : 'disabled';
            $default = ((int)($arr[Account::schema_fields_IS_DEFAULT] ?? 0) === 1) ? ', default' : '';
            $data[] = [
                'value' => (string)($arr['id'] ?? ''),
                'label' => ($arr['account_name'] ?? '') . ' (' . $providerName . ', ' . $status . $default . ')',
                'is_active' => (int)($arr[Account::schema_fields_IS_ACTIVE] ?? 0),
                'is_default' => (int)($arr[Account::schema_fields_IS_DEFAULT] ?? 0),
                'connection_status' => (string)($arr[Account::schema_fields_CONNECTION_STATUS] ?? ''),
            ];
        }
        return $this->fetchJson(['success' => true, 'data' => $data]);
    }

    /**
     * Fetch model options from the selected provider account's models endpoint.
     */
    public function getRemoteModelsForSelect()
    {
        try {
            $providerCode = $this->normalizeProviderCode((string)($this->request->getParam('provider_code') ?? $this->request->getGet('provider_code') ?? ''));
            $accountId = (int)($this->request->getParam('account_id') ?? $this->request->getGet('account_id') ?? 0);
            $apiKey = trim((string)($this->request->getParam('api_key') ?? $this->request->getGet('api_key') ?? ''));
            $baseUrl = trim((string)($this->request->getParam('base_url') ?? $this->request->getGet('base_url') ?? ''));
            $requireRemote = (string)($this->request->getParam('require_remote') ?? $this->request->getGet('require_remote') ?? '') === '1';

            if ($providerCode === '') {
                return $this->fetchJson(['success' => false, 'message' => __('请选择供应商')]);
            }

            $config = VendorConfigManager::getProviderConfig($providerCode);
            if (!$config) {
                return $this->fetchJson(['success' => false, 'message' => __('供应商不存在: %{1}', [$providerCode])]);
            }

            $modelsApi = $config['models_api'] ?? [];
            if (!is_array($modelsApi) || empty($modelsApi['path'])) {
                return $this->fetchJson([
                    'success' => false,
                    'unsupported' => true,
                    'message' => __('该供应商未配置 models 接口，请手动输入模型代码。')
                ]);
            }

            if ($accountId > 0) {
                /** @var Account $account */
                $account = ObjectManager::getInstance(Account::class)->load($accountId);
                if (!$account->getId() || $this->normalizeProviderCode((string)$account->getData(Account::schema_fields_PROVIDER_CODE)) !== $providerCode) {
                    return $this->fetchJson(['success' => false, 'message' => __('供应商账户不存在或不属于当前供应商')]);
                }
                $apiKey = $account->getDecryptedApiKey();
                $baseUrl = (string)($account->getData(Account::schema_fields_BASE_URL) ?: ($config['base_url'] ?? ''));
            } elseif ($apiKey === '') {
                $account = $this->accountService->getAvailableAccount($providerCode);
                if (!$account || !$account->getId()) {
                    /** @var Account $account */
                    $account = ObjectManager::getInstance(Account::class)->clear()
                        ->where(Account::schema_fields_PROVIDER_CODE, $providerCode)
                        ->where(Account::schema_fields_IS_ACTIVE, 1)
                        ->where(Account::schema_fields_CONNECTION_STATUS, Account::STATUS_SUCCESS)
                        ->order(Account::schema_fields_IS_DEFAULT, 'DESC')
                        ->order(Account::schema_fields_CREATED_AT, 'DESC')
                        ->find()
                        ->fetch();
                }
                if ($account && $account->getId()) {
                    $apiKey = $account->getDecryptedApiKey();
                    $baseUrl = (string)($account->getData(Account::schema_fields_BASE_URL) ?: ($config['base_url'] ?? ''));
                }
            }

            if ($apiKey === '') {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('请选择供应商账户，或在自配置模式填写 API Key 后再拉取模型。')
                ]);
            }

            if ($baseUrl === '') {
                $baseUrl = (string)($config['base_url'] ?? '');
            }
            $baseUrl = $this->normalizeProviderBaseUrl($providerCode, $baseUrl);

            $provider = $this->accountService->getProviderInstance($providerCode);
            if (!$provider instanceof ModelListingProviderInterface || !$provider->supportsModelsApi()) {
                return $this->fetchJson([
                    'success' => false,
                    'unsupported' => true,
                    'message' => __('该供应商类未实现 models 接口，请手动输入模型代码。')
                ]);
            }

            $modelConfig = array_replace($config, [
                'provider_code' => $providerCode,
                'api_key' => $apiKey,
                'base_url' => $baseUrl,
                'models_api' => $modelsApi,
            ]);
            $remoteError = '';
            try {
                $models = $provider->listRemoteModels($modelConfig, [
                    'provider_code' => $providerCode,
                    'models_api' => $modelsApi,
                ]);
            } catch (\Throwable $remoteThrowable) {
                $models = [];
                $remoteError = $remoteThrowable->getMessage();
            }
            $source = 'models';
            if ($models === [] && !$requireRemote) {
                $models = $this->normalizeProviderPresetModels($config['models'] ?? []);
                $source = $models === [] ? 'empty' : 'preset';
            }
            if ($models === []) {
                return $this->fetchJson([
                    'success' => false,
                    'data' => [],
                    'unsupported' => false,
                    'message' => $remoteError !== '' ? $remoteError : __('models 接口未返回可用模型'),
                    'source' => $source,
                    'default_model_code' => (string)($config['test_model'] ?? ''),
                ]);
            }
            $defaultModelCode = $this->resolveDefaultModelCode($models, (string)($config['test_model'] ?? ''));
            return $this->fetchJson([
                'success' => true,
                'data' => $models,
                'unsupported' => false,
                'source' => $source,
                'default_model_code' => $defaultModelCode,
                'warning' => $remoteError,
            ]);
        } catch (ResponseTerminateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    private function normalizeProviderPresetModels(array $models): array
    {
        $items = [];
        foreach ($models as $model) {
            if (is_scalar($model)) {
                $code = trim((string)$model);
                if ($code !== '') {
                    $items[] = [
                        'value' => $code,
                        'label' => $code,
                        'code' => $code,
                        'name' => $code,
                        'primary_modality' => '',
                    ];
                }
                continue;
            }
            if (!is_array($model)) {
                continue;
            }
            $code = trim((string)($model['code'] ?? $model['id'] ?? $model['model'] ?? $model['name'] ?? ''));
            if ($code === '') {
                continue;
            }
            $name = trim((string)($model['name'] ?? $code));
            $items[] = [
                'value' => $code,
                'label' => $name !== $code ? ($name . ' (' . $code . ')') : $code,
                'code' => $code,
                'name' => $name,
                'description' => (string)($model['description'] ?? ''),
                'max_tokens' => (int)($model['max_tokens'] ?? 0),
                'context_window' => (int)($model['context_window'] ?? 0),
                'primary_modality' => (string)($model['primary_modality'] ?? ''),
            ];
        }
        return $items;
    }

    /**
     * @param array<int,array<string,mixed>> $models
     */
    private function resolveDefaultModelCode(array $models, string $configuredDefault): string
    {
        $configuredDefault = trim($configuredDefault);
        $first = '';
        foreach ($models as $model) {
            $code = trim((string)($model['code'] ?? $model['value'] ?? ''));
            if ($code === '') {
                continue;
            }
            if ($first === '') {
                $first = $code;
            }
            if ($configuredDefault !== '' && strcasecmp($code, $configuredDefault) === 0) {
                return $code;
            }
        }
        return $first;
    }

    /**
     * 获取供应商信息（供模型表单选择供应商后自动填充）
     *
     * @return \Weline\Framework\Http\Response|string
     */
    public function getProviderInfo()
    {
        $providerCode = $this->normalizeProviderCode((string)($this->request->getParam('provider_code') ?? $this->request->getGet('provider_code') ?? ''));
        if ($providerCode === '') {
            return $this->fetchJson(['success' => false, 'message' => __('供应商代码不能为空')]);
        }
        $config = VendorConfigManager::getProviderConfig($providerCode);
        if (!$config) {
            return $this->fetchJson(['success' => false, 'message' => __('供应商不存在: %{1}', [$providerCode])]);
        }
        $info = [
            'name' => $config['name'] ?? $providerCode,
            'code' => $config['code'] ?? $providerCode,
            'base_url' => $config['base_url'] ?? '',
            'test_model' => $config['test_model'] ?? '',
            'account_setup_guide' => $config['account_setup_guide'] ?? [],
            'model_config_defaults' => $config['model_config_defaults'] ?? [],
            'models' => array_map(function ($m) {
                return [
                    'code' => $m['code'] ?? '',
                    'name' => $m['name'] ?? $m['code'] ?? '',
                ];
            }, $config['models'] ?? []),
        ];
        return $this->fetchJson(['success' => true, 'data' => $info]);
    }

    /**
     * Offcanvas 编辑/新建账户（供侧边栏加载）
     */
    #[Acl('Weline_Ai::ai_provider_edit_offcanvas', '编辑供应商账户（侧边栏）', 'mdi-pencil', '编辑供应商账户（侧边栏）')]
    public function editOffcanvas(): string
    {
        $this->layoutType = 'default.blank';
        $id = (int)($this->request->getParam('id') ?? 0);
        $account = null;
        if ($id > 0) {
            /** @var Account $account */
            $account = ObjectManager::getInstance(Account::class)->load($id);
            if (!$account->getId()) {
                $account = null;
            } else {
                $this->normalizeAccountModelBaseUrl($account);
            }
        }
        $this->assign('account', $account);
        $this->assign('providers', $this->accountService->getSupportedProviders());
        return $this->fetch('offcanvas_edit');
    }

    /**
     * 添加账户页面
     */
    public function add()
    {
        $this->assign('title', __('添加供应商账户'));
        $this->assign('providers', $this->accountService->getSupportedProviders());
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
                throw new \Exception(__('账户ID无效'));
            }

            /** @var Account $account */
            $account = ObjectManager::getInstance(Account::class)->load($id);
            if (!$account->getId()) {
                throw new \Exception(__('账户不存在'));
            }

            $this->assign('title', __('编辑供应商账户'));
            $this->normalizeAccountModelBaseUrl($account);
            $this->assign('providers', $this->accountService->getSupportedProviders());
            $this->assign('account', $account);
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
            
            // 如果是JSON请求，尝试从body参数获取数据
            if (empty($data['provider_code']) && $this->request->isPost()) {
                $bodyData = $this->request->getBodyParams(true);
                if (is_array($bodyData) && !empty($bodyData)) {
                    $data = array_merge($data, $bodyData);
                }
            }
            
            
            $id = (int)($data['id'] ?? 0);

            /** @var Account $account */
            $account = ObjectManager::getInstance(Account::class);
            if ($id > 0) {
                $account->load($id);
                if (!$account->getId()) {
                    throw new \Exception(__('账户不存在'));
                }
            }

            // 验证必填字段
            if (empty($data['provider_code'])) {
                throw new \Exception(__('请选择供应商'));
            }
            if (empty($data['account_name'])) {
                throw new \Exception(__('请输入账户名称'));
            }
            if (empty($data['api_key']) && !$account->getId()) {
                throw new \Exception(__('请输入API密钥'));
            }

            // 设置基本信息
            $account->setData(Account::schema_fields_PROVIDER_CODE, $this->normalizeProviderCode((string)$data['provider_code']));
            $account->setData(Account::schema_fields_ACCOUNT_NAME, $data['account_name']);
            
            // 只有当提供新的API密钥时才更新
            if (!empty($data['api_key'])) {
                $account->setEncryptedApiKey($data['api_key']);
            }
            
            // 设置其他字段
            if (!empty($data['api_secret'])) {
                $account->setData(Account::schema_fields_API_SECRET, $data['api_secret']);
            }
            
            $account->setData(
                Account::schema_fields_BASE_URL,
                $this->normalizeProviderBaseUrl((string)$data['provider_code'], (string)($data['base_url'] ?? ''))
            );
            $account->setData(Account::schema_fields_BALANCE, (float)($data['balance'] ?? 0));
            $account->setData(Account::schema_fields_CURRENCY, $data['currency'] ?? 'USD');
            $account->setData(Account::schema_fields_IS_ACTIVE, (int)($data['is_active'] ?? 0));
            
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
                $account->setData(Account::schema_fields_PROXY_CONFIG, json_encode($proxyConfig));
            } else {
                $account->setData(Account::schema_fields_PROXY_CONFIG, null);
            }

            // 保存时间戳
            if (!$account->getId()) {
                $account->setData(Account::schema_fields_CREATED_AT, time());
                $account->setData(Account::schema_fields_CONNECTION_STATUS, Account::STATUS_PENDING);
            }
            $account->setData(Account::schema_fields_UPDATED_AT, time());

            $account->save();

            // 如果设置为默认账户
            if (!empty($data['is_default'])) {
                $this->accountService->setDefaultAccount($account);
            }

            return $this->fetchJson([
                'success' => true,
                'message' => __('账户保存成功'),
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
    public function postDelete()
    {
        try {
            $id = (int)$this->request->getParam('id');
            if (!$id) {
                throw new \Exception(__('账户ID无效'));
            }

            /** @var Account $account */
            $account = ObjectManager::getInstance(Account::class)->load($id);
            if (!$account->getId()) {
                throw new \Exception(__('账户不存在'));
            }

            // 检查是否有使用记录
            /** @var UsageRecord $usageRecord */
            $usageRecord = ObjectManager::getInstance(UsageRecord::class);
            $hasUsage = $usageRecord->where(UsageRecord::schema_fields_ACCOUNT_ID, $id)->count() > 0;
            
            if ($hasUsage) {
                throw new \Exception(__('该账户有使用记录，无法删除'));
            }

            $account->delete()->fetch();

            return $this->fetchJson([
                'success' => true,
                'message' => __('账户删除成功')
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
            $data = $this->request->getContent();
            $data = $data ? json_decode($data, true) : [];
            
            // 检查是否是测试请求（不保存到数据库）
            if (isset($data['test_only']) && $data['test_only']) {
                return $this->testConnectionOnly($data);
            }
            
            // 原有的测试已保存账户的逻辑
            $id = (int)$this->request->getParam('id');
            if (!$id && isset($data['id'])) {
                $id = (int)$data['id'];
            }
            
            if (!$id) {
                throw new \Exception(__('账户ID无效'));
            }

            /** @var Account $account */
            $account = ObjectManager::getInstance(Account::class)->load($id);
            if (!$account->getId()) {
                throw new \Exception(__('账户不存在'));
            }

            $testModelCode = trim((string)($data['model_code'] ?? $this->request->getParam('model_code') ?? ''));
            $result = $this->accountService->testConnection(
                $account,
                $testModelCode !== '' ? $testModelCode : null,
                $this->buildConnectionTestOptions($data)
            );
            
            // 测试完成后，重新加载账户以获取最新状态
            $account->reset()->load($id);
            
            // 如果测试失败且账户是激活状态，取消激活
            if (!$result['success'] && $account->getData(Account::schema_fields_IS_ACTIVE)) {
                $account->setData(Account::schema_fields_IS_ACTIVE, 0);
                $account->setData(Account::schema_fields_CONNECTION_STATUS, Account::STATUS_FAILED);
                $account->setData(Account::schema_fields_CONNECTION_TEST_MESSAGE, $result['message']);
                $account->setData(Account::schema_fields_CONNECTION_TEST_TIME, time());
                $account->save();
            }
            
            // 在返回结果中包含更新后的连接状态
            $result['connection_status'] = $account->getData(Account::schema_fields_CONNECTION_STATUS);
            $result['connection_test_time'] = $account->getData(Account::schema_fields_CONNECTION_TEST_TIME);
            $result['connection_test_message'] = $account->getData(Account::schema_fields_CONNECTION_TEST_MESSAGE);
            
            return $this->fetchJson($result);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * 仅测试连接（不保存到数据库）
     */
    private function testConnectionOnly($data)
    {
        try {
            // 验证必要字段
            if (empty($data['provider_code'])) {
                throw new \Exception('请选择供应商');
            }
            if (empty($data['api_key'])) {
                throw new \Exception('请输入API密钥');
            }
            
            // 创建临时账户对象进行测试
            /** @var Account $tempAccount */
            $tempAccount = ObjectManager::getInstance(Account::class);
            $tempAccount->setData(Account::schema_fields_PROVIDER_CODE, $this->normalizeProviderCode((string)$data['provider_code']));
            $tempAccount->setEncryptedApiKey($data['api_key']);
            $tempAccount->setData(
                Account::schema_fields_BASE_URL,
                $this->normalizeProviderBaseUrl((string)$data['provider_code'], (string)($data['base_url'] ?? ''))
            );
            
            // 如果有代理配置
            if (!empty($data['proxy_enabled'])) {
                $proxyConfig = [
                    'enabled' => true,
                    'type' => $data['proxy_type'] ?? 'http',
                    'host' => $data['proxy_host'] ?? '',
                    'port' => $data['proxy_port'] ?? '',
                    'username' => $data['proxy_username'] ?? '',
                    'password' => $data['proxy_password'] ?? ''
                ];
                $tempAccount->setData(Account::schema_fields_PROXY_CONFIG, json_encode($proxyConfig));
            }
            
            $testModelCode = trim((string)($data['model_code'] ?? ''));
            $result = $this->accountService->testConnection(
                $tempAccount,
                $testModelCode !== '' ? $testModelCode : null,
                $this->buildConnectionTestOptions($data)
            );
            
            return $this->fetchJson($result);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function buildConnectionTestOptions(array $data): array
    {
        $options = [];
        $primaryModality = trim((string)($data['primary_modality'] ?? ''));
        if ($primaryModality !== '') {
            $options['primary_modality'] = AiModel::normalizePrimaryModality($primaryModality);
        }
        if (is_array($data['capabilities'] ?? null)) {
            $options['capabilities'] = $data['capabilities'];
        }
        if (is_array($data['provider_config'] ?? null)) {
            $options['provider_config'] = $data['provider_config'];
        }

        return $options;
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
            $account = ObjectManager::getInstance(Account::class)->load($id);
            if (!$account->getId()) {
                throw new \Exception('账户不存在');
            }

            $isActive = !$account->getData(Account::schema_fields_IS_ACTIVE);
            
            // 如果要激活账户，先检查连接状态
            if ($isActive && $account->getData(Account::schema_fields_CONNECTION_STATUS) !== Account::STATUS_SUCCESS) {
                throw new \Exception(__('请先测试连接成功后再激活账户'));
            }

            $account->setData(Account::schema_fields_IS_ACTIVE, $isActive ? 1 : 0);
            $account->setData(Account::schema_fields_UPDATED_AT, time());
            $account->save();

            return $this->fetchJson([
                'success' => true,
                'message' => $isActive ? __('账户已激活') : __('账户已停用'),
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
        $this->assign('title', __('使用记录'));
        $this->assign('account_id', $accountId);
        
        if ($accountId) {
            /** @var Account $account */
            $account = ObjectManager::getInstance(Account::class)->load($accountId);
            if ($account->getId()) {
                $this->assign('account', $account);
            }
        }
        
        return $this->fetch();
    }

    /**
     * 获取连接状态文本
     */
    private function getConnectionStatusText(string $status): string
    {
        $statusMap = [
            'pending' => __('待测试'),
            'success' => __('连接正常'),
            'failed' => __('连接失败'),
            'testing' => __('测试中')
        ];
        
        return $statusMap[$status] ?? __('未知状态');
    }

    /**
     * 获取单个账户数据（POST）
     */
    public function postAccount()
    {
        try {
            $data = $this->request->getContent() ? json_decode($this->request->getContent(), true) : [];
            $id = $data['id'] ?? $this->request->getParam('id');
            
            if (!$id) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('账户ID不能为空')
                ]);
            }
            
            /** @var Account $accountModel */
            $accountModel = ObjectManager::getInstance(Account::class);
            $account = $accountModel->load($id);
            
            if (!$account->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('账户不存在')
                ]);
            }
            
            $accountData = $this->normalizeAccountBaseUrlForOutput($account->getData());
            // 安全处理：不返回明文API Key，仅返回掩码
            if (!empty($accountData['api_key'])) {
                $len = strlen((string)$accountData['api_key']);
                $accountData['api_key_masked'] = $len > 8
                    ? str_repeat('*', max(0, $len - 4)) . substr($accountData['api_key'], -4)
                    : str_repeat('*', $len);
                $accountData['api_key'] = '';
            } else {
                $accountData['api_key_masked'] = '';
                $accountData['api_key'] = '';
            }
            
            // 处理代理配置
            if (!empty($accountData['proxy_config'])) {
                try {
                    $accountData['proxy_config'] = json_decode($accountData['proxy_config'], true);
                } catch (\Exception $e) {
                    $accountData['proxy_config'] = null;
                }
            }
            
            return $this->fetchJson([
                'success' => true,
                'data' => $accountData
            ]);
            
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('获取账户数据失败: %{msg}', ['msg' => $e->getMessage()])
            ]);
        }
    }

    /**
     * 获取单个账户数据（GET）
     */
    public function getAccount()
    {
        try {
            $id = $this->request->getParam('id');
            if (!$id) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('账户ID不能为空')
                ]);
            }

            /** @var Account $accountModel */
            $accountModel = ObjectManager::getInstance(Account::class);
            $account = $accountModel->load((int)$id);

            if (!$account->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('账户不存在')
                ]);
            }

            $accountData = $this->normalizeAccountBaseUrlForOutput($account->getData());
            // 安全处理：不返回明文API Key，仅返回掩码
            if (!empty($accountData['api_key'])) {
                $len = strlen((string)$accountData['api_key']);
                $accountData['api_key_masked'] = $len > 8
                    ? str_repeat('*', max(0, $len - 4)) . substr($accountData['api_key'], -4)
                    : str_repeat('*', $len);
                $accountData['api_key'] = '';
            } else {
                $accountData['api_key_masked'] = '';
                $accountData['api_key'] = '';
            }
            if (!empty($accountData['proxy_config'])) {
                try {
                    $accountData['proxy_config'] = json_decode($accountData['proxy_config'], true);
                } catch (\Exception $e) {
                    $accountData['proxy_config'] = null;
                }
            }

            return $this->fetchJson([
                'success' => true,
                'data' => $accountData
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('获取账户数据失败: %{msg}', ['msg' => $e->getMessage()])
            ]);
        }
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
            $usageModel = ObjectManager::getInstance(UsageRecord::class);
            
            if ($accountId) {
                $usageModel->where(UsageRecord::schema_fields_ACCOUNT_ID, $accountId);
            }
            
            if ($dateFrom) {
                $usageModel->where(UsageRecord::schema_fields_CREATED_AT, strtotime($dateFrom), '>=');
            }
            
            if ($dateTo) {
                $usageModel->where(UsageRecord::schema_fields_CREATED_AT, strtotime($dateTo . ' 23:59:59'), '<=');
            }

            $total = $usageModel->count();
            $records = $usageModel->order(UsageRecord::schema_fields_CREATED_AT, 'DESC')
                ->pagination($page, $limit)
                ->select()
                ->fetchArray();

            if (!is_array($records)) {
                $records = [];
            }

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
        $usageModel = ObjectManager::getInstance(UsageRecord::class);
        
        if ($accountId) {
            $usageModel->where(UsageRecord::schema_fields_ACCOUNT_ID, $accountId);
        }
        
        if ($dateFrom) {
            $usageModel->where(UsageRecord::schema_fields_CREATED_AT, strtotime($dateFrom), '>=');
        }
        
        if ($dateTo) {
            $usageModel->where(UsageRecord::schema_fields_CREATED_AT, strtotime($dateTo . ' 23:59:59'), '<=');
        }
        
        $stats = $usageModel->fields(
            'COUNT(*) AS total_requests,' .
            'SUM(' . UsageRecord::schema_fields_TOTAL_TOKENS . ') AS total_tokens,' .
            'SUM(' . UsageRecord::schema_fields_TOTAL_COST . ') AS total_cost,' .
            'AVG(' . UsageRecord::schema_fields_TOTAL_COST . ') AS avg_cost,' .
            'SUM(CASE WHEN ' . UsageRecord::schema_fields_STATUS . ' = "success" THEN 1 ELSE 0 END) AS success_count,' .
            'SUM(CASE WHEN ' . UsageRecord::schema_fields_STATUS . ' = "failed" THEN 1 ELSE 0 END) AS failed_count'
        )->find()->fetch();

        if (!$stats || !is_array($stats)) {
            $stats = [
                'total_requests' => 0,
                'total_tokens' => 0,
                'total_cost' => 0,
                'avg_cost' => 0,
                'success_count' => 0,
                'failed_count' => 0,
            ];
        }
        
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
}
