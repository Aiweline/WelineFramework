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
use Weline\Ai\Model\AiScenarioAdapter;
use Weline\Ai\Service\DefaultModelManager;
use Weline\Ai\Service\AdapterScanner;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Manager\Message;

/**
 * 默认模型管理后台控制器
 * 
 * 功能：
 * - 默认模型配置管理
 * - 服务类型模型分配
 * - 模型保护状态查看
 * - 默认模型验证
 */
class DefaultModel extends BackendController
{
    /**
     * @var AiModel
     */
    private AiModel $aiModel;

    /**
     * @var DefaultModelManager
     */
    private DefaultModelManager $defaultModelManager;

    /**
     * 构造函数
     * 
     * @param AiModel $aiModel
     * @param DefaultModelManager $defaultModelManager
     */
    /**
     * 获取AI模型（懒加载）
     */
    private function getAiModel(): AiModel
    {
        return \Weline\Framework\Manager\ObjectManager::getInstance(AiModel::class);
    }

    /**
     * 获取默认模型管理器（懒加载）
     */
    private function getDefaultModelManager(): DefaultModelManager
    {
        return \Weline\Framework\Manager\ObjectManager::getInstance(DefaultModelManager::class);
    }

    /**
     * 获取适配器扫描器（懒加载）
     */
    private function getAdapterScanner(): AdapterScanner
    {
        return \Weline\Framework\Manager\ObjectManager::getInstance(AdapterScanner::class);
    }

    /**
     * 默认模型配置页面
     * 
     * @return string
     */
    public function index(): string
    {
        // 获取所有默认模型配置
        $defaultModels = $this->getDefaultModelManager()->getAllDefaultModels();
        foreach ($defaultModels as $defaultModel) {
            $defaultModelsArray[] = $defaultModel->getData();
        }
        
        // 从数据库获取场景适配器作为服务类型（与适配器管理页面使用相同的查询方式）
        $serviceTypes = [];
        try {
            // 从数据库查询所有场景适配器（与适配器管理页面保持一致，不过滤 is_active）
            $adapterModel = ObjectManager::getInstance(AiScenarioAdapter::class);
            $adapterCollection = $adapterModel->reset()
                ->order(AiScenarioAdapter::fields_CODE, 'ASC')
                ->select()
                ->fetch();
            
            // 获取适配器记录数组（与适配器管理页面使用相同的方式）
            $adapterItems = $adapterCollection->getItems();
            
            // 从适配器记录中提取服务类型（使用适配器的 code 作为 key，name 作为 label）
            if (!empty($adapterItems)) {
                foreach ($adapterItems as $adapter) {
                    if (is_object($adapter)) {
                        $code = $adapter->getData(AiScenarioAdapter::fields_CODE);
                        $name = $adapter->getData(AiScenarioAdapter::fields_NAME);
                        if ($code && $name) {
                            $serviceTypes[$code] = $name;
                        }
                    }
                }
            }
            
            // 如果没有适配器，使用默认列表作为后备
            if (empty($serviceTypes)) {
                $serviceTypes = $this->getDefaultModelManager()->getAvailableServiceTypes();
            }
        } catch (\Exception $e) {
            // 如果获取适配器失败，使用默认列表作为后备
            $serviceTypes = $this->getDefaultModelManager()->getAvailableServiceTypes();
        }
        
        // 获取所有可用模型
        $availableModels = $this->getAiModel()->reset()
            ->where(AiModel::fields_IS_ACTIVE, 1)
            ->order(AiModel::fields_SUPPLIER, 'ASC')
            ->order(AiModel::fields_NAME, 'ASC')
            ->select()
            ->fetchArray(); 
        $this->assign('defaultModels', $defaultModelsArray);
        $this->assign('serviceTypes', $serviceTypes);
        $this->assign('availableModels', $availableModels);
        $this->assign('models', $availableModels); // 兼容模板变量名

        return $this->fetch();
    }

    /**
     * 设置默认模型
     * 
     * @return string
     */
    public function setDefault(): string
    {
        $serviceType = $this->request->getPost('service_type');
        $modelCode = $this->request->getPost('model_code');
        $priority = (int)$this->request->getPost('priority', 100);
        $isActive = (int)$this->request->getPost('is_active', 1);
        
        if (!$serviceType || !$modelCode) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '服务类型和模型代码不能为空'
            ]);
        }

        try {
            // 检查模型是否存在
            $model = $this->getAiModel()->reset()
                ->where(AiModel::fields_MODEL_CODE, $modelCode)
                ->find()
                ->fetch();
            
            if (!$model || !$model->getId()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => '模型不存在: ' . $modelCode
                ]);
            }
            
            $result = $this->getDefaultModelManager()->setDefaultModel($serviceType, $modelCode, $priority);
            if ($result) {
                // 如果需要更新 is_active 状态，可以在这里处理
                // 注意：DefaultModelManager 的 setDefaultModel 可能不处理 is_active
                // 如果需要，可以单独更新
                
                return $this->jsonResponse([
                    'success' => true,
                    'message' => '默认模型设置成功'
                ]);
            } else {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => '默认模型设置失败'
                ]);
            }
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '设置失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 移除默认模型配置
     * 
     * @return string
     */
    public function removeDefault(): string
    {
        $serviceType = $this->request->getPost('service_type');
        
        if (!$serviceType) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '服务类型不能为空'
            ]);
        }

        try {
            $result = $this->getDefaultModelManager()->removeDefaultModel($serviceType);
            
            if ($result) {
                return $this->jsonResponse([
                    'success' => true,
                    'message' => '默认模型配置移除成功'
                ]);
            } else {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => '默认模型配置移除失败'
                ]);
            }
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '移除失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 获取模型保护状态
     * 
     * @return string
     */
    public function getProtectionStatus(): string
    {
        $modelCode = $this->request->getGet('model_code');
        
        if (!$modelCode) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '模型代码不能为空'
            ]);
        }

        $isProtected = $this->getDefaultModelManager()->isProtectedModel($modelCode);
        $reason = $isProtected ? $this->getDefaultModelManager()->getProtectionReason($modelCode) : '';
        $usage = $this->getDefaultModelManager()->getModelUsageAsDefault($modelCode);

        return $this->jsonResponse([
            'success' => true,
            'data' => [
                'protected' => $isProtected,
                'reason' => $reason,
                'usage' => $usage
            ]
        ]);
    }

    /**
     * 初始化默认配置
     * 
     * @return string
     */
    public function initialize(): string
    {
        try {
            $result = $this->getDefaultModelManager()->initializeDefaults();
            
            if ($result) {
                Message::success(__('默认配置初始化成功'));
            } else {
                Message::notes(__('默认配置已存在，无需初始化'));
            }
        } catch (\Exception $e) {
            Message::error(__('初始化失败: %{error}', ['error' => $e->getMessage()]));
        }

        $this->redirect('*/backend/defaultmodel');
        return '';
    }

    /**
     * 验证默认模型配置
     * 
     * @return string
     */
    public function validate(): string
    {
        try {
            $issues = $this->getDefaultModelManager()->validateDefaultModels();
            
            if (empty($issues)) {
                return $this->jsonResponse([
                    'success' => true,
                    'message' => '所有默认模型配置都是有效的',
                    'issues' => []
                ]);
            } else {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => '发现配置问题',
                    'issues' => $issues
                ]);
            }
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '验证失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 获取服务类型的默认模型
     * 
     * @return string
     */
    public function getDefaultModel(): string
    {
        $serviceType = $this->request->getGet('service_type', DefaultModelManager::SERVICE_TYPE_DEFAULT);
        
        try {
            $model = $this->getDefaultModelManager()->getDefaultModel($serviceType);
            
            if ($model) {
                return $this->jsonResponse([
                    'success' => true,
                    'data' => [
                        'model_code' => $model->getData(AiModel::fields_MODEL_CODE),
                        'model_name' => $model->getName(),
                        'vendor' => $model->getVendor(),
                        'status' => $model->getStatus()
                    ]
                ]);
            } else {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => '未找到默认模型'
                ]);
            }
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '获取失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 清除缓存
     * 
     * @return string
     */
    public function clearCache(): string
    {
        try {
            $this->getDefaultModelManager()->clearCache();
            
            return $this->jsonResponse([
                'success' => true,
                'message' => __('默认模型缓存清除成功')
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('清除缓存失败: %{error}', ['error' => $e->getMessage()])
            ]);
        }
    }

    /**
     * 获取受保护的模型列表
     * 
     * @return string
     */
    public function getProtected(): string
    {
        try {
            $defaultModels = $this->getDefaultModelManager()->getAllDefaultModels();
            $protectedModels = [];
            
            // 按模型代码分组，收集每个模型对应的服务类型
            $modelServiceTypes = [];
            foreach ($defaultModels as $dm) {
                $modelCode = is_object($dm) ? $dm->getData('model_code') : ($dm['model_code'] ?? '');
                $serviceType = is_object($dm) ? $dm->getData('service_type') : ($dm['service_type'] ?? '');
                
                if ($modelCode && $serviceType) {
                    if (!isset($modelServiceTypes[$modelCode])) {
                        $modelServiceTypes[$modelCode] = [];
                    }
                    $modelServiceTypes[$modelCode][] = $serviceType;
                }
            }
            
            // 获取每个模型的详细信息
            foreach ($modelServiceTypes as $modelCode => $serviceTypes) {
                $model = $this->getAiModel()->reset()
                    ->where(AiModel::fields_MODEL_CODE, $modelCode)
                    ->find()
                    ->fetch();
                
                if ($model && $model->getId()) {
                    $protectedModels[] = [
                        'model_code' => $modelCode,
                        'model_name' => $model->getName(),
                        'vendor' => $model->getSupplier(),
                        'service_types' => $serviceTypes
                    ];
                }
            }
            
            return $this->jsonResponse([
                'success' => true,
                'data' => $protectedModels
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('获取受保护模型列表失败: %{error}', ['error' => $e->getMessage()]),
                'data' => []
            ]);
        }
    }

    /**
     * 批量设置默认模型
     * 
     * @return string
     */
    public function batchSet(): string
    {
        $configurationsJson = $this->request->getPost('configurations', '[]');
        
        // 解析 JSON 字符串
        if (is_string($configurationsJson)) {
            $configurations = json_decode($configurationsJson, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => '配置数据格式错误: ' . json_last_error_msg()
                ]);
            }
        } else {
            $configurations = $configurationsJson;
        }
        
        if (empty($configurations) || !is_array($configurations)) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '配置数据不能为空'
            ]);
        }

        $results = [];
        $successCount = 0;
        $errorCount = 0;

        foreach ($configurations as $config) {
            if (!isset($config['service_type'])) {
                $results[] = [
                    'service_type' => $config['service_type'] ?? 'unknown',
                    'success' => false,
                    'message' => '缺少服务类型字段'
                ];
                $errorCount++;
                continue;
            }

            $serviceType = $config['service_type'];
            $modelCode = $config['model_code'] ?? '';
            $priority = (int)($config['priority'] ?? 100);
            $isActive = isset($config['is_active']) ? (int)$config['is_active'] : null;

            try {
                // 如果有模型代码，验证模型是否存在
                if ($modelCode) {
                    $model = $this->getAiModel()->reset()
                        ->where(AiModel::fields_MODEL_CODE, $modelCode)
                        ->find()
                        ->fetch();
                    
                    if (!$model || !$model->getId()) {
                        $results[] = [
                            'service_type' => $serviceType,
                            'success' => false,
                            'message' => '模型不存在: ' . $modelCode
                        ];
                        $errorCount++;
                        continue;
                    }
                }
                
                // 设置默认模型（如果 model_code 为空，则清空该服务类型的默认模型）
                if ($modelCode) {
                    $result = $this->getDefaultModelManager()->setDefaultModel(
                        $serviceType,
                        $modelCode,
                        $priority
                    );
                } else {
                    // 清空默认模型：删除该服务类型的配置
                    $defaultModel = $this->getDefaultModelManager()->getDefaultModel($serviceType);
                    if ($defaultModel) {
                        $defaultModel->delete();
                        $result = true;
                    } else {
                        $result = true; // 如果不存在，也算成功
                    }
                }
                
                // 如果需要更新 is_active 状态，单独处理
                if ($result && $isActive !== null) {
                    $defaultModel = $this->getDefaultModelManager()->getDefaultModel($serviceType);
                    if ($defaultModel) {
                        $defaultModel->setData('is_active', $isActive);
                        $defaultModel->save();
                    }
                }

                $results[] = [
                    'service_type' => $serviceType,
                    'success' => $result,
                    'message' => $result ? '设置成功' : '设置失败'
                ];

                if ($result) {
                    $successCount++;
                } else {
                    $errorCount++;
                }
            } catch (\Exception $e) {
                $results[] = [
                    'service_type' => $serviceType,
                    'success' => false,
                    'message' => $e->getMessage()
                ];
                $errorCount++;
            }
        }

        return $this->jsonResponse([
            'success' => $errorCount === 0,
            'message' => sprintf('批量设置完成：成功 %d 个，失败 %d 个', $successCount, $errorCount),
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'results' => $results,
            'summary' => [
                'total' => count($configurations),
                'success' => $successCount,
                'error' => $errorCount
            ]
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
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return json_encode($data);
    }
}
