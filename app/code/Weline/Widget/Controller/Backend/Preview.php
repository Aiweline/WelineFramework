<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Widget\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Widget\Service\WidgetData;
use Weline\Widget\Service\WidgetPreviewService;
use Weline\Widget\Taglib\Widget;

/**
 * AJAX 预览控制器
 *
 * 必须有类级 #[Acl] 并挂到菜单 ACL（widget_management），
 * 否则 setup:upgrade 会把 method 为空的方法级 ACL 互相当「类级父级」，
 * 最终出现 source_id === parent_source（阻断整站路由生成 → 全站 404）。
 */
#[\Weline\Framework\Acl\Acl('Weline_Widget::preview', '部件预览', 'eye', '部件 AJAX 预览', 'Weline_Widget::widget_management')]
class Preview extends BackendController
{
    public function __construct(
        private readonly WidgetData $widgetData,
        private readonly WidgetPreviewService $previewService,
    ) {
    }

    /**
     * 预览单个部件
     */
    #[\Weline\Framework\Acl\Acl('Weline_Widget::preview_widget', '预览部件', 'eye', '预览部件', 'Weline_Widget::preview')]
    public function widget()
    {
        try {
            $type = $this->request->getParam('type', '');
            $name = $this->request->getParam('name', '');
            $paramsJson = $this->request->getParam('params', '{}');

            if (empty($type) || empty($name)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('部件类型和名称不能为空')
                ]);
            }

            // 解析参数
            $params = [];
            if (!empty($paramsJson)) {
                $decoded = json_decode($paramsJson, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $params = $decoded;
                }
            }

            // 从注册表读取部件配置（运行时只读取，不扫描）
            $widget = $this->widgetData->getWidget($type, $name);
            if (!$widget) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('部件不存在')
                ]);
            }

            // 使用反射调用私有方法渲染部件
            $reflection = new \ReflectionClass(Widget::class);
            $method = $reflection->getMethod('renderWidget');
            $method->setAccessible(true);
            $html = $method->invokeArgs(null, [$widget, $params]);
            $html = $this->previewService->sanitizePreviewHtml(is_string($html) ? $html : '');

            return $this->fetchJson([
                'success' => true,
                'html' => $html,
                'preview_safe' => true,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('预览失败: %{1}', [$e->getMessage()])
            ]);
        }
    }

    /**
     * 预览完整页面
     */
    #[\Weline\Framework\Acl\Acl('Weline_Widget::preview_page', '预览页面', 'eye', '预览页面', 'Weline_Widget::preview')]
    public function page()
    {
        try {
            $content = $this->request->getParam('content', '');
            
            if (empty($content)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('页面内容不能为空')
                ]);
            }

            $html = $this->previewService->sanitizePreviewHtml($content);

            return $this->fetchJson([
                'success' => true,
                'html' => $html,
                'preview_safe' => true,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('预览失败: %{1}', [$e->getMessage()])
            ]);
        }
    }
}

