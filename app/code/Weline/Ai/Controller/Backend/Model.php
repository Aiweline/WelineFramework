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
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Manager\Message;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;
use Weline\Framework\Acl\Acl;

/**
 * AI模型管理后台控制器
 * 
 * 功能：
 * - AI模型列表展示
 * - 模型详情查看
 * - 模型状态管理
 * - 模型收集和更新
 */
#[Acl('Weline_Ai::ai_model_manager', 'AI模型管理', 'mdi-robot', 'AI模型管理', 'Weline_Ai::ai')]
class Model extends BackendController
{
    /**
     * @var AiModel
     */
    private AiModel $aiModel;

    /**
     * @var ModelCollector
     */
    private ModelCollector $modelCollector;

    /**
     * 构造函数
     * 
     * @param AiModel $aiModel
     * @param ModelCollector $modelCollector
     */
    public function __construct(
        AiModel $aiModel,
        ModelCollector $modelCollector
    ) {
        $this->aiModel = $aiModel;
        $this->modelCollector = $modelCollector;
    }

    /**
     * 确保AI模型对象有效
     * 
     * @return AiModel
     */
    private function getAiModel(): AiModel
    {
        if (!$this->aiModel || $this->aiModel === false) {
            $this->aiModel = ObjectManager::getInstance(AiModel::class);
        }
        return $this->aiModel;
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
        try {
            $page = (int)$this->request->getGet('page', 1);
            $pageSize = 20;

            // 获取模型列表
            $models = $this->getAiModel()->reset()
                ->pagination($page, $pageSize)
                ->order(AiModel::fields_CREATED_AT, 'DESC')
                ->select()
                ->fetch();

            $pagination = $models->getPagination();
            $total = is_object($pagination) && method_exists($pagination, 'getTotal') 
                ? $pagination->getTotal() 
                : count($models->getItems());

            // 获取所有供应商列表（用于筛选）
            $vendors = [];
            foreach ($models->getItems() as $model) {
                $vendor = $model->getVendor();
                if ($vendor && !in_array($vendor, $vendors)) {
                    $vendors[] = $vendor;
                }
            }
            sort($vendors);
            
            $this->assign('models', $models->getItems());
            $this->assign('pagination', $pagination);
            $this->assign('total', (string)$total);
            $this->assign('vendors', $vendors);

            return $this->fetch();
        } catch (\Exception $e) {
            // 如果出现错误，显示错误信息
            $this->assign('error', $e->getMessage());
            $this->assign('models', []);
            $this->assign('total', '0');
            $this->assign('vendors', []);
            return $this->fetch();
        }
    }

    private function indexJson(): string
    {
        try {
            $models = $this->getAiModel()->reset()->select()->fetchArray();
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
            $collectedModels = $this->modelCollector->collectAllModels();
            
            return $this->jsonResponse([
                'success' => true,
                'message' => __('成功收集 %{1} 个模型', [count($collectedModels)]),
                'count' => count($collectedModels),
                'models' => array_map(function($model) {
                    return [
                        'id' => $model->getId(),
                        'name' => $model->getData('name'),
                        'model_code' => $model->getData('model_code'),
                        'supplier' => $model->getData('supplier')
                    ];
                }, $collectedModels)
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('模型收集失败: %{1}', [$e->getMessage()]),
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
        
        if (!$id) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '模型ID不能为空'
            ]);
        }

        $model = $this->getAiModel()->reset()->load($id);
        
        if (!$model->getId()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '模型不存在'
            ]);
        }

        try {
            $newStatus = $model->isActive() ? 0 : 1;
            $model->setData(AiModel::fields_IS_ACTIVE, $newStatus);
            $model->save();

            return $this->jsonResponse([
                'success' => true,
                'message' => '状态更新成功',
                'status' => $newStatus
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '状态更新失败: ' . $e->getMessage()
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
                'message' => '模型ID不能为空'
            ]);
        }

        $model = $this->getAiModel()->reset()->load($id);
        
        if (!$model->getId()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '模型不存在'
            ]);
        }

        try {
            // 取消其他模型的默认状态
            $this->getAiModel()->reset()
                ->where(AiModel::fields_IS_DEFAULT, 1)
                ->update([AiModel::fields_IS_DEFAULT => 0])
                ->fetch();

            // 设置当前模型为默认
            $model->setData(AiModel::fields_IS_DEFAULT, 1);
            $model->save();

            return $this->jsonResponse([
                'success' => true,
                'message' => '默认模型设置成功'
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '设置失败: ' . $e->getMessage()
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
        $template = $this->modelCollector->getModelConfigTemplate();
        
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
        $id = (int)($this->request->getPost('id') ?: $this->request->getGet('id'));
        
        if (!$id) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '模型ID不能为空'
            ]);
        }

        $model = $this->getAiModel()->reset()->load($id);
        
        if (!$model->getId()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '模型不存在'
            ]);
        }

        try {
            // 使用AI服务测试连接
            $aiService = ObjectManager::getInstance(\Weline\Ai\Service\AiService::class);
            $testPrompt = "Hello, this is a connection test.";
            $response = $aiService->generate($testPrompt, $model->getData(AiModel::fields_MODEL_CODE));

            return $this->jsonResponse([
                'success' => true,
                'message' => '连接测试成功',
                'response' => $response
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '连接测试失败: ' . $e->getMessage()
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
    #[Acl('Weline_Ai::ai_model_edit', '编辑AI模型', 'mdi-pencil', '编辑AI模型')]
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
        
        return $this->fetch('offcanvas_edit');
    }

    /**
     * 保存模型
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_model_save', '保存AI模型', 'mdi-content-save', '保存AI模型')]
    public function save(): string
    {
        $id = (int)$this->request->getPost('id');
        $data = $this->request->getPost();
        $isAjax = $this->request->isAjax();
        
        try {
            $model = $this->getAiModel()->reset();
            
            if ($id) {
                $model->load($id);
                
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
            }
            
            // 设置基本数据
            // 如果是编辑原始模型（非复制模型），基本信息不可修改
            if (!$id || $model->isCopied()) {
                // 只在明确提供时才更新供应商，避免清空已有值
                if (isset($data['vendor']) || isset($data['supplier'])) {
                    $model->setData(AiModel::fields_SUPPLIER, $data['vendor'] ?? $data['supplier']);
                }
                if (isset($data['model_code'])) {
                    $model->setData(AiModel::fields_MODEL_CODE, $data['model_code']);
                }
                if (isset($data['model_version']) || isset($data['version'])) {
                    $model->setData(AiModel::fields_VERSION, $data['model_version'] ?? $data['version'] ?? '1.0');
                }
            }
            
            // 模型名称始终可以修改（用于区分复制模型）
            $model->setData(AiModel::fields_NAME, $data['model_name'] ?? $data['name'] ?? '');
            
            // 令牌价格：只在字段存在时更新，避免清空已有数据
            if (isset($data['token_price_input'])) {
                $model->setData(AiModel::fields_TOKEN_PRICE_INPUT, $data['token_price_input']);
            }
            if (isset($data['token_price_output'])) {
                $model->setData(AiModel::fields_TOKEN_PRICE_OUTPUT, $data['token_price_output']);
            }
            
            // 处理激活状态：如果提交了is_active字段则使用，否则根据status判断
            if (isset($data['is_active'])) {
                $model->setData(AiModel::fields_IS_ACTIVE, 1);
            } elseif (isset($data['status'])) {
                // 根据status状态设置is_active：active=1，其他=0
                $model->setData(AiModel::fields_IS_ACTIVE, $data['status'] === 'active' ? 1 : 0);
            }
            
            // 处理默认模型：只有明确勾选才设置，否则不修改
            if (isset($data['is_default'])) {
                $model->setData(AiModel::fields_IS_DEFAULT, 1);
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
                $model->setData(AiModel::fields_CONFIG, json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $model->setData(AiModel::fields_CONFIG, $data['config_json'] ?? '');
            }
            
            // 处理代理信息JSON
            if (isset($data['proxy']) && is_array($data['proxy'])) {
                // 过滤空值
                $proxy = array_filter($data['proxy'], function($value) {
                    return $value !== '' && $value !== null;
                });
                if (!empty($proxy)) {
                    $model->setData(AiModel::fields_PROXY_INFO, json_encode($proxy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                } else {
                    $model->setData(AiModel::fields_PROXY_INFO, '');
                }
            } else {
                $model->setData(AiModel::fields_PROXY_INFO, $data['proxy_info'] ?? '');
            }
            
            // 处理其他字段
            if (isset($data['max_tokens'])) {
                $model->setData(AiModel::fields_MAX_TOKENS, (int)$data['max_tokens']);
            }
            if (isset($data['cost_per_token'])) {
                $model->setData(AiModel::fields_COST_PER_TOKEN, (string)$data['cost_per_token']);
            }
            if (isset($data['status'])) {
                $model->setData(AiModel::fields_STATUS, $data['status']);
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
            // 记录错误详情到日志
            error_log('模型保存失败: ' . $e->getMessage());
            error_log('错误堆栈: ' . $e->getTraceAsString());
            
            // AJAX 请求返回 JSON 错误
            if ($isAjax) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('模型保存失败: %{1}', [$e->getMessage()]),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
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
                ->where(AiModel::fields_MODEL_CODE, $data['model_code'])
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
            $copiedModel->setData(AiModel::fields_SUPPLIER, $originalModel->getVendor());
            $copiedModel->setData(AiModel::fields_MODEL_CODE, $data['model_code']);
            $copiedModel->setData(AiModel::fields_NAME, $data['model_name']);
            $copiedModel->setData(AiModel::fields_VERSION, $originalModel->getModelVersion());
            
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
                    $copiedModel->setData(AiModel::fields_CONFIG, json_encode($mergedConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                } else {
                    // 用户没有提供任何配置，使用原配置
                    $copiedModel->setData(AiModel::fields_CONFIG, $originalModel->getConfigJson());
                }
            } else {
                // 复制原配置
                $copiedModel->setData(AiModel::fields_CONFIG, $originalModel->getConfigJson());
            }
            
            // 复制其他配置信息
            $copiedModel->setData(AiModel::fields_PROXY_INFO, $originalModel->getProxyInfo());
            $copiedModel->setData(AiModel::fields_TOKEN_PRICE_INPUT, $originalModel->getTokenPriceInput());
            $copiedModel->setData(AiModel::fields_TOKEN_PRICE_OUTPUT, $originalModel->getTokenPriceOutput());
            $copiedModel->setData(AiModel::fields_MAX_TOKENS, $originalModel->getMaxTokens());
            $copiedModel->setData(AiModel::fields_COST_PER_TOKEN, $originalModel->getCostPerToken());
            
            // 复制能力信息（如果有）
            if ($originalModel->getData(AiModel::fields_CAPABILITIES)) {
                $copiedModel->setData(AiModel::fields_CAPABILITIES, $originalModel->getData(AiModel::fields_CAPABILITIES));
            }
            
            // 设置状态（复制模型默认启用但不是默认模型）
            $copiedModel->setData(AiModel::fields_IS_ACTIVE, 1);
            $copiedModel->setData(AiModel::fields_IS_DEFAULT, 0);
            
            // 标记为复制模型
            $copiedModel->setData(AiModel::fields_IS_COPY, 1);
            $copiedModel->setData(AiModel::fields_ORIGIN_MODEL_ID, $originalModel->getId());
            
            // 保存
            $saveResult = $copiedModel->save();
            
            // 验证保存是否成功
            if (!$copiedModel->getId()) {
                error_log('[AI Model Copy] Save failed - no ID generated');
                throw new \RuntimeException('保存失败：未生成模型ID');
            }
            
            error_log('[AI Model Copy] Success - New model ID: ' . $copiedModel->getId());
            
            return $this->jsonResponse([
                'success' => true,
                'message' => __('模型复制成功'),
                'model_id' => $copiedModel->getId()
            ]);
            
        } catch (\Exception $e) {
            error_log('[AI Model Copy] Exception: ' . $e->getMessage());
            error_log('[AI Model Copy] Trace: ' . $e->getTraceAsString());
            
            return $this->jsonResponse([
                'success' => false,
                'message' => __('模型复制失败: %{1}', $e->getMessage()),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
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
            echo json_encode(['success' => false, 'error' => 'Missing model id']);
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
            echo json_encode(['success' => false, 'error' => 'Delete failed for unknown reason']);
            return;
        } catch (\Exception $e) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            return;
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
