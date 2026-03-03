<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Session;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Http\Request;
use Weline\Theme\Helper\ComponentMetaParser;
use Weline\Theme\Helper\PreviewManager;
use Weline\Theme\Helper\PreviewAccountManager;
use Weline\Theme\Model\WelineTheme;

/**
 * 主题预览自动登录Observer
 * 
 * 功能：
 * - 在路由拦截之前处理主题预览请求
 * - 检查后端session是否已登录（允许预览的前提）
 * - 检查预览的布局是否需要前端登录（通过@preview.login标记）
 * - 如果需要，自动登录前端用户
 * 
 * 注意：此Observer只在预览模式下生效，且需要后端用户已登录
 */
class PreviewAutoLogin implements ObserverInterface
{
    private Session $session;
    private Request $request;

    public function __construct(Session $session, Request $request)
    {
        $this->session = $session;
        $this->request = $request;
    }

    /**
     * 执行Observer逻辑
     *
     * @param Event $event
     * @return void
     */
    public function execute(Event &$event): void
    {
        try {
            $request = $this->request;
            // 检查是否是预览模式
            if (!$this->isPreviewMode($request)) {
                return;
            }


            // 检查是否是后端请求，如果是后端请求，不处理前端预览自动登录
            if ($request->isBackend()) {
                return;
            }

            // 检查后端session是否已登录（允许预览的前提）
            if (!$this->isBackendUserLoggedIn()) {
                // 后端未登录，不允许预览，直接返回（让正常的登录拦截处理）
                return;
            }

            // 检查是否需要前端登录
            $themeId = (int)$request->getParam('preview_theme', 0);

            if ($this->shouldAutoLogin($request)) {
                if ($themeId) {
                    PreviewAccountManager::loginPreviewUserByThemeId($themeId);
                }
            } else {
                if ($themeId) {
                    PreviewAccountManager::logoutPreviewUser($themeId);
                } else {
                    PreviewAccountManager::logoutPreviewUser();
                }
            }
        } catch (\Throwable $e) {
            // 静默处理异常，不影响正常流程
            w_log_error("主题预览自动登录失败: " . $e->getMessage(), [], 'theme');
        }
    }

    /**
     * 检查是否是预览模式
     * 
     * @param \Weline\Framework\Http\Request $request
     * @return bool
     */
    private function isPreviewMode($request): bool
    {
        // 方式1：检查URL参数 preview_theme
        if ($request->getParam('preview_theme')) {
            return true;
        }

        // 方式2：检查session中是否有预览主题ID
        $previewThemeId = $this->session->getData('preview_theme_id');
        if (!empty($previewThemeId)) {
            return true;
        }

        // 方式3：检查URL路径是否包含预览相关路径
        $path = $request->getPathInfo();
        if (str_contains($path, 'theme/backend/config/layout/preview') ||
            str_contains($path, 'theme/backend/index/preview')) {
            return true;
        }

        return false;
    }

    /**
     * 检查后端用户是否已登录
     * 
     * @return bool
     */
    private function isBackendUserLoggedIn(): bool
    {
        try {
            // 使用BackendSession检查后端登录状态
            /** @var AuthenticatedSessionInterface $backendSession */
            $backendSession = SessionFactory::getInstance()->createBackendSession();
            return $backendSession->isLoggedIn();
        } catch (\Exception $e) {
            // 如果获取BackendSession失败，返回false
            return false;
        }
    }

    /**
     * 检查是否需要自动登录前端用户
     * 
     * @param \Weline\Framework\Http\Request $request
     * @return bool
     */
    private function shouldAutoLogin($request): bool
    {
        // 只处理前端预览
        $area = $this->session->getData('preview_theme_area') ?? 'frontend';
        if ($area !== 'frontend') {
            return false;
        }

        // 检查session中是否已设置自动登录标志
        $previewAutoLogin = $this->session->getData('preview_auto_login');
        if ($previewAutoLogin !== null) {
            return (bool)$previewAutoLogin;
        }

        // 如果没有设置，尝试根据布局配置判断
        $themeId = $this->session->getData('preview_theme_id');
        if (empty($themeId)) {
            return false;
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        
        if (!$theme->getId()) {
            return false;
        }

        // 获取布局配置
        $layouts = PreviewManager::getPreviewConfig('layouts', 'frontend');
        if (empty($layouts)) {
            return false;
        }

        // 检查布局是否需要登录
        return $this->checkLayoutRequiresLogin($theme, $layouts);
    }

    /**
     * 检查布局是否需要登录
     * 
     * @param WelineTheme $theme
     * @param array $layouts
     * @return bool
     */
    private function checkLayoutRequiresLogin(WelineTheme $theme, array $layouts): bool
    {
        try {
            // 优先检查 account 布局（通常需要登录）
            $priorityOrder = ['account', 'homepage', 'default'];
            
            foreach ($priorityOrder as $layoutType) {
                if (isset($layouts[$layoutType]) && !empty($layouts[$layoutType])) {
                    $layoutOption = $layouts[$layoutType];
                    $previewLogin = $this->getLayoutPreviewLogin($theme, 'frontend', $layoutType, $layoutOption);
                    if ($previewLogin !== null) {
                        return $previewLogin == 1;
                    }
                }
            }
            
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 获取布局文件的 @preview.login 标记值
     * 
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域
     * @param string $layoutType 布局类型
     * @param string $layoutOption 布局选项
     * @return int|null 标记值（0或1），如果找不到返回null
     */
    private function getLayoutPreviewLogin(WelineTheme $theme, string $area, string $layoutType, string $layoutOption): ?int
    {
        try {
            $themePath = $theme->getPath();
            if (empty($themePath)) {
                return null;
            }
            
            $layoutPath = rtrim($themePath, DS) . DS . 'view' . DS . 'theme' . DS . $area . DS . 'layouts' . DS . $layoutType . DS . $layoutOption . '.phtml';
            $layoutPath = str_replace('\\', DS, $layoutPath);
            
            // 如果当前主题不存在，尝试父主题
            if (!is_file($layoutPath)) {
                $parentId = $theme->getParentId();
                if ($parentId) {
                    /** @var WelineTheme $parentTheme */
                    $parentTheme = ObjectManager::getInstance(WelineTheme::class);
                    $parentTheme->load($parentId);
                    if ($parentTheme->getId()) {
                        return $this->getLayoutPreviewLogin($parentTheme, $area, $layoutType, $layoutOption);
                    }
                }
                return null;
            }
            
            // 解析布局文件的 Meta 信息
            $meta = ComponentMetaParser::parse($layoutPath);
            
            return isset($meta['preview_login']) ? (int)$meta['preview_login'] : null;
        } catch (\Exception $e) {
            return null;
        }
    }

}

