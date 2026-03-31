<?php

declare(strict_types=1);

/*
 * 鏈枃浠剁敱 绉嬫灚闆侀 缂栧啓锛屾墍鏈夎В閲婃潈褰扐iweline鎵€鏈夈€?
 * 閭锛歛iweline@qq.com
 * 缃戝潃锛歛iweline.com
 * 璁哄潧锛歨ttps://bbs.aiweline.com
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
        // WLS 下 Observer 可能复用旧实例，这里强制切到当前请求上下文。
        $this->request = ObjectManager::getInstance(Request::class);

        $data = $event->getData('data');
        $url = $data->getUrl();
        $code = $data->getCode();
        
        // 鍙鐞嗗墠绔姹?
        if ($this->request->isBackend()) {
            return;
        }

        # 濡傛灉涓嶆槸http寮€澶达紝鍒欒涓烘槸鐩稿璺緞
        if (!str_starts_with($url, 'http')) {
            $url = $this->request->getUrlBuilder()->getFrontendUrl($url);
        }
        
        // 澶勭悊URL閲嶅啓閲嶅畾鍚?
        $this->handleUrlRewrite($data, $url, $code);
        
        // 澶勭悊SEO閲嶅畾鍚?
        $this->handleSeoRedirect($data, $url, $code);
        
        // 澶勭悊瀹夊叏閲嶅畾鍚?
        $this->handleSecurityRedirect($data, $url, $code);
        
        // 澶勭悊绉诲姩绔噸瀹氬悜
        $this->handleMobileRedirect($data, $url, $code);
    }

    /**
     * 澶勭悊URL閲嶅啓閲嶅畾鍚?
     */
    protected function handleUrlRewrite(DataObject $data, string $url, int $code): void
    {
        try {
            // 妫€鏌ユ槸鍚︽湁URL閲嶅啓瑙勫垯
            if (class_exists(UrlRewrite::class)) {
                /** @var UrlRewrite $urlRewrite */
                $urlRewrite = ObjectManager::getInstance(UrlRewrite::class);
                
                // 瑙ｆ瀽褰撳墠URL璺緞
                $parsedUrl = parse_url($url);
                $path = $parsedUrl['path'] ?? '';
                
                if (!empty($path)) {
                    $path = ltrim($path, '/');
                    
                    // 鑾峰彇褰撳墠缃戠珯ID
                    $websiteId = UrlRewrite::getCurrentWebsiteId();
                    
                    // 鎸?website_id 鏌ユ壘閲嶅啓瑙勫垯锛堜笉鍥為€€鍒?website_id=0锛?
                    $rewrite = $urlRewrite->reset()
                        ->clearQuery()
                        ->where(UrlRewrite::schema_fields_WEBSITE_ID, $websiteId)
                        ->where(UrlRewrite::schema_fields_PATH, $path)
                        ->order(UrlRewrite::schema_fields_ID, 'DESC')
                        ->find()
                        ->fetch();
                    
                    if ($rewrite->getId()) {
                        $rewritePath = $rewrite->getData('rewrite');
                        if (!empty($rewritePath)) {
                            // 鏋勫缓鏂扮殑URL
                            $newUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
                            if (isset($parsedUrl['port'])) {
                                $newUrl .= ':' . $parsedUrl['port'];
                            }
                            $newUrl .= '/' . $rewritePath;
                            
                            if (isset($parsedUrl['query'])) {
                                $newUrl .= '?' . $parsedUrl['query'];
                            }
                            
                            $data->setData('url', $newUrl);
                            $data->setData('code', 301); // 姘镐箙閲嶅畾鍚?
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // 璁板綍閿欒浣嗕笉褰卞搷閲嶅畾鍚?
            w_log_error("URL閲嶅啓澶勭悊澶辫触: " . $e->getMessage());
        }
    }

    /**
     * 澶勭悊SEO閲嶅畾鍚?
     */
    protected function handleSeoRedirect(DataObject $data, string $url, int $code): void
    {
        try {
            $parsedUrl = parse_url($url);
            if (!is_array($parsedUrl)) {
                return;
            }

            // Preview URLs are internal workflow links and must not be canonicalized.
            if ($this->isPreviewRequestUrl($parsedUrl)) {
                return;
            }

            $path = $parsedUrl['path'] ?? '';

            if (strlen($path) > 1 && !str_ends_with($path, '/')) {
                $pathInfo = pathinfo($path);
                if (empty($pathInfo['extension'])) {
                    $parsedUrl['path'] = $path . '/';
                    $newUrl = $this->buildUrl($parsedUrl);
                    $data->setData('url', $newUrl);
                    $data->setData('code', 301);
                    $url = $newUrl;
                    $parsedUrl = parse_url($newUrl) ?: $parsedUrl;
                }
            }

            $host = $parsedUrl['host'] ?? '';
            if (str_starts_with($host, 'www.')) {
                $parsedUrl['host'] = substr($host, 4);
                $newUrl = $this->buildUrl($parsedUrl);
                $data->setData('url', $newUrl);
                $data->setData('code', 301);
            }

        } catch (\Exception $e) {
            w_log_error("SEO redirect handling failed: " . $e->getMessage());
        }
    }

    protected function handleSecurityRedirect(DataObject $data, string $url, int $code): void
    {
        try {
            // 妫€鏌RL瀹夊叏鎬?
            $parsedUrl = parse_url($url);
            
            // 闃叉寮€鏀鹃噸瀹氬悜鏀诲嚮
            if (isset($parsedUrl['host'])) {
                $host = $parsedUrl['host'];
                $currentHost = $_SERVER['HTTP_HOST'] ?? '';
                
                // 鍙厑璁搁噸瀹氬悜鍒板綋鍓嶅煙鍚嶆垨鐧藉悕鍗曞煙鍚?
                if ($host !== $currentHost && !$this->isAllowedHost($host)) {
                    // 閲嶅畾鍚戝埌棣栭〉
                    $data->setData('url', '/');
                    $data->setData('code', 302);
                    return;
                }
            }
            
            // 妫€鏌RL涓槸鍚﹀寘鍚嵄闄╁瓧绗?
            if (str_contains($url, '<script') || str_contains($url, 'javascript:') || str_contains($url, 'data:')) {
                $data->setData('url', '/');
                $data->setData('code', 302);
            }
            
        } catch (\Exception $e) {
            w_log_error("瀹夊叏閲嶅畾鍚戝鐞嗗け璐? " . $e->getMessage());
        }
    }

    /**
     * 澶勭悊绉诲姩绔噸瀹氬悜
     */
    protected function handleMobileRedirect(DataObject $data, string $url, int $code): void
    {
        try {
            // 妫€鏌ユ槸鍚︽槸绉诲姩璁惧
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $isMobile = $this->isMobileDevice($userAgent);
            
            if ($isMobile) {
                // 妫€鏌ユ槸鍚﹂渶瑕佺Щ鍔ㄧ鐗规畩澶勭悊
                $parsedUrl = parse_url($url);
                $path = $parsedUrl['path'] ?? '';
                
                // 绉诲姩绔壒娈婅矾寰勫鐞?
                if (str_contains($path, '/mobile/')) {
                    // 绉诲姩绔矾寰勪紭鍖?
                    $mobilePath = str_replace('/mobile/', '/m/', $path);
                    $newUrl = str_replace($path, $mobilePath, $url);
                    $data->setData('url', $newUrl);
                }
            }
            
        } catch (\Exception $e) {
            w_log_error("绉诲姩绔噸瀹氬悜澶勭悊澶辫触: " . $e->getMessage());
        }
    }

    /**
     * 妫€鏌ユ槸鍚︽槸鍏佽鐨勪富鏈?
     */
    protected function isAllowedHost(string $host): bool
    {
        $allowedHosts = [
            'localhost',
            '127.0.0.1',
            // 鍙互娣诲姞鏇村鍏佽鐨勫煙鍚?
        ];
        
        return in_array($host, $allowedHosts);
    }

    /**
     * 妫€鏌ユ槸鍚︽槸绉诲姩璁惧
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

    private function isPreviewRequestUrl(array $parsedUrl): bool
    {
        $query = [];
        if (!empty($parsedUrl['query'])) {
            parse_str((string)$parsedUrl['query'], $query);
        }

        foreach (['preview_theme', 'frontend_theme_id', 'backend_theme_id', 'weline_preview_token'] as $key) {
            $value = $query[$key] ?? null;
            if ($value !== null && $value !== '' && $value !== '0' && $value !== 0) {
                return true;
            }
        }

        return false;
    }

    private function buildUrl(array $parts): string
    {
        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $user = (string)($parts['user'] ?? '');
        $pass = isset($parts['pass']) ? ':' . $parts['pass'] : '';
        $auth = $user !== '' ? $user . $pass . '@' : '';
        $host = (string)($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = (string)($parts['path'] ?? '');
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '';

        return $scheme . $auth . $host . $port . $path . $query . $fragment;
    }
}
