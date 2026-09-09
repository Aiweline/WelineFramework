<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Phrase;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\App\State;
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\Contract\RememberOptions;
use Weline\Framework\Context;
use Weline\Framework\Exception\Core;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\StateManager;

class Parser
{

    private const TRANSLATION_RESOLUTION_DEPTH_CONTEXT_KEY = 'phrase.translation_resolution_depth';
    private const REQUEST_PREFETCHED_GLOBAL_WORDS_CONTEXT_KEY = 'phrase.prefetched_global_words';

    public static bool $loaded = false;
    public const PARSER_WORDS_CACHE_KEY = 'PARSER_WORDS_CACHE_KEY';
    private const GLOBAL_DICTIONARY_SINGLE_FLIGHT_TIMEOUT_MS = 40;
    private const GLOBAL_DICTIONARY_WORD_SHARED_TTL_SECONDS = 3600;
    private const MODULE_DICTIONARY_SHARED_TTL_SECONDS = 86400;
    private const WORKER_TRANSLATED_WORD_CACHE_MAX_ITEMS = 32768;
    private const WORKER_TRANSLATED_WORD_CACHE_TRIM_ITEMS = 4096;
    private const WLS_HEAVY_LOCALE_HEADROOM_BYTES = 100663296;
    private const WLS_HEAVY_LOCALE_PRESSURE_THRESHOLD = 0.70;
    protected static array $words = [];
    protected static array $workerWordsCache = [];
    protected static array $workerLocaleWordsCache = [];
    protected static array $workerModuleWordsCache = [];
    protected static array $workerGlobalDictionaryWordsCache = [];
    /** @var array<string, array<string, string>> */
    protected static array $workerGlobalDictionaryLocaleWords = [];
    /** @var array<string, list<string>> */
    protected static array $workerGlobalDictionaryLoadedModules = [];
    /** @var array<string, bool> */
    protected static array $workerGlobalDictionaryAllLocales = [];
    protected static array $workerGlobalDictionaryWordCache = [];
    protected static array $workerLayeredWordsCache = [];
    protected static array $workerMaterializedWordsCache = [];
    protected static array $workerTranslatedWordsCache = [];
    private static ?CachePoolInterface $sharedPhraseCachePool = null;
    private static ?GlobalDictionaryProviderInterface $globalDictionaryProviderInstance = null;
    protected static ?string $currentRequestWordsId = null;
    protected static ?string $currentRequestWordsKey = null;
    protected static ?string $currentRequestLayeredWordsId = null;
    protected static ?string $currentRequestLayeredWordsKey = null;
    /** @var array<string, mixed>|null */
    protected static ?array $currentRequestLayeredWords = null;
    private static ?string $currentRequestLayeredWordsSignature = null;
    protected static array $currentRequestTranslatedWords = [];
    
    /**
     * 请求生命周期内使用的翻译词（用于按需加载）
     * @var array
     */
    protected static array $usedWords = [];
    
    /**
     * 是否正在加载翻译文件（防止循环调用）
     * @var bool
     */
    protected static bool $isLoadingWords = false;
    private static int $translationResolutionDepth = 0;
    
    /**
     * 当前加载的语言（用于判断是否需要重新加载）
     * @var string|null
     */
    protected static ?string $loadedLang = null;
    
    /**
     * WLS 状态管理注册（请求级数据，需跨请求重置）
     */
    private static bool $stateRegistered = false;
    
    private static function ensureStateRegistered(): void
    {
        if (self::$stateRegistered) {
            return;
        }
        self::$stateRegistered = true;
        
        StateManager::registerResetCallback('Parser::reset', static function () {
            self::$usedWords = [];
            self::$isLoadingWords = false;
            self::$translationResolutionDepth = 0;
            self::$currentRequestWordsId = null;
            self::$currentRequestWordsKey = null;
            self::$currentRequestLayeredWordsId = null;
            self::$currentRequestLayeredWordsKey = null;
            self::$currentRequestLayeredWords = null;
            self::$currentRequestLayeredWordsSignature = null;
            self::$currentRequestTranslatedWords = [];
        });
    }

    /**
     * @DESC         # 翻译解析函数
     * DEV环境下解析字词并收集到generated/language/words.php
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/16 22:50
     * 参数区：
     *
     * @param string|array $words
     * @param int|array|string|null $args
     *
     * @return mixed|string|string[]
     * @throws Exception
     * @throws Core
     */
    public static function parse(string|array &$words, int|array|string|null $args = null): mixed
    {
        $words = self::processWords($words);

        if ($args === null) {
            return $words;
        }

        // 如果是字符串 或者 数字
        if (is_string($args) || is_numeric($args)) {
            // 占位符%{} 这种占位符
            if (str_contains($words, '%{1}')) {
                $words = str_replace('%{1}', '%{}', $words);
            }
            $words = str_replace('%{}', $args, $words);
            return $words;
        }
        // 如果是数组
        if (is_array($args)) {
            foreach ($args as $key => $arg) {
                if (is_numeric($key)) {
                    $key += 1;
                }
                $replacement = $arg ?? '';
                // str_replace 的替换参数必须是字符串；兼容占位参数传入数字/数组/对象等情况
                if (is_array($replacement) || is_object($replacement)) {
                    $replacement = json_encode($replacement, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                if (!is_string($replacement)) {
                    $replacement = (string)$replacement;
                }
                $words = str_replace('%{' . $key . '}', $replacement, $words);
            }
        }

        return $words;
    }

    /**
     * @DESC         |处理词组
     *
     * 参数区：
     *
     * @param string $words
     *
     * @return string
     * @throws Exception
     */
    protected static function processWords(string $words): string
    {
        // DB / cache / logger bootstrap may call __() again (e.g. MySQL version
        // warning while loading the global dictionary). Never re-enter resolution.
        if (self::translationResolutionDepth() > 0 || self::$isLoadingWords) {
            return $words;
        }

        if (Runtime::isPersistent()) {
            self::ensureStateRegistered();
            self::$usedWords[$words] = $words;
            self::enterTranslationResolution();
            try {
                // Request overlays can change after EventDictionary::refresh().
                // Resolve them before both request and shared public-word caches.
                $lang = State::getLangLocal();
                $eventTranslation = self::translateFromEventDictionary($words, $lang);
                if ($eventTranslation !== null) {
                    return $eventTranslation;
                }
                if (EventDictionary::isExclusive($lang)) {
                    EventDictionary::reportMissing($words, null, $lang);
                    return $words;
                }

                $layers = RequestLifecycleTrace::measurePhase(
                    'i18n.phrase.layered_words',
                    static fn(): array => self::getCurrentLayeredWords(),
                );
                $translationCacheKey = (string)($layers['cache_key'] ?? '') . '|' . $words;
                if (isset(DictionaryCacheNamespace::localCache(self::$currentRequestTranslatedWords)[$translationCacheKey])) {
                    return DictionaryCacheNamespace::localCache(self::$currentRequestTranslatedWords)[$translationCacheKey];
                }

                return DictionaryCacheNamespace::localCache(self::$currentRequestTranslatedWords)[$translationCacheKey]
                    = RequestLifecycleTrace::measurePhase(
                        'i18n.phrase.resolve',
                        static fn(): string => self::translateWordFromLayers($words, $layers),
                    );
            } finally {
                self::leaveTranslationResolution();
            }
        }

        self::getWords();
        // 记录请求生命周期内使用的翻译词（用于按需加载到前端）
        self::$usedWords[$words] = $words;

        $lang = State::getLangLocal();
        $eventTranslation = self::translateFromEventDictionary($words, $lang);
        if ($eventTranslation !== null) {
            return $eventTranslation;
        }
        if (EventDictionary::isExclusive($lang)) {
            EventDictionary::reportMissing($words, null, $lang);
            return $words;
        }
        
        if (isset(self::$words[$words])) {
            $translated = self::$words[$words];
            if (\is_string($translated) && $translated !== '' && $translated !== $words) {
                $words = $translated;
            }
        } else {
            self::$words[$words] = $words;
            if (EventDictionary::isActive($lang)) {
                EventDictionary::reportMissing($words, null, $lang);
            }
        }
        return $words;
    }
    
    /**
     * 获取请求生命周期内使用的翻译词
     * @return array
     */
    public static function getUsedWords(): array
    {
        return self::$usedWords;
    }
    
    /**
     * 获取请求生命周期内使用的翻译词及其翻译
     * @return array
     */
    public static function getUsedWordsWithTranslations(): array
    {
        $result = [];
        $layers = Runtime::isPersistent() ? self::getCurrentLayeredWords() : null;
        $layerCacheKey = \is_array($layers) ? (string)($layers['cache_key'] ?? '') : '';
        foreach (self::$usedWords as $word) {
            // 获取翻译（如果存在）
            if (Runtime::isPersistent()) {
                $translationCacheKey = $layerCacheKey . '|' . $word;
                if (!\array_key_exists($translationCacheKey, self::$currentRequestTranslatedWords)) {
                    DictionaryCacheNamespace::localCache(self::$currentRequestTranslatedWords)[$translationCacheKey] = self::translateWordFromLayers(
                        $word,
                        $layers,
                    );
                }
                $result[$word] = DictionaryCacheNamespace::localCache(self::$currentRequestTranslatedWords)[$translationCacheKey];
            } elseif (isset(self::$words[$word])) {
                $result[$word] = self::$words[$word];
            } else {
                // 如果没有翻译，使用原词
                $result[$word] = $word;
            }
        }
        return $result;
    }

    public static function getWords()
    {
        if (self::translationResolutionDepth() > 0 || self::$isLoadingWords) {
            return self::$words;
        }

        self::enterTranslationResolution();
        try {
            return self::loadWords();
        } finally {
            self::leaveTranslationResolution();
        }
    }

    private static function loadWords(): array
    {
        // An exclusive request must neither load public dictionaries nor publish
        // its empty public layer into the worker-wide materialized cache.
        if (EventDictionary::isExclusive(State::getLangLocal())) {
            return self::$words = [];
        }

        if (Runtime::isPersistent()) {
            self::ensureStateRegistered();
            $layers = self::getCurrentLayeredWords();
            $cacheKey = (string)($layers['cache_key'] ?? self::buildWordsCacheKey((string)($layers['lang'] ?? State::getLangLocal()), (array)($layers['modules'] ?? [])));
            if (!isset(DictionaryCacheNamespace::localCache(self::$workerLayeredWordsCache, 1024)[$cacheKey])) {
                // 未确认完整的全局词典不能经物化入口变成进程级空快照。
                return self::$words = self::materializeLayeredWords($layers);
            }
            if (!isset(DictionaryCacheNamespace::localCache(self::$workerMaterializedWordsCache, 1024)[$cacheKey])) {
                DictionaryCacheNamespace::localCache(self::$workerMaterializedWordsCache, 1024)[$cacheKey] = self::materializeLayeredWords($layers);
            }
            self::$words = DictionaryCacheNamespace::localCache(self::$workerMaterializedWordsCache, 1024)[$cacheKey];
            return self::$words;
        }
        // 确保 WLS 状态管理已注册
        self::ensureStateRegistered();
        
        // 防止循环调用：如果正在加载翻译文件，直接返回空数组或已加载的词
        $requestId = Runtime::isPersistent() ? RequestContext::getId() : null;
        $currentLang = State::getLangLocal();
        $requestModules = self::resolveRequestModules();
        $requestCacheKey = self::buildWordsCacheKey($currentLang, $requestModules);
        if ($requestId !== null
            && self::$currentRequestWordsId === $requestId
            && self::$currentRequestWordsKey === $requestCacheKey
            && isset(DictionaryCacheNamespace::localCache(self::$workerWordsCache, 1024)[$requestCacheKey])
        ) {
            self::$words = DictionaryCacheNamespace::localCache(self::$workerWordsCache, 1024)[$requestCacheKey];
            return self::$words;
        }

        if (isset(DictionaryCacheNamespace::localCache(self::$workerWordsCache, 1024)[$requestCacheKey])) {
            self::$words = DictionaryCacheNamespace::localCache(self::$workerWordsCache, 1024)[$requestCacheKey];
            self::$loaded = true;
            self::$loadedLang = $currentLang;
            self::$currentRequestWordsId = $requestId;
            self::$currentRequestWordsKey = $requestCacheKey;
            return self::$words;
        }
        
        // WLS 模式下：检查语言是否变化，如果变化需要重新加载词典
        if (self::$loaded && self::$loadedLang !== null && self::$loadedLang !== $currentLang) {
            self::$loaded = false;
            self::$words = [];
        }
        
        // 仅加载一次翻译到对象self::$words
        if (!isset(DictionaryCacheNamespace::localCache(self::$workerWordsCache, 1024)[$requestCacheKey])) {
            // 设置加载标志，防止循环调用
            self::$isLoadingWords = true;
            
            try {
                // 先访问缓存
                if (Runtime::isPersistent()) {
                    self::$words = self::buildWordsFromWorkerCache($currentLang, $requestModules);
                    DictionaryCacheNamespace::localCache(self::$workerWordsCache, 1024)[$requestCacheKey] = self::$words;
                    self::$loaded = true;
                    self::$loadedLang = $currentLang;
                    self::$currentRequestWordsId = $requestId;
                    self::$currentRequestWordsKey = $requestCacheKey;
                    return self::$words;
                }

                /**@var \Weline\Framework\Cache\CacheInterface $phraseCache */
                $phraseCache = self::getSharedPhraseCachePool();
                // 获取翻译模式（支持 translation.mode 和 i18n.translate_mode）
                $translate_mode = Env::get('translation.mode','default');

                // 获取当前请求关联的所有模块（支持多模块）
                $modules = [];
                try {
                    /**@var Request $request */
                    $request = ObjectManager::getInstance(Request::class);
                    $modules = $request->getModules() ?: [];
                    $moduleName = $request->getModuleName();
                    if (!empty($moduleName)) {
                        $modules[] = $moduleName;
                    }
                    // 过滤空值
                    $modules = array_filter($modules, fn($m) => !empty($m));
                } catch (\Exception $e) {
                    // 如果无法获取模块名，继续使用总词典
                }
                $lang = $currentLang;
                // 缓存键包含所有模块名，用于区分不同模块组合
                $cache_key = self::buildWordsCacheKey($lang, $modules);
                # 非实时翻译
                if ($translate_mode !== 'online' && $phrase_words = $phraseCache?->get($cache_key)) {
                    self::$words = $phrase_words;
                } else {
                    // 从所有关联模块的词典读取（支持多模块）
                    $module_words = [];
                    foreach ($modules as $module_name) {
                        $words = self::loadModuleWordsForLocaleChain($module_name, $lang);
                        // 后加载的模块词典会覆盖先加载的（优先级：后添加的模块 > 先添加的模块）
                        $module_words = array_merge($module_words, $words);
                    }
                    $all_words = self::loadLocaleWordsForLocaleChain($lang, $modules);
                    
                    // 合并：模块词典优先，总词典作为补充（模块词典覆盖总词典）
                    // 先加载总词典，再加载模块词典，这样模块词典会覆盖总词典
                    self::$words = array_merge($all_words, $module_words);
                    $phraseCache?->set($cache_key, self::$words);
                }
                DictionaryCacheNamespace::localCache(self::$workerWordsCache, 1024)[$requestCacheKey] = self::$words;
                self::$currentRequestWordsId = $requestId;
                self::$currentRequestWordsKey = $requestCacheKey;
            } finally {
                // 清除加载标志
                self::$isLoadingWords = false;
            }
            self::$loaded = true;
            self::$loadedLang = $currentLang;
        }
        return self::$words ?? [];
    }

    private static function translationResolutionDepth(): int
    {
        if (Context::hasCurrent()) {
            return (int)Context::current()->get(self::TRANSLATION_RESOLUTION_DEPTH_CONTEXT_KEY, 0);
        }

        return self::$translationResolutionDepth;
    }

    private static function enterTranslationResolution(): void
    {
        if (Context::hasCurrent()) {
            $context = Context::current();
            $context->set(
                self::TRANSLATION_RESOLUTION_DEPTH_CONTEXT_KEY,
                (int)$context->get(self::TRANSLATION_RESOLUTION_DEPTH_CONTEXT_KEY, 0) + 1,
            );
            return;
        }

        ++self::$translationResolutionDepth;
    }

    private static function leaveTranslationResolution(): void
    {
        if (Context::hasCurrent()) {
            $context = Context::current();
            $depth = (int)$context->get(self::TRANSLATION_RESOLUTION_DEPTH_CONTEXT_KEY, 0);
            if ($depth <= 1) {
                $context->remove(self::TRANSLATION_RESOLUTION_DEPTH_CONTEXT_KEY);
                return;
            }
            $context->set(self::TRANSLATION_RESOLUTION_DEPTH_CONTEXT_KEY, $depth - 1);
            return;
        }

        self::$translationResolutionDepth = max(0, self::$translationResolutionDepth - 1);
    }

    public static function preloadWorkerDictionaries(): void
    {
        self::ensureStateRegistered();

        // Persistent Workers must stay route-scoped. The first internal/homepage
        // warmup request naturally discovers its controller, layout and query
        // modules; preloading every active module here defeats that boundary and
        // multiplies startup RSS by the Worker count.
        if (Runtime::isPersistent()) {
            return;
        }

        $languages = self::discoverPreloadLanguages();
        $modules = [];
        foreach (Env::getInstance()->getActiveModules() as $module) {
            if (!empty($module['name'])) {
                $modules[] = (string)$module['name'];
            }
        }
        $modules = \array_values(\array_unique(\array_filter($modules)));

        $includeGlobalDictionary = !Runtime::isPersistent();
        foreach ($languages as $lang) {
            self::getLayeredWords($lang, [], $includeGlobalDictionary);
            foreach ($modules as $moduleName) {
                self::loadModuleWords($moduleName, $lang);
                self::getLayeredWords($lang, [$moduleName], $includeGlobalDictionary);
            }
        }
    }

    public static function clearWorkerCaches(): void
    {
        self::$words = [];
        self::$workerWordsCache = [];
        self::$workerLocaleWordsCache = [];
        self::$workerModuleWordsCache = [];
        self::$workerGlobalDictionaryWordsCache = [];
        self::$workerGlobalDictionaryLocaleWords = [];
        self::$workerGlobalDictionaryLoadedModules = [];
        self::$workerGlobalDictionaryAllLocales = [];
        self::$workerGlobalDictionaryWordCache = [];
        self::$workerLayeredWordsCache = [];
        self::$workerMaterializedWordsCache = [];
        self::$workerTranslatedWordsCache = [];
        self::$sharedPhraseCachePool = null;
        self::$globalDictionaryProviderInstance = null;
        self::$currentRequestWordsId = null;
        self::$currentRequestWordsKey = null;
        self::$currentRequestLayeredWordsId = null;
        self::$currentRequestLayeredWordsKey = null;
        self::$currentRequestLayeredWords = null;
        self::$currentRequestLayeredWordsSignature = null;
        self::$currentRequestTranslatedWords = [];
        self::$loaded = false;
        self::$loadedLang = null;
        self::$isLoadingWords = false;
        self::$translationResolutionDepth = 0;
    }

    private static function buildWordsFromWorkerCache(string $lang, array $modules, bool $includeGlobalDictionary = true): array
    {
        $module_words = [];
        foreach ($modules as $module_name) {
            $module_words = \array_merge($module_words, self::loadModuleWordsForLocaleChain($module_name, $lang));
        }

        return \array_merge(self::loadLocaleWordsForLocaleChain($lang, $modules, $includeGlobalDictionary), $module_words);
    }

    private static function getCurrentLayeredWords(): array
    {
        $lang = State::getLangLocal();
        $modules = self::resolveRequestModules();
        // Modules can be appended by widget hooks during the same request, and
        // the locale can be switched by a nested template context. Compare
        // those small request-local inputs before rebuilding the persistent
        // cache key (which may stat() every locale/module dictionary file).
        $normalizedModules = \array_values(\array_unique(\array_map(
            [self::class, 'getFullModuleName'],
            \array_filter($modules),
        )));
        \sort($normalizedModules);
        $requestSignature = $lang . '|' . \implode(',', $normalizedModules);
        $requestId = Runtime::isPersistent() ? RequestContext::getId() : null;
        if (
            $requestId !== null
            && self::$currentRequestLayeredWordsId === $requestId
            && self::$currentRequestLayeredWordsSignature === $requestSignature
            && self::$currentRequestLayeredWords !== null
        ) {
            return self::$currentRequestLayeredWords;
        }

        $layers = self::getLayeredWords($lang, $modules);
        self::$currentRequestLayeredWordsId = $requestId;
        self::$currentRequestLayeredWordsKey = (string)($layers['cache_key'] ?? '');
        self::$currentRequestLayeredWordsSignature = $requestSignature;
        self::$currentRequestLayeredWords = $layers;

        return $layers;
    }

    private static function buildLayeredWordsCacheKey(string $lang, array $modules, bool $includeGlobalDictionary = true): string
    {
        $modules = \array_values(\array_unique(\array_map([self::class, 'getFullModuleName'], \array_filter($modules))));
        \sort($modules);

        if (Runtime::isPersistent()) {
            // A running Worker owns this immutable scope until cache epoch
            // invalidation. Keep the first lookup entirely in process memory;
            // file versions are only needed when the module L1 itself misses.
            return DictionaryCacheNamespace::cacheKey('phrase_worker_scope|' . $lang . '|' . \implode(',', $modules)
                . '|' . ($includeGlobalDictionary ? 'db' : 'file'));
        }

        return self::buildWordsCacheKey($lang, $modules) . '|' . ($includeGlobalDictionary ? 'db' : 'file');
    }

    private static function getLayeredWords(string $lang, array $modules, bool $includeGlobalDictionary = true): array
    {
        $modules = \array_values(\array_unique(\array_map([self::class, 'getFullModuleName'], \array_filter($modules))));
        \sort($modules);
        $cacheKey = self::buildLayeredWordsCacheKey($lang, $modules, $includeGlobalDictionary);
        if (EventDictionary::isExclusive($lang)) {
            // This request-only empty layer must not replace the global one.
            return [
                'cache_key' => $cacheKey,
                'lang' => $lang,
                'modules' => $modules,
                'module_words' => [],
                'locale_words' => [],
                'global_words' => [],
            ];
        }

        if (isset(DictionaryCacheNamespace::localCache(self::$workerLayeredWordsCache, 1024)[$cacheKey])) {
            return DictionaryCacheNamespace::localCache(self::$workerLayeredWordsCache, 1024)[$cacheKey];
        }

        $sharedModuleWords = self::prefetchSharedModuleWords($lang, $modules);
        $moduleLayers = [];
        foreach ($modules as $moduleName) {
            $moduleLayers[$moduleName] = self::loadModuleWordsForLocaleChain($moduleName, $lang, $sharedModuleWords);
        }

        $layers = [
            'cache_key' => $cacheKey,
            'lang' => $lang,
            'modules' => $modules,
            'module_words' => $moduleLayers,
            'locale_words' => self::loadLocaleWordsForLocaleChain(
                $lang,
                $modules,
                $includeGlobalDictionary,
            ),
            'global_words' => [],
        ];
        if ($includeGlobalDictionary && !self::globalDictionaryLayersLoaded($lang, $modules)) {
            // 全局批量读取失败时不冻结外层快照，下一次相同组合仍能重试。
            return $layers;
        }
        return DictionaryCacheNamespace::localCache(self::$workerLayeredWordsCache, 1024)[$cacheKey] = $layers;
    }

    private static function translateWordFromLayers(string $word, array $layers): string
    {
        $lang = (string)($layers['lang'] ?? '');
        if ($lang !== '') {
            $eventTranslation = self::translateFromEventDictionary($word, $lang);
            if ($eventTranslation !== null) {
                return $eventTranslation;
            }
            if (EventDictionary::isExclusive($lang)) {
                EventDictionary::reportMissing($word, null, $lang);
                return $word;
            }
        }

        // Only public dictionary results may be shared across request scopes.
        $workerCacheKey = (string)($layers['cache_key'] ?? '') . '|' . $word;
        if (\array_key_exists($workerCacheKey, self::$workerTranslatedWordsCache)) {
            return DictionaryCacheNamespace::localCache(self::$workerTranslatedWordsCache)[$workerCacheKey];
        }

        $modules = (array)($layers['modules'] ?? []);
        $moduleWords = (array)($layers['module_words'] ?? []);
        for ($i = \count($modules) - 1; $i >= 0; $i--) {
            $moduleName = $modules[$i];
            if (!isset($moduleWords[$moduleName][$word])) {
                continue;
            }
            $translate = $moduleWords[$moduleName][$word];
            if (\is_string($translate) && $translate !== '' && $translate !== $word) {
                return self::rememberWorkerTranslatedWord($workerCacheKey, $translate);
            }
        }

        $localeWords = (array)($layers['locale_words'] ?? []);
        if (isset($localeWords[$word])) {
            $translate = $localeWords[$word];
            if (\is_string($translate) && $translate !== '' && $translate !== $word) {
                return self::rememberWorkerTranslatedWord($workerCacheKey, $translate);
            }
        }

        if ($lang !== '') {
            foreach (LocaleFallbackChain::candidates($lang, self::websiteDefaultLocale()) as $candidateLocale) {
                $globalTranslation = RequestLifecycleTrace::measurePhase(
                    'i18n.phrase.global_word',
                    static fn(): string|null|false => self::loadGlobalDictionaryWord($candidateLocale, $word),
                );
                if (\is_string($globalTranslation) && $globalTranslation !== '' && $globalTranslation !== $word) {
                    return self::rememberWorkerTranslatedWord($workerCacheKey, $globalTranslation);
                }
                if ($globalTranslation === false) {
                    // A transient cache/DB failure must not become a process-lifetime
                    // negative entry. The next request may retry after the shared
                    // single-flight owner has published the exact word.
                    return $word;
                }
            }
        }

        if ($lang !== '' && EventDictionary::isActive($lang)) {
            EventDictionary::reportMissing($word, null, $lang);
        }

        if ($lang !== '' && !self::globalDictionaryLayersLoaded($lang, $modules)) {
            // 批量回源未完成时的原词只是当前请求回退，不能冻结为进程级译文。
            return $word;
        }
        return self::rememberWorkerTranslatedWord($workerCacheKey, $word);
    }

    private static function translateFromEventDictionary(string $word, string $lang): ?string
    {
        if (!EventDictionary::isActive($lang)) {
            return null;
        }

        $translated = EventDictionary::translate($word, $lang);
        if (\is_string($translated) && $translated !== '' && $translated !== $word) {
            return $translated;
        }

        return null;
    }

    private static function rememberWorkerTranslatedWord(string $cacheKey, string $translation): string
    {
        if (DictionaryCacheNamespace::fingerprint() === null) {
            return $translation;
        }
        if (\count(self::$workerTranslatedWordsCache) >= self::WORKER_TRANSLATED_WORD_CACHE_MAX_ITEMS) {
            self::$workerTranslatedWordsCache = \array_slice(
                self::$workerTranslatedWordsCache,
                self::WORKER_TRANSLATED_WORD_CACHE_TRIM_ITEMS,
                null,
                true,
            );
        }
        DictionaryCacheNamespace::localCache(self::$workerTranslatedWordsCache)[$cacheKey] = $translation;

        return $translation;
    }

    private static function materializeLayeredWords(array $layers): array
    {
        $words = (array)($layers['locale_words'] ?? []);
        foreach ((array)($layers['modules'] ?? []) as $moduleName) {
            $words = \array_merge($words, (array)($layers['module_words'][$moduleName] ?? []));
        }

        return $words;
    }

    public static function resolveRequestModules(): array
    {
        $modules = [];
        try {
            /**@var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            $modules = $request->getModules() ?: [];
            $moduleName = $request->getModuleName();
            if (!empty($moduleName)) {
                $modules[] = $moduleName;
            }
        } catch (\Exception) {
        }

        return \array_values(\array_unique(\array_filter($modules, fn($m) => !empty($m))));
    }

    private static function buildWordsCacheKey(string $lang, array $modules): string
    {
        $modules = \array_values(\array_unique(\array_map([self::class, 'getFullModuleName'], $modules)));
        \sort($modules);

        return DictionaryCacheNamespace::cacheKey('phrase_locale_words_' . $lang . '_' . self::getWordsCacheVersion($lang, $modules)
            . (!empty($modules) ? '_' . \implode('_', $modules) : ''));
    }

    private static function getWordsCacheVersion(string $lang, array $modules): string
    {
        $parts = [];
        foreach (LocaleFallbackChain::candidates($lang, self::websiteDefaultLocale()) as $candidateLocale) {
            $languageFile = Env::path_TRANSLATE_FILES_PATH . $candidateLocale . '.php';
            $parts[] = $languageFile . ':' . self::getFileVersion($languageFile);

            foreach ($modules as $moduleName) {
                try {
                    $module = Env::getInstance()->getModuleInfo($moduleName);
                    $csvFile = ($module['base_path'] ?? '') . '/i18n/' . $candidateLocale . '.csv';
                    $parts[] = $csvFile . ':' . self::getFileVersion($csvFile);
                } catch (\Throwable) {
                }
            }
        }

        return \substr(\md5(\implode('|', $parts)), 0, 12);
    }

    private static function getFileVersion(string $file): string
    {
        if ($file === '' || !is_file($file)) {
            return 'missing';
        }

        clearstatcache(true, $file);

        return (string)@filemtime($file) . ':' . (string)@filesize($file);
    }

    private static function discoverGeneratedLanguages(): array
    {
        $languages = [];
        foreach (\glob(Env::path_TRANSLATE_FILES_PATH . '*.php') ?: [] as $file) {
            $lang = \pathinfo($file, PATHINFO_FILENAME);
            if ($lang === 'words') {
                continue;
            }
            $languages[] = $lang;
        }
        $languages[] = Env::default_LANGUAGE_CODE;

        return \array_values(\array_unique(\array_filter($languages)));
    }

    private static function discoverPreloadLanguages(): array
    {
        $configured = self::normalizeLanguageList(Env::get('wls.i18n.preload_locales', ''));
        if ($configured === []) {
            $configured = self::normalizeLanguageList(Env::get('i18n.preload_locales', ''));
        }
        if ($configured === []) {
            $configured = self::normalizeLanguageList(Env::get('i18n.locales', ''));
        }
        if (\in_array('all', $configured, true)) {
            return self::discoverGeneratedLanguages();
        }

        $languages = \array_merge(
            $configured,
            Runtime::isPersistent() ? self::discoverGeneratedLanguages() : [],
            self::normalizeLanguageList(Env::get('user.lang', '')),
            self::normalizeLanguageList(Env::get('locale', '')),
            self::normalizeLanguageList(Env::get('language', '')),
            [Env::default_LANGUAGE_CODE]
        );

        return \array_values(\array_unique(\array_filter($languages)));
    }

    private static function normalizeLanguageList(mixed $value): array
    {
        if (\is_string($value)) {
            $decoded = \json_decode($value, true);
            if (\is_array($decoded)) {
                $value = $decoded;
            } else {
                $value = \preg_split('/[,\s]+/', $value) ?: [];
            }
        }
        if (!\is_array($value)) {
            return [];
        }

        $languages = [];
        foreach ($value as $key => $row) {
            if (\is_array($row)) {
                if (!empty($row['enabled']) && \is_string($key)) {
                    $languages[] = $key;
                }
                if (!empty($row['code'])) {
                    $languages[] = (string)$row['code'];
                }
                if (!empty($row['locale'])) {
                    $languages[] = (string)$row['locale'];
                }
                continue;
            }
            if (\is_string($key) && $key !== '' && \filter_var($row, FILTER_VALIDATE_BOOLEAN)) {
                $languages[] = $key;
            }
            if (\is_scalar($row)) {
                $languages[] = (string)$row;
            }
        }

        return \array_values(\array_unique(\array_filter(\array_map('trim', $languages))));
    }
    
    /**
     * Load and merge translated module words from the deterministic locale chain.
     *
     * @return array<string, string>
     */
    private static function loadModuleWordsForLocaleChain(string $moduleName, string $lang, array $sharedModuleWords = []): array
    {
        $words = [];
        $locales = LocaleFallbackChain::candidates($lang, self::websiteDefaultLocale());
        foreach (\array_reverse($locales) as $candidateLocale) {
            $words = self::mergePreferTranslatedWords(
                $words,
                self::loadModuleWordsWithSharedCache($moduleName, $candidateLocale, $sharedModuleWords),
            );
        }

        return $words;
    }

    /**
     * @return array<string, string>
     */
    private static function loadLocaleWordsForLocaleChain(
        string $lang,
        array $modules,
        bool $includeGlobalDictionary = true,
    ): array {
        $words = [];
        $locales = LocaleFallbackChain::candidates($lang, self::websiteDefaultLocale());
        foreach (\array_reverse($locales) as $candidateLocale) {
            $words = self::mergePreferTranslatedWords(
                $words,
                self::loadLocaleWords($candidateLocale, $modules, $includeGlobalDictionary),
            );
        }

        return $words;
    }

    private static function websiteDefaultLocale(): string
    {
        foreach (['website.language', 'locale', 'lang'] as $configKey) {
            try {
                $candidate = Env::get($configKey, '');
                if (!\is_scalar($candidate)) {
                    continue;
                }
                $candidate = LocaleFallbackChain::normalize((string)$candidate);
                if ($candidate !== '') {
                    return $candidate;
                }
            } catch (\Throwable) {
            }
        }

        return LocaleFallbackChain::normalize(Env::default_LANGUAGE_CODE);
    }

    /** 将本层尚未命中 L1 的模块词典快照合并为一次共享缓存读取。 */
    private static function prefetchSharedModuleWords(string $lang, array $modules): array
    {
        if (!Runtime::isPersistent()) {
            return [];
        }

        $keys = [];
        $locales = LocaleFallbackChain::candidates($lang, self::websiteDefaultLocale());
        foreach ($modules as $moduleName) {
            foreach ($locales as $locale) {
                if (isset(DictionaryCacheNamespace::localCache(self::$workerModuleWordsCache, 2048)[DictionaryCacheNamespace::cacheKey('worker|' . $locale . '|' . $moduleName)])) {
                    continue;
                }
                try {
                    $module = Env::getInstance()->getModuleInfo($moduleName);
                    $file = ($module['base_path'] ?? '') . '/i18n/' . $locale . '.csv';
                    if (!$module || !isset($module['base_path']) || !is_file($file)) {
                        continue;
                    }
                    $versionedKey = $locale . '|' . $moduleName . '|' . self::getFileVersion($file);
                    $keys['module_dictionary|v1|' . \sha1($versionedKey)] = null;
                } catch (\Throwable) {
                    // 仍由原模块加载流程负责本地文件回退。
                }
            }
        }
        if ($keys === []) {
            return [];
        }

        try {
            $cached = RequestLifecycleTrace::measurePhase(
                'i18n.phrase.module_cache_get',
                static fn(): array => self::getSharedPhraseCachePool()?->getMultiple(\array_keys($keys)) ?? [],
                ['batch' => true, 'keys' => \count($keys)],
            );
            return \array_replace($keys, \array_intersect_key($cached, $keys));
        } catch (\Throwable) {
            // null 仅表示已尝试共享读取，不代表已缓存空词典。
            // 未取得快照的模块仍回退到权威 CSV 文件。
            return $keys;
        }
    }

    /** @return array<string, string> */
    protected static function loadModuleWords(string $module_name, string $lang): array
    {
        return self::loadModuleWordsWithSharedCache($module_name, $lang);
    }

    /** @return array<string, string> */
    private static function loadModuleWordsWithSharedCache(string $module_name, string $lang, array $sharedModuleWords = []): array
    {
        $module_name = self::getFullModuleName($module_name);
        $worker_scope_key = DictionaryCacheNamespace::cacheKey('worker|' . $lang . '|' . $module_name);
        if (Runtime::isPersistent() && isset(DictionaryCacheNamespace::localCache(self::$workerModuleWordsCache, 2048)[$worker_scope_key])) {
            return DictionaryCacheNamespace::localCache(self::$workerModuleWordsCache, 2048)[$worker_scope_key];
        }

        $cache_key = $lang . '|' . $module_name . '|unresolved';
        $worker_cache_key = Runtime::isPersistent() ? $worker_scope_key : DictionaryCacheNamespace::cacheKey($cache_key);
        try {
            // 获取模块信息
            $module_info = Env::getInstance()->getModuleInfo($module_name);
            $module_i18n_file = ($module_info['base_path'] ?? '') . '/i18n/' . $lang . '.csv';
            $cache_key = $lang . '|' . $module_name . '|' . self::getFileVersion($module_i18n_file);
            $worker_cache_key = Runtime::isPersistent() ? $worker_scope_key : DictionaryCacheNamespace::cacheKey($cache_key);
            if (isset(DictionaryCacheNamespace::localCache(self::$workerModuleWordsCache, 2048)[$worker_cache_key])) {
                return DictionaryCacheNamespace::localCache(self::$workerModuleWordsCache, 2048)[$worker_cache_key];
            }

            if (!$module_info || !isset($module_info['base_path'])) {
                return DictionaryCacheNamespace::localCache(self::$workerModuleWordsCache, 2048)[$worker_cache_key] = [];
            }

            if (!is_file($module_i18n_file)) {
                return DictionaryCacheNamespace::localCache(self::$workerModuleWordsCache, 2048)[$worker_cache_key] = [];
            }

            $sharedCacheKey = 'module_dictionary|v1|' . \sha1($cache_key);
            if (Runtime::isPersistent()) {
                try {
                    $cached = \array_key_exists($sharedCacheKey, $sharedModuleWords)
                        ? $sharedModuleWords[$sharedCacheKey]
                        : RequestLifecycleTrace::measurePhase(
                            'i18n.phrase.module_cache_get',
                            static fn(): mixed => self::getSharedPhraseCachePool()?->get($sharedCacheKey),
                        );
                    if (\is_array($cached)) {
                        return DictionaryCacheNamespace::localCache(self::$workerModuleWordsCache, 2048)[$worker_cache_key] = $cached;
                    }
                } catch (\Throwable) {
                    // Shared cache is an optimization only; local CSV is authoritative.
                }
            }

            $words = [];
            $handle = @fopen($module_i18n_file, 'r');
            if ($handle === false) {
                return DictionaryCacheNamespace::localCache(self::$workerModuleWordsCache, 2048)[$worker_cache_key] = [];
            }

            try {
                while (($data = fgetcsv($handle, 100000, ',', '"', '\\')) !== false) {
                    if (!isset($data[0]) || empty(trim($data[0]))) {
                        continue;
                    }
                    if (!isset($data[1])) {
                        continue;
                    }

                    $word = trim($data[0]);
                    $translate = trim($data[1]);
                    // 第三列是模块名（可选），如果存在且与当前模块不匹配，跳过
                    if (isset($data[2]) && !empty(trim($data[2]))) {
                        $word_module = trim($data[2]);
                        if ($word_module !== $module_name) {
                            continue;
                        }
                    }

                    if ($translate !== '' && $translate !== $word) {
                        $words[$word] = $translate;
                    }
                }
            } finally {
                fclose($handle);
            }

            if (Runtime::isPersistent()) {
                try {
                    RequestLifecycleTrace::measurePhase(
                        'i18n.phrase.module_cache_set',
                        static function () use ($sharedCacheKey, $words): mixed {
                            return self::getSharedPhraseCachePool()?->set(
                                $sharedCacheKey,
                                $words,
                                self::MODULE_DICTIONARY_SHARED_TTL_SECONDS,
                            );
                        },
                    );
                } catch (\Throwable) {
                    // The current Worker already owns the parsed dictionary.
                }
            }

            return DictionaryCacheNamespace::localCache(self::$workerModuleWordsCache, 2048)[$worker_cache_key] = $words;
        } catch (\Throwable) {
            // 静默处理错误
        }

        return DictionaryCacheNamespace::localCache(self::$workerModuleWordsCache, 2048)[$worker_cache_key] = [];
    }
    
    /**
     * 获取完整的模块名（如 Weline_I18n）
     * 
     * @param string $module_name 模块名（如 I18n）
     * @return string 完整模块名（如 Weline_I18n）
     */
    private static function getFullModuleName(string $module_name): string
    {
        $module_name = \trim($module_name);
        if ($module_name === '') {
            return '';
        }

        // Module identity is Vendor_Module, not necessarily Weline_Module.
        // Preserve every already-qualified vendor name even when that module is
        // optional or absent from the current installation; otherwise a valid
        // WeShop_Affiliate request scope becomes Weline_WeShop_Affiliate.
        if (\str_contains($module_name, '_')) {
            return $module_name;
        }
        
        // 尝试从模块信息获取完整名称
        try {
            $module_info = Env::getInstance()->getModuleInfo($module_name);
            if ($module_info && isset($module_info['name'])) {
                return $module_info['name'];
            }
        } catch (\Exception $e) {
            // 忽略错误
        }
        
        // 默认格式：Weline_模块名
        return 'Weline_' . $module_name;
    }

    /**
     * 优先按语言文件加载，避免常规请求直接 include 巨大的总词典文件。
     * 仅当语言文件缺失时，才回退到 words.php。
     */
    private static function loadLocaleWords(string $lang, array $modules, bool $includeGlobalDictionary = true): array
    {
        $modules = \array_values(\array_unique(\array_map([self::class, 'getFullModuleName'], $modules)));
        \sort($modules);

        if (Runtime::isPersistent()) {
            $cache_key = DictionaryCacheNamespace::cacheKey('worker|' . $lang . '|' . \implode(',', $modules)
                . '|' . ($includeGlobalDictionary ? 'db' : 'file'));
            if (isset(DictionaryCacheNamespace::localCache(self::$workerLocaleWordsCache, 1024)[$cache_key])) {
                return DictionaryCacheNamespace::localCache(self::$workerLocaleWordsCache, 1024)[$cache_key];
            }

            // WLS workers keep a bounded, module-scoped global snapshot when
            // there is enough headroom. This turns the common translation
            // fallback from one DB/WLS lookup per phrase into one shared read
            // per locale/scope. Under memory pressure the empty layer keeps the
            // existing exact-word fallback path below as a safe degradation.
            $global_dictionary_words = [];
            if ($includeGlobalDictionary && !self::shouldSkipHeavyLocaleDictionaryLoad()) {
                $global_dictionary_words = RequestLifecycleTrace::measurePhase(
                    'i18n.phrase.global_dictionary_batch',
                    static fn(): array => self::loadGlobalDictionaryScopeWords($lang, $modules),
                    ['locale' => $lang, 'modules' => \count($modules)],
                );
                if (self::globalDictionaryProvider() !== null
                    && !self::globalDictionaryModulesLoaded($lang, $modules)
                ) {
                    return $global_dictionary_words;
                }
            }

            return DictionaryCacheNamespace::localCache(self::$workerLocaleWordsCache, 1024)[$cache_key] = $global_dictionary_words;
        }

        $cache_key = DictionaryCacheNamespace::cacheKey($lang . '|' . self::getWordsCacheVersion($lang, $modules) . '|' . \implode(',', $modules) . '|' . ($includeGlobalDictionary ? 'db' : 'file'));
        if (isset(DictionaryCacheNamespace::localCache(self::$workerLocaleWordsCache, 1024)[$cache_key])) {
            return DictionaryCacheNamespace::localCache(self::$workerLocaleWordsCache, 1024)[$cache_key];
        }

        $global_dictionary_words = $includeGlobalDictionary ? self::loadGlobalDictionaryWords($lang, $modules) : [];

        if (self::shouldSkipHeavyLocaleDictionaryLoad()) {
            return $global_dictionary_words;
        }

        $words_file = Env::path_TRANSLATE_FILES_PATH . $lang . '.php';
        if (is_file($words_file)) {
            try {
                // 同代次 L1 已命中时不会到这里；只失效本次将读取的语言文件。
                if (\function_exists('opcache_invalidate')) {
                    @opcache_invalidate($words_file, true);
                }
                $lang_words = (array)include $words_file;
                return DictionaryCacheNamespace::localCache(self::$workerLocaleWordsCache, 1024)[$cache_key] = self::mergePreferTranslatedWords(
                    $global_dictionary_words,
                    self::extractModuleWords($lang_words, $modules)
                );
            } catch (\Throwable) {
                // 回退到总词典
            }
        }

        $all_words_file = Env::path_TRANSLATE_ALL_COLLECTIONS_WORDS_FILE;
        if (!is_file($all_words_file)) {
            return DictionaryCacheNamespace::localCache(self::$workerLocaleWordsCache, 1024)[$cache_key] = $global_dictionary_words;
        }

        try {
            if (\function_exists('opcache_invalidate')) {
                @opcache_invalidate($all_words_file, true);
            }
            $all_words_data = (array)include $all_words_file;
            $all_words = [];

            if (isset($all_words_data['all_words']) && is_array($all_words_data['all_words'])) {
                $all_words = array_merge($all_words, $all_words_data['all_words']);
            }

            if (isset($all_words_data[$lang]) && is_array($all_words_data[$lang])) {
                $all_words = array_merge($all_words, self::extractModuleWords($all_words_data[$lang], $modules));
            }

            return DictionaryCacheNamespace::localCache(self::$workerLocaleWordsCache, 1024)[$cache_key] = self::mergePreferTranslatedWords($global_dictionary_words, $all_words);
        } catch (\Throwable) {
            return DictionaryCacheNamespace::localCache(self::$workerLocaleWordsCache, 1024)[$cache_key] = $global_dictionary_words;
        }
    }

    /**
     * 从按模块组织的语言数组中提取当前请求所需词条。
     */
    private static function extractModuleWords(array $lang_words, array $modules): array
    {
        $all_words = [];

        if (empty($modules)) {
            foreach ($lang_words as $word => $module_words_data) {
                if (is_string($word) && is_string($module_words_data)) {
                    $all_words = self::mergePreferTranslatedWords($all_words, [$word => $module_words_data]);
                    continue;
                }
                if (is_array($module_words_data)) {
                    $all_words = self::mergePreferTranslatedWords($all_words, $module_words_data);
                }
            }
            return $all_words;
        }

        foreach ($lang_words as $word => $translate) {
            if (is_string($word) && is_string($translate)) {
                $all_words = self::mergePreferTranslatedWords($all_words, [$word => $translate]);
            }
        }

        foreach ($modules as $module_name) {
            $full_module_name = self::getFullModuleName($module_name);
            if (isset($lang_words[$full_module_name]) && is_array($lang_words[$full_module_name])) {
                $all_words = self::mergePreferTranslatedWords($all_words, $lang_words[$full_module_name]);
            } elseif (isset($lang_words[$module_name]) && is_array($lang_words[$module_name])) {
                $all_words = self::mergePreferTranslatedWords($all_words, $lang_words[$module_name]);
            }
        }

        return $all_words;
    }

    private static function shouldSkipHeavyLocaleDictionaryLoad(): bool
    {
        if (!Runtime::isPersistent()) {
            return false;
        }

        $limitBytes = self::getMemoryLimitBytes();
        if ($limitBytes <= 0) {
            return false;
        }

        $usedBytes = \memory_get_usage(false);
        $headroomBytes = $limitBytes - $usedBytes;
        if ($headroomBytes < self::WLS_HEAVY_LOCALE_HEADROOM_BYTES) {
            return true;
        }

        return ($usedBytes / $limitBytes) >= self::WLS_HEAVY_LOCALE_PRESSURE_THRESHOLD;
    }

    private static function getMemoryLimitBytes(): int
    {
        $limit = \trim((string)\ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return 0;
        }

        $unit = \strtolower(\substr($limit, -1));
        $value = (float)$limit;
        if ($value <= 0) {
            return 0;
        }

        return match ($unit) {
            'g' => (int)($value * 1024 * 1024 * 1024),
            'm' => (int)($value * 1024 * 1024),
            'k' => (int)($value * 1024),
            default => (int)$value,
        };
    }

    /**
     * Load translations from the global locale dictionary as a fallback.
     *
     * AI translations are written here first, so they must be effective without
     * requiring `translation.mode=online` or leaking unrelated module CSV groups.
     */
    private static function loadGlobalDictionaryWords(string $lang, array $modules = []): array
    {
        $modules = \array_values(\array_unique(\array_map(
            [self::class, 'getFullModuleName'],
            \array_filter($modules, static fn(mixed $module): bool => \is_string($module) && \trim($module) !== ''),
        )));
        \sort($modules);

        // Persistent requests use loadGlobalDictionaryWord() after their
        // module-local layers miss. They never materialize a DB dictionary.
        if (Runtime::isPersistent()) {
            return [];
        }

        // Keep one immutable snapshot per actual route/module combination. A
        // shared miss performs one provider query with source_module IN (...),
        // rather than one query per module, then every Worker reuses the result.
        return self::loadGlobalDictionaryScopeWords($lang, $modules);
    }

    /**
     * @param list<string> $modules Empty means the explicit non-persistent full-dictionary flow.
     * @return array<string, string>
     */
    private static function loadGlobalDictionaryScopeWords(string $lang, array $modules): array
    {
        $scope = $modules === [] ? 'all' : \implode(',', $modules);
        $localeKey = DictionaryCacheNamespace::cacheKey($lang);
        if (DictionaryCacheNamespace::fingerprint() === null) {
            return self::loadGlobalDictionaryWordsFromDatabase($lang, $modules) ?? [];
        }

        // Request module membership grows while a persistent Worker renders
        // hooks and slots. Keep the global dictionary language-scoped, but only
        // extend it with modules that were not loaded by this Worker yet. This
        // avoids both repeated superset queries and a full-locale array in a
        // long-lived process. An empty module list remains the explicit
        // maintenance/CLI "load all" path.
        if (Runtime::isPersistent()) {
            $hasSnapshot = \array_key_exists($localeKey, DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryLocaleWords, 128));
            if (!$hasSnapshot) {
                unset(
                    DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryLoadedModules, 128)[$localeKey],
                    DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryAllLocales, 128)[$localeKey],
                );
            }
            $snapshot = DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryLocaleWords, 128)[$localeKey] ?? [];
            $loadedModules = $hasSnapshot ? (DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryLoadedModules, 128)[$localeKey] ?? []) : [];
            $allLoaded = $hasSnapshot && (DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryAllLocales, 128)[$localeKey] ?? false);

            if ($allLoaded) {
                return $snapshot;
            }

            $missingModules = $modules === []
                ? []
                : \array_values(\array_diff($modules, $loadedModules));
            if ($modules !== [] && $missingModules === []) {
                return $snapshot;
            }

            $queryModules = $modules === [] ? [] : $missingModules;
            $queryScope = $queryModules === [] ? 'all' : \implode(',', $queryModules);
        } else {
            $snapshot = [];
            $queryModules = $modules;
            $queryScope = $scope;
        }

        $workerCacheKey = DictionaryCacheNamespace::cacheKey($lang . '|' . $scope);
        if (!Runtime::isPersistent() && isset(DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryWordsCache, 128)[$workerCacheKey])) {
            return DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryWordsCache, 128)[$workerCacheKey];
        }

        if (self::globalDictionaryProvider() === null) {
            return Runtime::isPersistent()
                ? $snapshot
                : DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryWordsCache, 128)[$workerCacheKey] = [];
        }

        $cachePool = self::getSharedPhraseCachePool();
        $cacheKey = 'global_dictionary_words|' . $lang . '|v2|' . \sha1($queryScope);
        if ($cachePool !== null) {
            try {
                $provider = self::globalDictionaryProvider();
                if ($queryModules !== [] && $provider instanceof ModuleGlobalDictionaryProviderInterface) {
                    $words = self::loadGlobalDictionaryModuleWords($cachePool, $provider, $lang, $queryModules);
                } else {
                    // 旧 Provider 与显式全词典入口保持原组合快照路径。
                    $words = $cachePool->remember(
                        $cacheKey,
                        3600,
                        static fn(): ?array => self::loadGlobalDictionaryWordsFromDatabase($lang, $queryModules),
                        new RememberOptions(
                            nullTtl: 5,
                            jitter: true,
                            jitterRatio: 0.10,
                            singleFlight: true,
                            singleFlightTimeoutMs: self::globalDictionarySingleFlightTimeoutMs(),
                            computeOnSingleFlightTimeout: !Runtime::isPersistent(),
                        )
                    );
                }

                if (Runtime::isPersistent()) {
                    // A null result means a transient shared/DB failure. Do
                    // not mark the requested modules as loaded; a later
                    // request may retry after the cache service recovers.
                    if (!\is_array($words)) {
                        return $snapshot;
                    }

                    // 共享/数据库读取可能让出执行权；以发布时的最新快照合并其他 Fiber 的增量。
                    $latestSnapshot = DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryLocaleWords, 128)[$localeKey] ?? null;
                    $loadedModules = \is_array($latestSnapshot)
                        ? (DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryLoadedModules, 128)[$localeKey] ?? [])
                        : [];
                    if (!\is_array($latestSnapshot)) {
                        unset(DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryAllLocales, 128)[$localeKey]);
                    }
                    $snapshot = self::mergePreferTranslatedWords($latestSnapshot ?? [], $words);
                    DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryLocaleWords, 128)[$localeKey] = $snapshot;
                    if ($queryModules === []) {
                        DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryAllLocales, 128)[$localeKey] = true;
                    } else {
                        DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryLoadedModules, 128)[$localeKey] = \array_values(
                            \array_unique(\array_merge($loadedModules, $queryModules)),
                        );
                        \sort(DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryLoadedModules, 128)[$localeKey]);
                    }

                    return $snapshot;
                }

                return DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryWordsCache, 128)[$workerCacheKey] =
                    \is_array($words) ? $words : [];
            } catch (\Throwable) {
                if (Runtime::isPersistent()) {
                    return $snapshot;
                }
                // CLI / non-persistent fallback can still read DB directly.
            }
        }

        if (Runtime::isPersistent()) {
            return $snapshot;
        }

        return DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryWordsCache, 128)[$workerCacheKey] =
            self::loadGlobalDictionaryWordsFromDatabase($lang, $queryModules) ?? [];
    }

    /** 已加载标记同时区分确认空模块与读取失败，不再增加失败状态袋。 */
    private static function globalDictionaryModulesLoaded(string $lang, array $modules): bool
    {
        if (DictionaryCacheNamespace::fingerprint() === null) {
            return true;
        }
        $localeKey = DictionaryCacheNamespace::cacheKey($lang);
        if (!\array_key_exists($localeKey, DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryLocaleWords, 128))) {
            return false;
        }
        return (DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryAllLocales, 128)[$localeKey] ?? false)
            || ($modules !== [] && \array_diff($modules, DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryLoadedModules, 128)[$localeKey] ?? []) === []);
    }

    private static function globalDictionaryLayersLoaded(string $lang, array $modules): bool
    {
        if (!Runtime::isPersistent() || self::shouldSkipHeavyLocaleDictionaryLoad()
            || self::globalDictionaryProvider() === null
        ) {
            return true;
        }
        foreach (LocaleFallbackChain::candidates($lang, self::websiteDefaultLocale()) as $candidateLocale) {
            if (!self::globalDictionaryModulesLoaded($candidateLocale, $modules)) {
                return false;
            }
        }
        return true;
    }

    /**
     * 共享键固定到语言/模块，Worker 的模块发现顺序不会产生新的组合键。
     * @param list<string> $modules
     * @return array<string, string>|null 协调等待超时且尚未发布完整结果时返回 null。
     */
    private static function loadGlobalDictionaryModuleWords(
        CachePoolInterface $pool,
        ModuleGlobalDictionaryProviderInterface $provider,
        string $lang,
        array $modules,
    ): ?array {
        $keys = [];
        foreach ($modules as $module) {
            $keys[$module] = 'global_dictionary_module_words|' . $lang . '|v1|' . \sha1($module);
        }
        $shared = $pool->getMultiple(\array_values($keys));
        $maps = [];
        $missing = [];
        foreach ($keys as $module => $key) {
            if (\is_array($shared[$key] ?? null)) {
                $maps[$module] = $shared[$key];
            } else {
                $missing[] = $module;
            }
        }
        if ($missing !== []) {
            // 使用真实的首个缺失模块键复用缓存池 single-flight；不创建组合缓存或私锁。
            $leader = $missing[0];
            $leaderMap = $pool->remember(
                $keys[$leader],
                self::GLOBAL_DICTIONARY_WORD_SHARED_TTL_SECONDS,
                static function () use ($pool, $provider, $lang, $missing, $keys, $leader, &$maps): array {
                    // 等待执行权期间其他 Worker 可能已发布，获得锁后必须再次读取。
                    $recheck = $pool->getMultiple(\array_values(\array_intersect_key($keys, \array_fill_keys($missing, true))));
                    $unresolved = [];
                    foreach ($missing as $module) {
                        if (\is_array($recheck[$keys[$module]] ?? null)) {
                            $maps[$module] = $recheck[$keys[$module]];
                        } else {
                            $unresolved[] = $module;
                        }
                    }
                    if ($unresolved !== []) {
                        // 一次 SQL 返回各缺失模块的独立词表；异常不发布空数组。
                        $loaded = $provider->wordsByModule($lang, $unresolved);
                        $values = [];
                        foreach ($unresolved as $module) {
                            $maps[$module] = $loaded[$module] ?? [];
                            $values[$keys[$module]] = $maps[$module];
                        }
                        $pool->setMultiple($values, self::GLOBAL_DICTIONARY_WORD_SHARED_TTL_SECONDS);
                    }
                    return $maps[$leader];
                },
                new RememberOptions(
                    nullTtl: 5,
                    jitter: true,
                    jitterRatio: 0.10,
                    singleFlight: true,
                    singleFlightTimeoutMs: self::globalDictionarySingleFlightTimeoutMs(),
                    computeOnSingleFlightTimeout: !Runtime::isPersistent(),
                ),
            );
            if (\is_array($leaderMap)) {
                $maps[$leader] = $leaderMap;
            }
            $remaining = \array_diff_key($keys, $maps);
            if ($remaining !== []) {
                $published = $pool->getMultiple(\array_values($remaining));
                foreach ($remaining as $module => $key) {
                    if (!\is_array($published[$key] ?? null)) {
                        return null;
                    }
                    $maps[$module] = $published[$key];
                }
            }
        }
        $words = [];
        foreach ($modules as $module) {
            $words = self::mergePreferTranslatedWords($words, $maps[$module]);
        }
        return $words;
    }

    private static function getSharedPhraseCachePool(): ?\Weline\Framework\Cache\Contract\CachePoolInterface
    {
        if (DictionaryCacheNamespace::fingerprint() === null) {
            return null;
        }
        if (self::$sharedPhraseCachePool instanceof CachePoolInterface) {
            return self::$sharedPhraseCachePool;
        }

        try {
            /** @var CacheManager $cacheManager */
            $cacheManager = ObjectManager::getInstance(CacheManager::class);
            $pool = DictionaryCacheNamespace::scopedPool($cacheManager->pool('phrase'));
            if ($pool instanceof CachePoolInterface) {
                self::$sharedPhraseCachePool = $pool;
            }
            return $pool;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 为已知词列表预取公共词典回退项，不解析或覆盖请求/模块词典。
     * 命中和确认不存在的词复用普通逐词路径的 Worker 缓存及失效周期。
     *
     * @param list<string> $words
     */
    public static function prefetchWords(array $words, ?string $locale = null): void
    {
        $words = \array_values(\array_unique(\array_filter($words,
            static fn(mixed $word): bool => \is_string($word) && \trim($word) !== '',
        )));
        $locale = $locale ?? State::getLangLocal();
        if ($words === [] || $locale === '') {
            return;
        }
        // 与普通解析一致：独占词典请求不加载任何公共词典。
        if (EventDictionary::isExclusive($locale)) {
            return;
        }
        $provider = self::globalDictionaryProvider();
        if (!$provider instanceof BatchGlobalDictionaryProviderInterface) {
            return;
        }
        \sort($words, SORT_STRING);

        self::enterTranslationResolution();
        try {
            $cachePool = self::getSharedPhraseCachePool();
            $versionPrefix = DictionaryCacheNamespace::cacheKey('');
            foreach (LocaleFallbackChain::candidates($locale, self::websiteDefaultLocale()) as $candidateLocale) {
                $missing = [];
                foreach ($words as $word) {
                    $workerKey = $versionPrefix . $candidateLocale . '|' . $word;
                    $requestTranslation = null;
                    if (!\array_key_exists($workerKey, self::$workerGlobalDictionaryWordCache)
                        && !self::readRequestPrefetchedWord($candidateLocale, $word, $requestTranslation)
                    ) {
                        $missing[] = $word;
                    }
                }
                foreach (\array_chunk($missing, 200) as $chunk) {
                    $keys = [];
                    foreach ($chunk as $word) {
                        $keys[$word] = self::globalDictionaryWordCacheKey($candidateLocale, $word);
                    }
                    $records = [];
                    if ($cachePool !== null) {
                        try {
                            $records = $cachePool->getMultiple(\array_values($keys));
                        } catch (\Throwable) {
                            // 共享缓存失败时仍可执行有限批量数据库查询。
                        }
                    }
                    $unresolved = [];
                    foreach ($chunk as $word) {
                        $record = $records[$keys[$word]] ?? null;
                        if (\is_array($record) && \array_key_exists('found', $record)) {
                            $translation = (bool)$record['found']
                                ? (string)($record['translation'] ?? '') : null;
                            DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryWordCache)[$versionPrefix . $candidateLocale . '|' . $word] = $translation;
                            self::rememberRequestPrefetchedWord($candidateLocale, $word, $translation);
                        } else {
                            $unresolved[] = $word;
                        }
                    }
                    if ($unresolved === []) {
                        continue;
                    }
                    try {
                        // 与普通解析复用同一词条键，由统一缓存池执行 WLS 批量传输。
                        $translations = RequestLifecycleTrace::measurePhase(
                            'i18n.phrase.exact_dictionary_batch',
                            static fn(): array => $provider->exactWords($candidateLocale, $unresolved),
                            ['locale' => $candidateLocale, 'words' => \count($unresolved)],
                        );
                    } catch (\Throwable) {
                        // 查询未完成不能记为缺词，普通逐词回退仍可重试。
                        continue;
                    }
                    if (!\is_array($translations)) {
                        continue;
                    }
                    $writeRecords = [];
                    foreach ($unresolved as $word) {
                        $translation = $translations[$word] ?? null;
                        $translation = \is_string($translation) && $translation !== '' && $translation !== $word
                            ? $translation : null;
                        DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryWordCache)[$versionPrefix . $candidateLocale . '|' . $word] = $translation;
                        self::rememberRequestPrefetchedWord($candidateLocale, $word, $translation);
                        $writeRecords[$keys[$word]] = ['found' => $translation !== null, 'translation' => $translation ?? ''];
                    }
                    if ($cachePool !== null) {
                        try {
                            $cachePool->setMultiple($writeRecords, self::GLOBAL_DICTIONARY_WORD_SHARED_TTL_SECONDS);
                        } catch (\Throwable) {
                            // 已查明的数据库结果仍保留在本 Worker 的既有 L1 中。
                        }
                    }
                }
            }
        } finally {
            self::leaveTranslationResolution();
        }
    }

    /**
     * Resolve a single legacy/global dictionary entry.
     *
     * @return string|null|false Translation, known miss, or transient failure.
     */
    private static function loadGlobalDictionaryWord(string $lang, string $word): string|null|false
    {
        $workerCacheKey = DictionaryCacheNamespace::cacheKey($lang . '|' . $word);
        if (\array_key_exists($workerCacheKey, self::$workerGlobalDictionaryWordCache)) {
            return DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryWordCache)[$workerCacheKey];
        }
        $requestWord = null;
        if (self::readRequestPrefetchedWord($lang, $word, $requestWord)) {
            return $requestWord;
        }

        if (self::globalDictionaryProvider() === null) {
            DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryWordCache)[$workerCacheKey] = null;
            return null;
        }

        $cachePool = self::getSharedPhraseCachePool();
        $cacheKey = self::globalDictionaryWordCacheKey($lang, $word);
        if ($cachePool !== null) {
            try {
                // CachePool::remember() performs the initial read itself. Do
                // not preflight with get(), which doubles every miss's WLS
                // round-trip before single-flight/DB resolution begins.
                $record = $cachePool->remember(
                    $cacheKey,
                    self::GLOBAL_DICTIONARY_WORD_SHARED_TTL_SECONDS,
                    static fn(): ?array => self::loadGlobalDictionaryWordRecordFromDatabase($lang, $word),
                    new RememberOptions(
                        nullTtl: 5,
                        jitter: true,
                        jitterRatio: 0.10,
                        singleFlight: true,
                        singleFlightTimeoutMs: self::globalDictionarySingleFlightTimeoutMs(),
                        computeOnSingleFlightTimeout: true,
                    )
                );

                if (\is_array($record) && \array_key_exists('found', $record)) {
                    $translation = (bool)$record['found']
                        ? (string)($record['translation'] ?? '')
                        : null;
                    DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryWordCache)[$workerCacheKey] = $translation;
                    return $translation;
                }

                return false;
            } catch (\Throwable) {
                // Exact indexed DB fallback remains bounded and preserves
                // translations when shared memory is temporarily unavailable.
            }
        }

        $record = self::loadGlobalDictionaryWordRecordFromDatabase($lang, $word);
        if (!\is_array($record)) {
            return false;
        }

        $translation = (bool)($record['found'] ?? false)
            ? (string)($record['translation'] ?? '')
            : null;
        DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryWordCache)[$workerCacheKey] = $translation;

        return $translation;
    }

    private static function rememberRequestPrefetchedWord(string $locale, string $word, ?string $translation): void
    {
        if (!Context::hasCurrent()) {
            return;
        }
        $cache = RequestContext::get(self::REQUEST_PREFETCHED_GLOBAL_WORDS_CONTEXT_KEY, []);
        if (!is_array($cache)) {
            $cache = [];
        }
        $cache[$locale . '|' . $word] = $translation;
        RequestContext::set(self::REQUEST_PREFETCHED_GLOBAL_WORDS_CONTEXT_KEY, $cache);
    }

    private static function readRequestPrefetchedWord(string $locale, string $word, mixed &$translation): bool
    {
        if (!Context::hasCurrent()) {
            return false;
        }
        $cache = RequestContext::get(self::REQUEST_PREFETCHED_GLOBAL_WORDS_CONTEXT_KEY, []);
        $key = $locale . '|' . $word;
        if (!is_array($cache) || !array_key_exists($key, $cache)) {
            return false;
        }
        $translation = $cache[$key];

        return true;
    }

    private static function globalDictionarySingleFlightTimeoutMs(): int
    {
        // A persistent storefront request must not queue behind a peer's
        // remote dictionary lock. The exact-word fallback is idempotent and
        // the shared recheck inside CachePool::remember still reuses a result
        // that was published before this call.
        return Runtime::isPersistent() ? 0 : self::GLOBAL_DICTIONARY_SINGLE_FLIGHT_TIMEOUT_MS;
    }

    private static function globalDictionaryWordCacheKey(string $locale, string $word): string
    {
        return 'global_dictionary_word|v1|' . \sha1($locale . '|' . $word);
    }

    /**
     * @return array{found:bool,translation:string}|null
     */
    private static function loadGlobalDictionaryWordRecordFromDatabase(string $lang, string $word): ?array
    {
        try {
            $translation = self::globalDictionaryProvider()?->word($lang, $word);
            return [
                'found' => \is_string($translation) && $translation !== '' && $translation !== $word,
                'translation' => \is_string($translation) ? $translation : '',
            ];
        } catch (\Throwable $throwable) {
            if (\function_exists('w_log_warning')) {
                \w_log_warning('[Phrase] exact global dictionary DB load failed: ' . $throwable->getMessage(), [
                    'lang' => $lang,
                    'word_hash' => \sha1($word),
                ], 'phrase');
            }
            return null;
        }
    }

    /**
     * @return array<string,string>|null null means the DB read failed and should only be cached briefly.
     */
    private static function loadGlobalDictionaryWordsFromDatabase(string $lang, array $modules = []): ?array
    {
        try {
            return self::globalDictionaryProvider()?->words($lang, $modules) ?? [];
        } catch (\Throwable $throwable) {
            if (\function_exists('w_log_warning')) {
                \w_log_warning('[Phrase] global dictionary DB load failed: ' . $throwable->getMessage(), [
                    'lang' => $lang,
                ], 'phrase');
            }
            return null;
        }

    }

    private static function globalDictionaryProvider(): ?GlobalDictionaryProviderInterface
    {
        if (self::$globalDictionaryProviderInstance instanceof GlobalDictionaryProviderInterface) {
            return self::$globalDictionaryProviderInstance;
        }

        try {
            $provider = ObjectManager::getInstance(\Weline\Framework\Runtime\RuntimeProviderResolver::class)
                ->resolve(GlobalDictionaryProviderInterface::class);
            if ($provider instanceof GlobalDictionaryProviderInterface) {
                self::$globalDictionaryProviderInstance = $provider;
                return $provider;
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * Merge dictionaries without letting untranslated source=same-value rows hide real translations
     * from another module. This matters when generic labels such as "AI翻译" exist in multiple modules.
     */
    private static function mergePreferTranslatedWords(array $base_words, array $candidate_words): array
    {
        foreach ($candidate_words as $word => $translate) {
            if (!is_string($word) || !is_string($translate)) {
                continue;
            }

            if (
                !isset($base_words[$word])
                || $base_words[$word] === $word
                || $translate !== $word
            ) {
                $base_words[$word] = $translate;
            }
        }

        return $base_words;
    }
}
