<?php

declare(strict_types=1);

/*
 * 脱敏API控制器
 */

namespace GuoLaiRen\Desensitization\Controller\Api;

use GuoLaiRen\Desensitization\Service\DesensitizationService;
use Weline\Framework\App\Controller\FrontendRestController;

class Desensitization extends FrontendRestController
{
    private DesensitizationService $service;

    public function __construct(DesensitizationService $service)
    {
        $this->service = $service;
    }

    /**
     * 单条内容脱敏
     *
     * @return mixed
     */
    public function process()
    {
        if (!$this->isPost()) {
            return $this->error('仅支持POST请求');
        }

        $data = $this->request->getParams();
        
        $content = $data['content'] ?? '';
        $method = $data['method'] ?? 'regex';
        $options = $data['options'] ?? [];

        if (empty($content)) {
            return $this->error('内容不能为空');
        }

        try {
            $result = $this->service->desensitize($content, $method, $options);
            return $this->success([
                'original' => $content,
                'desensitized' => $result
            ], '脱敏成功');
        } catch (\Exception $e) {
            return $this->error('脱敏失败: ' . $e->getMessage());
        }
    }

    /**
     * 批量内容脱敏
     *
     * @return mixed
     */
    public function batch()
    {
        if (!$this->isPost()) {
            return $this->error('仅支持POST请求');
        }

        $data = $this->request->getParams();
        
        $contents = $data['contents'] ?? [];
        $method = $data['method'] ?? 'regex';
        $options = $data['options'] ?? [];

        if (empty($contents) || !is_array($contents)) {
            return $this->error('内容不能为空');
        }

        try {
            $results = $this->service->desensitizeBatch($contents, $method, $options);
            return $this->success(['results' => $results], '批量脱敏成功');
        } catch (\Exception $e) {
            return $this->error('批量脱敏失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取可用方法列表
     *
     * @return mixed
     */
    public function methods()
    {
        try {
            $methods = $this->service->getAvailableMethods();
            return $this->success(['methods' => $methods], '获取成功');
        } catch (\Exception $e) {
            return $this->error('获取失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取规则列表
     *
     * @return mixed
     */
    public function rules()
    {
        try {
            $type = $this->request->getParam('type', '');
            $rules = $this->service->getRules($type ?: null);
            return $this->success(['rules' => $rules], '获取成功');
        } catch (\Exception $e) {
            return $this->error('获取失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取可用AI模型列表
     *
     * @return mixed
     */
    public function models()
    {
        try {
            $models = $this->service->getAvailableModels();
            return $this->success(['models' => $models], '获取成功');
        } catch (\Exception $e) {
            return $this->error('获取失败: ' . $e->getMessage());
        }
    }

    /**
     * 检测敏感内容
     *
     * @return mixed
     */
    public function detect()
    {
        $data = $this->request->getParams();
        
        $content = $data['content'] ?? '';

        if (empty($content)) {
            return $this->error('内容不能为空');
        }

        try {
            $options = [
                'rule_types' => $data['rule_types'] ?? [],
                'return_positions' => isset($data['return_positions']) ? (bool)$data['return_positions'] : true
            ];
            
            $result = $this->service->detectSensitive($content, $options);
            return $this->success($result, '检测成功');
        } catch (\Exception $e) {
            return $this->error('检测失败: ' . $e->getMessage());
        }
    }

    /**
     * 脱敏并使用AI重写润色
     *
     * @return mixed
     */
    public function rewrite()
    {
        if (!$this->isPost()) {
            return $this->error('仅支持POST请求');
        }

        $data = $this->request->getParams();
        
        $content = $data['content'] ?? '';

        if (empty($content)) {
            return $this->error('内容不能为空');
        }

        try {
            $options = [
                'model_code' => $data['model_code'] ?? '',
                'rewrite_style' => $data['rewrite_style'] ?? 'natural',
                'preserve_format' => isset($data['preserve_format']) ? (bool)$data['preserve_format'] : true
            ];
            
            $result = $this->service->desensitizeAndRewrite($content, $options);
            return $this->success([
                'original' => $content,
                'rewritten' => $result
            ], '重写润色成功');
        } catch (\Exception $e) {
            return $this->error('重写润色失败: ' . $e->getMessage());
        }
    }
}

