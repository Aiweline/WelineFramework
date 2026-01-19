<?php

declare(strict_types=1);

/*
 * AI重写润色控制器
 */

namespace GuoLaiRen\Desensitization\Controller\Backend;

use GuoLaiRen\Desensitization\Service\DesensitizationService;
use Weline\Framework\App\Controller\BackendController;

class Rewrite extends BackendController
{
    private DesensitizationService $service;

    public function __construct(DesensitizationService $service)
    {
        $this->service = $service;
    }

    /**
     * 重写润色页面
     *
     * @return mixed
     */
    public function index()
    {
        // 获取重写风格
        $config = require __DIR__ . '/../../etc/env.php';
        $styles = $config['desensitization']['rewrite_styles'] ?? [
            'natural' => '自然流畅',
            'formal' => '正式专业',
            'casual' => '轻松随意',
            'professional' => '专业严谨',
            'concise' => '简洁精炼'
        ];
        
        // 获取可用的AI模型列表
        $models = $this->service->getAvailableModels();
        
        $this->assign('styles', $styles);
        $this->assign('models', $models);
        return $this->fetch();
    }

    /**
     * 执行重写润色
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
            return $this->error()->json('请输入内容');
        }

        try {
            $startTime = microtime(true);
            
            $options = [
                'model_code' => $data['model_code'] ?? '',
                'rewrite_style' => $data['rewrite_style'] ?? 'natural',
                'preserve_format' => isset($data['preserve_format']) ? (bool)$data['preserve_format'] : true
            ];
            
            $result = $this->service->desensitizeAndRewrite($content, $options);
            $executionTime = microtime(true) - $startTime;

            return $this->success([
                'original' => $content,
                'rewritten' => $result,
                'execution_time' => round($executionTime * 1000, 2) . 'ms'
            ])->json('重写润色成功');
        } catch (\Exception $e) {
            return $this->error()->json('重写润色失败: ' . $e->getMessage());
        }
    }
}

