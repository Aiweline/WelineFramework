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
use Weline\Framework\Manager\ObjectManager;
use Weline\Widget\Service\WidgetData;
use Weline\Widget\Taglib\Widget;

/**
 * AJAX 预览控制器
 */
class Preview extends BackendController
{
    private WidgetScanner $widgetScanner;

    public function __construct(WidgetScanner $widgetScanner)
    {
        $this->widgetScanner = $widgetScanner;
    }

    /**
     * 预览单个部件
     */
    #[\Weline\Framework\Acl\Acl('Weline_Widget::preview_widget', '预览部件', '', '预览部件')]
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

            return $this->fetchJson([
                'success' => true,
                'html' => $html
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
    #[\Weline\Framework\Acl\Acl('Weline_Widget::preview_page', '预览页面', '', '预览页面')]
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

            // 这里可以处理页面内容，渲染所有 w:widget 标签
            // 暂时返回原始内容
            return $this->fetchJson([
                'success' => true,
                'html' => $content
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('预览失败: %{1}', [$e->getMessage()])
            ]);
        }
    }
}

