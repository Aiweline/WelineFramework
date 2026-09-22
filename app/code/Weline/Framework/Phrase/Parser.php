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
use Weline\Framework\Runtime\MemDiag;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\StateManager;

class Parser
{

    private const TRANSLATION_RESOLUTION_DEPTH_CONTEXT_KEY = 'phrase.translation_resolution_depth';
    private const REQUEST_PREFETCHED_GLOBAL_WORDS_CONTEXT_KEY = 'phrase.prefetched_global_words';
    private const REQUEST_STATE_CONTEXT_KEY = 'phrase.parser.request_state';

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
    /** @var array<string, list<array<string, string>>> Immutable incremental dictionaries per generation/locale. */
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
    /** CLI / no-RequestContext fallback only; live HTTP uses RequestContext bag. */
    private static ?ParserRequestState $cliRequestState = null;
    private static int $translationResolutionDepth = 0;

    /**
     * setup:upgrade CLI 轻词典模式：额外跳过重型 locale 文件驻留等路径。
     * 翻译读路径本身已禁止 DB 全局词典 hydrate；本开关仅由 Setup\Upgrade 进程入口开启。
     * clearWorkerCaches() 不得清除本标志。
     */
    private static bool $setupUpgradeLightDictionary = false;
    
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
            self::$translationResolutionDepth = 0;
            self::resetRequestStateBag();
        });
    }

    /**
     * Fiber-safe request bag for layered words / used phrases / loading flags.
     * Process-static copies of these fields race under concurrent WLS Fibers.
     */
    private static function requestState(): ParserRequestState
    {
        if (RequestContext::isInitialized()) {
            $state = RequestContext::get(self::REQUEST_STATE_CONTEXT_KEY);
            if (!$state instanceof ParserRequestState) {
                $state = new ParserRequestState();
                RequestContext::set(self::REQUEST_STATE_CONTEXT_KEY, $state);
            }

            return $state;
        }

        return self::$cliRequestState ??= new ParserRequestState();
    }

    private static function resetRequestStateBag(): void
    {
        if (RequestContext::isInitialized()) {
            $state = RequestContext::get(self::REQUEST_STATE_CONTEXT_KEY);
            if ($state instanceof ParserRequestState) {
                $state->reset();
            }
            RequestContext::remove(self::REQUEST_STATE_CONTEXT_KEY);
        }
        self::$cliRequestState = null;
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
        $requestState = self::requestState();
        if (self::translationResolutionDepth() > 0 || $requestState->isLoadingWords) {
            return $words;
        }

        if (Runtime::isPersistent()) {
            self::ensureStateRegistered();
            $requestState->usedWords[$words] = $words;
            self::enterTranslationResolution();
            try {
                // 请求覆盖词典只读；缺词不收集、不 hydrate。
                $lang = State::getLangLocal();
                $eventTranslation = self::translateFromEventDictionary($words, $lang);
                if ($eventTranslation !== null) {
                    return $eventTranslation;
                }
                if (EventDictionary::isExclusive($lang)) {
                    return $words;
                }

                $layers = RequestLifecycleTrace::measurePhase(
                    'i18n.phrase.layered_words',
                    static fn(): array => self::getCurrentLayeredWords(),
                );
                $translationCacheKey = (string)($layers['cache_key'] ?? '') . '|' . $words;
                $requestWords = &DictionaryCacheNamespace::localCache($requestState->translatedWords, 32768, self::layerLocales($layers));
                if (isset($requestWords[$translationCacheKey]) && \is_string($requestWords[$translationCacheKey])) {
                    return $requestWords[$translationCacheKey];
                }

                $resolved = RequestLifecycleTrace::measurePhase(
                    'i18n.phrase.resolve',
                    static fn(): string => self::translateWordFromLayers($words, $layers),
                );
                if (!\is_string($resolved) || $resolved === '') {
                    $resolved = $words;
                }

                return $requestWords[$translationCacheKey] = $resolved;
            } finally {
                self::leaveTranslationResolution();
            }
        }

        // CLI / FPM：只读文件层 + 进程缓存；缺词原样返回，禁止写回词典 / 收集 / DB hydrate。
        self::getWords();
        $requestState->usedWords[$words] = $words;

        $lang = State::getLangLocal();
        $eventTranslation = self::translateFromEventDictionary($words, $lang);
        if ($eventTranslation !== null) {
            return $eventTranslation;
        }
        if (EventDictionary::isExclusive($lang)) {
            return $words;
        }

        if (isset(self::$words[$words])) {
            $translated = self::$words[$words];
            if (\is_string($translated) && $translated !== '' && $translated !== $words) {
                return $translated;
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
        return self::requestState()->usedWords;
    }
    
    /**
     * 获取请求生命周期内使用的翻译词及其翻译
     * @return array
     */
    public static function getUsedWordsWithTranslations(): array
    {
        $result = [];
        $requestState = self::requestState();
        $layers = Runtime::isPersistent() ? self::getCurrentLayeredWords() : null;
        $layerCacheKey = \is_array($layers) ? (string)($layers['cache_key'] ?? '') : '';
        foreach ($requestState->usedWords as $word) {
            // 获取翻译（如果存在）
            if (Runtime::isPersistent()) {
                $translationCacheKey = $layerCacheKey . '|' . $word;
                if (!\array_key_exists($translationCacheKey, $requestState->translatedWords)) {
                    DictionaryCacheNamespace::localCache($requestState->translatedWords, 32768, self::layerLocales($layers))[$translationCacheKey] = self::translateWordFromLayers(
                        $word,
                        $layers,
                    );
                }
                $result[$word] = DictionaryCacheNamespace::localCache($requestState->translatedWords, 32768, self::layerLocales($layers))[$translationCacheKey];
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
        if (self::translationResolutionDepth() > 0 || self::requestState()->isLoadingWords) {
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
        $locales = self::localeChain(State::getLangLocal());
        // An exclusive request must neither load public dictionaries nor publish
        // its empty public layer into the worker-wide materialized cache.
        if (EventDictionary::isExclusive(State::getLangLocal())) {
            return self::$words = [];
        }

        if (Runtime::isPersistent()) {
            self::ensureStateRegistered();
            $layers = self::getCurrentLayeredWords();
            $cacheKey = (string)($layers['cache_key'] ?? self::buildWordsCacheKey((string)($layers['lang'] ?? State::getLangLocal()), (array)($layers['modules'] ?? [])));
            if (!isset(DictionaryCacheNamespace::localCache(self::$workerLayeredWordsCache, 1024, $locales)[$cacheKey])) {
                // 未确认完整的全局词典不能经物化入口变成进程级空快照。
                return self::$words = self::materializeLayeredWords($layers);
            }
            if (!isset(DictionaryCacheNamespace::localCache(self::$workerMaterializedWordsCache, 1024, $locales)[$cacheKey])) {
                DictionaryCacheNamespace::localCache(self::$workerMaterializedWordsCache, 1024, $locales)[$cacheKey] = self::materializeLayeredWords($layers);
            }
            self::$words = DictionaryCacheNamespace::localCache(self::$workerMaterializedWordsCache, 1024, $locales)[$cacheKey];
            return self::$words;
        }
        // 确保 WLS 状态管理已注册
        self::ensureStateRegistered();
        
        // 防止循环调用：如果正在加载翻译文件，直接返回空数组或已加载的词
        $requestId = Runtime::isPersistent() ? RequestContext::getId() : null;
        $currentLang = State::getLangLocal();
        $requestModules = self::resolveRequestModules();
        $requestCacheKey = self::buildWordsCacheKey($currentLang, $requestModules);
        $requestState = self::requestState();
        if ($requestId !== null
            && $requestState->wordsId === $requestId
            && $requestState->wordsKey === $requestCacheKey
            && isset(DictionaryCacheNamespace::localCache(self::$workerWordsCache, 1024, $locales)[$requestCacheKey])
        ) {
            self::$words = DictionaryCacheNamespace::localCache(self::$workerWordsCache, 1024, $locales)[$requestCacheKey];
            return self::$words;
        }

        if (isset(DictionaryCacheNamespace::localCache(self::$workerWordsCache, 1024, $locales)[$requestCacheKey])) {
            self::$words = DictionaryCacheNamespace::localCache(self::$workerWordsCache, 1024, $locales)[$requestCacheKey];
            self::$loaded = true;
            $requestState->loadedLang = $currentLang;
            $requestState->wordsId = $requestId;
            $requestState->wordsKey = $requestCacheKey;
            return self::$words;
        }
        
        // WLS 模式下：检查语言是否变化，如果变化需要重新加载词典
        if (self::$loaded && $requestState->loadedLang !== null && $requestState->loadedLang !== $currentLang) {
            self::$loaded = false;
            self::$words = [];
        }
        
        // 仅加载一次翻译到对象self::$words
        if (!isset(DictionaryCacheNamespace::localCache(self::$workerWordsCache, 1024, $locales)[$requestCacheKey])) {
            // 设置加载标志，防止循环调用
            $requestState->isLoadingWords = true;
            
            try {
                // 先访问缓存
                if (Runtime::isPersistent()) {
                    self::$words = self::buildWordsFromWorkerCache($currentLang, $requestModules);
                    DictionaryCacheNamespace::localCache(self::$workerWordsCache, 1024, $locales)[$requestCacheKey] = self::$words;
                    self::$loaded = true;
                    $requestState->loadedLang = $currentLang;
                    $requestState->wordsId = $requestId;
                    $requestState->wordsKey = $requestCacheKey;
                    return self::$words;
                }

                /**@var \Weline\Framework\Cache\CacheInterface $phraseCache */
                $phraseCache = self::getSharedPhraseCachePool($locales);
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
                DictionaryCacheNamespace::localCache(self::$workerWordsCache, 1024, $locales)[$requestCacheKey] = self::$words;
                $requestState->wordsId = $requestId;
                $requestState->wordsKey = $requestCacheKey;
            } finally {
                // 清除加载标志
                $requestState->isLoadingWords = false;
            }
            self::$loaded = true;
            $requestState->loadedLang = $currentLang;
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

        // 预热只读文件层；禁止整表 DB 词典 hydrate（__() 读路径同样禁止）。
        foreach ($languages as $lang) {
            self::getLayeredWords($lang, [], null);
            foreach ($modules as $moduleName) {
                self::loadModuleWords($moduleName, $lang);
                self::getLayeredWords($lang, [$moduleName], null);
            }
        }
    }

    public static function setSetupUpgradeLightDictionary(bool $enabled): void
    {
        self::$setupUpgradeLightDictionary = $enabled;
    }

    public static function isSetupUpgradeLightDictionary(): bool
    {
        return self::$setupUpgradeLightDictionary;
    }

    public static function clearWorkerCaches(): void
    {
        DictionaryCacheNamespace::clearProcessMemoryStore();
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
        self::resetRequestStateBag();
        self::$loaded = false;
        self::$translationResolutionDepth = 0;
        // 保留 setupUpgradeLightDictionary：升级进程内清缓存不得重新打开 DB 词典 hydrate。
        self::bindDictionaryProcessBags();
    }

    /** 将 Phrase 进程袋绑定到 DictionaryCacheNamespace，供 locale 桶清理回扫。 */
    private static function bindDictionaryProcessBags(): void
    {
        DictionaryCacheNamespace::bindProcessBag('workerWords', self::$workerWordsCache);
        DictionaryCacheNamespace::bindProcessBag('workerLocaleWords', self::$workerLocaleWordsCache);
        DictionaryCacheNamespace::bindProcessBag('workerModuleWords', self::$workerModuleWordsCache);
        DictionaryCacheNamespace::bindProcessBag('workerGlobalDictionaryWords', self::$workerGlobalDictionaryWordsCache);
        DictionaryCacheNamespace::bindProcessBag('workerGlobalDictionaryLocaleWords', self::$workerGlobalDictionaryLocaleWords);
        DictionaryCacheNamespace::bindProcessBag('workerGlobalDictionaryLoadedModules', self::$workerGlobalDictionaryLoadedModules);
        DictionaryCacheNamespace::bindProcessBag('workerGlobalDictionaryAllLocales', self::$workerGlobalDictionaryAllLocales);
        DictionaryCacheNamespace::bindProcessBag('workerGlobalDictionaryWord', self::$workerGlobalDictionaryWordCache);
        DictionaryCacheNamespace::bindProcessBag('workerLayeredWords', self::$workerLayeredWordsCache);
        DictionaryCacheNamespace::bindProcessBag('workerMaterializedWords', self::$workerMaterializedWordsCache);
        DictionaryCacheNamespace::bindProcessBag('workerTranslatedWords', self::$workerTranslatedWordsCache);
    }

    /**
     * Clear the request-scoped "used phrases" bag without dropping dictionary caches.
     *
     * Long CLI flows (setup:upgrade) accumulate every __() into $usedWords. Embedding that
     * bag into storefront runtime JSON (Frontend head) can explode memory and stall for minutes.
     */
    public static function clearUsedWords(): void
    {
        $state = self::requestState();
        $state->usedWords = [];
        $state->translatedWords = [];
    }

    private static function buildWordsFromWorkerCache(string $lang, array $modules): array
    {
        $module_words = [];
        foreach ($modules as $module_name) {
            $module_words = \array_merge($module_words, self::loadModuleWordsForLocaleChain($module_name, $lang));
        }

        return \array_merge(self::loadLocaleWordsForLocaleChain($lang, $modules), $module_words);
    }

    private static function getCurrentLayeredWords(): array
    {
        $lang = State::getLangLocal();
        $modules = self::resolveRequestModules();
        $locales = self::localeChain($lang);
        // Modules can be appended by widget hooks during the same request, and
        // the locale can be switched by a nested template context. Compare
        // those small request-local inputs before rebuilding the persistent
        // cache key (which may stat() every locale/module dictionary file).
        $normalizedModules = \array_values(\array_unique(\array_map(
            [self::class, 'getFullModuleName'],
            \array_filter($modules),
        )));
        \sort($normalizedModules);
        $requestSignature = $lang . '|' . \implode(',', $normalizedModules) . '|chain:' . \implode(',', $locales);
        $requestId = Runtime::isPersistent() ? RequestContext::getId() : null;
        $requestState = self::requestState();
        if (
            $requestId !== null
            && $requestState->layeredWordsId === $requestId
            && $requestState->layeredWordsSignature === $requestSignature
            && $requestState->layeredWords !== null
        ) {
            return $requestState->layeredWords;
        }

        $layers = self::getLayeredWords($lang, $modules, $locales);
        $requestState->layeredWordsId = $requestId;
        $requestState->layeredWordsKey = (string)($layers['cache_key'] ?? '');
        $requestState->layeredWordsSignature = $requestSignature;
        $requestState->layeredWords = $layers;

        return $layers;
    }

    private static function buildLayeredWordsCacheKey(string $lang, array $modules, ?array $locales = null): string
    {
        $locales ??= self::localeChain($lang);
        $modules = \array_values(\array_unique(\array_map([self::class, 'getFullModuleName'], \array_filter($modules))));
        \sort($modules);

        if (Runtime::isPersistent()) {
            // A running Worker owns this immutable scope until cache epoch
            // invalidation. Keep the first lookup entirely in process memory;
            // file versions are only needed when the module L1 itself misses.
            return DictionaryCacheNamespace::cacheKey('phrase_worker_scope|' . $lang . '|' . \implode(',', $modules)
                . '|chain:' . \implode(',', $locales) . '|file', $locales);
        }

        return self::buildWordsCacheKey($lang, $modules) . '|chain:' . \implode(',', $locales) . '|file';
    }

    private static function getLayeredWords(string $lang, array $modules, ?array $locales = null): array
    {
        $locales ??= self::localeChain($lang);
        $modules = \array_values(\array_unique(\array_map([self::class, 'getFullModuleName'], \array_filter($modules))));
        \sort($modules);
        $cacheKey = self::buildLayeredWordsCacheKey($lang, $modules, $locales);
        if (EventDictionary::isExclusive($lang)) {
            // This request-only empty layer must not replace the global one.
            return [
                'cache_key' => $cacheKey,
                'lang' => $lang,
                'locales' => $locales,
                'modules' => $modules,
                'module_words' => [],
                'locale_words' => [],
                'global_words' => [],
            ];
        }

        if (isset(DictionaryCacheNamespace::localCache(self::$workerLayeredWordsCache, 1024, $locales)[$cacheKey])) {
            return DictionaryCacheNamespace::localCache(self::$workerLayeredWordsCache, 1024, $locales)[$cacheKey];
        }

        $sharedModuleWords = self::prefetchSharedModuleWords($lang, $modules, $locales);
        $moduleLayers = [];
        foreach ($modules as $moduleName) {
            $moduleLayers[$moduleName] = self::loadModuleWordsForLocaleChain($moduleName, $lang, $sharedModuleWords);
        }

        // 仅挂接已驻留的全局模块词表（显式 prefetch）；此处绝不 hydrate DB。
        $localeWordLayers = Runtime::isPersistent()
            ? self::peekResidentModuleDictionaryLayers($lang, $modules, $locales)
            : [];

        $layers = [
            'cache_key' => $cacheKey,
            'lang' => $lang,
            'locales' => $locales,
            'modules' => $modules,
            'module_words' => $moduleLayers,
            'locale_word_layers' => $localeWordLayers,
            'locale_words' => Runtime::isPersistent() ? [] : self::loadLocaleWordsForLocaleChain($lang, $modules),
            'global_words' => [],
        ];
        return DictionaryCacheNamespace::localCache(self::$workerLayeredWordsCache, 1024, $locales)[$cacheKey] = $layers;
    }

    /**
     * 只读 Worker 内已 prefetch 的模块全局词典原子表；未驻留则跳过，不触发 DB。
     *
     * @param list<string> $modules
     * @param list<string> $locales
     * @return list<array<string, string>>
     */
    private static function peekResidentModuleDictionaryLayers(string $lang, array $modules, array $locales): array
    {
        if ($modules === [] || $locales === []) {
            return [];
        }

        $layers = [];
        foreach (\array_reverse($locales) as $candidateLocale) {
            $candidateLocale = (string)$candidateLocale;
            if ($candidateLocale === '') {
                continue;
            }
            $workerPrefix = DictionaryCacheNamespace::cacheKey('module|' . $candidateLocale . '|', [$candidateLocale]);
            $local = &DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryWordsCache, 128, [$candidateLocale]);
            $merged = [];
            $any = false;
            foreach ($modules as $module) {
                $cached = $local[$workerPrefix . \sha1((string)$module)] ?? null;
                if (\is_array($cached)) {
                    $merged = self::mergePreferTranslatedWords($merged, $cached);
                    $any = true;
                }
            }
            if ($any) {
                $layers[] = $merged;
            }
        }

        return $layers;
    }

    private static function translateWordFromLayers(string $word, array $layers): string
    {
        try {
            return self::doTranslateWordFromLayers($word, $layers);
        } catch (\Throwable) {
            return $word;
        }
    }

    private static function doTranslateWordFromLayers(string $word, array $layers): string
    {
        $lang = (string)($layers['lang'] ?? '');
        $locales = self::layerLocales($layers);
        if ($lang !== '') {
            $eventTranslation = self::translateFromEventDictionary($word, $lang);
            if (\is_string($eventTranslation) && $eventTranslation !== '') {
                return $eventTranslation;
            }
            if (EventDictionary::isExclusive($lang)) {
                return $word;
            }
        }

        // Only public dictionary results may be shared across request scopes.
        $workerCacheKey = (string)($layers['cache_key'] ?? '') . '|' . $word;
        // Must read via localCache: when fingerprint() is null, localCache returns an
        // ephemeral empty array. Checking the raw process cache then indexing localCache
        // yields null and violates :string (seen on theme-editor remove-widget JSON).
        $workerWords = &DictionaryCacheNamespace::localCache(self::$workerTranslatedWordsCache, 32768, $locales);
        if (\array_key_exists($workerCacheKey, $workerWords)) {
            $cached = $workerWords[$workerCacheKey];
            if (\is_string($cached)) {
                return $cached;
            }
            unset($workerWords[$workerCacheKey]);
        }

        $loadedTranslation = self::translationFromLoadedLayers($word, $layers);
        if ($loadedTranslation !== null) {
            return self::rememberWorkerTranslatedWord($workerCacheKey, $loadedTranslation, $locales);
        }

        // 只读已装入的文件层 + 显式 prefetchWords 写入的缓存；缺词原样返回，不打 DB。
        foreach ($locales !== [] ? $locales : ($lang !== '' ? [$lang] : []) as $candidateLocale) {
            $prefetched = self::getPrefetchedGlobalWord((string)$candidateLocale, $word);
            if (\is_string($prefetched) && $prefetched !== '' && $prefetched !== $word) {
                return self::rememberWorkerTranslatedWord($workerCacheKey, $prefetched, $locales);
            }
        }

        return self::rememberWorkerTranslatedWord($workerCacheKey, $word, $locales);
    }

    /** Read only already materialized translation layers; never query a provider. */
    private static function translationFromLoadedLayers(string $word, array $layers): ?string
    {
        $lang = (string)($layers['lang'] ?? '');
        $modules = (array)($layers['modules'] ?? []);
        $moduleWords = (array)($layers['module_words'] ?? []);
        for ($i = \count($modules) - 1; $i >= 0; $i--) {
            $translation = $moduleWords[$modules[$i]][$word] ?? null;
            if (!\is_string($translation) || $translation === '') {
                continue;
            }
            if ($translation !== $word) {
                return $translation;
            }
            // Module CSV identity hit: resolve as source and STOP.
            // Falling through would let fallback-locale (en_US) public/locale packs
            // paint Chinese UI with English for keys that only exist as zh identity.
            if (self::isChineseLocaleCode($lang) || !self::sourceContainsCjk($word)) {
                return $word;
            }
            // CJK identity on a non-zh locale is an untranslated placeholder — keep looking.
        }
        $translation = $layers['locale_words'][$word] ?? null;
        if (\is_string($translation) && $translation !== '' && $translation !== $word) {
            return $translation;
        }
        if (
            \is_string($translation)
            && $translation === $word
            && $translation !== ''
            && (self::isChineseLocaleCode($lang) || !self::sourceContainsCjk($word))
        ) {
            return $word;
        }
        $localeLayers = (array)($layers['locale_word_layers'] ?? []);
        for ($i = \count($localeLayers) - 1; $i >= 0; $i--) {
            $translation = $localeLayers[$i][$word] ?? null;
            if (!\is_string($translation) || $translation === '') {
                continue;
            }
            if ($translation !== $word) {
                return $translation;
            }
            if (self::isChineseLocaleCode($lang) || !self::sourceContainsCjk($word)) {
                return $word;
            }
        }
        return null;
    }

    private static function isChineseLocaleCode(string $localeCode): bool
    {
        return \str_starts_with(\strtolower(\str_replace('-', '_', \trim($localeCode))), 'zh');
    }

    private static function sourceContainsCjk(string $text): bool
    {
        return \preg_match('/[\x{4e00}-\x{9fff}]/u', $text) === 1;
    }

    /** Shared template entry: use the same request layers and exact-word L1/L2 as parse(). */
    public static function prefetchTemplateWords(array $words): void
    {
        // The non-persistent parser already materializes its complete file dictionary.
        if (!Runtime::isPersistent() || $words === [] || self::translationResolutionDepth() > 0 || self::requestState()->isLoadingWords) {
            return;
        }
        $locale = State::getLangLocal();
        if ($locale === '' || EventDictionary::isExclusive($locale)) {
            return;
        }
        self::enterTranslationResolution();
        try {
            $publicWords = [];
            foreach ($words as $word) {
                if (\is_string($word) && \trim($word) !== ''
                    && self::translateFromEventDictionary($word, $locale) === null
                ) {
                    $publicWords[] = $word;
                }
            }
            // Match parse(): an overlay-only template never needs public module layers.
            if ($publicWords === []) {
                return;
            }
            $layers = self::getCurrentLayeredWords();
            $unresolved = [];
            foreach ($publicWords as $word) {
                if (self::translationFromLoadedLayers($word, $layers) === null) {
                    $unresolved[] = $word;
                }
            }
            self::prefetchWords($unresolved, $locale);
        } finally {
            self::leaveTranslationResolution();
        }
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

    private static function rememberWorkerTranslatedWord(string $cacheKey, string $translation, array $locales): string
    {
        if (DictionaryCacheNamespace::fingerprint($locales) === null) {
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
        DictionaryCacheNamespace::localCache(self::$workerTranslatedWordsCache, 32768, $locales)[$cacheKey] = $translation;

        return $translation;
    }

    /** Materialize stacked word layers for explicit prefetch / legacy dictionary consumers. */
    private static function materializeLocaleWordLayers(array $layers): array
    {
        $words = [];
        foreach ($layers as $layer) {
            $words = self::mergePreferTranslatedWords($words, $layer);
        }
        return $words;
    }

    private static function materializeLayeredWords(array $layers): array
    {
        $words = self::materializeLocaleWordLayers((array)($layers['locale_word_layers'] ?? []));
        $words = self::mergePreferTranslatedWords($words, (array)($layers['locale_words'] ?? []));
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
        $locales = self::localeChain($lang);
        $modules = \array_values(\array_unique(\array_map([self::class, 'getFullModuleName'], $modules)));
        \sort($modules);

        return DictionaryCacheNamespace::cacheKey('phrase_locale_words_' . $lang . '_' . self::getWordsCacheVersion($lang, $modules)
            . (!empty($modules) ? '_' . \implode('_', $modules) : '') . '|chain:' . \implode(',', $locales), $locales);
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
        $locales = self::localeChain($lang);
        $cacheKey = DictionaryCacheNamespace::cacheKey('locale_chain|' . \implode(',', $locales) . '|' . $moduleName, $locales);
        if (Runtime::isPersistent() && isset(DictionaryCacheNamespace::localCache(self::$workerModuleWordsCache, 2048, $locales)[$cacheKey])) {
            return DictionaryCacheNamespace::localCache(self::$workerModuleWordsCache, 2048, $locales)[$cacheKey];
        }
        // 热路径只加载「目标语言」模块 CSV。
        // 禁止把 en_US 等回退 locale 的模块 CSV 提前合并进 module_words：
        // 否则 translationFromLoadedLayers 会在查目标语文件层前就命中英文，
        // 导致 locale 文件译文被英文盖住（店面印地语/阿语漏译）。
        // __() 缺译原样返回；显式 prefetchWords() 写入的缓存可由 getPrefetchedGlobalWord 只读消费。
        $targetLocale = (string)($locales[0] ?? LocaleFallbackChain::normalize($lang));
        $words = $targetLocale === ''
            ? []
            : self::loadModuleWordsWithSharedCache($moduleName, $targetLocale, $sharedModuleWords);

        return Runtime::isPersistent()
            ? DictionaryCacheNamespace::localCache(self::$workerModuleWordsCache, 2048, $locales)[$cacheKey] = $words
            : $words;
    }

    /**
     * @return array<string, string>
     */
    private static function loadLocaleWordsForLocaleChain(
        string $lang,
        array $modules,
    ): array {
        $words = [];
        $locales = LocaleFallbackChain::candidates($lang, self::websiteDefaultLocale());
        foreach (\array_reverse($locales) as $candidateLocale) {
            // Higher-priority locales merge last and MUST overwrite — including zh identity.
            // Preferring "already-translated" values would let en_US beat zh identity rows.
            $words = \array_merge(
                $words,
                self::loadLocaleWords((string)$candidateLocale, $modules),
            );
        }

        return $words;
    }

    private static function websiteDefaultLocale(): string
    {
        return LocaleFallbackChain::websiteDefaultLocale();
    }

    /** @return list<string> */
    private static function localeChain(string $locale): array
    {
        return LocaleFallbackChain::candidates($locale, self::websiteDefaultLocale());
    }

    /** 已发布层携带自己的有序回退链；不能被后续网站默认语言覆盖。 */
    private static function layerLocales(array $layers): array
    {
        return (array)($layers['locales'] ?? self::localeChain((string)($layers['lang'] ?? State::getLangLocal())));
    }

    /** 将本层尚未命中 L1 的模块词典快照合并为一次共享缓存读取。 */
    private static function prefetchSharedModuleWords(string $lang, array $modules, ?array $locales = null): array
    {
        if (!Runtime::isPersistent()) {
            return [];
        }
        $locales ??= self::localeChain($lang);
        $result = [];
        foreach ($locales as $locale) {
            $keys = [];
            foreach ($modules as $moduleName) {
                if (isset(DictionaryCacheNamespace::localCache(self::$workerModuleWordsCache, 2048, [$locale])[DictionaryCacheNamespace::cacheKey('worker|' . $locale . '|' . $moduleName, [$locale])])) {
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
                    // 模块原加载入口负责 CSV 回退。
                }
            }
            if ($keys === []) {
                continue;
            }
            try {
                $cached = RequestLifecycleTrace::measurePhase(
                    'i18n.phrase.module_cache_get',
                    static fn(): array => self::getSharedPhraseCachePool([$locale])?->getMultiple(\array_keys($keys)) ?? [],
                    ['batch' => true, 'keys' => \count($keys), 'locale' => $locale],
                );
                $keys = \array_replace($keys, \array_intersect_key($cached, $keys));
            } catch (\Throwable) {
                // null 只表示已尝试读取，不标记空词典。
            }
            $result += $keys;
        }
        return $result;
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
        $worker_scope_key = DictionaryCacheNamespace::cacheKey('worker|' . $lang . '|' . $module_name, [$lang]);
        if (Runtime::isPersistent() && isset(DictionaryCacheNamespace::localCache(self::$workerModuleWordsCache, 2048, [$lang])[$worker_scope_key])) {
            return DictionaryCacheNamespace::localCache(self::$workerModuleWordsCache, 2048, [$lang])[$worker_scope_key];
        }

        $cache_key = $lang . '|' . $module_name . '|unresolved';
        $worker_cache_key = Runtime::isPersistent() ? $worker_scope_key : DictionaryCacheNamespace::cacheKey($cache_key, [$lang]);
        try {
            // 获取模块信息
            $module_info = Env::getInstance()->getModuleInfo($module_name);
            $module_i18n_file = ($module_info['base_path'] ?? '') . '/i18n/' . $lang . '.csv';
            $cache_key = $lang . '|' . $module_name . '|' . self::getFileVersion($module_i18n_file);
            $worker_cache_key = Runtime::isPersistent() ? $worker_scope_key : DictionaryCacheNamespace::cacheKey($cache_key, [$lang]);
            if (isset(DictionaryCacheNamespace::localCache(self::$workerModuleWordsCache, 2048, [$lang])[$worker_cache_key])) {
                return DictionaryCacheNamespace::localCache(self::$workerModuleWordsCache, 2048, [$lang])[$worker_cache_key];
            }

            if (!$module_info || !isset($module_info['base_path'])) {
                return DictionaryCacheNamespace::localCache(self::$workerModuleWordsCache, 2048, [$lang])[$worker_cache_key] = [];
            }

            if (!is_file($module_i18n_file)) {
                return DictionaryCacheNamespace::localCache(self::$workerModuleWordsCache, 2048, [$lang])[$worker_cache_key] = [];
            }

            $sharedCacheKey = 'module_dictionary|v1|' . \sha1($cache_key);
            if (Runtime::isPersistent()) {
                try {
                    $cached = \array_key_exists($sharedCacheKey, $sharedModuleWords)
                        ? $sharedModuleWords[$sharedCacheKey]
                        : RequestLifecycleTrace::measurePhase(
                            'i18n.phrase.module_cache_get',
                            static fn(): mixed => self::getSharedPhraseCachePool([$lang])?->get($sharedCacheKey),
                        );
                    if (\is_array($cached)) {
                        return DictionaryCacheNamespace::localCache(self::$workerModuleWordsCache, 2048, [$lang])[$worker_cache_key] = $cached;
                    }
                } catch (\Throwable) {
                    // Shared cache is an optimization only; local CSV is authoritative.
                }
            }

            $words = [];
            $handle = @fopen($module_i18n_file, 'rb');
            if ($handle === false) {
                return DictionaryCacheNamespace::localCache(self::$workerModuleWordsCache, 2048, [$lang])[$worker_cache_key] = [];
            }

            try {
                // Match I18nCsvCodec: skip one file-level UTF-8 BOM so it never becomes part of the first key.
                $bom = fread($handle, 3);
                if ($bom !== "\xEF\xBB\xBF") {
                    rewind($handle);
                }

                while (($data = fgetcsv($handle, 100000, ',', '"', '\\')) !== false) {
                    if (!isset($data[0]) || empty(trim($data[0]))) {
                        continue;
                    }
                    if (!isset($data[1])) {
                        continue;
                    }

                    $word = trim($data[0]);
                    $translate = trim($data[1]);
                    // Drop BOM-polluted cells (same policy as I18nCsvCodec::isGarbledText).
                    if ($word === '' || str_contains($word, "\xEF\xBB\xBF") || str_contains($translate, "\xEF\xBB\xBF")
                        || str_contains($word, "\u{FEFF}") || str_contains($translate, "\u{FEFF}")) {
                        continue;
                    }
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
                        static function () use ($sharedCacheKey, $words, $lang): mixed {
                            return self::getSharedPhraseCachePool([$lang])?->set(
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

            return DictionaryCacheNamespace::localCache(self::$workerModuleWordsCache, 2048, [$lang])[$worker_cache_key] = $words;
        } catch (\Throwable) {
            // 静默处理错误
        }

        return DictionaryCacheNamespace::localCache(self::$workerModuleWordsCache, 2048, [$lang])[$worker_cache_key] = [];
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
     * 仅当语言文件缺失时，才回退到 words.php。WLS 热路径不走本方法（只用模块 CSV）。
     */
    private static function loadLocaleWords(string $lang, array $modules): array
    {
        $modules = \array_values(\array_unique(\array_map([self::class, 'getFullModuleName'], $modules)));
        \sort($modules);

        if (Runtime::isPersistent()) {
            // Worker 翻译层只用 module_words；不在此 materialize 全局文件/DB。
            return [];
        }

        $cache_key = DictionaryCacheNamespace::cacheKey($lang . '|' . self::getWordsCacheVersion($lang, $modules) . '|' . \implode(',', $modules) . '|file', [$lang]);
        if (isset(DictionaryCacheNamespace::localCache(self::$workerLocaleWordsCache, 1024, [$lang])[$cache_key])) {
            self::touchHeavyLocaleResident($lang);
            return DictionaryCacheNamespace::localCache(self::$workerLocaleWordsCache, 1024, [$lang])[$cache_key];
        }

        // setup:upgrade 轻词典：跳过巨型 generated/language 文件 include。
        if (self::shouldSkipHeavyLocaleDictionaryLoad()) {
            return DictionaryCacheNamespace::localCache(self::$workerLocaleWordsCache, 1024, [$lang])[$cache_key] = [];
        }

        $words_file = Env::path_TRANSLATE_FILES_PATH . $lang . '.php';
        if (is_file($words_file)) {
            try {
                // 同代次 L1 已命中时不会到这里；只失效本次将读取的语言文件。
                if (\function_exists('opcache_invalidate')) {
                    @opcache_invalidate($words_file, true);
                }
                $beforeUsed = \memory_get_usage(false);
                $lang_words = (array)include $words_file;
                if (\class_exists(MemDiag::class, false) || \class_exists(MemDiag::class)) {
                    MemDiag::event('phrase_include_locale_php', [
                        'locale' => $lang,
                        'file' => $words_file,
                        'file_bytes' => @\filesize($words_file) ?: 0,
                        'entries' => \count($lang_words),
                        'delta_used' => \memory_get_usage(false) - $beforeUsed,
                        'persistent' => Runtime::isPersistent(),
                    ]);
                }
                $merged = self::extractModuleWords($lang_words, $modules);
                DictionaryCacheNamespace::localCache(self::$workerLocaleWordsCache, 1024, [$lang])[$cache_key] = $merged;
                self::touchHeavyLocaleResident($lang);
                return $merged;
            } catch (\Throwable) {
                // 回退到总词典文件
            }
        }

        $all_words_file = Env::path_TRANSLATE_ALL_COLLECTIONS_WORDS_FILE;
        if (!is_file($all_words_file)) {
            return DictionaryCacheNamespace::localCache(self::$workerLocaleWordsCache, 1024, [$lang])[$cache_key] = [];
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

            $merged = DictionaryCacheNamespace::localCache(self::$workerLocaleWordsCache, 1024, [$lang])[$cache_key] = $all_words;
            self::touchHeavyLocaleResident($lang);
            return $merged;
        } catch (\Throwable) {
            return DictionaryCacheNamespace::localCache(self::$workerLocaleWordsCache, 1024, [$lang])[$cache_key] = [];
        }
    }

    /**
     * Mark a locale's heavy dictionary as recently used; evict oldest beyond resident max.
     * Delegates to DictionaryCacheNamespace ProcessMemoryStore locale buckets.
     *
     * @return list<string> Current resident locales (oldest → newest)
     */
    protected static function touchHeavyLocaleResident(string $lang): array
    {
        self::bindDictionaryProcessBags();
        $before = DictionaryCacheNamespace::localeBucketResidents();
        $residents = DictionaryCacheNamespace::touchLocaleBucket($lang);

        if (\class_exists(MemDiag::class, false) || \class_exists(MemDiag::class)) {
            MemDiag::event('phrase_locale_resident', [
                'locale' => \trim($lang),
                'resident_n' => \count($residents),
                'residents' => $residents,
            ]);
            foreach (\array_values(\array_diff($before, $residents)) as $evicted) {
                MemDiag::event('phrase_locale_evict', [
                    'locale' => $evicted,
                    'resident_n' => \count($residents),
                    'residents' => $residents,
                ]);
            }
        }

        return $residents;
    }

    /** @return list<string> */
    protected static function heavyLocaleResidents(): array
    {
        return DictionaryCacheNamespace::localeBucketResidents();
    }

    protected static function heavyLocaleResidentMax(): int
    {
        return DictionaryCacheNamespace::heavyLocaleResidentMax();
    }

    /** Drop worker L1 buckets belonging to one locale after LRU eviction. */
    protected static function evictHeavyLocaleCaches(string $lang): void
    {
        self::bindDictionaryProcessBags();
        DictionaryCacheNamespace::evictLocaleBucket($lang);
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
        if (self::$setupUpgradeLightDictionary) {
            return true;
        }

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
        if (self::shouldSkipHeavyLocaleDictionaryLoad()) {
            return [];
        }

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
    private static function loadGlobalDictionaryScopeWords(string $lang, array $modules, bool $asLayers = false): array
    {
        if (self::shouldSkipHeavyLocaleDictionaryLoad()) {
            return [];
        }

        // 路由尚未确定模块时只允许逐词回退；空列表的全库语义仅保留给非持久维护入口。
        // 不发布空的模块快照，后续模块加入后仍按原来的语言/模块缓存加载。
        if (Runtime::isPersistent() && $modules === []) {
            return [];
        }

        $scope = $modules === [] ? 'all' : \implode(',', $modules);
        $localeKey = DictionaryCacheNamespace::cacheKey($lang, [$lang]);
        if (DictionaryCacheNamespace::fingerprint([$lang]) === null) {
            $words = self::loadGlobalDictionaryWordsFromDatabase($lang, $modules) ?? [];
            return $asLayers ? ($words === [] ? [] : [$words]) : $words;
        }

        // Request module membership grows while a persistent Worker renders
        // hooks and slots. Keep the global dictionary language-scoped, but only
        // extend it with modules that were not loaded by this Worker yet. This
        // avoids both repeated superset queries and a full-locale array in a
        // long-lived process. An empty module list remains the explicit
        // maintenance/CLI "load all" path.
        if (Runtime::isPersistent()) {
            $hasSnapshot = \array_key_exists($localeKey, DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryLocaleWords, 128, [$lang]));
            if (!$hasSnapshot) {
                unset(
                    DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryLoadedModules, 128, [$lang])[$localeKey],
                    DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryAllLocales, 128, [$lang])[$localeKey],
                );
            }
            $snapshot = DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryLocaleWords, 128, [$lang])[$localeKey] ?? [];
            $loadedModules = $hasSnapshot ? (DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryLoadedModules, 128, [$lang])[$localeKey] ?? []) : [];
            $allLoaded = $hasSnapshot && (DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryAllLocales, 128, [$lang])[$localeKey] ?? false);

            if ($allLoaded) {
                return $asLayers ? $snapshot : self::materializeLocaleWordLayers($snapshot);
            }

            $missingModules = $modules === []
                ? []
                : \array_values(\array_diff($modules, $loadedModules));
            if ($modules !== [] && $missingModules === []) {
                return $asLayers ? $snapshot : self::materializeLocaleWordLayers($snapshot);
            }

            $queryModules = $modules === [] ? [] : $missingModules;
            $queryScope = $queryModules === [] ? 'all' : \implode(',', $queryModules);
        } else {
            $snapshot = [];
            $queryModules = $modules;
            $queryScope = $scope;
        }

        $workerCacheKey = DictionaryCacheNamespace::cacheKey($lang . '|' . $scope, [$lang]);
        if (!Runtime::isPersistent() && isset(DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryWordsCache, 128, [$lang])[$workerCacheKey])) {
            return DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryWordsCache, 128, [$lang])[$workerCacheKey];
        }

        if (self::globalDictionaryProvider() === null) {
            return Runtime::isPersistent()
                ? ($asLayers ? $snapshot : self::materializeLocaleWordLayers($snapshot))
                : DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryWordsCache, 128, [$lang])[$workerCacheKey] = [];
        }

        $cachePool = self::getSharedPhraseCachePool([$lang]);
        $cacheKey = 'global_dictionary_words|' . $lang . '|v2|' . \sha1($queryScope);
        if ($cachePool !== null) {
            try {
                $provider = self::globalDictionaryProvider();
                $moduleMaps = null;
                if ($queryModules !== [] && $provider instanceof ModuleGlobalDictionaryProviderInterface) {
                    $fetchModules = self::withNullSourceGlobalModule($queryModules);
                    if (Runtime::isPersistent()) {
                        $moduleMaps = self::loadGlobalDictionaryModuleMaps($cachePool, $provider, $lang, $fetchModules);
                        $words = $moduleMaps === null ? null : [];
                    } else {
                        $words = self::loadGlobalDictionaryModuleWords($cachePool, $provider, $lang, $queryModules);
                    }
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
                        return $asLayers ? $snapshot : self::materializeLocaleWordLayers($snapshot);
                    }

                    // 共享/数据库读取可能让出执行权；以发布时的最新快照合并其他 Fiber 的增量。
                    $latestSnapshot = DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryLocaleWords, 128, [$lang])[$localeKey] ?? null;
                    $loadedModules = \is_array($latestSnapshot)
                        ? (DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryLoadedModules, 128, [$lang])[$localeKey] ?? [])
                        : [];
                    if (!\is_array($latestSnapshot)) {
                        unset(DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryAllLocales, 128, [$lang])[$localeKey]);
                    }
                    // Only the small list is copied. Published word arrays are immutable,
                    // so older request/module scopes retain their exact precedence cheaply.
                    $snapshot = $latestSnapshot ?? [];
                    if ($moduleMaps !== null) {
                        // 预取只保存原子词表；实际用到模块时才按既有顺序挂载只读引用。
                        // NULL source_module globals first; request modules override.
                        $globalLayer = $moduleMaps[ModuleGlobalDictionaryProviderInterface::NULL_SOURCE_MODULE_KEY] ?? [];
                        if ($globalLayer !== []) {
                            $snapshot[] = $globalLayer;
                        }
                        foreach ($queryModules as $module) {
                            if (($moduleMaps[$module] ?? []) !== []) {
                                $snapshot[] = $moduleMaps[$module];
                            }
                        }
                    } elseif ($words !== []) {
                        $snapshot[] = $words;
                    }
                    DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryLocaleWords, 128, [$lang])[$localeKey] = $snapshot;
                    if ($queryModules === []) {
                        DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryAllLocales, 128, [$lang])[$localeKey] = true;
                    } else {
                        DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryLoadedModules, 128, [$lang])[$localeKey] = \array_values(
                            \array_unique(\array_merge($loadedModules, $queryModules)),
                        );
                        \sort(DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryLoadedModules, 128, [$lang])[$localeKey]);
                    }

                    return $asLayers ? $snapshot : self::materializeLocaleWordLayers($snapshot);
                }

                return DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryWordsCache, 128, [$lang])[$workerCacheKey] =
                    \is_array($words) ? $words : [];
            } catch (\Throwable) {
                if (Runtime::isPersistent()) {
                    return $asLayers ? $snapshot : self::materializeLocaleWordLayers($snapshot);
                }
                // CLI / non-persistent fallback can still read DB directly.
            }
        }

        if (Runtime::isPersistent()) {
            return $asLayers ? $snapshot : self::materializeLocaleWordLayers($snapshot);
        }

        return DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryWordsCache, 128, [$lang])[$workerCacheKey] =
            self::loadGlobalDictionaryWordsFromDatabase($lang, $queryModules) ?? [];
    }

    /** 已加载标记同时区分确认空模块与读取失败，不再增加失败状态袋。 */
    private static function globalDictionaryModulesLoaded(string $lang, array $modules): bool
    {
        if (DictionaryCacheNamespace::fingerprint([$lang]) === null) {
            return true;
        }
        $localeKey = DictionaryCacheNamespace::cacheKey($lang, [$lang]);
        if (!\array_key_exists($localeKey, DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryLocaleWords, 128, [$lang]))) {
            return false;
        }
        return (DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryAllLocales, 128, [$lang])[$localeKey] ?? false)
            || ($modules !== [] && \array_diff($modules, DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryLoadedModules, 128, [$lang])[$localeKey] ?? []) === []);
    }

    private static function globalDictionaryLayersLoaded(string $lang, array $modules, ?array $locales = null): bool
    {
        if (!Runtime::isPersistent() || self::shouldSkipHeavyLocaleDictionaryLoad()
            || self::globalDictionaryProvider() === null
        ) {
            return true;
        }
        foreach (($locales ?? self::localeChain($lang)) as $candidateLocale) {
            if (!self::globalDictionaryModulesLoaded($candidateLocale, $modules)) {
                return false;
            }
        }
        return true;
    }

    /** 批量预取已知依赖的原子词表，不启用模块或改变当前翻译层。 */
    public static function prefetchGlobalDictionaryModules(array $modules): void
    {
        if (!Runtime::isPersistent() || $modules === [] || self::translationResolutionDepth() > 0 || self::requestState()->isLoadingWords) {
            return;
        }
        $modules = \array_values(\array_unique(\array_map(
            static fn(string $module): string => self::getFullModuleName(\trim($module)),
            \array_values(\array_filter($modules, static fn(mixed $module): bool => \is_string($module) && \trim($module) !== '')),
        )));
        if ($modules === []) {
            return;
        }
        \sort($modules);
        $locale = LocaleFallbackChain::normalize(State::getLangLocal());
        if ($locale === '' || EventDictionary::isExclusive($locale)) {
            return;
        }

        self::enterTranslationResolution();
        try {
            $provider = self::globalDictionaryProvider();
            if (!$provider instanceof ModuleGlobalDictionaryProviderInterface) {
                return;
            }
            // 只读已冻结对象；没有对象或局部语言不同，继续使用 Parser 原来的回退链。
            $context = \Weline\Framework\Cache\StorefrontCacheKeyContext::current();
            $locales = $context !== null && LocaleFallbackChain::normalize($context->lang) === $locale
                ? $context->translationLocales
                : self::localeChain($locale);
            if (DictionaryCacheNamespace::fingerprint($locales) === null) {
                return;
            }
            foreach ($locales as $candidateLocale) {
                try {
                    $pool = self::getSharedPhraseCachePool([$candidateLocale]);
                    if ($pool !== null) {
                        self::loadGlobalDictionaryModuleMaps(
                            $pool,
                            $provider,
                            $candidateLocale,
                            self::withNullSourceGlobalModule($modules),
                        );
                    }
                } catch (\Throwable) {
                    // 预取失败不标记模块已加载，正常解析仍能沿原路径重试。
                }
            }
        } finally {
            self::leaveTranslationResolution();
        }
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
        $fetchModules = self::withNullSourceGlobalModule($modules);
        $maps = self::loadGlobalDictionaryModuleMaps($pool, $provider, $lang, $fetchModules);
        if ($maps === null) {
            return null;
        }
        $words = [];
        $globalLayer = $maps[ModuleGlobalDictionaryProviderInterface::NULL_SOURCE_MODULE_KEY] ?? [];
        if ($globalLayer !== []) {
            $words = self::mergePreferTranslatedWords($words, $globalLayer);
        }
        foreach ($modules as $module) {
            $words = self::mergePreferTranslatedWords($words, $maps[$module] ?? []);
        }
        return $words;
    }

    /**
     * Always fetch NULL/empty source_module globals once alongside request modules.
     *
     * @param list<string> $modules
     * @return list<string>
     */
    private static function withNullSourceGlobalModule(array $modules): array
    {
        $modules[] = ModuleGlobalDictionaryProviderInterface::NULL_SOURCE_MODULE_KEY;

        return \array_values(\array_unique($modules));
    }

    /** 获取原子词表并复用进程缓存与共享池，不改变模块激活状态。 */
    private static function loadGlobalDictionaryModuleMaps(
        CachePoolInterface $pool,
        ModuleGlobalDictionaryProviderInterface $provider,
        string $lang,
        array $modules,
    ): ?array {
        $workerPrefix = DictionaryCacheNamespace::cacheKey('module|' . $lang . '|', [$lang]);
        $keys = [];
        $maps = [];
        foreach ($modules as $module) {
            $cached = DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryWordsCache, 128, [$lang])[$workerPrefix . \sha1($module)] ?? null;
            if (\is_array($cached)) {
                $maps[$module] = $cached;
            } else {
                $keys[$module] = 'global_dictionary_module_words|' . $lang . '|v1|' . \sha1($module);
            }
        }
        if ($keys === []) {
            return $maps;
        }
        $shared = $pool->getMultiple(\array_values($keys));
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
        foreach ($maps as $module => $words) {
            // 不跨可能让出执行权的 I/O 保留缓存袋引用；按当前请求已固定的代次发布。
            DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryWordsCache, 128, [$lang])[$workerPrefix . \sha1($module)] = $words;
        }
        return $maps;
    }

    private static function getSharedPhraseCachePool(array $locales): ?\Weline\Framework\Cache\Contract\CachePoolInterface
    {
        if (DictionaryCacheNamespace::fingerprint($locales) === null) {
            return null;
        }
        try {
            if (!self::$sharedPhraseCachePool instanceof CachePoolInterface) {
                $cacheManager = ObjectManager::getInstance(CacheManager::class);
                self::$sharedPhraseCachePool = \Weline\Framework\Cache\Pool\NamespaceScopedCachePool::create(
                    $cacheManager->pool('phrase'), [DictionaryCacheNamespace::NAMESPACE],
                );
            }
            $pool = self::$sharedPhraseCachePool;
            // 第三方旧池的基础接口保持兼容；标准池通过既有可选能力追加当前语言叶子。
            return $pool instanceof \Weline\Framework\Cache\Contract\NamespaceScopedCachePoolInterface
                ? $pool->withNamespaces(DictionaryCacheNamespace::namespacePaths($locales))
                : $pool;
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
            $locales = self::localeChain($locale);
            // 先固定完整回退依赖，再分别复用每个语言的原子词条。
            DictionaryCacheNamespace::fingerprint($locales);
            foreach ($locales as $candidateLocale) {
                $cachePool = self::getSharedPhraseCachePool([$candidateLocale]);
                $versionPrefix = DictionaryCacheNamespace::cacheKey('', [$candidateLocale]);
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
                            // Known misses stay out of worker L1 (shared layer keeps short nullTtl).
                            if (\is_string($translation) && $translation !== '') {
                                DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryWordCache, 32768, [$candidateLocale])[$versionPrefix . $candidateLocale . '|' . $word] = $translation;
                            }
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
                        if (\is_string($translation)) {
                            DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryWordCache, 32768, [$candidateLocale])[$versionPrefix . $candidateLocale . '|' . $word] = $translation;
                        }
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
     * 读取 prefetchWords() / 全局词典路径已写入的 Worker/请求级词条缓存。
     * 仅查缓存，不触发 DB；未预取或已知缺词时返回 null（调用方回退 source）。
     */
    public static function getPrefetchedGlobalWord(string $locale, string $word): ?string
    {
        $locale = \trim($locale);
        $word = \trim($word);
        if ($locale === '' || $word === '') {
            return null;
        }

        $localWords = &DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryWordCache, 32768, [$locale]);
        $prefetchKey = DictionaryCacheNamespace::cacheKey('', [$locale]) . $locale . '|' . $word;
        if (\array_key_exists($prefetchKey, $localWords)) {
            $cached = $localWords[$prefetchKey];

            return \is_string($cached) && $cached !== '' && $cached !== $word ? $cached : null;
        }

        $loadKey = DictionaryCacheNamespace::cacheKey($locale . '|' . $word, [$locale]);
        if (\array_key_exists($loadKey, $localWords)) {
            $cached = $localWords[$loadKey];

            return \is_string($cached) && $cached !== '' && $cached !== $word ? $cached : null;
        }

        $requestWord = null;
        if (self::readRequestPrefetchedWord($locale, $word, $requestWord)) {
            return \is_string($requestWord) && $requestWord !== '' && $requestWord !== $word
                ? $requestWord
                : null;
        }

        return null;
    }

    /**
     * Resolve a single legacy/global dictionary entry.
     *
     * @return string|null|false Translation, known miss, or transient failure.
     */
    private static function loadGlobalDictionaryWord(string $lang, string $word): string|null|false
    {
        $workerCacheKey = DictionaryCacheNamespace::cacheKey($lang . '|' . $word, [$lang]);
        $localWords = &DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryWordCache, 32768, [$lang]);
        if (\array_key_exists($workerCacheKey, $localWords)) {
            $cached = $localWords[$workerCacheKey];
            // Known miss is stored as null; transient miss must not leak via raw-cache vs localCache mismatch.
            return \is_string($cached) || $cached === null ? $cached : null;
        }
        $requestWord = null;
        if (self::readRequestPrefetchedWord($lang, $word, $requestWord)) {
            return $requestWord;
        }

        if (self::globalDictionaryProvider() === null) {
            return null;
        }

        $cachePool = self::getSharedPhraseCachePool([$lang]);
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
                    if (\is_string($translation) && $translation !== '') {
                        DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryWordCache, 32768, [$lang])[$workerCacheKey] = $translation;
                    }
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
        if (\is_string($translation) && $translation !== '') {
            DictionaryCacheNamespace::localCache(self::$workerGlobalDictionaryWordCache, 32768, [$lang])[$workerCacheKey] = $translation;
        }

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
        if (self::shouldSkipHeavyLocaleDictionaryLoad()) {
            return [];
        }

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
