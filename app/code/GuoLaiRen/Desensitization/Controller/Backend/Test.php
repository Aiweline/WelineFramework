<?php

declare(strict_types=1);

/*
 * 脱敏测试控制器
 */

namespace GuoLaiRen\Desensitization\Controller\Backend;

use GuoLaiRen\Desensitization\Service\DesensitizationService;
use Weline\Framework\App\Controller\BackendController;

class Test extends BackendController
{
    private DesensitizationService $service;

    public function __construct(DesensitizationService $service)
    {
        $this->service = $service;
    }

    /**
     * 脱敏测试页面
     *
     * @return mixed
     */
    public function index()
    {
        $methods = $this->service->getAvailableMethods();
        $rules = $this->service->getRules();
        
        // 获取可用的AI模型列表
        $models = $this->service->getAvailableModels();
        
        $this->assign('methods', $methods);
        $this->assign('rules', $rules);
        $this->assign('models', $models);
        return $this->fetch();
    }

    /**
     * 执行脱敏测试
     *
     * @return mixed
     */
    public function execute()
    {
        if (!$this->isPost()) {
            return $this->error()->json('仅支持POST请求');
        }

        $data = $this->request->getParams();
        
        $content = $data['content'] ?? '';
        $method = $data['method'] ?? 'regex';
        $options = $data['options'] ?? [];

        if (empty($content)) {
            return $this->error()->json('请输入测试内容');
        }

        try {
            $startTime = microtime(true);
            
            // 如果指定了model_code，添加到options中
            if (isset($data['model_code']) && !empty($data['model_code'])) {
                $options['model_code'] = $data['model_code'];
            }
            
            $result = $this->service->desensitize($content, $method, $options);
            $executionTime = microtime(true) - $startTime;

            return $this->success([
                'original' => $content,
                'desensitized' => $result,
                'execution_time' => round($executionTime * 1000, 2) . 'ms'
            ])->json('脱敏测试成功');
        } catch (\Exception $e) {
            return $this->error()->json('脱敏测试失败: ' . $e->getMessage());
        }
    }
}

