<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Backend\Observer;

use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Session;

class ResponseRedirectBefore implements ObserverInterface
{
    /**
     * @var Request
     */
    protected Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $data = $event->getData('data');
        $url = $data->getUrl();
        $code = $data->getCode();
        $originalUrl = $url;
        
        // 只处理后台请求
        if (!$this->request->isBackend()) {
            return;
        }

        # 如果不是http开头，则认为是相对路径
        if (!str_starts_with($url, 'http')) {
            $url = $this->request->getUrlBuilder()->getBackendUrl($url);
        }
        
        // 处理登录来源页面记录
        $this->handleLoginReferer($data, $url, $code);
        
        // 处理权限重定向
        $this->handlePermissionRedirect($data, $url, $code);
        
        // 处理安全重定向
        $this->handleSecurityRedirect($data, $url, $code);
        
        // 处理后台特殊重定向
        $this->handleBackendSpecialRedirect($data, $url, $code);
    }

    /**
     * 处理登录来源页面记录
     */
    protected function handleLoginReferer(DataObject $data, string $url, int $code): void
    {
        try {
            $path = $this->request->getRouteUrlPath($url);
            
            // 只处理GET请求
            if (!$this->request->isGet()) {
                return;
            }
            
            // 只处理登录页面的302重定向
            if ($path !== 'admin/login' || $code !== 302) {
                return;
            }
            
            // 跳过AJAX和iframe请求
            if ($this->request->isAjax() || $this->request->isIframe()) {
                return;
            }
            
            // 获取白名单URL
            $whiteUrls = $this->getBackendWhitelistUrls();
            $whiteUrls[] = 'admin/login/logout';
            
            // 检查当前路径是否在白名单中
            $currentPath = trim($this->request->getRouteUrlPath(), '/');
            if (!in_array($currentPath, $whiteUrls)) {
                // 记录登录来源页面
                /** @var Session $session */
                $session = ObjectManager::getInstance(Session::class);
                $session->setData('backend_login_referer', $this->request->getUrlBuilder()->getCurrentUrl());
            }
            
        } catch (\Exception $e) {
            w_log_error("登录来源页面记录失败: " . $e->getMessage());
        }
    }

    /**
     * 处理权限重定向
     */
    protected function handlePermissionRedirect(DataObject $data, string $url, int $code): void
    {
        try {
            // 检查用户是否已登录
            /** @var AuthenticatedSessionInterface $backendSession */
            $backendSession = SessionFactory::getInstance()->createBackendSession();
            
            if (!$backendSession->isLoggedIn()) {
                // 未登录用户重定向到登录页
                $loginUrl = $this->request->getUrlBuilder()->getBackendUrl('admin/login');
                $data->setData('url', $loginUrl);
                $data->setData('code', 302);
                return;
            }
            
            // 检查用户权限
            $currentPath = $this->request->getRouteUrlPath();
            $hasPermission = $this->hasPermission($currentPath);
            
            if (!$hasPermission) {
                // 无权限用户重定向到后台首页（路由为 admin，不是 admin/dashboard）
                $homeUrl = $this->request->getUrlBuilder()->getBackendUrl('admin');
                $data->setData('url', $homeUrl);
                $data->setData('code', 302);
            }
            
        } catch (\Exception $e) {
            w_log_error("权限重定向处理失败: " . $e->getMessage());
        }
    }

    /**
     * 处理安全重定向
     */
    protected function handleSecurityRedirect(DataObject $data, string $url, int $code): void
    {
        try {
            // 检查URL安全性
            $parsedUrl = parse_url($url);
            
            // 防止开放重定向攻击
            if (isset($parsedUrl['host'])) {
                $host = $parsedUrl['host'];
                // HTTP_HOST 可能包含端口（如 my.com:9981），parse_url()['host'] 不含端口
                // 必须统一比较纯主机名，否则 "my.com" !== "my.com:9981" 会误判为攻击
                $currentHost = strtok($_SERVER['HTTP_HOST'] ?? '', ':') ?: '';
                
                // 只允许重定向到当前域名或白名单域名
                if ($host !== $currentHost && !$this->isAllowedHost($host)) {
                    // 重定向到后台首页
                    $data->setData('url', $this->request->getUrlBuilder()->getBackendUrl('admin'));
                    $data->setData('code', 302);
                    return;
                }
            }
            
            // 检查URL中是否包含危险字符
            if (str_contains($url, '<script') || str_contains($url, 'javascript:') || str_contains($url, 'data:')) {
                $data->setData('url', $this->request->getUrlBuilder()->getBackendUrl('admin'));
                $data->setData('code', 302);
            }
            
            // 检查CSRF令牌（如果适用）
            if ($this->request->isPost() && !$this->validateCsrfToken()) {
                // CSRF验证失败，重定向到当前页面
                $data->setData('url', $this->request->getUrlBuilder()->getCurrentUrl());
                $data->setData('code', 302);
            }
            
        } catch (\Exception $e) {
            w_log_error("安全重定向处理失败: " . $e->getMessage());
        }
    }

    /**
     * 处理后台特殊重定向
     */
    protected function handleBackendSpecialRedirect(DataObject $data, string $url, int $code): void
    {
        try {
            $parsedUrl = parse_url($url);
            $path = $parsedUrl['path'] ?? '';
            
            // 处理后台URL规范化
            if (str_contains($path, '/admin/')) {
                // 确保后台URL格式正确
                $path = preg_replace('#/admin/+#', '/admin/', $path);
                $newUrl = str_replace($parsedUrl['path'], $path, $url);
                $data->setData('url', $newUrl);
            }
            
            // 处理语言参数重定向
            if (isset($parsedUrl['query'])) {
                parse_str($parsedUrl['query'], $queryParams);
                if (isset($queryParams['lang'])) {
                    // 保存语言选择到会话
                    /** @var Session $session */
                    $session = ObjectManager::getInstance(Session::class);
                    $session->setData('backend_language', $queryParams['lang']);
                }
            }
            
        } catch (\Exception $e) {
            w_log_error("后台特殊重定向处理失败: " . $e->getMessage());
        }
    }

    /**
     * 获取后台白名单URL
     */
    protected function getBackendWhitelistUrls(): array
    {
        return [
            'admin/login',
            'admin/login/post',
            'admin/logout',
            'admin/forgot-password',
            'admin/reset-password',
            'admin/register',
            'admin/api',
            'admin/assets',
            'admin/static',
            'admin/media',
            'admin/favicon.ico',
            'admin/robots.txt',
            'admin/sitemap.xml'
        ];
    }

    /**
     * 检查是否是允许的主机
     */
    protected function isAllowedHost(string $host): bool
    {
        $allowedHosts = [
            'localhost',
            '127.0.0.1',
            // 可以添加更多允许的域名
        ];
        
        return in_array($host, $allowedHosts);
    }

    /**
     * 检查用户权限
     */
    protected function hasPermission(string $path): bool
    {
        try {
            // 这里可以集成ACL权限检查
            // 暂时返回true，实际项目中应该检查用户权限
            return true;
        } catch (\Exception $e) {
            w_log_error("权限检查失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 验证CSRF令牌
     */
    protected function validateCsrfToken(): bool
    {
        try {
            // 这里可以添加CSRF令牌验证逻辑
            // 暂时返回true，实际项目中应该验证CSRF令牌
            return true;
        } catch (\Exception $e) {
            w_log_error("CSRF验证失败: " . $e->getMessage());
            return false;
        }
    }
}
