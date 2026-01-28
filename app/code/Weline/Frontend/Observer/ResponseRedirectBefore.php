<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Frontend\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\UrlManager\Model\UrlRewrite;

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
        
        // 只处理前端请求
        if ($this->request->isBackend()) {
            return;
        }

        # 如果不是http开头，则认为是相对路径
        if (!str_starts_with($url, 'http')) {
            $url = $this->request->getUrlBuilder()->getFrontendUrl($url);
        }
        
        // 处理URL重写重定向
        $this->handleUrlRewrite($data, $url, $code);
        
        // 处理SEO重定向
        $this->handleSeoRedirect($data, $url, $code);
        
        // 处理安全重定向
        $this->handleSecurityRedirect($data, $url, $code);
        
        // 处理移动端重定向
        $this->handleMobileRedirect($data, $url, $code);
    }

    /**
     * 处理URL重写重定向
     */
    protected function handleUrlRewrite(DataObject $data, string $url, int $code): void
    {
        try {
            // 检查是否有URL重写规则
            if (class_exists(UrlRewrite::class)) {
                /** @var UrlRewrite $urlRewrite */
                $urlRewrite = ObjectManager::getInstance(UrlRewrite::class);
                
                // 解析当前URL路径
                $parsedUrl = parse_url($url);
                $path = $parsedUrl['path'] ?? '';
                
                if (!empty($path)) {
                    $path = ltrim($path, '/');
                    
                    // 获取当前网站ID
                    $websiteId = UrlRewrite::getCurrentWebsiteId();
                    
                    // 按 website_id 查找重写规则（不回退到 website_id=0）
                    $rewrite = $urlRewrite->reset()
                        ->clearQuery()
                        ->where(UrlRewrite::fields_WEBSITE_ID, $websiteId)
                        ->where(UrlRewrite::fields_PATH, $path)
                        ->find()
                        ->fetch();
                    
                    if ($rewrite->getId()) {
                        $rewritePath = $rewrite->getData('rewrite');
                        if (!empty($rewritePath)) {
                            // 构建新的URL
                            $newUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
                            if (isset($parsedUrl['port'])) {
                                $newUrl .= ':' . $parsedUrl['port'];
                            }
                            $newUrl .= '/' . $rewritePath;
                            
                            if (isset($parsedUrl['query'])) {
                                $newUrl .= '?' . $parsedUrl['query'];
                            }
                            
                            $data->setData('url', $newUrl);
                            $data->setData('code', 301); // 永久重定向
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // 记录错误但不影响重定向
            error_log("URL重写处理失败: " . $e->getMessage());
        }
    }

    /**
     * 处理SEO重定向
     */
    protected function handleSeoRedirect(DataObject $data, string $url, int $code): void
    {
        try {
            // 检查是否需要SEO重定向
            $parsedUrl = parse_url($url);
            $path = $parsedUrl['path'] ?? '';
            
            // 处理尾部斜杠重定向
            if (strlen($path) > 1 && !str_ends_with($path, '/')) {
                // 检查是否是目录路径（不包含文件扩展名）
                $pathInfo = pathinfo($path);
                if (empty($pathInfo['extension'])) {
                    $newUrl = $url . '/';
                    $data->setData('url', $newUrl);
                    $data->setData('code', 301);
                }
            }
            
            // 处理www重定向
            $host = $parsedUrl['host'] ?? '';
            if (str_starts_with($host, 'www.')) {
                $newHost = substr($host, 4);
                $newUrl = str_replace($host, $newHost, $url);
                $data->setData('url', $newUrl);
                $data->setData('code', 301);
            }
            
        } catch (\Exception $e) {
            error_log("SEO重定向处理失败: " . $e->getMessage());
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
                $currentHost = $_SERVER['HTTP_HOST'] ?? '';
                
                // 只允许重定向到当前域名或白名单域名
                if ($host !== $currentHost && !$this->isAllowedHost($host)) {
                    // 重定向到首页
                    $data->setData('url', '/');
                    $data->setData('code', 302);
                    return;
                }
            }
            
            // 检查URL中是否包含危险字符
            if (str_contains($url, '<script') || str_contains($url, 'javascript:') || str_contains($url, 'data:')) {
                $data->setData('url', '/');
                $data->setData('code', 302);
            }
            
        } catch (\Exception $e) {
            error_log("安全重定向处理失败: " . $e->getMessage());
        }
    }

    /**
     * 处理移动端重定向
     */
    protected function handleMobileRedirect(DataObject $data, string $url, int $code): void
    {
        try {
            // 检查是否是移动设备
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $isMobile = $this->isMobileDevice($userAgent);
            
            if ($isMobile) {
                // 检查是否需要移动端特殊处理
                $parsedUrl = parse_url($url);
                $path = $parsedUrl['path'] ?? '';
                
                // 移动端特殊路径处理
                if (str_contains($path, '/mobile/')) {
                    // 移动端路径优化
                    $mobilePath = str_replace('/mobile/', '/m/', $path);
                    $newUrl = str_replace($path, $mobilePath, $url);
                    $data->setData('url', $newUrl);
                }
            }
            
        } catch (\Exception $e) {
            error_log("移动端重定向处理失败: " . $e->getMessage());
        }
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
     * 检查是否是移动设备
     */
    protected function isMobileDevice(string $userAgent): bool
    {
        $mobileKeywords = [
            'Mobile', 'Android', 'iPhone', 'iPad', 'iPod', 
            'BlackBerry', 'Windows Phone', 'Opera Mini'
        ];
        
        foreach ($mobileKeywords as $keyword) {
            if (stripos($userAgent, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }
}
