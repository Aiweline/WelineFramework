<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\App;

use Weline\Framework\Context;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

class State extends DataObject
{
    public const area_backend = 'backend';

    public const area_frontend = 'frontend';

    public const area_base = 'base';

    public static bool $is_backend = false;

    /** 请求级缓存：getLangLocal() 结果，同请求内只触发一次事件，WLS 下由 StateManager 重置 */
    private static ?string $langLocalCache = null;

    private const LANG_LOCAL_CONTEXT_CACHE = 'state.lang_local_cache';

    /**
     * State 初始函数...
     *
     * @param Request $request
     */
    public function __construct(
        Request $request
    )
    {
        parent::__construct();
        self::$is_backend = $request->isBackend();
    }

    /**
     * 获取当前请求对象（始终从 ObjectManager 获取最新实例，兼容 WLS 单例场景）
     */
    protected function getRequest(): Request
    {
        return ObjectManager::getInstance(Request::class);
    }

    public function getStateCode()
    {
        return $this->getRequest()->getAreaRouter();
    }

    static function isBackend(): bool
    {
        return self::$is_backend;
    }

    static function setIsBackend()
    {
        self::$is_backend = true;
    }

    /**
     * 获取当前语言
     * 优先级：URL 路径解析的 SERVER 变量 > Cookie > 默认值
     * 
     * @return string
     */
    public static function getLang(): string
    {
        // 优先从 URL 路径解析的变量中读取（从路径配置的 URL）
        $lang = self::detectLanguageFromRequestPath();
        if (!empty($lang)) {
            return $lang;
        }

        $lang = \w_env('user.lang');
        // 如果 w_env 中没有，从 Cookie 读取
        if (empty($lang)) {
            $lang = Cookie::get('WELINE_USER_LANG');
        }
        // 默认网站语言
        if (empty($lang)) {
            $lang = Cookie::get('WELINE-WEBSITE-LANG', 'zh_Hans_CN');
        }
        return $lang;
    }

    /**
     * 获取当前货币
     * 优先级：URL 路径解析的变量 > Cookie > 默认值
     *
     * @return string
     */
    public static function getCurrency(): string
    {
        // 优先从 URL 路径解析的变量中读取（从路径配置的 URL）
        $currency = self::detectCurrencyFromRequestPath();
        if (!empty($currency)) {
            return $currency;
        }

        $currency = \w_env('user.currency');
        // 如果 w_env 中没有，从 Cookie 读取
        if (empty($currency)) {
            $currency = Cookie::get('WELINE_USER_CURRENCY');
        }
        // 默认网站货币
        if (empty($currency)) {
            $currency = Cookie::get('WELINE_WEBSITE_CURRENCY', 'CNY');
        }
        return $currency;
    }

    /**
     * 获取语言本地化代码（触发事件，允许其他模块修改）
     * 同请求内只触发一次事件，后续调用直接返回缓存值，减少重复 dispatch。
     *
     * @return string
     */
    public static function getLangLocal(): string
    {
        $lang = self::getLang();
        $currency = self::getCurrency();
        $cacheKey = $lang . '|' . $currency;
        $context = Context::getCurrent();
        if ($context !== null) {
            $cached = $context->get(self::LANG_LOCAL_CONTEXT_CACHE, null);
            if (\is_array($cached)
                && (string)($cached['key'] ?? '') === $cacheKey
                && \array_key_exists('value', $cached)
            ) {
                return (string)$cached['value'];
            }
        } elseif (self::$langLocalCache !== null) {
            return self::$langLocalCache;
        }
        $data = new DataObject();
        $data->setData('lang', $lang);
        $data->setData('currency', $currency);
        $data->setData('lang_local', $lang);

        try {
            \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class)
                ->dispatch('Weline_Framework_Cookie::lang_local', $data);
        } catch (\Exception $e) {
            // 如果事件系统未初始化，静默处理
        }

        $langLocal = (string)$data->getData('lang_local');
        if ($context !== null) {
            $context->set(self::LANG_LOCAL_CONTEXT_CACHE, [
                'key' => $cacheKey,
                'value' => $langLocal,
            ]);
        } else {
            self::$langLocalCache = $langLocal;
        }

        return $langLocal;
    }

    private static function detectLanguageFromRequestPath(): string
    {
        foreach (self::requestPathSegmentsCandidates() as $segments) {
            foreach ($segments as $segment) {
                $segment = str_replace('-', '_', trim($segment));
                if (preg_match('/^[a-z]{2}_[A-Za-z]{2,}(?:_[A-Z]{2})?$/', $segment)) {
                    return $segment;
                }
            }
        }

        return '';
    }

    private static function detectCurrencyFromRequestPath(): string
    {
        foreach (self::requestPathSegmentsCandidates() as $segments) {
            foreach ($segments as $segment) {
                $segment = strtoupper(trim($segment));
                if (preg_match('/^[A-Z]{3}$/', $segment)) {
                    return $segment;
                }
            }
        }

        return '';
    }

    /**
     * @return list<list<string>>
     */
    private static function requestPathSegmentsCandidates(): array
    {
        $uris = [
            (string)\w_env('origin_request_uri', ''),
            (string)\w_env('full_request_uri', ''),
            (string)\w_env('request.uri', ''),
            (string)\Weline\Framework\Env\WelineEnv::server('WELINE_ORIGIN_REQUEST_URI', ''),
            (string)\Weline\Framework\Env\WelineEnv::server('REQUEST_URI', ''),
            (string)($_SERVER['WELINE_ORIGIN_REQUEST_URI'] ?? ''),
            (string)($_SERVER['REQUEST_URI'] ?? ''),
        ];

        $candidates = [];
        foreach ($uris as $uri) {
            if ($uri === '') {
                continue;
            }

            $path = (string)(parse_url($uri, PHP_URL_PATH) ?: $uri);
            $segments = array_values(array_filter(
                explode('/', trim($path, '/')),
                static fn (string $segment): bool => $segment !== ''
            ));
            if ($segments === []) {
                continue;
            }

            $candidates[] = array_slice($segments, 0, 4);
        }

        return $candidates;
    }
}
