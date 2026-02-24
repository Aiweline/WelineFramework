<?php

declare(strict_types=1);

namespace Weline\Theme\Controller\Backend\Widget;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\ThemeLayout;

/**
 * Widget 参数渲染 API 控制器（薄代理：派发 Weline_Widget::query，返回 data.result）
 */
class ParamRender extends BackendController
{
    /**
     * 渲染完整的配置表单
     * POST /theme/backend/widget/paramRender/form
     *
     * 当提供 layoutId 时，优先从服务端根据 layout 解析 widget 并用 getParamDefinitions 获取完整 params
     *（含 item_schema 等），避免前端传入的 params 被截断导致 array 项无编辑/选图等字段。
     */
    public function postForm(): string
    {
        $layoutId = $this->request->getPost('layoutId', '');
        $params = $this->request->getPost('params', []);
        $config = $this->request->getPost('config', []);
        if (is_string($params)) {
            $params = json_decode($params, true) ?? [];
        }
        if (is_string($config)) {
            $config = json_decode($config, true) ?? [];
        }

        // 有 layoutId 时从服务端取完整参数定义（含 array 的 item_schema），避免前端列表中的 params 不完整
        if ($layoutId !== '' && $layoutId !== null) {
            $layoutIdInt = (int) $layoutId;
            if ($layoutIdInt > 0) {
                try {
                    /** @var ThemeLayout $themeLayout */
                    $themeLayout = ObjectManager::getInstance(ThemeLayout::class);
                    $themeLayout->reset()->load($layoutIdInt);
                    if ($themeLayout->getLayoutId()) {
                        $widgetModule = $themeLayout->getData('widget_module');
                        $widgetCode = $themeLayout->getData('widget_code');
                        $area = $themeLayout->getData('area') ?: 'frontend';
                        if ($widgetModule !== null && $widgetCode !== null) {
                            $defEvent = [
                                'data' => [
                                    'operation' => 'getParamDefinitions',
                                    'params' => [
                                        'widget_module' => (string) $widgetModule,
                                        'widget_code' => (string) $widgetCode,
                                        'area' => (string) $area,
                                    ],
                                ],
                            ];
                            $this->getEventManager()->dispatch('Weline_Widget::query', $defEvent);
                            $serverParams = $defEvent['data']['result'] ?? null;
                            if (is_array($serverParams) && !empty($serverParams)) {
                                $params = $serverParams;
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // 解析失败时继续使用请求中的 params
                }
            }
        }

        $eventData = [
            'data' => [
                'operation' => 'getConfigForm',
                'params' => ['layout_id' => $layoutId, 'params' => $params, 'config' => $config],
            ],
        ];
        $this->getEventManager()->dispatch('Weline_Widget::query', $eventData);
        $html = $eventData['data']['result'] ?? '';
        $err = $eventData['data']['error'] ?? null;
        if ($err !== null && $err !== '') {
            return '<div class="alert alert-danger">' . htmlspecialchars((string)$err) . '</div>';
        }
        return is_string($html) ? $html : '';
    }

    /**
     * 渲染单个字段
     * POST /theme/backend/widget/paramRender/field
     */
    public function postField(): string
    {
        $key = $this->request->getPost('key', '');
        $param = $this->request->getPost('param', []);
        $value = $this->request->getPost('value');
        $layoutId = $this->request->getPost('layoutId', '');
        if (is_string($param)) {
            $param = json_decode($param, true) ?? [];
        }
        if (empty($key) || empty($param)) {
            return '<div class="alert alert-warning">' . __('缺少必要参数') . '</div>';
        }
        $eventData = [
            'data' => [
                'operation' => 'renderField',
                'params' => ['key' => $key, 'param' => $param, 'value' => $value, 'layout_id' => $layoutId],
            ],
        ];
        $this->getEventManager()->dispatch('Weline_Widget::query', $eventData);
        $html = $eventData['data']['result'] ?? '';
        $err = $eventData['data']['error'] ?? null;
        if ($err !== null && $err !== '') {
            return '<div class="alert alert-danger">' . htmlspecialchars((string)$err) . '</div>';
        }
        return is_string($html) ? $html : '';
    }

    /**
     * 验证配置值
     * POST /theme/backend/widget/paramRender/validate
     */
    public function postValidate(): string
    {
        $params = $this->request->getPost('params', []);
        $values = $this->request->getPost('values', []);
        if (is_string($params)) {
            $params = json_decode($params, true) ?? [];
        }
        if (is_string($values)) {
            $values = json_decode($values, true) ?? [];
        }
        $eventData = [
            'data' => [
                'operation' => 'validateConfig',
                'params' => ['params' => $params, 'values' => $values],
            ],
        ];
        $this->getEventManager()->dispatch('Weline_Widget::query', $eventData);
        $result = $eventData['data']['result'] ?? null;
        $err = $eventData['data']['error'] ?? null;
        if ($err !== null && $err !== '') {
            return $this->fetchJson(['valid' => false, 'errors' => ['_exception' => $err]]);
        }
        return $this->fetchJson(is_array($result) ? $result : ['valid' => false, 'errors' => []]);
    }

    /**
     * 处理配置值
     * POST /theme/backend/widget/paramRender/process
     */
    public function postProcess(): string
    {
        $params = $this->request->getPost('params', []);
        $values = $this->request->getPost('values', []);
        if (is_string($params)) {
            $params = json_decode($params, true) ?? [];
        }
        if (is_string($values)) {
            $values = json_decode($values, true) ?? [];
        }
        $eventData = [
            'data' => [
                'operation' => 'processConfig',
                'params' => ['params' => $params, 'values' => $values],
            ],
        ];
        $this->getEventManager()->dispatch('Weline_Widget::query', $eventData);
        $processed = $eventData['data']['result'] ?? null;
        $err = $eventData['data']['error'] ?? null;
        if ($err !== null && $err !== '') {
            return $this->fetchJson(['success' => false, 'error' => $err]);
        }
        return $this->fetchJson([
            'success' => true,
            'data' => is_array($processed) ? $processed : [],
        ]);
    }

    /**
     * 获取所有已注册的类型
     * GET /theme/backend/widget/paramRender/types
     */
    public function getTypes(): string
    {
        $eventData = [
            'data' => [
                'operation' => 'getRegisteredTypes',
                'params' => [],
            ],
        ];
        $this->getEventManager()->dispatch('Weline_Widget::query', $eventData);
        $types = $eventData['data']['result'] ?? [];
        return $this->fetchJson([
            'success' => true,
            'types' => is_array($types) ? array_unique($types) : [],
        ]);
    }
}
