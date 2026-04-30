<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：<?= date('Y/m/d H:i:s') ?>

 */

namespace Weline\Ai\Controller\Backend;

use Weline\Ai\Model\AiModel;
use Weline\Ai\Service\ModelCollector;
use Weline\Ai\Service\Provider\AccountService;
use Weline\Ai\Service\Provider\VendorConfigManager;
use Weline\Ai\Service\Provider\ModelSyncService;
use Weline\Ai\Model\Provider\Account;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Manager\Message;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Env;

/**
 * AI模型管理后台控制器
 * 
 * 功能：
 * - AI模型列表展示
 * - 模型详情查看
 * - 模型状态管理
 * - 模型收集和更新
 */
#[Acl('Weline_Ai::ai_model_manager', 'AI模型管理', 'mdi-robot', 'AI模型管理', 'Weline_Backend::ai_group')]
class Model extends BackendController
{
    private bool $vendorModelsEnsured = false;

    /**
     * 获取AI模型（懒加载）
     */
    private function getAiModel(): AiModel
    {
        return \Weline\Framework\Manager\ObjectManager::getInstance(AiModel::class);
    }

    /**
     * 获取模型收集器（懒加载）
     */
    private function getModelCollector(): ModelCollector
    {
        return \Weline\Framework\Manager\ObjectManager::getInstance(ModelCollector::class);
    }

    /**
     * 获取模型同步服务（懒加载）
     */
    private function getModelSyncService(): ModelSyncService
    {
        return \Weline\Framework\Manager\ObjectManager::getInstance(ModelSyncService::class);
    }

    private function getTestRequestData(): array
    {
        $data = $this->request->getParams();
        if (!is_array($data)) {
            $data = [];
        }

        $raw = method_exists($this->request, 'getContent') ? ($this->request->getContent() ?: '') : '';
        if (!empty($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = array_replace_recursive($data, $decoded);
            }
        }

        return $data;
    }

    private function isTestOnlyRequest(array $data): bool
    {
        $value = $data['test_only'] ?? false;
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (bool)$value;
        }

        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function buildTestModel(AiModel $model, array $data): AiModel
    {
        $testModel = clone $model;
        $this->applyTestRequestToModel($testModel, $data);
        return $testModel;
    }

    private function applyTestRequestToModel(AiModel $model, array $data): void
    {
        $supplier = trim((string)($data['supplier'] ?? $data['vendor'] ?? ''));
        if ($supplier !== '') {
            $model->setData(AiModel::schema_fields_SUPPLIER, $supplier);
        }

        $modelCode = trim((string)($data['model_code'] ?? ''));
        if ($modelCode !== '') {
            $model->setData(AiModel::schema_fields_MODEL_CODE, $modelCode);
        }

        $providerModelCode = trim((string)($data['provider_model_code'] ?? ''));
        if ($providerModelCode === '') {
            $providerModelCode = (string)($data['model_code'] ?? $model->getData(AiModel::schema_fields_MODEL_CODE) ?? '');
        }

        if (isset($data['config']) && is_array($data['config']) || array_key_exists('config_json', $data)) {
            $config = $this->buildIncomingConfigData($data);
            $model->setData(AiModel::schema_fields_CONFIG, $this->encodeJsonConfig($config));
        }

        if (isset($data['provider_config']) && is_array($data['provider_config']) || array_key_exists('provider_config_json', $data)) {
            $config = $this->buildIncomingConfigData($data);
            $providerConfig = $this->buildIncomingProviderConfigData($data, $providerModelCode, $config);
            $model->setData(AiModel::schema_fields_PROVIDER_CONFIG, $this->encodeJsonConfig($providerConfig));
        }
    }

    private function hasSelfConfig(AiModel $model): bool
    {
        return $this->hasApiCredential($model->getProviderConfig()) || $this->hasApiCredential($model->getConfig());
    }

    private function hasApiCredential(array $config): bool
    {
        return !empty($config['api_key']) || !empty($config['api_key_env']);
    }

    private function getRequestedProviderAccountId(AiModel $model): int
    {
        $providerConfig = $model->getProviderConfig();
        return isset($providerConfig['account_id']) ? (int)$providerConfig['account_id'] : 0;
    }

    private function getBoundProviderAccountUnavailableMessage(?Account $account, int $requestedAccountId): ?string
    {
        if ($requestedAccountId <= 0) {
            return null;
        }

        if (!$account || !$account->getId()) {
            return (string)__('模型绑定的供应商账户不存在（account_id: %{1}）', [$requestedAccountId]);
        }

        if ((int)$account->getData(Account::schema_fields_IS_ACTIVE) !== 1) {
            return (string)__('模型绑定的供应商账户未启用（account_id: %{1}，account_name: %{2}）', [
                $requestedAccountId,
                (string)($account->getData(Account::schema_fields_ACCOUNT_NAME) ?: '-')
            ]);
        }

        return null;
    }

    private function normalizeBooleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (bool)$value;
        }

        $normalized = strtolower(trim((string)$value));
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function filterConfigValues(array $config): array
    {
        return array_filter($config, static function ($value) {
            if (is_array($value)) {
                return !empty($value);
            }
            return $value !== '' && $value !== null;
        });
    }

    private function decodeStoredConfig(mixed $config): array
    {
        if (is_array($config)) {
            return $config;
        }
        if (!is_string($config) || trim($config) === '') {
            return [];
        }

        $decoded = json_decode($config, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function isSelectedModelRow(array $row, array $supportedProviders): bool
    {
        if (!empty($row[AiModel::schema_fields_IS_ACTIVE]) || !empty($row[AiModel::schema_fields_IS_DEFAULT])) {
            return true;
        }
        if (!empty($row['is_copy']) || !empty($row['is_copied'])) {
            return true;
        }

        $supplier = trim((string)($row[AiModel::schema_fields_SUPPLIER] ?? ($row['vendor'] ?? '')));
        $modelSource = (string)($row[AiModel::schema_fields_MODEL_SOURCE] ?? '');
        if ($modelSource === AiModel::SOURCE_LOCAL || ($supplier !== '' && !isset($supportedProviders[$supplier]))) {
            return true;
        }

        $config = $this->decodeStoredConfig($row[AiModel::schema_fields_CONFIG] ?? null);
        $providerConfig = $this->decodeStoredConfig($row[AiModel::schema_fields_PROVIDER_CONFIG] ?? null);
        if ($this->hasApiCredential($config) || $this->hasApiCredential($providerConfig)) {
            return true;
        }
        if (!empty($config['selected_model_preset']) || !empty($providerConfig['selected_model_preset'])) {
            return true;
        }
        if (!empty($providerConfig['account_id'])) {
            return true;
        }

        foreach (['connection_test_status', 'self_config_test_status', 'provider_test_status'] as $statusField) {
            $status = (string)($row[$statusField] ?? '');
            if ($status === 'success' || $status === 'failed') {
                return true;
            }
        }

        return false;
    }

    private function normalizePricePerThousand(float $price, string $priceUnit): float
    {
        if ($price <= 0) {
            return 0.0;
        }
        if ($priceUnit === 'per_1m_tokens') {
            return round($price / 1000, 6);
        }
        return round($price, 6);
    }

    private function buildIncomingConfigData(array $data): array
    {
        $config = [];

        if (array_key_exists('config_json', $data)) {
            $configJson = trim((string)$data['config_json']);
            if ($configJson !== '') {
                $config = $this->decodeJsonConfig($configJson, 'config_json');
            }
        }

        if (isset($data['config']) && is_array($data['config'])) {
            $config = array_replace($config, $data['config']);
        }

        $quickApiKey = trim((string)($data['api_key_input'] ?? $data['api_key'] ?? ''));
        if ($quickApiKey !== '') {
            $config['api_key'] = $quickApiKey;
        }

        $config = $this->filterConfigValues($config);

        if (array_key_exists('stream', $config)) {
            $config['stream'] = $this->normalizeBooleanValue($config['stream']);
        }

        if (isset($config['api_key'])) {
            $config['api_key'] = $this->normalizeApiKeyValue((string)$config['api_key']);
        }

        if (isset($config['api_url'])) {
            $config['api_url'] = trim((string)$config['api_url']);
        }

        if (isset($config['base_url'])) {
            $config['base_url'] = trim((string)$config['base_url']);
        }

        return $config;
    }

    private function buildIncomingProviderConfigData(array $data, string $providerModelCode, array $config = []): array
    {
        $providerConfig = [];
        $requestedAccountId = null;

        if (array_key_exists('provider_config_json', $data)) {
            $providerConfigJson = trim((string)$data['provider_config_json']);
            if ($providerConfigJson !== '') {
                $providerConfig = $this->decodeJsonConfig($providerConfigJson, 'provider_config_json');
            }
        }

        if (isset($data['provider_config']) && is_array($data['provider_config'])) {
            if (array_key_exists('account_id', $data['provider_config'])) {
                $requestedAccountId = trim((string)$data['provider_config']['account_id']);
            }
            $providerConfig = array_replace($providerConfig, $data['provider_config']);
        }

        $providerConfig = $this->filterConfigValues($providerConfig);

        if ($requestedAccountId !== null && $requestedAccountId === '') {
            unset($providerConfig['account_id']);
        }

        if (!empty($config['api_key'])) {
            $providerConfig['api_key'] = $config['api_key'];
        }

        if (!empty($config['api_key_env']) && empty($providerConfig['api_key'])) {
            $providerConfig['api_key_env'] = $config['api_key_env'];
        }

        if (!empty($config['api_url'])) {
            $providerConfig['base_url'] = trim((string)$config['api_url']);
        }

        if (!empty($config['base_url'])) {
            $providerConfig['base_url'] = trim((string)$config['base_url']);
        }

        if (array_key_exists('stream', $providerConfig)) {
            $providerConfig['stream'] = $this->normalizeBooleanValue($providerConfig['stream']);
        }

        if (isset($providerConfig['api_key'])) {
            $providerConfig['api_key'] = $this->normalizeApiKeyValue((string)$providerConfig['api_key']);
        }

        if (isset($providerConfig['base_url'])) {
            $providerConfig['base_url'] = trim((string)$providerConfig['base_url']);
        }

        if ($providerModelCode !== '') {
            $providerConfig['provider_model_code'] = $providerModelCode;
            $providerConfig['model'] = $providerModelCode;
            $providerConfig['model_id'] = $providerModelCode;
        }

        return $providerConfig;
    }

    private function encodeJsonConfig(array $config): string
    {
        return empty($config) ? '' : json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function normalizeApiKeyValue(string $apiKey): string
    {
        $apiKey = trim($apiKey);

        // Repair the known duplicated-prefix corruption seen on OpenAI-style keys: sk-... -> ssk-...
        if (str_starts_with($apiKey, 'ssk-')) {
            return 'sk-' . substr($apiKey, 4);
        }

        return $apiKey;
    }

    private function decodeJsonConfig(string $json, string $fieldName): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException(__('字段 %{field} 不是有效的 JSON 配置', ['field' => $fieldName]));
        }

        return $decoded;
    }

    /**
     * 模型列表页面
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_list', '查看AI模型列表', 'mdi-view-list', '查看AI模型列表')]
    public function index(): string
    {
        // The static vendor catalog is only used as quick-create presets; do not flood the list with every vendor model.
        if ($this->request->isAjax() || $this->request->getGet('format') === 'json') {
            return $this->indexJson();
        }
        if ($this->request->getGet('embed') === '1') {
            $this->layoutType = 'default.blank';
        }
        try {
            $page = max(1, (int)$this->request->getGet('page', 1));
            $pageSize = 20;
            $vendorFilter = trim((string)$this->request->getGet('vendor', ''));
            $statusFilter = trim((string)$this->request->getGet('status', ''));
            $searchFilter = trim((string)$this->request->getGet('search', ''));

            // 先按供应商/状态做数据库过滤，再在内存中做搜索匹配（名称/代码/供应商）
            $query = $this->getAiModel()->reset();
            if ($vendorFilter !== '') {
                $query->where(AiModel::schema_fields_SUPPLIER, $vendorFilter);
            }
            if ($statusFilter === '1' || $statusFilter === '0') {
                $query->where(AiModel::schema_fields_IS_ACTIVE, (int)$statusFilter);
            }

            $allFilteredRows = $query
                ->order(AiModel::schema_fields_CREATED_AT, 'DESC')
                ->select()
                ->fetchArray();

            $supportedProviders = VendorConfigManager::getSupportedProviders();
            $allFilteredRows = array_values(array_filter($allFilteredRows, function (array $row) use ($supportedProviders): bool {
                return $this->isSelectedModelRow($row, $supportedProviders);
            }));

            if ($searchFilter !== '') {
                $needle = mb_strtolower($searchFilter);
                $allFilteredRows = array_values(array_filter($allFilteredRows, static function (array $row) use ($needle): bool {
                    $haystacks = [
                        (string)($row['name'] ?? ''),
                        (string)($row['model_code'] ?? ''),
                        (string)($row['supplier'] ?? ''),
                        (string)($row['vendor'] ?? ''),
                    ];
                    foreach ($haystacks as $text) {
                        if ($text !== '' && str_contains(mb_strtolower($text), $needle)) {
                            return true;
                        }
                    }
                    return false;
                }));
            }

            $total = count($allFilteredRows);
            $totalPages = max(1, (int)ceil($total / $pageSize));
            $page = min($page, $totalPages);
            $offset = ($page - 1) * $pageSize;
            $modelData = array_slice($allFilteredRows, $offset, $pageSize);

            $models = [];
            foreach ($modelData as $data) {
                // 检查配置状态
                $config = $data['config'] ?? '';
                $providerConfig = $data['provider_config'] ?? '';
                $hasApiKey = false;
                
                // 检查是否有API密钥
                if (!empty($providerConfig)) {
                    $providerData = is_string($providerConfig) ? json_decode($providerConfig, true) : $providerConfig;
                    $hasApiKey = !empty($providerData['api_key']);
                }
                if (!$hasApiKey && !empty($config)) {
                    $configData = is_string($config) ? json_decode($config, true) : $config;
                    $hasApiKey = !empty($configData['api_key']);
                }
                
                // 检查自配置连通性状态
                $selfConfigTestStatus = 'pending';
                if ($hasApiKey) {
                    $selfConfigTestStatus = $data['self_config_test_status'] ?? 'pending';
                } else {
                    $selfConfigTestStatus = 'no_config';
                }
                
                // 检查供应商连通性状态
                $providerTestStatus = $data['provider_test_status'] ?? 'pending';
                
                $supplier = (string)($data['supplier'] ?? '');
                $modelSource = (string)($data[AiModel::schema_fields_MODEL_SOURCE] ?? '');
                $isCustomSupplier = ($supplier !== '' && !isset($supportedProviders[$supplier]));
                $isCustomModel = $isCustomSupplier || $modelSource === AiModel::SOURCE_LOCAL;
                $priceCurrency = (string)($supportedProviders[$supplier]['price_currency'] ?? 'USD');

                $models[] = [
                    'id' => $data['id'] ?? '',
                    // 显示供应商：优先使用配置中的vendor，退回到数据库字段supplier
                    'vendor' => $data['vendor'] ?? ($data['supplier'] ?? ''),
                    'supplier' => $supplier,
                    'name' => $data['name'] ?? '',
                    'model_code' => $data['model_code'] ?? '',
                    'primary_modality' => AiModel::normalizePrimaryModality((string)($data['primary_modality'] ?? '')),
                    'version' => $data['version'] ?? '',
                    'status' => $data['status'] ?? '',
                    'is_active' => $data['is_active'] ?? 0,
                    'is_default' => $data['is_default'] ?? 0,
                    'created_at' => $data['created_at'] ?? '',
                    'is_copied' => $data['is_copied'] ?? 0,
                    'token_price_input' => $data['token_price_input'] ?? 0,
                    'token_price_output' => $data['token_price_output'] ?? 0,
                    'price_currency' => $priceCurrency,
                    'has_api_key' => $hasApiKey,
                    'has_config' => !empty($config) || !empty($providerConfig),
                    'connection_test_status' => $data['connection_test_status'] ?? 'pending',
                    'connection_test_time' => $data['connection_test_time'] ?? 0,
                    'self_config_test_status' => $selfConfigTestStatus,
                    'provider_test_status' => $providerTestStatus,
                    'is_custom_model' => $isCustomModel,
                    'is_custom_supplier' => $isCustomSupplier,
                ];
            }

            // 渲染前按ID倒序（保障前端显示顺序），与数据库排序双保险
            usort($models, function ($a, $b) {
                $aid = (int)($a['id'] ?? 0);
                $bid = (int)($b['id'] ?? 0);
                return $bid <=> $aid;
            });

            // 获取所有供应商列表（用于筛选）
            // 注意：不能只从当前分页 models 提取，否则会漏掉非当前页供应商（如 deepseek）。
            $vendors = $this->collectAllVendorsForFilter();

            $pagination = [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_prev' => $page > 1,
                'has_next' => $page < $totalPages,
                'prev_page' => max(1, $page - 1),
                'next_page' => min($totalPages, $page + 1),
            ];

            $this->assign('models', $models);
            $this->assign('pagination', $pagination);
            $this->assign('total', (string)$total);
            $this->assign('vendors', $vendors);
            $this->assign('currentVendor', $vendorFilter);
            $this->assign('currentStatus', $statusFilter);
            $this->assign('currentSearch', $searchFilter);
            $this->assign('embed', ($this->request->getGet('embed') === '1' || $this->request->getGet('embed') === true));
            $this->assign('activeTab', 'model');

            return $this->fetch();
        } catch (\Exception $e) {
            // 如果出现错误，显示错误信息
            $this->assign('error', $e->getMessage());
            $this->assign('models', []);
            $this->assign('total', '0');
            $this->assign('vendors', []);
            $this->assign('embed', ($this->request->getGet('embed') === '1' || $this->request->getGet('embed') === true));
            $this->assign('activeTab', 'model');
            return $this->fetch();
        }
    }

    private function indexJson(): string
    {
        try {
            $models = $this->getAiModel()->reset()
                ->order(\Weline\Ai\Model\AiModel::schema_fields_CREATED_AT, 'DESC')
                ->select()
                ->fetchArray();
            $supportedProviders = VendorConfigManager::getSupportedProviders();
            $models = array_values(array_filter($models, function (array $row) use ($supportedProviders): bool {
                return $this->isSelectedModelRow($row, $supportedProviders);
            }));
            usort($models, function ($a, $b) {
                $aid = (int)($a['id'] ?? 0);
                $bid = (int)($b['id'] ?? 0);
                return $bid <=> $aid;
            });
            return $this->jsonResponse([
                'success' => true,
                'total' => count($models),
                'items' => $models,
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 模型详情页面（完整页面）
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_detail', '查看AI模型详情', 'mdi-information', '查看AI模型详情')]
    public function detail(): string
    {
        $this->layoutType = 'default.blank';
        $id = (int)$this->request->getGet('id');
        
        if (!$id) {
            Message::error(__('模型ID不能为空'));
            return $this->redirect($this->_url->getBackendUrl('*/backend/model'));
        }

        $model = $this->getAiModel()->reset()->load($id);
        
        if (!$model->getId()) {
            Message::error(__('模型不存在'));
            return $this->redirect($this->_url->getBackendUrl('*/backend/model'));
        }

        $this->assign('model', $model);
        $this->assign('config', $model->getConfig());
        $this->assign('proxyInfo', $model->getProxyInfo());

        return $this->fetch();
    }

    /**
     * Offcanvas 模型详情（侧边栏）
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_detail', '查看AI模型详情', 'mdi-information', '查看AI模型详情')]
    public function detailOffcanvas(): string
    {
        $this->layoutType = 'default.blank';
        $id = (int)$this->request->getGet('id');
        
        if (!$id) {
            return '<div class="alert alert-danger p-3">' 
                . '<i class="bi bi-exclamation-triangle me-2"></i>' 
                . __('模型ID不能为空') 
                . '</div>';
        }

        $model = $this->getAiModel()->reset()->load($id);
        
        if (!$model->getId()) {
            return '<div class="alert alert-danger p-3">' 
                . '<i class="bi bi-exclamation-triangle me-2"></i>' 
                . __('模型不存在') 
                . '</div>';
        }

        $this->assign('model', $model);
        $this->assign('config', $model->getConfig());
        $proxyInfo = $model->getProxyInfo();
        if (is_string($proxyInfo) && !empty($proxyInfo)) {
            $proxyInfo = json_decode($proxyInfo, true);
        }
        $this->assign('proxyInfo', is_array($proxyInfo) ? $proxyInfo : []);

        return $this->fetch('offcanvas_detail');
    }

    /**
     * 收集模型
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_collect', '收集AI模型', 'mdi-download', '收集AI模型配置')]
    public function collect(): string
    {
        try {
            $result = $this->getModelSyncService()->syncAllProviders([
                'collect' => true,
            ]);
            $collectedCount = (int)($result['collected_count'] ?? 0);
            $providers = $result['providers'] ?? [];
            $providerSummary = [];
            foreach ($providers as $providerCode => $providerResult) {
                if (!empty($providerResult['success'])) {
                    $providerSummary[] = sprintf(
                        '%s=%d',
                        $providerCode,
                        (int)($providerResult['count'] ?? 0)
                    );
                } else {
                    $providerSummary[] = sprintf('%s=failed', $providerCode);
                }
            }

            return $this->jsonResponse([
                'success' => true,
                'message' => __('模型同步完成，收集 %{1} 个模型', [$collectedCount]),
                'count' => $collectedCount,
                'provider_summary' => $providerSummary,
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('模型同步失败: %{1}', [$e->getMessage()]),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 切换模型状态
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_toggle', '切换AI模型状态', 'mdi-toggle-switch', '启用或禁用AI模型')]
    public function toggleStatus(): string
    {
        $id = (int)$this->request->getPost('id');
        $modelCode = $this->request->getPost('model_code');
        
        if (!$id && empty($modelCode)) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('模型代码或ID不能为空')
            ]);
        }

        // 优先通过模型代码加载
        if (!empty($modelCode)) {
            $model = $this->getAiModel()->reset()
                ->where(AiModel::schema_fields_MODEL_CODE, $modelCode)
                ->find()
                ->fetch();
        } else {
            $model = $this->getAiModel()->reset()->load($id);
        }
        
        if (!$model || !$model->getId()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('模型不存在')
            ]);
        }

        try {
            // 如果要激活模型，需要检查连通性：
            // 1) 供应商连通性成功；2) 若存在自配置，则自配置连通性也必须成功
            if (!$model->isActive()) {
                $providerOk = ($model->getData(AiModel::schema_fields_PROVIDER_TEST_STATUS) === 'success');
                // 判断是否存在自配置api_key
                $hasSelfConfig = false;
                $providerCfgRaw = $model->getData(AiModel::schema_fields_PROVIDER_CONFIG);
                if (!empty($providerCfgRaw)) {
                    $pCfg = is_string($providerCfgRaw) ? json_decode($providerCfgRaw, true) : $providerCfgRaw;
                    $hasSelfConfig = is_array($pCfg) && !empty($pCfg['api_key']);
                }
                if (!$hasSelfConfig) {
                    $cfgRaw = $model->getData(AiModel::schema_fields_CONFIG);
                    if (!empty($cfgRaw)) {
                        $cCfg = is_string($cfgRaw) ? json_decode($cfgRaw, true) : $cfgRaw;
                        $hasSelfConfig = is_array($cCfg) && !empty($cCfg['api_key']);
                    }
                }
                $selfOk = !$hasSelfConfig || ($model->getData(AiModel::schema_fields_SELF_CONFIG_TEST_STATUS) === 'success');

                if (!$providerOk) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => __('供应商连通性测试未通过，无法激活。请先进行连通性测试。')
                    ]);
                }
                if ($hasSelfConfig && !$selfOk) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => __('自配置连通性测试未通过，无法激活。请先进行自配置测试。')
                    ]);
                }
            }
            
            $newStatus = $model->isActive() ? 0 : 1;
            $model->setData(AiModel::schema_fields_IS_ACTIVE, $newStatus);
            $model->save();

            return $this->jsonResponse([
                'success' => true,
                'message' => __('状态更新成功'),
                'status' => $newStatus
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('状态更新失败: %{msg}', ['msg' => $e->getMessage()])
            ]);
        }
    }

    /**
     * 设置默认模型
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_default', '设置默认AI模型', 'mdi-star', '设置默认AI模型')]
    public function setDefault(): string
    {
        $id = (int)$this->request->getPost('id');
        
        if (!$id) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('模型ID不能为空')
            ]);
        }

        $model = $this->getAiModel()->reset()->load($id);
        
        if (!$model->getId()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('模型不存在')
            ]);
        }

        try {
            // 取消其他模型的默认状态
            $this->getAiModel()->reset()
                ->where(AiModel::schema_fields_IS_DEFAULT, 1)
                ->update([AiModel::schema_fields_IS_DEFAULT => 0])
                ->fetch();

            // 设置当前模型为默认
            $model->setData(AiModel::schema_fields_IS_DEFAULT, 1);
            $model->save();

            return $this->jsonResponse([
                'success' => true,
                'message' => __('默认模型设置成功')
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('设置失败: %{msg}', ['msg' => $e->getMessage()])
            ]);
        }
    }

    /**
     * 获取模型配置模板
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_template', '获取AI模型配置模板', 'mdi-file-document', '获取AI模型配置模板')]
    public function getConfigTemplate(): string
    {
        $template = $this->getModelCollector()->getModelConfigTemplate();
        
        return $this->jsonResponse([
            'success' => true,
            'template' => $template
        ]);
    }

    /**
     * 测试模型连接
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_test', '测试AI模型连接', 'mdi-connection', '测试AI模型连接')]
    public function testConnection(): string
    {
        // 兼容 JSON 请求体与表单/查询参数
        $data = $this->getTestRequestData();
        $testOnly = $this->isTestOnlyRequest($data);
        $modelCode = $data['model_code'] ?? ($this->request->getPost('model_code') ?: $this->request->getGet('model_code'));
        
        if (!$modelCode) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('模型代码不能为空')
            ]);
        }

        try {
            /** @var AccountService $accountService */
            $accountService = ObjectManager::getInstance(AccountService::class);

            // 自动检测供应商
            $providerCode = trim((string)($data['supplier'] ?? $data['vendor'] ?? ''));
            if ($providerCode === '') {
                $providerCode = $accountService->getProviderByModelCode($modelCode);
            }
            if (!$providerCode) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('无法识别模型 %{code} 的供应商，请检查模型代码是否正确', ['code' => $modelCode])
                ]);
            }

            // 加载模型
            $model = $this->getAiModel()->reset()
                ->where(AiModel::schema_fields_MODEL_CODE, $modelCode)
                ->find()
                ->fetch();
            // 结果容器
            $testModel = $this->buildTestModel($model, $data);
            $providerCode = (string)($testModel->getData(AiModel::schema_fields_SUPPLIER) ?: $providerCode);
            $results = [
                'self_config' => [
                    'tested' => false,
                    'success' => false,
                    'message' => '',
                    'response' => '',
                    'duration' => 0
                ],
                'provider_account' => [
                    'tested' => false,
                    'success' => false,
                    'message' => '',
                    'response' => '',
                    'duration' => 0,
                    'account_name' => ''
                ]
            ];

            // 先测自配置（如存在），但不提前返回
            $hasSelfConfig = $this->hasSelfConfig($testModel);
            if ($hasSelfConfig) {
                $results['self_config']['tested'] = true;
                try {
                    $selfRes = $this->testModelSelfConfig($testModel);
                    $results['self_config']['success'] = true;
                    $results['self_config']['message'] = __('自配置测试成功');
                    $results['self_config']['response'] = $selfRes['response'] ?? '';
                    $results['self_config']['duration'] = $selfRes['duration'] ?? 0;

                    // 保存自配置测试成功状态
                    if (!$testOnly) {
                        $this->getAiModel()->reset()
                            ->where(AiModel::schema_fields_MODEL_CODE, $modelCode)
                            ->update([
                                AiModel::schema_fields_SELF_CONFIG_TEST_STATUS => 'success',
                                AiModel::schema_fields_SELF_CONFIG_TEST_TIME => time()
                            ])->fetch();
                    }
                } catch (\Exception $e) {
                    $results['self_config']['success'] = false;
                    $results['self_config']['message'] = $e->getMessage();

                    // 保存自配置测试失败状态
                    if (!$testOnly) {
                        $this->getAiModel()->reset()
                            ->where(AiModel::schema_fields_MODEL_CODE, $modelCode)
                            ->update([
                                AiModel::schema_fields_SELF_CONFIG_TEST_STATUS => 'failed',
                                AiModel::schema_fields_SELF_CONFIG_TEST_TIME => time()
                            ])->fetch();
                    }
                }
            }

            // 再测供应商账户
            // 检查是否有可用的供应商账户（不限制连接状态，以便测试所有账户）
            /** @var Account $accountModel */
            $accountModel = ObjectManager::getInstance(Account::class);
            $requestedAccountId = $this->getRequestedProviderAccountId($testModel);
            if ($requestedAccountId > 0) {
                $requestedAccount = $accountModel->clear()->load($requestedAccountId);
                $boundAccountUnavailableMessage = $this->getBoundProviderAccountUnavailableMessage($requestedAccount, $requestedAccountId);
                if ($boundAccountUnavailableMessage !== null) {
                    $accounts = [];
                    $results['provider_account']['tested'] = false;
                    $results['provider_account']['success'] = false;
                    $results['provider_account']['message'] = $boundAccountUnavailableMessage;
                } else {
                    $accounts = [['id' => $requestedAccountId]];
                }
            } else {
                $accounts = $accountModel->clear()
                    ->where(Account::schema_fields_PROVIDER_CODE, $providerCode)
                    ->where(Account::schema_fields_IS_ACTIVE, 1)
                    ->order(Account::schema_fields_IS_DEFAULT, 'DESC')
                    ->order(Account::schema_fields_BALANCE, 'DESC')
                    ->select()
                    ->fetchArray();
                if (empty($accounts)) {
                    $accounts = $accountModel->clear()
                        ->where(Account::schema_fields_PROVIDER_CODE, $providerCode)
                        ->order(Account::schema_fields_IS_DEFAULT, 'DESC')
                        ->order(Account::schema_fields_BALANCE, 'DESC')
                        ->select()
                        ->fetchArray();
                }
            }
            
            if (!empty($accounts) && is_array($accounts)) {
                $results['provider_account']['tested'] = true;
                $testedAccount = null;
                $testSuccess = false;
                
                // 遍历所有激活的账户进行测试
                foreach ($accounts as $accountData) {
                    // 重新加载账户对象以确保数据完整性
                    $testAccount = ObjectManager::getInstance(Account::class);
                    $testAccount->load($accountData['id']);
                    
                    if (!$testAccount->getId()) {
                        continue;
                    }
                    
                    try {
                        // 调用账户服务测试连接（这会更新账户的连接状态）
                        $startTime = microtime(true);
                        $testResult = $accountService->testConnection($testAccount);
                        $duration = round((microtime(true) - $startTime) * 1000, 2);
                        
                        if ($testResult['success']) {
                            $testSuccess = true;
                            $testedAccount = $testAccount;
                            $results['provider_account']['success'] = true;
                            $results['provider_account']['message'] = __('供应商账户测试成功');
                            $results['provider_account']['response'] = $testResult['message'] ?? __('连接成功');
                            $results['provider_account']['duration'] = $duration;
                            $results['provider_account']['account_name'] = $testAccount->getData('account_name');
                            $results['provider_account']['account_id'] = $testAccount->getId();
                            $results['provider_account']['connection_status'] = $testAccount->getData(Account::schema_fields_CONNECTION_STATUS);
                            $results['provider_account']['trace'] = $testResult['trace'] ?? [];
                            
                            // 保存供应商测试成功状态
                            if (!$testOnly) {
                                $this->getAiModel()->reset()
                                    ->where(AiModel::schema_fields_MODEL_CODE, $modelCode)
                                    ->update([
                                        AiModel::schema_fields_PROVIDER_TEST_STATUS => 'success',
                                        AiModel::schema_fields_PROVIDER_TEST_TIME => time()
                                    ])->fetch();
                            }
                            
                            // 找到可用的账户后，跳出循环
                            break;
                        }
                    } catch (\Exception $e) {
                        // 继续测试下一个账户
                        Env::log('ai_model.log', sprintf('[testConnection] account_id=%s test_failed: %s', 
                            $testAccount->getId(), $e->getMessage()
                        ));
                        continue;
                    }
                }
                
                // 如果所有账户测试都失败
                if (!$testSuccess) {
                    $results['provider_account']['success'] = false;
                    $results['provider_account']['message'] = __('所有供应商账户测试均失败');
                    if (!isset($results['provider_account']['trace'])) {
                        $results['provider_account']['trace'] = [];
                    }
                    
                    // 保存供应商测试失败状态
                    if (!$testOnly) {
                        $this->getAiModel()->reset()
                            ->where(AiModel::schema_fields_MODEL_CODE, $modelCode)
                            ->update([
                                AiModel::schema_fields_PROVIDER_TEST_STATUS => 'failed',
                                AiModel::schema_fields_PROVIDER_TEST_TIME => time()
                            ])->fetch();
                    }
                }
            } else {
                // 无可用账户，记录状态
                $results['provider_account']['tested'] = false;
                $results['provider_account']['success'] = false;
                $results['provider_account']['message'] = __('没有可用的 %{provider} 供应商账户', ['provider' => $providerCode]);
            }

            // 计算整体连通性（任一成功视为成功）
            $overallSuccess = ($results['self_config']['success'] || $results['provider_account']['success']);
            if (!$testOnly) {
                try {
                    $this->getAiModel()->reset()
                        ->where(AiModel::schema_fields_MODEL_CODE, $modelCode)
                        ->update([
                            AiModel::schema_fields_CONNECTION_TEST_STATUS => $overallSuccess ? 'success' : 'failed',
                            AiModel::schema_fields_CONNECTION_TEST_TIME => time()
                        ])->fetch();
                } catch (\Exception $saveEx) {
                    Env::log('ai_model.log', 'Failed to save connection test status: ' . $saveEx->getMessage(), 'WARNING');
                }
            }

            // 组合返回
            return $this->jsonResponse([
                'success' => $overallSuccess,
                'message' => $overallSuccess ? __('连接测试完成（至少一项成功）') : __('连接测试完成（均失败）'),
                'data' => [
                    'model_code' => $modelCode,
                    'provider' => $providerCode,
                    'test_only' => $testOnly,
                    'results' => $results
                ]
            ]);

        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('测试失败: %{msg}', ['msg' => $e->getMessage()]),
                'data' => [
                    'model_code' => $modelCode,
                    'provider' => $providerCode ?? 'unknown'
                ]
            ]);
        }
    }
    
    /**
     * 测试模型自配置
     */
    private function testModelSelfConfig(AiModel $model): array
    {
        $prompt = 'Hello, this is a connection test. Please respond with "OK".';
        $hasSelfConfig = $this->hasSelfConfig($model);
        
        if (!$hasSelfConfig) {
            throw new \Exception(__('模型自配置不完整'));
        }
        
        // 创建临时模型用于测试
        
        // 获取对应的Provider
        $providerCode = (string)$model->getData(AiModel::schema_fields_SUPPLIER);
        $accountService = ObjectManager::getInstance(AccountService::class);
        if ($providerCode === '') {
            $providerCode = $accountService->getProviderByModelCode((string)$model->getData(AiModel::schema_fields_MODEL_CODE)) ?? '';
        }
        $provider = $accountService->getProviderInstance($providerCode);
        
        if (!$provider) {
            throw new \Exception(__('无法创建供应商实例'));
        }
        
        $startTime = microtime(true);
        $result = $provider->generate($model, $prompt, ['temperature' => 0, 'test_mode' => true]);
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        
        if (empty($result['content'])) {
            throw new \Exception(__('API响应为空'));
        }
        
        return [
            'success' => true,
            'response' => $result['content'],
            'duration' => $duration
        ];
    }
    
    /**
     * 测试模型自配置连接
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_test_self_config', '测试AI模型自配置连接', 'mdi-cog', '测试AI模型自配置连接')]
    public function testSelfConfig(): string
    {
        // 兼容 JSON 请求体与表单/查询参数
        $data = $this->getTestRequestData();
        $testOnly = $this->isTestOnlyRequest($data);
        $modelCode = $data['model_code'] ?? ($this->request->getPost('model_code') ?: $this->request->getGet('model_code'));
        
        if (!$modelCode) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('模型代码不能为空')
            ]);
        }

        try {
            // 获取模型
            $model = $this->getAiModel()->reset()
                ->where(AiModel::schema_fields_MODEL_CODE, $modelCode)
                ->find()
                ->fetch();
            
            if (!$model->getId()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('模型不存在')
                ]);
            }
            
            // 检查是否有自配置
            $testModel = $this->buildTestModel($model, $data);
            if (!$this->hasSelfConfig($testModel)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('模型没有自配置密钥')
                ]);
            }
            
            // 测试自配置
            $testResult = $this->testModelSelfConfig($testModel);
            
            // 保存自配置测试成功状态
            if (!$testOnly) {
                $this->getAiModel()->reset()
                    ->where(AiModel::schema_fields_MODEL_CODE, $modelCode)
                    ->update([
                        AiModel::schema_fields_SELF_CONFIG_TEST_STATUS => 'success',
                        AiModel::schema_fields_SELF_CONFIG_TEST_TIME => time()
                    ])->fetch();
            }
            
            return $this->jsonResponse([
                'success' => true,
                'message' => __('自配置测试成功'),
                'data' => [
                    'model_code' => $modelCode,
                    'test_only' => $testOnly,
                    'response' => $testResult['response'],
                    'duration' => $testResult['duration']
                ]
            ]);

        } catch (\Exception $e) {
            // 保存自配置测试失败状态
            if (!$testOnly) {
                try {
                    $this->getAiModel()->reset()
                        ->where(AiModel::schema_fields_MODEL_CODE, $modelCode)
                        ->update([
                            AiModel::schema_fields_SELF_CONFIG_TEST_STATUS => 'failed',
                            AiModel::schema_fields_SELF_CONFIG_TEST_TIME => time()
                        ])->fetch();
                } catch (\Exception $saveEx) {
                    Env::log('ai_model.log', 'Failed to save self config test status: ' . $saveEx->getMessage(), 'WARNING');
                }
            }

            return $this->jsonResponse([
                'success' => false,
                'message' => __('自配置测试失败: %{msg}', ['msg' => $e->getMessage()]),
                'data' => [
                    'model_code' => $modelCode
                ]
            ]);
        }
    }

    /**
     * 编辑模型（完整页面）
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_edit', '编辑AI模型', 'mdi-pencil', '编辑AI模型')]
    public function edit(): string
    {
        $id = (int)$this->request->getGet('id');
        
        if ($id) {
            $model = $this->getAiModel()->reset()->load($id);
            
            if (!$model->getId()) {
                $this->getMessageManager()->addError(__('模型不存在'));
                return $this->redirect('*/backend/model/index');
            }
            
            $this->assign('model', $model);
        } else {
            // 新建模型
            $this->assign('model', null);
        }

        $modelPresetsJson = json_encode($this->getCommonModelPresets(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assign('modelPresetsJson', $modelPresetsJson ?: '[]');
        
        return $this->fetch();
    }

    private function getCommonModelPresets(): array
    {
        $presets = [];

        try {
            $providers = VendorConfigManager::getSupportedProviders();
            foreach ($providers as $providerCode => $providerConfig) {
                if (isset($providerConfig['quick_create']) && !$providerConfig['quick_create']) {
                    continue;
                }

                $models = $providerConfig['models'] ?? [];
                if (!is_array($models) || empty($models)) {
                    continue;
                }

                $defaults = is_array($providerConfig['model_config_defaults'] ?? null)
                    ? $providerConfig['model_config_defaults']
                    : [];
                $providerCode = (string)$providerCode;
                $providerName = (string)($providerConfig['name'] ?? $providerCode);
                $baseUrl = (string)($providerConfig['base_url'] ?? '');
                $modelField = (string)($providerConfig['model_field'] ?? 'model');
                $priceUnit = (string)($providerConfig['price_unit'] ?? 'per_1k_tokens');
                $priceCurrency = (string)($providerConfig['price_currency'] ?? 'USD');

                foreach ($models as $modelMeta) {
                    if (!is_array($modelMeta)) {
                        continue;
                    }
                    if (isset($modelMeta['preset']) && !$modelMeta['preset']) {
                        continue;
                    }

                    $modelCode = trim((string)($modelMeta['code'] ?? ''));
                    if ($modelCode === '') {
                        continue;
                    }

                    $maxTokens = (int)($modelMeta['max_tokens'] ?? ($defaults['max_tokens'] ?? 4096));
                    $temperature = $defaults['temperature'] ?? 0.7;
                    $topP = $defaults['top_p'] ?? 1.0;
                    $stream = $defaults['stream'] ?? true;
                $timeout = (int)($defaults['timeout'] ?? \Weline\Ai\Service\Provider\ProviderTimeoutPolicy::DEFAULT_REQUEST_TIMEOUT);
                    $maxRetries = (int)($defaults['max_retries'] ?? 3);

                    $providerRuntimeConfig = [
                        'base_url' => $baseUrl,
                        'max_tokens' => $maxTokens,
                        'temperature' => $temperature,
                        'top_p' => $topP,
                        'stream' => $stream,
                        'timeout' => $timeout,
                        'max_retries' => $maxRetries,
                        'selected_model_preset' => true,
                        'provider_model_code' => $modelCode,
                        'model' => $modelCode,
                        'model_id' => $modelCode,
                    ];
                    $providerRuntimeConfig[$modelField] = $modelCode;

                    $inputPrice = $this->normalizePricePerThousand((float)($modelMeta['input_price'] ?? 0), $priceUnit);
                    $outputPrice = $this->normalizePricePerThousand((float)($modelMeta['output_price'] ?? 0), $priceUnit);
                    $presets[] = [
                        'key' => $providerCode . ':' . $modelCode,
                        'provider' => $providerCode,
                        'provider_name' => $providerName,
                        'model_code' => $modelCode,
                        'provider_model_code' => $modelCode,
                        'name' => (string)($modelMeta['name'] ?? $modelCode),
                        'version' => (string)($modelMeta['version'] ?? '1.0'),
                        'max_tokens' => $maxTokens,
                        'context_window' => (int)($modelMeta['context_window'] ?? 0),
                        'token_price_input' => $inputPrice,
                        'token_price_output' => $outputPrice,
                        'price_currency' => $priceCurrency,
                        'cost_per_token' => $inputPrice,
                        'temperature' => $temperature,
                        'top_p' => $topP,
                        'stream' => (bool)$stream,
                        'timeout' => $timeout,
                        'capabilities' => $modelMeta['capabilities'] ?? ($defaults['capabilities'] ?? []),
                        'config' => $this->filterConfigValues($providerRuntimeConfig),
                    ];
                }
            }
        } catch (\Throwable $e) {
            Env::log('ai_model.log', '[Model::getCommonModelPresets] ' . $e->getMessage(), 'WARNING');
        }

        return $presets;
    }

    /**
     * Offcanvas 编辑模型（侧边栏）
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_edit_offcanvas', '编辑AI模型（侧边栏）', 'mdi-pencil', '编辑AI模型（侧边栏）')]
    public function editOffcanvas(): string
    {
        # 使用blank布局
        $this->layoutType = 'default.blank';
        $id = (int)$this->request->getGet('id');
        
        if ($id) {
            $model = $this->getAiModel()->reset()->load($id);
            
            if (!$model->getId()) {
                return '<div class="alert alert-danger p-3">' 
                    . '<i class="bi bi-exclamation-triangle me-2"></i>' 
                    . __('模型不存在') 
                    . '</div>';
            }
            
            $this->assign('model', $model);
        } else {
            // 新建模型
            $this->assign('model', null);
        }

        // 供应商选项（供 search-select 使用）
        $providers = VendorConfigManager::getSupportedProviders();
        $optsMap = [];
        foreach ($providers as $code => $info) {
            $optsMap[$code] = ($info['name'] ?? $code);
        }
        // 合并账户中已有的自定义供应商代码，确保“新建供应商账户后可在模型里选择”
        /** @var Account $accountModel */
        $accountModel = ObjectManager::getInstance(Account::class);
        $accounts = $accountModel->reset()->select()->fetchArray();
        if (is_array($accounts)) {
            foreach ($accounts as $acc) {
                $code = (string)($acc['provider_code'] ?? '');
                if ($code !== '' && !isset($optsMap[$code])) {
                    $optsMap[$code] = $code;
                }
            }
        }
        $opts = [];
        foreach ($optsMap as $code => $name) {
            $opts[] = $code . ':' . $name;
        }
        $this->assign('providerOptionsStr', implode(',', $opts));
        $modelPresetsJson = json_encode($this->getCommonModelPresets(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assign('modelPresetsJson', $modelPresetsJson ?: '[]');
        
        return $this->fetch('offcanvas_edit');
    }

    /**
     * 保存模型
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_save', '保存AI模型', 'mdi-content-save', '保存AI模型')]
    public function postSave(): string
    {
        $id = (int)$this->request->getPost('id');
        $data = $this->request->getPost();
        $modelCodeFromRequest = $data['model_code'] ?? '';
        $isAjax = $this->request->isAjax();
        
        try {
            $model = $this->getAiModel()->reset();
            
            if (!empty($modelCodeFromRequest)) {
                $loaded = $model->reset()
                    ->where(\Weline\Ai\Model\AiModel::schema_fields_MODEL_CODE, $modelCodeFromRequest)
                    ->find()
                    ->fetch();
                if ($loaded && $loaded->getId()) {
                    $model = $loaded;
                    $id = (int)$model->getId();
                }
            }

            if (!$model->getId() && $id > 0) {
                $model->load($id);
            }

            $isNew = !$model->getId();
            
            // 设置基本数据
            // 如果是编辑原始模型（非复制模型），基本信息不可修改
            if (!$id || $model->isCopied()) {
                // 只在明确提供时才更新供应商，避免清空已有值
                if (isset($data['vendor']) || isset($data['supplier'])) {
                    $model->setData(AiModel::schema_fields_SUPPLIER, $data['vendor'] ?? $data['supplier']);
                }
                if (isset($data['model_code'])) {
                    $model->setData(AiModel::schema_fields_MODEL_CODE, $data['model_code']);
                }
                if (isset($data['model_version']) || isset($data['version'])) {
                    $model->setData(AiModel::schema_fields_VERSION, $data['model_version'] ?? $data['version'] ?? '1.0');
                }
            }
            
            // 模型名称始终可以修改（用于区分复制模型）
            $model->setData(AiModel::schema_fields_NAME, $data['model_name'] ?? $data['name'] ?? '');

            if (isset($data['primary_modality'])) {
                $model->setPrimaryModality((string)$data['primary_modality']);
            } elseif (!$model->getData(AiModel::schema_fields_PRIMARY_MODALITY)) {
                $model->setPrimaryModality(AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT);
            }

            // 供应商模型 code 映射：provider_model_code 可与 model_code 不同
            $providerModelCode = trim((string)($data['provider_model_code'] ?? ''));
            if ($providerModelCode === '') {
                $providerModelCode = (string)($data['model_code'] ?? $model->getData(AiModel::schema_fields_MODEL_CODE) ?? '');
            }
            
            // 令牌价格：只在字段存在时更新，避免清空已有数据
            if (isset($data['token_price_input'])) {
                $model->setData(AiModel::schema_fields_TOKEN_PRICE_INPUT, $data['token_price_input']);
            }
            if (isset($data['token_price_output'])) {
                $model->setData(AiModel::schema_fields_TOKEN_PRICE_OUTPUT, $data['token_price_output']);
            }
            
            // 处理激活状态：如果提交了is_active字段则使用，否则根据status判断
            $wantToActivate = false;
            if (isset($data['is_active'])) {
                $wantToActivate = true;
            } elseif (isset($data['status'])) {
                // 根据status状态设置is_active：active=1，其他=0
                $wantToActivate = ($data['status'] === 'active');
            }
            
            // 如果要激活模型，需要检查配置和连通性
            if ($wantToActivate) {
                // 规则：若没有 api_key，则必须存在该供应商的可用账户（二者其一即可）
                $hasApiKey = false;
                $providerConfigJson = $data['provider_config_json'] ?? '';
                $configJson = $data['config_json'] ?? '';
                if (!empty($providerConfigJson)) {
                    $pc = json_decode($providerConfigJson, true);
                    $hasApiKey = is_array($pc) && !empty($pc['api_key']);
                }
                if (!$hasApiKey && !empty($configJson)) {
                    $cfg = json_decode($configJson, true);
                    $hasApiKey = is_array($cfg) && !empty($cfg['api_key']);
                }

                if (!$hasApiKey) {
                    // 检查供应商账户是否存在可用
                    $providerCode = $model->getSupplier();
                    $accountsOk = false;
                    if (!empty($providerCode)) {
                        /** @var \Weline\Ai\Service\Provider\AccountService $accSrv */
                        $accSrv = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Ai\Service\Provider\AccountService::class);
                        try {
                            $rows = $accSrv->getProviderAccounts($providerCode);
                            if (is_array($rows)) {
                                foreach ($rows as $row) {
                                    if ((int)($row['is_active'] ?? 0) === 1) { $accountsOk = true; break; }
                                }
                            }
                        } catch (\Throwable $e) {
                            // 忽略账户读取异常，按无可用账户处理
                        }
                    }

                    if (!$accountsOk) {
                        $msg = __('模型未配置API密钥，且无可用供应商账户，无法激活。请先在供应商账户中添加可用账号或在配置中填写 api_key。');
                        if ($isAjax) {
                            return $this->jsonResponse(['success' => false, 'message' => $msg]);
                        }
                        $this->getMessageManager()->addError($msg);
                        return $this->redirect('*/backend/model/edit', ['id' => $id]);
                    }
                }
                
                // 检查连通性测试状态
                $testStatus = $model->getData(AiModel::schema_fields_CONNECTION_TEST_STATUS);
                if ($testStatus !== 'success') {
                    if ($isAjax) {
                        return $this->jsonResponse([
                            'success' => false,
                            'message' => '模型连通性测试未通过，无法激活。请先进行连通性测试。'
                        ]);
                    }
                    $this->getMessageManager()->addError(__('模型连通性测试未通过，无法激活。请先进行连通性测试。'));
                    return $this->redirect('*/backend/model/edit', ['id' => $id]);
                }
            }
            
            // 设置激活状态
            if ($wantToActivate) {
                $model->setData(AiModel::schema_fields_IS_ACTIVE, 1);
            } else {
                $model->setData(AiModel::schema_fields_IS_ACTIVE, 0);
            }
            
            // 处理默认模型：只有明确勾选才设置，否则不修改
            if (isset($data['is_default'])) {
                $model->setData(AiModel::schema_fields_IS_DEFAULT, 1);
            }
            
            // 处理配置JSON
            $config = $this->buildIncomingConfigData($data);
            $model->setData(AiModel::schema_fields_CONFIG, $this->encodeJsonConfig($config));
            
            // 处理代理信息JSON
            if (isset($data['proxy']) && is_array($data['proxy'])) {
                // 过滤空值
                $proxy = array_filter($data['proxy'], function($value) {
                    return $value !== '' && $value !== null;
                });
                if (!empty($proxy)) {
                    $model->setData(AiModel::schema_fields_PROXY_INFO, json_encode($proxy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                } else {
                    $model->setData(AiModel::schema_fields_PROXY_INFO, '');
                }
            } else {
                $model->setData(AiModel::schema_fields_PROXY_INFO, $data['proxy_info'] ?? '');
            }
            
            // 处理提供商配置JSON
            $providerConfig = $this->buildIncomingProviderConfigData($data, $providerModelCode, $config);
            $model->setData(AiModel::schema_fields_PROVIDER_CONFIG, $this->encodeJsonConfig($providerConfig));

            // 保存前校验：供应商必须支持映射后的供应商模型 code
            $supplierCode = (string)$model->getData(AiModel::schema_fields_SUPPLIER);
            if ($supplierCode !== '' && $providerModelCode !== '') {
                /** @var AccountService $accService */
                $accService = ObjectManager::getInstance(AccountService::class);
                if (!$accService->supportsModel($supplierCode, $providerModelCode)) {
                    $msg = __('供应商 %{1} 不支持模型 %{2}，不允许切换。请先同步供应商支持模型或更换模型代码。', [$supplierCode, $providerModelCode]);
                    if ($isAjax) {
                        return $this->jsonResponse(['success' => false, 'message' => $msg]);
                    }
                    $this->getMessageManager()->addError($msg);
                    return $this->redirect('*/backend/model/edit', ['id' => $id]);
                }
            }
            
            // 处理其他字段
            if (isset($data['max_tokens'])) {
                $model->setData(AiModel::schema_fields_MAX_TOKENS, (int)$data['max_tokens']);
            }
            if (isset($data['cost_per_token'])) {
                $model->setData(AiModel::schema_fields_COST_PER_TOKEN, (string)$data['cost_per_token']);
            }
            if (isset($data['status'])) {
                $model->setData(AiModel::schema_fields_STATUS, $data['status']);
            }
            
            // 保存
            $model->save();
            
            // AJAX 请求返回 JSON
            if ($isAjax) {
                return $this->jsonResponse([
                    'success' => true,
                    'message' => __('模型保存成功'),
                    'model_id' => $model->getId()
                ]);
            }
            
            // 普通请求返回重定向
            $this->getMessageManager()->addSuccess(__('模型保存成功'));
            return $this->redirect('*/backend/model/index');
            
        } catch (\Exception $e) {
            // AJAX 请求返回 JSON 错误
            if ($isAjax) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('模型保存失败: %{1}', [$e->getMessage()])
                ]);
            }
            
            // 普通请求返回重定向
            $this->getMessageManager()->addError(__('模型保存失败: %{1}', $e->getMessage()));
            return $this->redirect('*/backend/model/edit', ['id' => $id]);
        }
    }

    /**
     * 显示复制模型表单
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_copy_form', '复制AI模型表单', 'mdi-content-copy', '复制AI模型表单')]
    public function copyForm(): string
    {
        $id = (int)$this->request->getGet('id');
        
        $model = $this->getAiModel()->reset()->load($id);
        
        if (!$model->getId()) {
            return '<div class="alert alert-danger">' . __('原始模型不存在') . '</div>';
        }
        
        $this->assign('model', $model);
        
        return $this->fetch();
    }

    /**
     * 复制模型
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_copy', '复制AI模型', 'mdi-content-copy', '复制AI模型')]
    public function copy(): string
    {
        $id = (int)$this->request->getPost('id');
        $data = $this->request->getPost();
        
        try {
            $originalModel = $this->getAiModel()->reset()->load($id);
            
            if (!$originalModel->getId()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('原始模型不存在')
                ]);
            }
            
            // 验证必填字段
            if (empty($data['model_name'])) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('请输入新模型名称')
                ]);
            }
            
            if (empty($data['model_code'])) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('请输入新模型代码')
                ]);
            }
            
            // 检查模型代码是否已存在
            $existingModel = $this->getAiModel()->clearData()->reset()
                ->where(AiModel::schema_fields_MODEL_CODE, $data['model_code'])
                ->find()
                ->fetch();
            
            // 正确判断记录是否存在：使用 getId() 而不是直接判断对象
            if ($existingModel && $existingModel->getId()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('模型代码已存在，请使用其他代码')
                ]);
            }
            
            // 创建新模型（复制）
            $copiedModel = $this->getAiModel()->reset();
            
            // 复制基本信息（使用新的名称和代码）
            $copiedModel->setData(AiModel::schema_fields_SUPPLIER, $originalModel->getVendor());
            $copiedModel->setData(AiModel::schema_fields_MODEL_CODE, $data['model_code']);
            $copiedModel->setData(AiModel::schema_fields_NAME, $data['model_name']);
            $copiedModel->setData(AiModel::schema_fields_VERSION, $originalModel->getModelVersion());
            
            // 处理配置信息
            // 如果用户提供了新的配置，使用新配置；否则复制原配置
            if (isset($data['config']) && is_array($data['config'])) {
                // 过滤空值
                $newConfig = array_filter($data['config'], function($value) {
                    return $value !== '' && $value !== null;
                });
                
                // 如果用户没有提供完整配置，合并原配置
                if (!empty($newConfig)) {
                    $originalConfig = [];
                    if ($originalModel->getConfigJson()) {
                        $configJson = $originalModel->getConfigJson();
                        $originalConfig = is_string($configJson) ? json_decode($configJson, true) : $configJson;
                        if (!is_array($originalConfig)) {
                            $originalConfig = [];
                        }
                    }
                    
                    // 合并配置：新配置覆盖原配置
                    $mergedConfig = array_merge($originalConfig, $newConfig);
                    $copiedModel->setData(AiModel::schema_fields_CONFIG, json_encode($mergedConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                } else {
                    // 用户没有提供任何配置，使用原配置
                    $copiedModel->setData(AiModel::schema_fields_CONFIG, $originalModel->getConfigJson());
                }
            } else {
                // 复制原配置
                $copiedModel->setData(AiModel::schema_fields_CONFIG, $originalModel->getConfigJson());
            }
            
            // 复制其他配置信息
            $copiedModel->setData(AiModel::schema_fields_PROXY_INFO, $originalModel->getProxyInfo());
            $copiedModel->setData(AiModel::schema_fields_TOKEN_PRICE_INPUT, $originalModel->getTokenPriceInput());
            $copiedModel->setData(AiModel::schema_fields_TOKEN_PRICE_OUTPUT, $originalModel->getTokenPriceOutput());
            $copiedModel->setData(AiModel::schema_fields_MAX_TOKENS, $originalModel->getMaxTokens());
            $copiedModel->setData(AiModel::schema_fields_COST_PER_TOKEN, $originalModel->getCostPerToken());
            
            // 复制能力信息（如果有）
            if ($originalModel->getData(AiModel::schema_fields_CAPABILITIES)) {
                $copiedModel->setData(AiModel::schema_fields_CAPABILITIES, $originalModel->getData(AiModel::schema_fields_CAPABILITIES));
            }
            
            // 设置状态（复制模型默认启用但不是默认模型）
            $copiedModel->setData(AiModel::schema_fields_IS_ACTIVE, 1);
            $copiedModel->setData(AiModel::schema_fields_IS_DEFAULT, 0);
            
            // 标记为复制模型
            $copiedModel->setData(AiModel::schema_fields_IS_COPY, 1);
            $copiedModel->setData(AiModel::schema_fields_ORIGIN_MODEL_ID, $originalModel->getId());
            
            // 保存
            $saveResult = $copiedModel->save();
            
            // 验证保存是否成功
            if (!$copiedModel->getId()) {
                throw new \RuntimeException('保存失败：未生成模型ID');
            }
            
            return $this->jsonResponse([
                'success' => true,
                'message' => __('模型复制成功'),
                'model_id' => $copiedModel->getId()
            ]);
            
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('模型复制失败: %{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 删除模型
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_delete', '删除AI模型', 'mdi-delete', '删除AI模型')]
    public function delete(): string
    {
        $id = (int)$this->request->getGet('id');
        $isAjax = $this->request->isAjax();
        
        try {
            $model = $this->getAiModel()->reset()->load($id);
            
            if (!$model->getId()) {
                if ($isAjax) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => __('模型不存在')
                    ]);
                }
                $this->getMessageManager()->addError(__('模型不存在'));
                return $this->redirect('*/backend/model/index');
            }
            
            // 仅允许删除：复制模型或自定义模型
            if (!$model->canDelete()) {
                if ($isAjax) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => __('该模型受保护，只有复制模型或自定义模型可以删除')
                    ]);
                }
                $this->getMessageManager()->addError(__('该模型受保护，只有复制模型或自定义模型可以删除'));
                return $this->redirect('*/backend/model/index');
            }
            
            // 删除模型
            $model->delete()->fetch();
            
            if ($isAjax) {
                return $this->jsonResponse([
                    'success' => true,
                    'message' => __('模型删除成功')
                ]);
            }
            
            $this->getMessageManager()->addSuccess(__('模型删除成功'));
            
        } catch (\Exception $e) {
            if ($isAjax) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('模型删除失败: %{1}', $e->getMessage())
                ]);
            }
            $this->getMessageManager()->addError(__('模型删除失败: %{1}', $e->getMessage()));
        }
        
        return $this->redirect('*/backend/model/index');
    }

    /**
     * 删除模型接口：支持通过 id 参数删除已复制的模型
     */
    public function deleteAction()
    {
        // 支持 GET/POST 参数
        $id = 0;
        if (isset($this->request)) {
            $id = (int)$this->request->getParam('id');
        }
        if (!$id) {
            $id = (int)\w_env_get('id', 0);
        }
        if (!$id) {
            $id = (int)\w_env_post('id', 0);
        }

        if (!$id) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => __('Missing model id')]);
            return;
        }

        try {
            $service = new \Weline\Ai\Service\ModelService();
            $result = $service->deleteModel($id);

            if ($result) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true]);
                return;
            }

            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => __('Delete failed for unknown reason')]);
            return;
        } catch (\Exception $e) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            return;
        }
    }

    /**
     * 批量激活模型
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_bulk_activate', '批量激活模型', 'mdi-check-all', '批量激活模型')]
    public function bulkActivate(): string
    {
        $bodyParams = $this->request->getBodyParams();
        $jsonData = is_array($bodyParams) ? $bodyParams : (is_string($bodyParams) ? json_decode($bodyParams, true) : null);
        $ids = $jsonData['ids'] ?? [];
        
        if (empty($ids)) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('请选择要激活的模型')
            ]);
        }
        
        try {
            $count = 0;
            foreach ($ids as $id) {
                $model = $this->getAiModel()->reset()->load((int)$id);
                if ($model->getId()) {
                    $model->setData(AiModel::schema_fields_IS_ACTIVE, 1);
                    $model->save();
                    $count++;
                }
            }
            
            return $this->jsonResponse([
                'success' => true,
                'message' => __('成功激活 %{1} 个模型', $count)
            ]);
            
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('批量激活失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 批量禁用模型
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_bulk_deactivate', '批量禁用模型', 'mdi-cancel', '批量禁用模型')]
    public function bulkDeactivate(): string
    {
        $bodyParams = $this->request->getBodyParams();
        $jsonData = is_array($bodyParams) ? $bodyParams : (is_string($bodyParams) ? json_decode($bodyParams, true) : null);
        $ids = $jsonData['ids'] ?? [];
        
        if (empty($ids)) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('请选择要禁用的模型')
            ]);
        }
        
        try {
            $count = 0;
            foreach ($ids as $id) {
                $model = $this->getAiModel()->reset()->load((int)$id);
                if ($model->getId()) {
                    $model->setData(AiModel::schema_fields_IS_ACTIVE, 0);
                    $model->save();
                    $count++;
                }
            }
            
            return $this->jsonResponse([
                'success' => true,
                'message' => __('成功禁用 %{1} 个模型', $count)
            ]);
            
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('批量禁用失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 批量删除模型
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_bulk_delete', '批量删除模型', 'mdi-delete-sweep', '批量删除模型')]
    public function bulkDelete(): string
    {
        $ids = $this->resolveBulkModelIds();
        
        if (empty($ids)) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('请选择要删除的模型')
            ]);
        }
        
        try {
            $successCount = 0;
            $skipCount = 0;
            $errors = [];
            
            foreach ($ids as $id) {
                $model = $this->getAiModel()->reset()->load((int)$id);
                if ($model->getId()) {
                    // 允许删除复制模型与自定义模型
                    if ($model->canDelete()) {
                        $model->delete()->fetch();
                        $successCount++;
                    } else {
                        $skipCount++;
                    }
                }
            }
            
            $message = '';
            if ($successCount > 0) {
                $message .= __('成功删除 %{1} 个模型', $successCount);
            }
            if ($skipCount > 0) {
                $message .= ($successCount > 0 ? '；' : '') . __('跳过 %{1} 个受保护模型（仅复制/自定义模型可删除）', $skipCount);
            }
            
            $deleted = $successCount > 0;
            if (!$deleted && $skipCount > 0 && $message === '') {
                $message = __('所选模型均为受保护模型，仅复制/自定义模型可删除');
            }

            return $this->jsonResponse([
                'success' => $deleted,
                'message' => $message ?: __('没有模型被删除')
            ]);
            
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('批量删除失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 兼容多种传参方式解析批量模型 ID：
     * - JSON Body: {"ids":[1,2]}
     * - 表单: ids[]=1&ids[]=2 或 ids=1,2
     * - Query: ?id=1 或 ?ids=1,2
     */
    private function resolveBulkModelIds(): array
    {
        $ids = [];

        $bodyParams = $this->request->getBodyParams();
        $jsonData = is_array($bodyParams) ? $bodyParams : (is_string($bodyParams) ? json_decode($bodyParams, true) : null);
        if (is_array($jsonData) && isset($jsonData['ids'])) {
            $ids = $jsonData['ids'];
        }

        if (empty($ids)) {
            $ids = $this->request->getPost('ids', []);
        }

        if (empty($ids)) {
            $ids = $this->request->getGet('ids', []);
        }

        if (empty($ids)) {
            $singleId = (int)$this->request->getParam('id', 0);
            if ($singleId > 0) {
                $ids = [$singleId];
            }
        }

        if (is_string($ids)) {
            $ids = array_map('trim', explode(',', $ids));
        }

        if (!is_array($ids)) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(array_map(static function ($id): int {
            return (int)$id;
        }, $ids), static function (int $id): bool {
            return $id > 0;
        })));

        return $ids;
    }

    /**
     * 清空模型表（危险操作）
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_clear_all', '清空模型表', 'mdi-delete-forever', '清空所有模型数据')]
    public function clearAll(): string
    {
        try {
            // 获取所有模型
            $models = $this->getAiModel()->reset()
                ->select()
                ->fetch()
                ->getItems();
            
            $count = 0;
            foreach ($models as $model) {
                try {
                    $model->delete();
                    $count++;
                } catch (\Exception $e) {
                    // 继续删除其他模型
                    continue;
                }
            }
            
            return $this->jsonResponse([
                'success' => true,
                'message' => __('成功清空模型表，共删除 %{1} 个模型', $count)
            ]);
            
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('清空失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 批量测试模型连接
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_batch_test', '批量测试AI模型连接', 'mdi-connection', '批量测试AI模型连接')]
    public function batchTestConnection(): string
    {
        $modelIds = $this->request->getPost('model_ids', []);
        
        if (empty($modelIds)) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('请选择要测试的模型')
            ]);
        }

        $results = [];
        $successCount = 0;
        $failedCount = 0;

        // 获取供应商账户服务
        /** @var AccountService $accountService */
        $accountService = ObjectManager::getInstance(AccountService::class);
        
        foreach ($modelIds as $modelId) {
            $model = $this->getAiModel()->reset()->load((int)$modelId);
            
            if (!$model->getId()) {
                $results[] = [
                    'model_id' => $modelId,
                    'model_code' => '',
                    'success' => false,
                    'message' => '模型不存在'
                ];
                $failedCount++;
                continue;
            }
            
            $modelCode = $model->getData(AiModel::schema_fields_MODEL_CODE);
            
            try {
                // 获取供应商代码
                $providerCode = $accountService->getProviderByModelCode($modelCode);
                if (!$providerCode) {
                    throw new \Exception(__('无法确定模型的供应商'));
                }
                
                // 检查是否有可用的供应商账户
                $account = $accountService->getAvailableAccount($providerCode);
                if (!$account) {
                    throw new \Exception(__('没有可用的%{provider}供应商账户', ['provider' => $providerCode]));
                }
                
                // 使用AI服务测试连接
                $aiService = ObjectManager::getInstance(\Weline\Ai\Service\AiService::class);
                $startTime = microtime(true);
                $response = $aiService->generate('Test connection', $modelCode, null, null, [], null, true);
                $duration = round((microtime(true) - $startTime) * 1000, 2);
                
                // 更新连接状态
                $this->getAiModel()->reset()
                    ->where(AiModel::schema_fields_MODEL_CODE, $modelCode)
                    ->update([
                        AiModel::schema_fields_CONNECTION_TEST_STATUS => 'success',
                        AiModel::schema_fields_CONNECTION_TEST_TIME => time()
                    ])->fetch();
                
                $results[] = [
                    'model_id' => $modelId,
                    'model_code' => $modelCode,
                    'model_name' => $model->getData(AiModel::schema_fields_NAME),
                    'success' => true,
                    'message' => __('测试成功'),
                    'duration' => $duration,
                    'account_name' => $account->getData('account_name')
                ];
                $successCount++;
                
            } catch (\Exception $e) {
                // 更新连接状态为失败
                try {
                    $this->getAiModel()->reset()
                        ->where(AiModel::schema_fields_MODEL_CODE, $modelCode)
                        ->update([
                            AiModel::schema_fields_CONNECTION_TEST_STATUS => 'failed',
                            AiModel::schema_fields_CONNECTION_TEST_TIME => time()
                        ])->fetch();
                } catch (\Exception $saveEx) {
                    // 忽略保存错误
                }
                
                $results[] = [
                    'model_id' => $modelId,
                    'model_code' => $modelCode,
                    'model_name' => $model->getData(AiModel::schema_fields_NAME),
                    'success' => false,
                    'message' => $e->getMessage()
                ];
                $failedCount++;
            }
        }
        
        return $this->jsonResponse([
            'success' => true,
            'results' => $results,
            'summary' => [
                'total' => count($modelIds),
                'success' => $successCount,
                'failed' => $failedCount
            ]
        ]);
    }

    /**
     * 获取所有供应商及其支持的模型列表
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_providers', '获取AI供应商列表', 'mdi-domain', '获取AI供应商及模型列表')]
    public function getProviders(): string
    {
        try {
            $providers = VendorConfigManager::getAllProvidersWithModels();
            
            return $this->jsonResponse([
                'success' => true,
                'data' => $providers
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('获取供应商列表失败: %{1}', [$e->getMessage()])
            ]);
        }
    }

    /**
     * 获取指定供应商支持的模型列表
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_provider_models', '获取供应商模型列表', 'mdi-format-list-bulleted', '获取指定供应商支持的模型列表')]
    public function getProviderModels(): string
    {
        $providerCode = $this->request->getGet('provider') ?? $this->request->getPost('provider');
        
        if (empty($providerCode)) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('供应商代码不能为空')
            ]);
        }
        
        try {
            $providerConfig = VendorConfigManager::getProviderConfig($providerCode);
            
            if (!$providerConfig) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('不支持的供应商: %{1}', [$providerCode])
                ]);
            }
            
            $models = VendorConfigManager::getProviderModels($providerCode);
            
            return $this->jsonResponse([
                'success' => true,
                'data' => [
                    'provider' => [
                        'code' => $providerCode,
                        'name' => $providerConfig['name'] ?? $providerCode,
                        'description' => $providerConfig['description'] ?? '',
                        'base_url' => $providerConfig['base_url'] ?? '',
                    ],
                    'models' => $models
                ]
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('获取模型列表失败: %{1}', [$e->getMessage()])
            ]);
        }
    }

    /**
     * JSON响应
     * 
     * @param array $data
     * @return string
     */
    private function jsonResponse(array $data): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return json_encode($data);
    }

    /**
     * 收集筛选下拉需要的全量供应商列表。
     * 来源：数据库模型表（supplier/vendor）+ 供应商配置文件，避免分页导致漏项。
     */
    private function collectAllVendorsForFilter(): array
    {
        $vendors = [];

        try {
            $supportedProviders = VendorConfigManager::getSupportedProviders();
            $rows = $this->getAiModel()->reset()
                ->select()
                ->fetchArray();
            foreach ($rows as $row) {
                if (!$this->isSelectedModelRow($row, $supportedProviders)) {
                    continue;
                }
                $candidateVendors = [
                    trim((string)($row['vendor'] ?? '')),
                    trim((string)($row['supplier'] ?? '')),
                ];
                foreach ($candidateVendors as $vendorCode) {
                    if ($vendorCode !== '' && !in_array($vendorCode, $vendors, true)) {
                        $vendors[] = $vendorCode;
                    }
                }
            }
        } catch (\Throwable $e) {
            Env::log('ai_model.log', '[Model::collectAllVendorsForFilter][db] ' . $e->getMessage(), 'WARNING');
        }

        sort($vendors);
        return $vendors;
    }

    /**
     * 自动确保供应商静态模型已同步到数据库，避免后台列表缺失模型。
     */
    private function ensureVendorModelsAvailable(): void
    {
        if ($this->vendorModelsEnsured) {
            return;
        }
        $this->vendorModelsEnsured = true;

        try {
            $providers = VendorConfigManager::getSupportedProviders();
            foreach ($providers as $providerCode => $providerConfig) {
                $providerCode = trim((string)$providerCode);
                if ($providerCode === '') {
                    continue;
                }
                if (empty($providerConfig['models']) || !is_array($providerConfig['models'])) {
                    continue;
                }

                $exists = $this->getAiModel()->reset()
                    ->where(AiModel::schema_fields_SUPPLIER, $providerCode)
                    ->find()
                    ->fetch();
                if ($exists && $exists->getId()) {
                    continue;
                }

                $this->getModelSyncService()->syncProvider($providerCode, [
                    'keep_existing' => true,
                ]);
            }
        } catch (\Throwable $e) {
            Env::log('ai_model.log', '[Model::ensureVendorModelsAvailable] ' . $e->getMessage(), 'WARNING');
        }
    }
}
