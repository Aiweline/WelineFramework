<?php

declare(strict_types=1);

namespace Weline\Framework\App\Controller;

use Weline\Framework\Controller\PcController;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;

/**
 * 前台控制器基类
 *
 * 提供前台页面的通用功能：
 * - Session 管理（通过 AuthenticatedSession）
 * - 布局渲染辅助方法
 */
class FrontendController extends PcController
{
    /** 认证 Session（使用新架构） */
    protected AuthenticatedSessionInterface $session;

    public function __init()
    {
        if (!isset($this->session)) {
            $this->session = SessionFactory::getInstance()->createFrontendSession();
        }
        
        parent::__init();
    }

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
        $layoutMap = [
            'auth' => 'Weline_Theme::theme/frontend/layouts/account_auth/default.phtml',
            'default' => 'Weline_Theme::theme/frontend/layouts/default/default.phtml',
            'full' => 'Weline_Theme::theme/frontend/layouts/default/default.phtml',
        ];
        
        $layoutTemplate = $layoutMap[$layoutType] ?? $layoutType;
        
        if (\strpos($contentTemplate, '::') === false) {
            $controllerClass = \get_class($this);
            $moduleName = \explode('\\', $controllerClass)[0] . '_' . \explode('\\', $controllerClass)[1];
            $contentTemplate = $moduleName . '::templates/frontend/' . \ltrim($contentTemplate, '/');
        }
        
        $contentHtml = $this->fetch($contentTemplate, $additionalData);
        $layoutData = \array_merge([
            'title' => $title,
            'content' => $contentHtml,
        ], $additionalData);
        $layoutData['meta'] = \array_merge(
            $layoutData['meta'] ?? [],
            ['content' => $contentHtml, 'contentTemplate' => $contentTemplate]
        );
        
        return $this->fetch($layoutTemplate, $layoutData);
    }
    
    /**
     * 渲染认证布局（登录、注册等页面）
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
     */
    protected function renderFullLayout(
        string $contentTemplate,
        ?string $title = null,
        array $additionalData = []
    ): string {
        return $this->renderLayout('full', $contentTemplate, $title, $additionalData);
    }

    /**
     * 获取当前登录用户
     *
     * @return \Weline\Framework\Session\Auth\AuthenticableInterface|null
     */
    protected function getLoginUser(): ?\Weline\Framework\Session\Auth\AuthenticableInterface
    {
        return $this->session->getUser();
    }

    /**
     * 获取当前登录用户 ID
     *
     * @return int|string|null
     */
    protected function getLoginUserId(): int|string|null
    {
        return $this->session->getUserId();
    }

    /**
     * 获取当前登录用户名
     *
     * @return string|null
     */
    protected function getLoginUsername(): ?string
    {
        return $this->session->getUsername();
    }

    /**
     * 检查是否已登录
     *
     * @return bool
     */
    protected function isLoggedIn(): bool
    {
        return $this->session->isLoggedIn();
    }
}
