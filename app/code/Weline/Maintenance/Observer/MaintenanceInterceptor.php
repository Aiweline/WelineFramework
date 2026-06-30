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
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Runtime;
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
        if (\defined('WLS_MAINTENANCE_WORKER') && WLS_MAINTENANCE_WORKER) {
            $this->applyParsedRequestUri();
            $this->sendMaintenanceResponse();
        }

        if ((string)($_SERVER['WLS_INTERNAL_DYNAMIC_WARMUP'] ?? '') === '1'
            || (string)($_SERVER['WLS_INTERNAL_BACKEND_WARMUP'] ?? '') === '1'
            || (string)($_SERVER['WLS_INTERNAL_WARMUP'] ?? '') === '1'
        ) {
            return;
        }

        // CLI 模式不检查维护模式
        if (Runtime::isCli() && !(\defined('WLS_MODE') && WLS_MODE)) {
            return;
        }

        // 检查维护模式配置
        if (!Env::system('maintenance')) {
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
        $parse = $this->applyParsedRequestUri();
        $uri = (string)($parse['server']['ORIGIN_REQUEST_URI'] ?? \Weline\Framework\Env\WelineEnv::server('REQUEST_URI', ''));
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

    private function applyParsedRequestUri(): array
    {
        $uri = (string)\Weline\Framework\Env\WelineEnv::server('REQUEST_URI', '');
        $parse = UrlParser::parse($uri);
        if (isset($parse['server']) && \is_array($parse['server'])) {
            \Weline\Framework\Env\WelineEnv::replaceServer($parse['server']);
        }

        return $parse;
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
            $headerKey = w_env('server.' . strtolower('HTTP_' . strtoupper(str_replace('-', '_', $keyName)))) ?? null;
            if ($headerKey === $keyValue) {
                return true;
            }
        }

        // 从Cookie检查
        if (in_array('cookie', $methods)) {
            $cookieKey = \w_env_cookie($keyName);
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
            $userAgent = \w_env('server.http_user_agent') ?? '';
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
     * 1. URL 查询参数中明确指定的语言（维护页语言切换器使用）
     * 2. URL 路径中明确指定的语言
     * 3. 默认语言（默认语言路由不带语言段，不能被 Cookie 或浏览器语言覆盖）
     */
    private function getCurrentLang(): string
    {
        $queryString = (string)\Weline\Framework\Env\WelineEnv::server('QUERY_STRING', '');
        if ($queryString !== '') {
            parse_str($queryString, $queryParams);
            $lang = $this->normalizeLangCode((string)($queryParams['lang'] ?? ''));
            if ($lang !== '') {
                return $lang;
            }
        }

        $pathLang = $this->getExplicitPathLang();
        if ($pathLang !== '') {
            return $pathLang;
        }

        return self::DEFAULT_LANG;
    }

    private function getExplicitPathLang(): string
    {
        $uri = (string)(
            \Weline\Framework\Env\WelineEnv::server('ORIGIN_REQUEST_URI', '')
            ?: \Weline\Framework\Env\WelineEnv::server('REQUEST_URI', '')
        );
        $path = (string)(\parse_url($uri, \PHP_URL_PATH) ?: $uri);
        foreach (\explode('/', \trim($path, '/')) as $segment) {
            $lang = $this->normalizeLangCode($segment);
            if ($lang !== '') {
                return $lang;
            }
        }

        return '';
    }

    private function normalizeLangCode(string $code): string
    {
        $code = \trim($code);
        if ($code === '') {
            return '';
        }

        $normalized = \str_replace('-', '_', $code);
        if (\preg_match('/^[a-z]{2}_[A-Za-z]{2,}(?:_[A-Z]{2})?$/', $normalized) === 1) {
            $parts = \explode('_', $normalized);
            $lang = \strtolower($parts[0]);
            $scriptOrRegion = $parts[1] ?? '';
            if (\strlen($scriptOrRegion) === 4) {
                $scriptOrRegion = \ucfirst(\strtolower($scriptOrRegion));
            } else {
                $scriptOrRegion = \strtoupper($scriptOrRegion);
            }
            $region = isset($parts[2]) ? '_' . \strtoupper($parts[2]) : '';
            return $lang . '_' . $scriptOrRegion . $region;
        }

        $mapping = $this->getLangMapping();
        return $mapping[$code] ?? '';
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
     * 获取静态文件路径（跨平台，使用 DS）
     */
    private function getStaticFilePath(string $lang, bool $isApi = false): string
    {
        $suffix = $isApi ? '.json' : '.html';
        $base = defined('PUB') ? rtrim(PUB, \DIRECTORY_SEPARATOR) : (BP . 'pub');
        return $base . \DIRECTORY_SEPARATOR . 'errors' . \DIRECTORY_SEPARATOR . 'maintenance'
            . \DIRECTORY_SEPARATOR . $lang . $suffix;
    }

    private function isStaticFileFresh(string $staticFile, string $lang, bool $isApi = false): bool
    {
        if (!\is_file($staticFile)) {
            return false;
        }

        $staticMtime = (int)@\filemtime($staticFile);
        if ($staticMtime <= 0) {
            return false;
        }

        foreach ($this->getStaticDependencies($lang, $isApi) as $dependency) {
            if (\is_file($dependency) && (int)@\filemtime($dependency) > $staticMtime) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return string[]
     */
    private function getStaticDependencies(string $lang, bool $isApi = false): array
    {
        $dependencies = [
            __FILE__,
            BP . 'generated/language/' . $lang . '.php',
            __DIR__ . '/../i18n/' . $lang . '.csv',
            __DIR__ . '/../i18n/' . self::DEFAULT_LANG . '.csv',
            BP . 'app/code/Weline/I18n/i18n/' . $lang . '.csv',
            BP . 'app/code/Weline/I18n/i18n/' . self::DEFAULT_LANG . '.csv',
        ];

        if ($isApi) {
            $dependencies[] = __DIR__ . '/../view/templates/maintenance_api.json';
        } else {
            $dependencies[] = __DIR__ . '/../view/templates/maintenance.phtml';
            $dependencies[] = BP . 'app/code/Weline/I18n/view/hooks/header-language-switcher.phtml';
            $dependencies[] = BP . 'app/code/Weline/I18n/view/templates/Frontend/header-choice-selector-assets.phtml';
            $dependencies[] = BP . 'app/code/Weline/I18n/Taglib/LanguageSwitcher.php';
        }

        return $dependencies;
    }

    /**
     * 加载翻译
     * 优先级：
     * 1. generated/language/{lang}.php（编译后的翻译）
     * 2. 模块 i18n/{lang}.csv
     */
    private function loadTranslations(string $lang): array
    {
        $moduleFallbackTranslations = $this->loadModuleTranslations($lang);

        // 1. 尝试从 generated/language 读取
        $generatedFile = BP . 'generated/language/' . $lang . '.php';
        if (is_file($generatedFile)) {
            $allTranslations = @include $generatedFile;
            if (is_array($allTranslations)) {
                // 查找 Weline_Maintenance 模块的翻译
                if (isset($allTranslations['Weline_Maintenance'])) {
                    return \array_merge($allTranslations['Weline_Maintenance'], $moduleFallbackTranslations);
                }
                // 如果没有按模块分，尝试合并所有翻译
                $merged = [];
                foreach ($allTranslations as $generatedModuleTranslations) {
                    if (is_array($generatedModuleTranslations)) {
                        $merged = array_merge($merged, $generatedModuleTranslations);
                    }
                }
                if (!empty($merged)) {
                    return \array_merge($merged, $moduleFallbackTranslations);
                }
            }
        }

        // 2. 回退到模块 i18n 目录
        return $moduleFallbackTranslations;
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
        $acceptHeader = \Weline\Framework\Env\WelineEnv::server('HTTP_ACCEPT', '');
        $uri = \Weline\Framework\Env\WelineEnv::server('REQUEST_URI', '');
        $isApiRequest = str_contains($acceptHeader, 'application/json') 
                     || str_contains($uri, '/api/') 
                     || str_contains($uri, '/rest/');
        
        // 开发环境下：通过查询参数 ?api=1 可以测试 API 维护模式响应
        if (!$isApiRequest && defined('DEV') && DEV) {
            $queryString = \Weline\Framework\Env\WelineEnv::server('QUERY_STRING', '');
            parse_str($queryString, $queryParams);
            if (isset($queryParams['api']) && ($queryParams['api'] === '1' || $queryParams['api'] === 'true')) {
                $isApiRequest = true;
            }
        }

        $contentType = $isApiRequest
            ? 'application/json; charset=utf-8'
            : 'text/html; charset=utf-8';
        $body = $isApiRequest
            ? $this->renderApiResponse($lang, $retryAfter)
            : $this->renderHtmlResponse($lang);

        throw new ResponseTerminateException(
            503,
            $body,
            [
                'Content-Type' => $contentType,
                'Retry-After' => (string)$retryAfter,
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]
        );
    }

    /**
     * 发送 API JSON 响应
     */
    private function renderApiResponse(string $lang, int $retryAfter): string
    {
        // 尝试读取静态 JSON 文件
        $staticFile = $this->getStaticFilePath($lang, true);
        if ($this->isStaticFileFresh($staticFile, $lang, true)) {
            return (string)file_get_contents($staticFile);
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
        
        return (string)$jsonContent;
    }

    /**
     * 发送 HTML 响应
     */
    private function renderHtmlResponse(string $lang): string
    {
        // 尝试读取静态 HTML 文件
        $staticFile = $this->getStaticFilePath($lang, false);
        if ($this->isStaticFileFresh($staticFile, $lang, false)) {
            return (string)file_get_contents($staticFile);
        }
        
        // 加载翻译并生成 HTML
        $translations = $this->loadTranslations($lang);
        $htmlContent = $this->generateMaintenanceHtml($translations, $lang);
        
        // 保存静态文件
        $this->saveStaticFile($staticFile, $htmlContent);
        
        return $htmlContent;
    }

    /**
     * 保存静态文件（跨平台路径，确保目录创建成功）
     */
    private function saveStaticFile(string $filePath, string $content): void
    {
        $dir = \dirname($filePath);
        if (!\is_dir($dir)) {
            if (!@\mkdir($dir, 0755, true) && !\is_dir($dir)) {
                return;
            }
        }
        @\file_put_contents($filePath, $content);
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
        $recoveryNotice = $this->translate('系统会自动检测恢复状态，服务恢复后将自动刷新，无需反复点击刷新。', $translations);
        $backHome = $this->translate('返回首页', $translations);

        // 获取联系邮箱配置
        $contactEmail = Env::getInstance()->getConfig('contact_email', 'support@example.com');

        // 获取 Logo URL（后台配置，维护页为深色背景，优先用 logo_light）
        $maintenance_logo_url = '';
        try {
            $backendConfig = ObjectManager::getInstance(\Weline\Backend\Model\Config::class);
            $logoLight = trim((string) ($backendConfig->getConfig('logo_light', 'Weline_Backend') ?? ''));
            if ($logoLight === '') {
                $logoLight = trim((string) ($backendConfig->getConfig('logo_dark', 'Weline_Backend') ?? ''));
            }
            if ($logoLight !== '') {
                foreach (['/pub/media/', 'pub/media/', '/media/'] as $prefix) {
                    if (str_starts_with($logoLight, $prefix)) {
                        $logoLight = ltrim(substr($logoLight, strlen($prefix)), '/');
                        break;
                    }
                }
                $logoLight = ltrim($logoLight, '/');
                $maintenance_logo_url = '/pub/media/' . $logoLight;
            }
        } catch (\Throwable $e) {
            // 维护模式下 DB 可能不可用，忽略
        }
        
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
            // 创建Template实例以支持hook渲染
            $template = null;
            try {
                /** @var \Weline\Framework\View\Template $template */
                $template = \Weline\Framework\View\Template::getInstance();
            } catch (\Throwable $e) {
                // 如果创建失败，使用null（模板中会处理）
                $template = null;
            }
            
            // 确保$lang变量在模板中可用
            ob_start();
            include $templateFile;
            return ob_get_clean();
        }

        // 备用简单 HTML
        return $this->getFallbackHtml($htmlLang, $title, $heading, $message1, $message2, $recoveryNotice, $backHome);
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
        string $recoveryNotice,
        string $backHome
    ): string {
        return <<<HTML
<!DOCTYPE html>
<html lang="{$htmlLang}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
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
        <p>{$recoveryNotice}</p>
        <a href="/" class="back-btn">← {$backHome}</a>
    </div>
    <script>
        (function () {
            var initialDelay = 5000;
            var maxDelay = 30000;
            var jitter = 1500;
            var timer = 0;

            if (!window.fetch || !window.URL) {
                return;
            }

            function getProbeUrl() {
                var url = new URL(window.location.href);
                if (/^\/pub\/errors\/maintenance\//.test(url.pathname)) {
                    url = new URL('/', window.location.origin);
                }
                url.hash = '';
                url.searchParams.set('_maintenance_recovery_probe', String(Date.now()));
                return url.toString();
            }

            function schedule(delay) {
                if (timer) {
                    window.clearTimeout(timer);
                }
                timer = window.setTimeout(function () {
                    timer = 0;
                    check();
                }, delay + Math.floor(Math.random() * jitter));
            }

            function probe(method) {
                return window.fetch(getProbeUrl(), {
                    method: method,
                    cache: 'no-store',
                    credentials: 'same-origin',
                    redirect: 'follow',
                    headers: {
                        'Accept': 'text/html,application/xhtml+xml,*/*;q=0.8',
                        'X-Maintenance-Recovery-Check': '1'
                    }
                });
            }

            function handleResponse(response) {
                if (response.status === 200) {
                    window.location.reload();
                    return;
                }
                schedule(response.status === 503 ? initialDelay : maxDelay);
            }

            function check() {
                if (document.hidden) {
                    return;
                }

                probe('HEAD').then(function (response) {
                    if (response.status === 405 || response.status === 501) {
                        return probe('GET').then(handleResponse);
                    }
                    handleResponse(response);
                }).catch(function () {
                    schedule(maxDelay);
                });
            }

            document.addEventListener('visibilitychange', function () {
                if (document.hidden) {
                    if (timer) {
                        window.clearTimeout(timer);
                        timer = 0;
                    }
                    return;
                }
                schedule(0);
            });
            schedule(initialDelay);
        })();
    </script>
</body>
</html>
HTML;
    }
}
