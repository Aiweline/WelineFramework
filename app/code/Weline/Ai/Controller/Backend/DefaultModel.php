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
use Weline\Ai\Service\DefaultModelManager;
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
     * 默认模型配置页面
     * 
     * @return string
     */
    public function index(): string
    {
        // 获取所有默认模型配置
        $defaultModels = $this->getDefaultModelManager()->getAllDefaultModels();
        
        // 获取可用的服务类型
        $serviceTypes = $this->getDefaultModelManager()->getAvailableServiceTypes();
        
        // 获取所有可用模型
        $availableModels = $this->getAiModel()->reset()
            ->where(AiModel::fields_IS_ACTIVE, 1)
            ->order(AiModel::fields_SUPPLIER, 'ASC')
            ->order(AiModel::fields_NAME, 'ASC')
            ->select()
            ->fetch()
            ->getItems(); // 确保获取模型对象数组

        // 安全处理结果：确保是数组
        $defaultModelsArray = is_array($defaultModels) ? $defaultModels : 
            (is_object($defaultModels) && method_exists($defaultModels, 'getItems') ? $defaultModels->getItems() : []);
        
        $availableModelsArray = is_array($availableModels) ? $availableModels : [];

        $this->assign('defaultModels', $defaultModelsArray);
        $this->assign('serviceTypes', $serviceTypes);
        $this->assign('availableModels', $availableModelsArray);
        $this->assign('models', $availableModelsArray); // 兼容模板变量名

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
        
        if (!$serviceType || !$modelCode) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '服务类型和模型代码不能为空'
            ]);
        }

        try {
            $result = $this->getDefaultModelManager()->setDefaultModel($serviceType, $modelCode, $priority);
            
            if ($result) {
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

        return $this->redirect($this->_url->getBackendUrl('*/backend/defaultmodel'));
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
            
            Message::success(__('默认模型缓存清除成功'));
        } catch (\Exception $e) {
            Message::error(__('清除缓存失败: %{error}', ['error' => $e->getMessage()]));
        }

        return $this->redirect($this->_url->getBackendUrl('*/backend/defaultmodel'));
    }

    /**
     * 批量设置默认模型
     * 
     * @return string
     */
    public function batchSet(): string
    {
        $configurations = $this->request->getPost('configurations', []);
        
        if (empty($configurations)) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '配置数据不能为空'
            ]);
        }

        $results = [];
        $successCount = 0;
        $errorCount = 0;

        foreach ($configurations as $config) {
            if (!isset($config['service_type'], $config['model_code'])) {
                $results[] = [
                    'service_type' => $config['service_type'] ?? 'unknown',
                    'success' => false,
                    'message' => '缺少必需字段'
                ];
                $errorCount++;
                continue;
            }

            try {
                $result = $this->getDefaultModelManager()->setDefaultModel(
                    $config['service_type'],
                    $config['model_code'],
                    $config['priority'] ?? 100
                );

                $results[] = [
                    'service_type' => $config['service_type'],
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
                    'service_type' => $config['service_type'],
                    'success' => false,
                    'message' => $e->getMessage()
                ];
                $errorCount++;
            }
        }

        return $this->jsonResponse([
            'success' => $errorCount === 0,
            'message' => sprintf('批量设置完成：成功 %d 个，失败 %d 个', $successCount, $errorCount),
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
