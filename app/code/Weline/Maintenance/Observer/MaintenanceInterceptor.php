<?php

declare(strict_types=1);
/**
 * 文件信息
 * 作者：邹万才
 * 网名：秋风雁飞(Aiweline)
 * 网站：www.aiweline.com/bbs.aiweline.com
 * 工具：PhpStorm
 * 日期：2021/5/11
 * 时间：0:02
 * 描述：此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
 * 
 * 维护模式拦截器：
 * - 监听 Weline_Framework::App::run_before 事件（最早时机）
 * - 此时 URL 未解析，无数据库连接，无第三方服务请求
 * - 从 generated/language 读取翻译，不依赖数据库
 * - 在 pub/errors/maintenance/ 下生成静态文件，可被 nginx 直接返回
 */

namespace Weline\Maintenance\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Maintenance\Helper\IpMatcher;
use Weline\Maintenance\Helper\UrlParser;

/**
 * 维护模式拦截器
 * 
 * 在应用启动最早期拦截请求，检查维护模式状态
 * 如果处于维护模式，直接返回维护页面，避免任何数据库或第三方服务请求
 * 
 * 性能优化：
 * - 静态文件存储在 pub/errors/maintenance/ 目录，可被 nginx 直接返回
 * - 从 generated/language/{lang}.php 读取翻译，无数据库依赖
 * - 静态文件存在时直接 readfile() 返回
 */
class MaintenanceInterceptor implements \Weline\Framework\Event\ObserverInterface
{
    /**
     * 默认白名单 URL（静态资源等）
     */
    private const DEFAULT_WHITE_URLS = [
        // 静态资源
        '.css', '.js', '.png', '.jpg', '.jpeg', '.gif', '.svg', '.ico', '.woff', '.woff2', '.ttf', '.eot',
        // 媒体资源路径
        '/pub/static/', '/pub/media/',
        // 维护页面资源
        '/pub/errors/',
    ];

    /**
     * 默认语言
     */
    private const DEFAULT_LANG = 'zh_Hans_CN';

    /**
     * 静态文件存储目录（相对于 BP，在 pub 下便于 nginx 直接访问）
     */
    private const STATIC_DIR = 'pub/errors/maintenance/';

    /**
     * 语言映射缓存
     */
    private static ?array $langMapping = null;

    /**
     * 翻译缓存
     */
    private static ?array $translations = null;

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        // CLI 模式不检查维护模式
        if (PHP_SAPI === 'cli') {
            return;
        }

        // 检查维护模式配置
        if (!Env::getInstance()->getConfig('maintenance', false)) {
            return;
        }

        // 给Request对象设置当前模块名
        $request = ObjectManager::getInstance(Request::class);
        $request->setModuleName('Weline_Maintenance');
        $request->setRouter([
            'name' => 'Weline_Maintenance',
            'module' => 'Weline_Maintenance',
            'controller' => 'Maintenance',
            'action' => 'index',
        ]);
        // 直接使用 $_SERVER 获取请求 URI
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $parse = UrlParser::parse($uri);
        $_SERVER = $parse['server'];
        $pure_uri = $parse['uri'];
        // 检查是否在白名单中
        if ($this->isWhitelisted($pure_uri)) {
            return;
        }


        // 获取当前语言（从路径、Cookie 或浏览器 Accept-Language）
        $currentLang = $this->getCurrentLang();
        
        // 触发维护模式事件，允许其他模块添加白名单或自定义响应
        /**@var EventsManager $eventManager */
        $eventManager = ObjectManager::getInstance(EventsManager::class);
        $data = new DataObject([
            'white_urls' => self::DEFAULT_WHITE_URLS,
            'original_uri' => $uri,
            'uri' => $pure_uri,
            'parse' => $parse,
            'handled' => false,
            'language' => $currentLang, // 传递当前语言给观察者
        ]);
        $eventManager->dispatch('Weline_Maintenance::maintenance', $data);
        // 如果已被其他观察者处理，则退出
        if ($data->getData('handled')) {
            return;
        }

        // 再次检查扩展后的白名单
        $white_urls = $data->getData('white_urls') ?? [];
        foreach ($white_urls as $white_url_string) {
            if (!empty($white_url_string) && str_contains($uri, $white_url_string)) {
                return;
            }
        }

        // 检查后端路径放行
        if ($this->checkBackendPath($pure_uri)) {
            $this->logBypass('backend_path', $pure_uri);
            return;
        }

        // 检查IP白名单
        if ($this->checkIpWhitelist()) {
            $this->logBypass('ip_whitelist', $pure_uri);
            return;
        }

        // 检查Bypass Key验证
        if ($this->checkBypassKey()) {
            $this->logBypass('bypass_key', $pure_uri);
            return;
        }

        // 返回维护页面响应
        $this->sendMaintenanceResponse();
    }

    /**
     * 检查 URI 是否在默认白名单中
     */
    private function isWhitelisted(string $uri): bool
    {
        foreach (self::DEFAULT_WHITE_URLS as $pattern) {
            if (str_contains($uri, $pattern)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 检查是否为后端路径（维护模式下自动放行）
     * 
     * @param string $uri
     * @return bool
     */
    private function checkBackendPath(string $uri): bool
    {
        $bypassConfig = Env::getInstance()->getConfig('maintenance.bypass', []);
        $backendPaths = $bypassConfig['backend_paths'] ?? [];
        
        if (empty($backendPaths) || !is_array($backendPaths)) {
            return false;
        }

        foreach ($backendPaths as $path) {
            if (!empty($path) && str_starts_with($uri, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 检查IP是否在白名单中
     * 
     * @return bool
     */
    private function checkIpWhitelist(): bool
    {
        $bypassConfig = Env::getInstance()->getConfig('maintenance.bypass', []);
        $ipWhitelist = $bypassConfig['ip_whitelist'] ?? [];
        
        if (empty($ipWhitelist) || !is_array($ipWhitelist)) {
            return false;
        }

        $clientIp = IpMatcher::getClientIp();
        
        return IpMatcher::isIpInWhitelist($clientIp, $ipWhitelist);
    }

    /**
     * 检查Bypass Key验证
     * 支持URL参数、Header和Cookie三种方式
     * 
     * @return bool
     */
    private function checkBypassKey(): bool
    {
        $bypassConfig = Env::getInstance()->getConfig('maintenance.bypass', []);
        $keyConfig = $bypassConfig['bypass_key'] ?? [];
        
        // 检查是否启用
        if (empty($keyConfig['enabled']) || empty($keyConfig['name']) || empty($keyConfig['value'])) {
            return false;
        }

        $keyName = $keyConfig['name'];
        $keyValue = $keyConfig['value'];
        $methods = $keyConfig['methods'] ?? ['url', 'header', 'cookie'];

        // 从URL参数检查
        if (in_array('url', $methods)) {
            $urlKey = $_GET[$keyName] ?? null;
            if ($urlKey === $keyValue) {
                return true;
            }
        }

        // 从Header检查
        if (in_array('header', $methods)) {
            $headerKey = $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $keyName))] ?? null;
            if ($headerKey === $keyValue) {
                return true;
            }
        }

        // 从Cookie检查
        if (in_array('cookie', $methods)) {
            $cookieKey = $_COOKIE[$keyName] ?? null;
            if ($cookieKey === $keyValue) {
                return true;
            }
        }

        return false;
    }

    /**
     * 记录绕过日志
     * 
     * @param string $bypassType 绕过类型：backend_path/ip_whitelist/bypass_key
     * @param string $uri 请求URI
     * @return void
     */
    private function logBypass(string $bypassType, string $uri): void
    {
        $bypassConfig = Env::getInstance()->getConfig('maintenance.bypass', []);
        
        // 如果未启用日志记录，直接返回
        if (empty($bypassConfig['log_bypass'])) {
            return;
        }

        try {
            $clientIp = IpMatcher::getClientIp();
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $timestamp = date('Y-m-d H:i:s');
            
            $logDir = BP . 'var' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR;
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            
            $logFile = $logDir . 'maintenance_bypass.log';
            $logMessage = sprintf(
                "[%s] Type: %s | IP: %s | URI: %s | UA: %s\n",
                $timestamp,
                $bypassType,
                $clientIp,
                $uri,
                substr($userAgent, 0, 200)
            );
            
            @file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
        } catch (\Exception $e) {
            // 静默失败，不影响主流程
        }
    }

    /**
     * 获取当前语言
     * 优先级：
     * 1. URL 路径中明确指定的语言（通过 UrlParser 解析，已设置到 $_SERVER['WELINE_USER_LANG']）
     * 2. Cookie 中的语言
     * 3. 浏览器 Accept-Language 头
     * 4. 默认语言
     */
    private function getCurrentLang(): string
    {
        // 优先级1：从 URL 路径中获取语言（UrlParser 已解析并设置到 $_SERVER['WELINE_USER_LANG']）
        // 如果路径中明确指定了语言，优先使用路径中的语言
        $lang = $_SERVER['WELINE_USER_LANG'] ?? null;
        
        // 优先级2：从 Cookie 获取语言（如果路径中没有指定）
        if (empty($lang)) {
            $lang = $_COOKIE['WELINE_USER_LANG'] ?? null;
        }
        
        // 优先级3：从浏览器 Accept-Language 头获取
        if (empty($lang)) {
            $acceptLang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
            if (!empty($acceptLang)) {
                $langs = explode(',', $acceptLang);
                $firstLang = trim(explode(';', $langs[0])[0]);
                $lang = $this->convertLangCode($firstLang);
            }
        }
        
        // 优先级4：使用默认语言
        // 如果检测到的语言是英文，但用户可能期望中文，优先使用中文
        // 只有在明确设置了英文 Cookie 或路径中指定了英文时才使用英文
        if (empty($lang) || ($lang === 'en_US' && empty($_COOKIE['WELINE_USER_LANG']) && empty($_SERVER['WELINE_USER_LANG']))) {
            // 检查 Accept-Language 是否包含中文
            $acceptLang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
            if (!empty($acceptLang) && (str_contains($acceptLang, 'zh') || str_contains($acceptLang, 'cn'))) {
                $lang = self::DEFAULT_LANG;
            } else {
                $lang = $lang ?: self::DEFAULT_LANG;
            }
        }
        
        return $lang;
    }

    /**
     * 转换语言代码格式
     * 从 i18n/lang_mapping.json 读取映射关系
     */
    private function convertLangCode(string $code): string
    {
        $mapping = $this->getLangMapping();
        return $mapping[$code] ?? self::DEFAULT_LANG;
    }

    /**
     * 获取语言映射表
     */
    private function getLangMapping(): array
    {
        if (self::$langMapping !== null) {
            return self::$langMapping;
        }

        $mappingFile = __DIR__ . '/../i18n/lang_mapping.json';
        
        if (is_file($mappingFile)) {
            $content = @file_get_contents($mappingFile);
            if ($content !== false) {
                $data = @json_decode($content, true);
                if (is_array($data) && isset($data['mapping'])) {
                    self::$langMapping = $data['mapping'];
                    return self::$langMapping;
                }
            }
        }

        // 默认基础映射
        self::$langMapping = [
            'zh-CN' => 'zh_Hans_CN',
            'zh' => 'zh_Hans_CN',
            'en-US' => 'en_US',
            'en' => 'en_US',
        ];
        return self::$langMapping;
    }

    /**
     * 获取静态文件路径
     */
    private function getStaticFilePath(string $lang, bool $isApi = false): string
    {
        $suffix = $isApi ? '.json' : '.html';
        return BP . self::STATIC_DIR . $lang . $suffix;
    }

    /**
     * 加载翻译
     * 优先级：
     * 1. generated/language/{lang}.php（编译后的翻译）
     * 2. 模块 i18n/{lang}.csv
     */
    private function loadTranslations(string $lang): array
    {
        // 1. 尝试从 generated/language 读取
        $generatedFile = BP . 'generated/language/' . $lang . '.php';
        if (is_file($generatedFile)) {
            $allTranslations = @include $generatedFile;
            if (is_array($allTranslations)) {
                // 查找 Weline_Maintenance 模块的翻译
                if (isset($allTranslations['Weline_Maintenance'])) {
                    return $allTranslations['Weline_Maintenance'];
                }
                // 如果没有按模块分，尝试合并所有翻译
                $merged = [];
                foreach ($allTranslations as $moduleTranslations) {
                    if (is_array($moduleTranslations)) {
                        $merged = array_merge($merged, $moduleTranslations);
                    }
                }
                if (!empty($merged)) {
                    return $merged;
                }
            }
        }

        // 2. 回退到模块 i18n 目录
        return $this->loadModuleTranslations($lang);
    }

    /**
     * 从模块 i18n 目录加载翻译
     */
    private function loadModuleTranslations(string $lang): array
    {
        $translations = [];
        
        // 尝试指定语言
        $i18nFile = __DIR__ . '/../i18n/' . $lang . '.csv';
        
        // 如果不存在，尝试默认语言
        if (!is_file($i18nFile)) {
            $i18nFile = __DIR__ . '/../i18n/' . self::DEFAULT_LANG . '.csv';
        }
        
        // 如果还不存在，尝试 en_US
        if (!is_file($i18nFile)) {
            $i18nFile = __DIR__ . '/../i18n/en_US.csv';
        }
        
        if (!is_file($i18nFile)) {
            return $translations;
        }
        
        $handle = @fopen($i18nFile, 'r');
        if ($handle === false) {
            return $translations;
        }
        
        while (($data = fgetcsv($handle, 100000, ',', '"', '\\')) !== false) {
            if (isset($data[0], $data[1]) && !empty(trim($data[0]))) {
                $translations[trim($data[0])] = trim($data[1]);
            }
        }
        
        fclose($handle);
        return $translations;
    }

    /**
     * 翻译文本
     */
    private function translate(string $text, array $translations): string
    {
        return $translations[$text] ?? $text;
    }

    /**
     * 发送维护模式响应
     */
    private function sendMaintenanceResponse(): void
    {
        $retryAfter = (int)(Env::getInstance()->getConfig('maintenance_retry_after', 60));
        $lang = $this->getCurrentLang();
        
        // 检查是否是 API 请求
        $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $isApiRequest = str_contains($acceptHeader, 'application/json') 
                     || str_contains($uri, '/api/') 
                     || str_contains($uri, '/rest/');
        
        // 开发环境下：通过查询参数 ?api=1 可以测试 API 维护模式响应
        if (!$isApiRequest && defined('DEV') && DEV) {
            $queryString = $_SERVER['QUERY_STRING'] ?? '';
            parse_str($queryString, $queryParams);
            if (isset($queryParams['api']) && ($queryParams['api'] === '1' || $queryParams['api'] === 'true')) {
                $isApiRequest = true;
            }
        }

        http_response_code(503);
        header('Retry-After: ' . $retryAfter);

        if ($isApiRequest) {
            $this->sendApiResponse($lang, $retryAfter);
        } else {
            $this->sendHtmlResponse($lang);
        }
        
        exit;
    }

    /**
     * 发送 API JSON 响应
     */
    private function sendApiResponse(string $lang, int $retryAfter): void
    {
        header('Content-Type: application/json; charset=utf-8');
        
        // 尝试读取静态 JSON 文件
        $staticFile = $this->getStaticFilePath($lang, true);
        if (is_file($staticFile)) {
            readfile($staticFile);
            return;
        }
        
        // 加载翻译
        $translations = $this->loadTranslations($lang);
        
        $response = [
            'success' => false,
            'code' => 'maintenance',
            'message' => $this->translate('系统正在升级维护中，请稍后再试。', $translations),
            'data' => [
                'retry_after' => $retryAfter,
                'lang' => $lang,
            ],
        ];
        
        $jsonContent = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
        // 保存静态文件
        $this->saveStaticFile($staticFile, $jsonContent);
        
        echo $jsonContent;
    }

    /**
     * 发送 HTML 响应
     */
    private function sendHtmlResponse(string $lang): void
    {
        header('Content-Type: text/html; charset=utf-8');
        
        // 尝试读取静态 HTML 文件
        $staticFile = $this->getStaticFilePath($lang, false);
        if (is_file($staticFile)) {
            readfile($staticFile);
            return;
        }
        
        // 加载翻译并生成 HTML
        $translations = $this->loadTranslations($lang);
        $htmlContent = $this->generateMaintenanceHtml($translations, $lang);
        
        // 保存静态文件
        $this->saveStaticFile($staticFile, $htmlContent);
        
        echo $htmlContent;
    }

    /**
     * 保存静态文件
     */
    private function saveStaticFile(string $filePath, string $content): void
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        
        @file_put_contents($filePath, $content);
    }

    /**
     * 生成维护页面 HTML
     */
    private function generateMaintenanceHtml(array $translations, string $lang): string
    {
        // 准备模板变量
        $title = $this->translate('网站维护', $translations);
        $heading = $this->translate('系统升级维护中', $translations);
        $message1 = $this->translate('网站正在维护中...', $translations);
        $message2 = $this->translate('请稍等片刻', $translations);
        $whyTitle = $this->translate('为什么网站会处于维护模式？', $translations);
        $whyContent = $this->translate('网站可能发生系统升级事件，或者大多数的内容发生变化，程序员们正在维护或者升级数据以提供更加优质的服务。', $translations);
        $howLongTitle = $this->translate('多久能够恢复？', $translations);
        $howLongContent = $this->translate('大部分的网站升级活动都只有几分钟甚至几秒钟的时间。', $translations);
        $helpTitle = $this->translate('需要帮助吗？', $translations);
        $helpContent = $this->translate('如果你长期看到这个页面，对网站存在疑惑，请联系：', $translations);
        $backHome = $this->translate('返回首页', $translations);

        // 获取联系邮箱配置
        $contactEmail = Env::getInstance()->getConfig('contact_email', 'support@example.com');
        
        // 根据语言设置 HTML lang 属性
        $htmlLang = str_replace('_', '-', $lang);
        if (str_starts_with($htmlLang, 'zh-Hans')) {
            $htmlLang = 'zh-CN';
        } elseif (str_starts_with($htmlLang, 'zh-Hant')) {
            $htmlLang = 'zh-TW';
        }

        // 尝试从模板文件读取
        $templateFile = __DIR__ . '/../view/templates/maintenance.phtml';
        if (is_file($templateFile)) {
            ob_start();
            include $templateFile;
            return ob_get_clean();
        }

        // 备用简单 HTML
        return $this->getFallbackHtml($htmlLang, $title, $heading, $message1, $message2, $backHome);
    }

    /**
     * 获取备用的简单 HTML
     */
    private function getFallbackHtml(
        string $htmlLang,
        string $title,
        string $heading,
        string $message1,
        string $message2,
        string $backHome
    ): string {
        return <<<HTML
<!DOCTYPE html>
<html lang="{$htmlLang}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>{$title}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            padding: 20px;
        }
        .container { text-align: center; max-width: 500px; }
        .icon { font-size: 64px; margin-bottom: 24px; }
        h1 { font-size: 2rem; margin-bottom: 16px; }
        p { font-size: 1.1rem; opacity: 0.9; line-height: 1.6; margin-bottom: 32px; }
        .back-btn {
            display: inline-block;
            padding: 12px 24px;
            border: 2px solid rgba(255,255,255,0.5);
            border-radius: 8px;
            color: #fff;
            text-decoration: none;
            transition: all 0.3s;
        }
        .back-btn:hover { background: rgba(255,255,255,0.1); border-color: #fff; }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">🔧</div>
        <h1>{$heading}</h1>
        <p>{$message1}<br>{$message2}</p>
        <a href="/" class="back-btn">← {$backHome}</a>
    </div>
</body>
</html>
HTML;
    }
}
