<?php

declare(strict_types=1);

/*
 * 敏感内容检测控制器
 */

namespace GuoLaiRen\Desensitization\Controller\Backend;

use GuoLaiRen\Desensitization\Service\DesensitizationService;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\App\Env;

class Detect extends BackendController
{
    private DesensitizationService $service;

    public function __construct(DesensitizationService $service)
    {
        $this->service = $service;
    }

    /**
     * 检测页面
     *
     * @return mixed
     */
    public function index()
    {
        // 获取默认模型配置
        $defaultModel = '';
        try {
            /** @var \Weline\SystemConfig\Model\SystemConfig $systemConfig */
            $systemConfig = ObjectManager::getInstance(\Weline\SystemConfig\Model\SystemConfig::class);
            $configParams = $systemConfig->getConfig('desensitization_adapter_params', 'GuoLaiRen_Desensitization', \Weline\SystemConfig\Model\SystemConfig::area_BACKEND);
            
            if ($configParams) {
                $params = json_decode($configParams, true);
                $defaultModel = $params['default_model'] ?? '';
            }
        } catch (\Exception $e) {
            // 忽略错误，使用空默认值
        }
        
        $this->assign('defaultModel', $defaultModel);
        return $this->fetch();
    }

    /**
     * 获取AI模型列表
     *
     * @return mixed
     */
    public function getModels()
    {
        try {
            $models = $this->service->getAvailableModels();
            return $this->jsonResponse([
                'success' => true,
                'message' => '获取成功',
                'data' => $models
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '获取模型列表失败: ' . $e->getMessage()
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
     * 执行检测
     *
     * @return mixed
     */
    public function execute()
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse(['success' => false, 'message' => '仅支持POST请求']);
        }

        // 获取原始POST数据（如果是JSON请求）
        $rawData = file_get_contents('php://input');
        Env::log('desensitization.log', "检测控制器: 原始POST数据: " . $rawData, 'DEBUG');
        
        // 尝试解析JSON数据
        $data = json_decode($rawData, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // 如果不是JSON，使用常规方法获取参数
            $data = $this->request->getParams();
        }
        
        // 调试日志：打印所有接收到的参数
        Env::log('desensitization.log', "检测控制器: 接收到的参数: " . json_encode($data), 'DEBUG');
        
        $content = $data['content'] ?? '';

        if (empty($content)) {
            return $this->jsonResponse(['success' => false, 'message' => '请输入检测内容']);
        }

        try {
            $startTime = microtime(true);
            
            $modelCode = $data['model_code'] ?? '';
            Env::log('desensitization.log', "检测控制器: 初始模型代码: " . $modelCode, 'DEBUG');
            
            // 如果没有指定模型代码，尝试从配置中获取默认模型
            if (empty($modelCode)) {
                /** @var \Weline\SystemConfig\Model\SystemConfig $systemConfig */
                $systemConfig = ObjectManager::getInstance(\Weline\SystemConfig\Model\SystemConfig::class);
                $configParams = $systemConfig->getConfig('desensitization_adapter_params', 'GuoLaiRen_Desensitization', \Weline\SystemConfig\Model\SystemConfig::area_BACKEND);
                
                if ($configParams) {
                    $params = json_decode($configParams, true);
                    $modelCode = $params['default_model'] ?? '';
                }
            }
            
            // 如果指定了AI模型，使用AI检测；否则使用正则检测
            if (!empty($modelCode)) {
                Env::log('desensitization.log', "检测控制器: 使用AI检测，模型代码: " . $modelCode, 'INFO');
                $options = [
                    'model_code' => $modelCode
                ];
                $result = $this->service->detectSensitiveWithAI($content, $options);
            } else {
                Env::log('desensitization.log', "检测控制器: 使用正则检测，没有模型代码", 'INFO');
                $options = [
                    'return_positions' => true
                ];
                $result = $this->service->detectSensitive($content, $options);
            }
            
            $executionTime = microtime(true) - $startTime;

            return $this->jsonResponse([
                'success' => true,
                'message' => '检测完成',
                'data' => [
                    'original' => $content,
                    'detection' => $result,
                    'execution_time' => round($executionTime * 1000, 2) . 'ms'
                ]
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(['success' => false, 'message' => '检测失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 润色内容
     *
     * @return mixed
     */
    public function rewrite()
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse(['success' => false, 'message' => '仅支持POST请求']);
        }

        $data = $this->request->getParams();
        
        $content = $data['content'] ?? '';
        $positions = $data['positions'] ?? [];
        $modelCode = $data['model_code'] ?? '';

        if (empty($content)) {
            return $this->jsonResponse(['success' => false, 'message' => '请输入内容']);
        }

        if (empty($positions)) {
            return $this->jsonResponse(['success' => false, 'message' => '没有需要润色的敏感内容']);
        }

        try {
            $options = [];
            if (!empty($modelCode)) {
                $options['model_code'] = $modelCode;
            }
            
            $result = $this->service->rewriteSensitiveContent($content, $positions, $options);
            return $this->jsonResponse(['success' => true, 'message' => '润色完成', 'data' => $result]);
        } catch (\Exception $e) {
            return $this->jsonResponse(['success' => false, 'message' => '润色失败: ' . $e->getMessage()]);
        }
    }
}

