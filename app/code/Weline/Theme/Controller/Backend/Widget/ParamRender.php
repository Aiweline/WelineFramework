<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Controller\Backend\Widget;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\Widget\ParamTypeRenderer;

/**
 * Widget 参数渲染 API 控制器
 * 
 * 提供后端渲染 Widget 配置表单的 API
 */
class ParamRender extends BackendController
{
    private ParamTypeRenderer $paramTypeRenderer;

    public function __construct(ParamTypeRenderer $paramTypeRenderer)
    {
        $this->paramTypeRenderer = $paramTypeRenderer;
    }

    /**
     * 渲染完整的配置表单
     * 
     * POST /theme/backend/widget/paramRender/form
     * 
     * @return string
     */
    public function postForm(): string
    {
        $layoutId = $this->request->getPost('layoutId', '');
        $params = $this->request->getPost('params', []);
        $config = $this->request->getPost('config', []);
        
        // 处理 JSON 字符串
        if (is_string($params)) {
            $params = json_decode($params, true) ?? [];
        }
        if (is_string($config)) {
            $config = json_decode($config, true) ?? [];
        }
        
        try {
            $html = $this->paramTypeRenderer->renderForm($layoutId, $params, $config);
            return $html;
        } catch (\Exception $e) {
            return '<div class="alert alert-danger">' . __('渲染表单失败: %{error}', ['error' => $e->getMessage()]) . '</div>';
        }
    }

    /**
     * 渲染单个字段
     * 
     * POST /theme/backend/widget/paramRender/field
     * 
     * @return string
     */
    public function postField(): string
    {
        $key = $this->request->getPost('key', '');
        $param = $this->request->getPost('param', []);
        $value = $this->request->getPost('value');
        $layoutId = $this->request->getPost('layoutId', '');
        
        // 处理 JSON 字符串
        if (is_string($param)) {
            $param = json_decode($param, true) ?? [];
        }
        
        if (empty($key) || empty($param)) {
            return '<div class="alert alert-warning">' . __('缺少必要参数') . '</div>';
        }
        
        try {
            $html = $this->paramTypeRenderer->renderField($key, $param, $value, $layoutId);
            return $html;
        } catch (\Exception $e) {
            return '<div class="alert alert-danger">' . __('渲染字段失败: %{error}', ['error' => $e->getMessage()]) . '</div>';
        }
    }

    /**
     * 验证配置值
     * 
     * POST /theme/backend/widget/paramRender/validate
     * 
     * @return string JSON
     */
    public function postValidate(): string
    {
        $params = $this->request->getPost('params', []);
        $values = $this->request->getPost('values', []);
        
        // 处理 JSON 字符串
        if (is_string($params)) {
            $params = json_decode($params, true) ?? [];
        }
        if (is_string($values)) {
            $values = json_decode($values, true) ?? [];
        }
        
        try {
            $result = $this->paramTypeRenderer->validateConfig($params, $values);
            return $this->fetchJson($result);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'valid' => false,
                'errors' => ['_exception' => $e->getMessage()],
            ]);
        }
    }

    /**
     * 处理配置值
     * 
     * POST /theme/backend/widget/paramRender/process
     * 
     * @return string JSON
     */
    public function postProcess(): string
    {
        $params = $this->request->getPost('params', []);
        $values = $this->request->getPost('values', []);
        
        // 处理 JSON 字符串
        if (is_string($params)) {
            $params = json_decode($params, true) ?? [];
        }
        if (is_string($values)) {
            $values = json_decode($values, true) ?? [];
        }
        
        try {
            $processed = $this->paramTypeRenderer->processConfig($params, $values);
            return $this->fetchJson([
                'success' => true,
                'data' => $processed,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 获取所有已注册的类型
     * 
     * GET /theme/backend/widget/paramRender/types
     * 
     * @return string JSON
     */
    public function getTypes(): string
    {
        $types = $this->paramTypeRenderer->getRegisteredTypes();
        return $this->fetchJson([
            'success' => true,
            'types' => array_unique($types),
        ]);
    }
}
