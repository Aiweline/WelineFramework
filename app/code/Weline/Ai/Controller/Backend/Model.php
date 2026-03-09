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

    /**
     * 模型列表页面
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_list', '查看AI模型列表', 'mdi-view-list', '查看AI模型列表')]
    public function index(): string
    {
        if ($this->request->isAjax() || $this->request->getGet('format') === 'json') {
            return $this->indexJson();
        }
        if ($this->request->getGet('embed') === '1') {
            $this->layoutType = 'default.blank';
        }
        try {
            $page = (int)$this->request->getGet('page', 1);
            $pageSize = 20;

            // 获取模型列表 - 使用fetchArray避免内存问题
            $modelData = $this->getAiModel()->reset()
                ->pagination($page, $pageSize)
                ->order(AiModel::schema_fields_CREATED_AT, 'DESC')
                ->select()
                ->fetchArray();

            // 简化数据，只获取必要字段
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
                
                $models[] = [
                    'id' => $data['id'] ?? '',
                    // 显示供应商：优先使用配置中的vendor，退回到数据库字段supplier
                    'vendor' => $data['vendor'] ?? ($data['supplier'] ?? ''),
                    'name' => $data['name'] ?? '',
                    'model_code' => $data['model_code'] ?? '',
                    'version' => $data['version'] ?? '',
                    'status' => $data['status'] ?? '',
                    'is_active' => $data['is_active'] ?? 0,
                    'is_default' => $data['is_default'] ?? 0,
                    'created_at' => $data['created_at'] ?? '',
                    'is_copied' => $data['is_copied'] ?? 0,
                    'token_price_input' => $data['token_price_input'] ?? 0,
                    'token_price_output' => $data['token_price_output'] ?? 0,
                    'has_api_key' => $hasApiKey,
                    'has_config' => !empty($config) || !empty($providerConfig),
                    'connection_test_status' => $data['connection_test_status'] ?? 'pending',
                    'connection_test_time' => $data['connection_test_time'] ?? 0,
                    'self_config_test_status' => $selfConfigTestStatus,
                    'provider_test_status' => $providerTestStatus,
                ];
            }

            // 渲染前按ID倒序（保障前端显示顺序），与数据库排序双保险
            usort($models, function ($a, $b) {
                $aid = (int)($a['id'] ?? 0);
                $bid = (int)($b['id'] ?? 0);
                return $bid <=> $aid;
            });

            // 获取总数
            $total = $this->getAiModel()->reset()->select()->count();

            // 获取所有供应商列表（用于筛选）
            $vendors = [];
            foreach ($models as $model) {
                $vendor = $model['vendor'] ?? '';
                if ($vendor && !in_array($vendor, $vendors)) {
                    $vendors[] = $vendor;
                }
            }
            sort($vendors);
            
            $this->assign('models', $models);
            $this->assign('pagination', null); // 简化分页
            $this->assign('total', (string)$total);
            $this->assign('vendors', $vendors);
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
        $data = $this->request->getParams();
        $raw = method_exists($this->request, 'getContent') ? ($this->request->getContent() ?: '') : '';
        if (!empty($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = array_merge($data, $decoded);
            }
        }
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
            $providerCode = $accountService->getProviderByModelCode($modelCode);
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
            $hasSelfConfig = ($model->getId() && $model->getProviderConfig());
            if ($hasSelfConfig) {
                $results['self_config']['tested'] = true;
                try {
                    $selfRes = $this->testModelSelfConfig($model, $modelCode);
                    $results['self_config']['success'] = true;
                    $results['self_config']['message'] = __('自配置测试成功');
                    $results['self_config']['response'] = $selfRes['response'] ?? '';
                    $results['self_config']['duration'] = $selfRes['duration'] ?? 0;

                    // 保存自配置测试成功状态
                    $this->getAiModel()->reset()
                        ->where(AiModel::schema_fields_MODEL_CODE, $modelCode)
                        ->update([
                            AiModel::schema_fields_SELF_CONFIG_TEST_STATUS => 'success',
                            AiModel::schema_fields_SELF_CONFIG_TEST_TIME => time()
                        ])->fetch();
                } catch (\Exception $e) {
                    $results['self_config']['success'] = false;
                    $results['self_config']['message'] = $e->getMessage();

                    // 保存自配置测试失败状态
                    $this->getAiModel()->reset()
                        ->where(AiModel::schema_fields_MODEL_CODE, $modelCode)
                        ->update([
                            AiModel::schema_fields_SELF_CONFIG_TEST_STATUS => 'failed',
                            AiModel::schema_fields_SELF_CONFIG_TEST_TIME => time()
                        ])->fetch();
                }
            }

            // 再测供应商账户
            // 检查是否有可用的供应商账户（不限制连接状态，以便测试所有账户）
            /** @var Account $accountModel */
            $accountModel = ObjectManager::getInstance(Account::class);
            $accounts = $accountModel->clear()
                ->where(Account::schema_fields_PROVIDER_CODE, $providerCode)
                ->where(Account::schema_fields_IS_ACTIVE, 1)
                ->order(Account::schema_fields_IS_DEFAULT, 'DESC')
                ->order(Account::schema_fields_BALANCE, 'DESC')
                ->select()
                ->fetchArray();
            
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
                            
                            // 保存供应商测试成功状态
                            $this->getAiModel()->reset()
                                ->where(AiModel::schema_fields_MODEL_CODE, $modelCode)
                                ->update([
                                    AiModel::schema_fields_PROVIDER_TEST_STATUS => 'success',
                                    AiModel::schema_fields_PROVIDER_TEST_TIME => time()
                                ])->fetch();
                            
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
                    
                    // 保存供应商测试失败状态
                    $this->getAiModel()->reset()
                        ->where(AiModel::schema_fields_MODEL_CODE, $modelCode)
                        ->update([
                            AiModel::schema_fields_PROVIDER_TEST_STATUS => 'failed',
                            AiModel::schema_fields_PROVIDER_TEST_TIME => time()
                        ])->fetch();
                }
            } else {
                // 无可用账户，记录状态
                $results['provider_account']['tested'] = false;
                $results['provider_account']['success'] = false;
                $results['provider_account']['message'] = __('没有可用的 %{provider} 供应商账户', ['provider' => $providerCode]);
            }

            // 计算整体连通性（任一成功视为成功）
            $overallSuccess = ($results['self_config']['success'] || $results['provider_account']['success']);
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

            // 组合返回
            return $this->jsonResponse([
                'success' => $overallSuccess,
                'message' => $overallSuccess ? __('连接测试完成（至少一项成功）') : __('连接测试完成（均失败）'),
                'data' => [
                    'model_code' => $modelCode,
                    'provider' => $providerCode,
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
    private function testModelSelfConfig(AiModel $model, string $modelCode): array
    {
        $prompt = 'Hello, this is a connection test. Please respond with "OK".';
        $providerConfig = $model->getProviderConfig();
        
        if (!$providerConfig || !isset($providerConfig['api_key'])) {
            throw new \Exception(__('模型自配置不完整'));
        }
        
        // 创建临时模型用于测试
        $testModel = clone $model;
        $testModel->setData(AiModel::schema_fields_CONFIG, json_encode([
            'api_key' => $providerConfig['api_key'],
            'base_url' => $providerConfig['base_url'] ?? '',
            'model' => $modelCode
        ]));
        
        // 获取对应的Provider
        $providerCode = $model->getData(AiModel::schema_fields_SUPPLIER);
        $accountService = ObjectManager::getInstance(AccountService::class);
        $provider = $accountService->getProviderInstance($providerCode);
        
        if (!$provider) {
            throw new \Exception(__('无法创建供应商实例'));
        }
        
        $startTime = microtime(true);
        $result = $provider->generate($testModel, $prompt, ['temperature' => 0, 'test_mode' => true]);
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
        $data = $this->request->getParams();
        $raw = method_exists($this->request, 'getContent') ? ($this->request->getContent() ?: '') : '';
        if (!empty($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = array_merge($data, $decoded);
            }
        }
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
            if (!$model->getProviderConfig()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('模型没有自配置密钥')
                ]);
            }
            
            // 测试自配置
            $testResult = $this->testModelSelfConfig($model, $modelCode);
            
            // 保存自配置测试成功状态
            $this->getAiModel()->reset()
                ->where(AiModel::schema_fields_MODEL_CODE, $modelCode)
                ->update([
                    AiModel::schema_fields_SELF_CONFIG_TEST_STATUS => 'success',
                    AiModel::schema_fields_SELF_CONFIG_TEST_TIME => time()
                ])->fetch();
            
            return $this->jsonResponse([
                'success' => true,
                'message' => __('自配置测试成功'),
                'data' => [
                    'model_code' => $modelCode,
                    'response' => $testResult['response'],
                    'duration' => $testResult['duration']
                ]
            ]);

        } catch (\Exception $e) {
            // 保存自配置测试失败状态
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
        
        return $this->fetch();
    }

    /**
     * Offcanvas 编辑模型（侧边栏）
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_edit_offcanvas', '编辑AI模型（侧边栏）', 'mdi-pencil', '编辑AI模型（侧边栏）')]
    public function editOffcanvas(): string
    {
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
        $opts = [];
        foreach ($providers as $code => $info) {
            $opts[] = $code . ':' . ($info['name'] ?? $code);
        }
        $this->assign('providerOptionsStr', implode(',', $opts));
        
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
            
            $isNew = ($id === 0);
            // 新建（id=0）：不加载，后续直接 setData + save
            if (!$isNew) {
                // 优先根据模型代码加载（以模型代码作为唯一标识）
                if (!empty($modelCodeFromRequest)) {
                    $loaded = $model->reset()
                        ->where(\Weline\Ai\Model\AiModel::schema_fields_MODEL_CODE, $modelCodeFromRequest)
                        ->find()
                        ->fetch();
                    if ($loaded && $loaded->getId()) {
                        $model = $loaded;
                    } else {
                        $model->load($id);
                    }
                } else {
                    $model->load($id);
                }
                if (!$model->getId()) {
                    if ($isAjax) {
                        return $this->jsonResponse([
                            'success' => false,
                            'message' => __('模型不存在或模型代码无效')
                        ]);
                    }
                    $this->getMessageManager()->addError(__('模型不存在或模型代码无效'));
                    return $this->redirect('*/backend/model/index');
                }
            }
            
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
            if (isset($data['config']) && is_array($data['config'])) {
                // 过滤空值
                $config = array_filter($data['config'], function($value) {
                    return $value !== '' && $value !== null;
                });
                // 转换stream为布尔值
                if (isset($config['stream'])) {
                    $config['stream'] = (bool)$config['stream'];
                }
                $model->setData(AiModel::schema_fields_CONFIG, json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $model->setData(AiModel::schema_fields_CONFIG, $data['config_json'] ?? '');
            }
            
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
            if (isset($data['provider_config']) && is_array($data['provider_config'])) {
                // 过滤空值
                $providerConfig = array_filter($data['provider_config'], function($value) {
                    return $value !== '' && $value !== null;
                });
                if (!empty($providerConfig)) {
                    $model->setData(AiModel::schema_fields_PROVIDER_CONFIG, json_encode($providerConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                } else {
                    $model->setData(AiModel::schema_fields_PROVIDER_CONFIG, '');
                }
            } else {
                $model->setData(AiModel::schema_fields_PROVIDER_CONFIG, $data['provider_config_json'] ?? '');
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
            
            // 检查是否为原始模型（非复制模型）
            if (!$model->isCopied()) {
                if ($isAjax) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => __('原始模型不能删除，只能删除复制的模型')
                    ]);
                }
                $this->getMessageManager()->addError(__('原始模型不能删除，只能删除复制的模型'));
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
        if (!$id && isset($_GET['id'])) {
            $id = (int)$_GET['id'];
        }
        if (!$id && isset($_POST['id'])) {
            $id = (int)$_POST['id'];
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
        $bodyParams = $this->request->getBodyParams();
        $jsonData = is_array($bodyParams) ? $bodyParams : (is_string($bodyParams) ? json_decode($bodyParams, true) : null);
        $ids = $jsonData['ids'] ?? [];
        
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
                    // 只能删除复制的模型
                    if ($model->isCopied()) {
                        $model->delete();
                        $successCount++;
                    } else {
                        $skipCount++;
                    }
                }
            }
            
            $message = '';
            if ($successCount > 0) {
                $message .= __('成功删除 %{1} 个复制模型', $successCount);
            }
            if ($skipCount > 0) {
                $message .= ($successCount > 0 ? '；' : '') . __('跳过 %{1} 个原始模型（原始模型不可删除）', $skipCount);
            }
            
            return $this->jsonResponse([
                'success' => true,
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
}
