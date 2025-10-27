<?php

declare(strict_types=1);

/*
 * 敏感内容检测控制器
 */

namespace GuoLaiRen\Desensitization\Controller\Backend;

use GuoLaiRen\Desensitization\Service\DesensitizationService;
use Weline\Framework\App\Controller\BackendController;

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
        // 获取可用的检测类型
        $rules = $this->service->getRules();
        $types = [];
        foreach ($rules as $rule) {
            if (!in_array($rule['type'], $types)) {
                $types[] = $rule['type'];
            }
        }
        
        // 获取可用的AI模型列表
        $models = $this->service->getAvailableModels();
        
        $this->assign('types', $types);
        $this->assign('models', $models);
        return $this->fetch();
    }

    /**
     * 执行检测
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

        if (empty($content)) {
            return $this->error()->json('请输入检测内容');
        }

        try {
            $startTime = microtime(true);
            
            $options = [
                'rule_types' => $data['rule_types'] ?? [],
                'return_positions' => true
            ];
            
            $result = $this->service->detectSensitive($content, $options);
            $executionTime = microtime(true) - $startTime;

            return $this->success([
                'original' => $content,
                'detection' => $result,
                'execution_time' => round($executionTime * 1000, 2) . 'ms'
            ])->json('检测完成');
        } catch (\Exception $e) {
            return $this->error()->json('检测失败: ' . $e->getMessage());
        }
    }
}

