<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\App\Controller;

use Weline\Framework\App\Session\FrontendSession;
use Weline\Framework\Controller\PcController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Session;

class FrontendController extends PcController
{
    /**
     * 渲染主题布局
     * 
     * @param string $layoutType 布局类型（auth, default, full 等）
     * @param string $contentTemplate 内容模板路径（相对于 view/templates/frontend/）
     * @param string|null $title 页面标题
     * @param array $additionalData 额外的模板数据
     * @return string
     */
    protected function renderLayout(
        string $layoutType,
        string $contentTemplate,
        ?string $title = null,
        array $additionalData = []
    ): string {
        // 预设布局路径映射
        $layoutMap = [
            'auth' => 'Weline_Theme::theme/frontend/layouts/account/auth.phtml',
            'default' => 'Weline_Theme::theme/frontend/layouts/default.phtml',
            'full' => 'Weline_Theme::theme/frontend/layouts/full.phtml',
        ];
        
        // 获取布局模板路径
        $layoutTemplate = $layoutMap[$layoutType] ?? null;
        if (!$layoutTemplate) {
            // 如果不在预设中，尝试直接使用传入的路径
            $layoutTemplate = $layoutType;
        }
        
        // 构建内容模板路径（如果传入的不是完整路径，则自动补全）
        if (strpos($contentTemplate, '::') === false) {
            // 自动推断模块名（从当前控制器类名）
            $controllerClass = get_class($this);
            $moduleName = explode('\\', $controllerClass)[0] . '_' . explode('\\', $controllerClass)[1];
            $contentTemplate = $moduleName . '::templates/frontend/' . ltrim($contentTemplate, '/');
        }
        
        // 准备布局数据
        $layoutData = array_merge([
            'title' => $title,
            'content' => $this->fetch($contentTemplate, $additionalData)
        ], $additionalData);
        
        // 渲染布局
        return $this->fetch($layoutTemplate, $layoutData);
    }
    
    /**
     * 渲染认证布局（登录、注册等页面）
     * 
     * @param string $contentTemplate 内容模板路径
     * @param string|null $title 页面标题
     * @param array $additionalData 额外的模板数据
     * @return string
     */
    protected function renderAuthLayout(
        string $contentTemplate,
        ?string $title = null,
        array $additionalData = []
    ): string {
        return $this->renderLayout('auth', $contentTemplate, $title, $additionalData);
    }
    
    /**
     * 渲染默认布局
     * 
     * @param string $contentTemplate 内容模板路径
     * @param string|null $title 页面标题
     * @param array $additionalData 额外的模板数据
     * @return string
     */
    protected function renderDefaultLayout(
        string $contentTemplate,
        ?string $title = null,
        array $additionalData = []
    ): string {
        return $this->renderLayout('default', $contentTemplate, $title, $additionalData);
    }
    
    /**
     * 渲染全屏布局
     * 
     * @param string $contentTemplate 内容模板路径
     * @param string|null $title 页面标题
     * @param array $additionalData 额外的模板数据
     * @return string
     */
    protected function renderFullLayout(
        string $contentTemplate,
        ?string $title = null,
        array $additionalData = []
    ): string {
        return $this->renderLayout('full', $contentTemplate, $title, $additionalData);
    }
}
