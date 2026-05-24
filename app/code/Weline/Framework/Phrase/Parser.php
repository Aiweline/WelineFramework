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
use Weline\Framework\Cache\Contract\RememberOptions;
use Weline\Framework\Exception\Core;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\StateManager;

class Parser
{

    public static bool $loaded = false;
    public const PARSER_WORDS_CACHE_KEY = 'PARSER_WORDS_CACHE_KEY';
    protected static array $words = [];
    protected static array $workerWordsCache = [];
    protected static array $workerLocaleWordsCache = [];
    protected static array $workerModuleWordsCache = [];
    protected static array $workerGlobalDictionaryWordsCache = [];
    protected static array $workerLayeredWordsCache = [];
    protected static array $workerMaterializedWordsCache = [];
    protected static ?string $currentRequestWordsId = null;
    protected static ?string $currentRequestWordsKey = null;
    protected static ?string $currentRequestLayeredWordsId = null;
    protected static ?string $currentRequestLayeredWordsKey = null;
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
            self::$currentRequestWordsId = null;
            self::$currentRequestWordsKey = null;
            self::$currentRequestLayeredWordsId = null;
            self::$currentRequestLayeredWordsKey = null;
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
        if (Runtime::isPersistent()) {
            self::ensureStateRegistered();
            self::$usedWords[$words] = $words;
            $layers = self::getCurrentLayeredWords();
            $translationCacheKey = (string)($layers['cache_key'] ?? '') . '|' . $words;
            if (isset(self::$currentRequestTranslatedWords[$translationCacheKey])) {
                return self::$currentRequestTranslatedWords[$translationCacheKey];
            }
            return self::$currentRequestTranslatedWords[$translationCacheKey] = self::translateWordFromLayers($words, $layers);
        }

        self::getWords();
        // 记录请求生命周期内使用的翻译词（用于按需加载到前端）
        self::$usedWords[$words] = $words;
        
        if (isset(self::$words[$words])) {
            $translated = self::$words[$words];
            if (\is_string($translated) && $translated !== '' && $translated !== $words) {
                $words = $translated;
            }
        } else {
            self::$words[$words] = $words;
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
        foreach (self::$usedWords as $word) {
            // 获取翻译（如果存在）
            if (Runtime::isPersistent()) {
                $result[$word] = self::translateWordFromLayers($word, self::getCurrentLayeredWords());
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
        if (Runtime::isPersistent()) {
            self::ensureStateRegistered();
            $layers = self::getCurrentLayeredWords();
            $cacheKey = (string)($layers['cache_key'] ?? self::buildWordsCacheKey((string)($layers['lang'] ?? State::getLangLocal()), (array)($layers['modules'] ?? [])));
            if (!isset(self::$workerMaterializedWordsCache[$cacheKey])) {
                self::$workerMaterializedWordsCache[$cacheKey] = self::materializeLayeredWords($layers);
            }
            self::$words = self::$workerMaterializedWordsCache[$cacheKey];
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
            && isset(self::$workerWordsCache[$requestCacheKey])
        ) {
            self::$words = self::$workerWordsCache[$requestCacheKey];
            return self::$words;
        }

        if (self::$isLoadingWords) {
            return self::$words ?? [];
        }
        if (isset(self::$workerWordsCache[$requestCacheKey])) {
            self::$words = self::$workerWordsCache[$requestCacheKey];
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
        if (!isset(self::$workerWordsCache[$requestCacheKey])) {
            // 设置加载标志，防止循环调用
            self::$isLoadingWords = true;
            
            try {
                // 先访问缓存
                if (Runtime::isPersistent()) {
                    self::$words = self::buildWordsFromWorkerCache($currentLang, $requestModules);
                    self::$workerWordsCache[$requestCacheKey] = self::$words;
                    self::$loaded = true;
                    self::$loadedLang = $currentLang;
                    self::$currentRequestWordsId = $requestId;
                    self::$currentRequestWordsKey = $requestCacheKey;
                    return self::$words;
                }

                /**@var \Weline\Framework\Cache\CacheInterface $phraseCache */
                $phraseCache = w_cache('phrase');
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
                if ($translate_mode !== 'online' && $phrase_words = $phraseCache->get($cache_key)) {
                    self::$words = $phrase_words;
                } else {
                    // 从所有关联模块的词典读取（支持多模块）
                    $module_words = [];
                    foreach ($modules as $module_name) {
                        $words = self::loadModuleWords($module_name, $lang);
                        // 后加载的模块词典会覆盖先加载的（优先级：后添加的模块 > 先添加的模块）
                        $module_words = array_merge($module_words, $words);
                    }
                    $all_words = self::loadLocaleWords($lang, $modules);
                    
                    // 合并：模块词典优先，总词典作为补充（模块词典覆盖总词典）
                    // 先加载总词典，再加载模块词典，这样模块词典会覆盖总词典
                    self::$words = array_merge($all_words, $module_words);
                    $phraseCache->set($cache_key, self::$words);
                }
                self::$workerWordsCache[$requestCacheKey] = self::$words;
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

    public static function preloadWorkerDictionaries(): void
    {
        self::ensureStateRegistered();

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
        self::$workerLayeredWordsCache = [];
        self::$workerMaterializedWordsCache = [];
        self::$currentRequestWordsId = null;
        self::$currentRequestWordsKey = null;
        self::$currentRequestLayeredWordsId = null;
        self::$currentRequestLayeredWordsKey = null;
        self::$currentRequestTranslatedWords = [];
        self::$loaded = false;
        self::$loadedLang = null;
        self::$isLoadingWords = false;
    }

    private static function buildWordsFromWorkerCache(string $lang, array $modules, bool $includeGlobalDictionary = true): array
    {
        $module_words = [];
        foreach ($modules as $module_name) {
            $module_words = \array_merge($module_words, self::loadModuleWords($module_name, $lang));
        }

        return \array_merge(self::loadLocaleWords($lang, $modules, $includeGlobalDictionary), $module_words);
    }

    private static function getCurrentLayeredWords(): array
    {
        $requestId = Runtime::isPersistent() ? RequestContext::getId() : null;
        if (
            $requestId !== null
            && self::$currentRequestLayeredWordsId === $requestId
            && self::$currentRequestLayeredWordsKey !== null
            && isset(self::$workerLayeredWordsCache[self::$currentRequestLayeredWordsKey])
        ) {
            return self::$workerLayeredWordsCache[self::$currentRequestLayeredWordsKey];
        }

        $lang = State::getLangLocal();
        $modules = self::resolveRequestModules();
        $layeredCacheKey = self::buildLayeredWordsCacheKey($lang, $modules);

        $layers = self::getLayeredWords($lang, $modules);
        self::$currentRequestLayeredWordsId = $requestId;
        self::$currentRequestLayeredWordsKey = $layeredCacheKey;

        return $layers;
    }

    private static function buildLayeredWordsCacheKey(string $lang, array $modules, bool $includeGlobalDictionary = true): string
    {
        return self::buildWordsCacheKey($lang, $modules) . '|' . ($includeGlobalDictionary ? 'db' : 'file');
    }

    private static function getLayeredWords(string $lang, array $modules, bool $includeGlobalDictionary = true): array
    {
        $modules = \array_values(\array_unique(\array_map([self::class, 'getFullModuleName'], \array_filter($modules))));
        $cacheKey = self::buildWordsCacheKey($lang, $modules) . '|' . ($includeGlobalDictionary ? 'db' : 'file');
        if (isset(self::$workerLayeredWordsCache[$cacheKey])) {
            return self::$workerLayeredWordsCache[$cacheKey];
        }

        $moduleLayers = [];
        foreach ($modules as $moduleName) {
            $moduleLayers[$moduleName] = self::loadModuleWords($moduleName, $lang);
        }

        return self::$workerLayeredWordsCache[$cacheKey] = [
            'cache_key' => $cacheKey,
            'lang' => $lang,
            'modules' => $modules,
            'module_words' => $moduleLayers,
            'locale_words' => self::loadLocaleWords($lang, $modules, $includeGlobalDictionary),
            'global_words' => [],
        ];
    }

    private static function translateWordFromLayers(string $word, array $layers): string
    {
        $modules = (array)($layers['modules'] ?? []);
        $moduleWords = (array)($layers['module_words'] ?? []);
        for ($i = \count($modules) - 1; $i >= 0; $i--) {
            $moduleName = $modules[$i];
            if (!isset($moduleWords[$moduleName][$word])) {
                continue;
            }
            $translate = $moduleWords[$moduleName][$word];
            if (\is_string($translate) && $translate !== '' && $translate !== $word) {
                return $translate;
            }
        }

        $localeWords = (array)($layers['locale_words'] ?? []);
        if (isset($localeWords[$word])) {
            $translate = $localeWords[$word];
            if (\is_string($translate) && $translate !== '' && $translate !== $word) {
                return $translate;
            }
        }

        return $word;
    }

    private static function materializeLayeredWords(array $layers): array
    {
        $words = (array)($layers['locale_words'] ?? []);
        foreach ((array)($layers['modules'] ?? []) as $moduleName) {
            $words = \array_merge($words, (array)($layers['module_words'][$moduleName] ?? []));
        }

        return $words;
    }

    private static function resolveRequestModules(): array
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

        return 'phrase_locale_words_' . $lang . '_' . self::getWordsCacheVersion($lang, $modules)
            . (!empty($modules) ? '_' . \implode('_', $modules) : '');
    }

    private static function getWordsCacheVersion(string $lang, array $modules): string
    {
        $parts = [];
        $languageFile = Env::path_TRANSLATE_FILES_PATH . $lang . '.php';
        $parts[] = $languageFile . ':' . self::getFileVersion($languageFile);

        foreach ($modules as $moduleName) {
            try {
                $module = Env::getInstance()->getModuleInfo($moduleName);
                $csvFile = ($module['base_path'] ?? '') . '/i18n/' . $lang . '.csv';
                $parts[] = $csvFile . ':' . self::getFileVersion($csvFile);
            } catch (\Throwable) {
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
     * 从模块的i18n目录加载翻译词
     * 
     * @param string $module_name 模块名
     * @param string $lang 语言代码
     * @return array
     */
    protected static function loadModuleWords(string $module_name, string $lang): array
    {
        $module_name = self::getFullModuleName($module_name);
        $cache_key = $lang . '|' . $module_name . '|unresolved';
        $words = [];
        try {
            // 获取模块信息
            $module_info = Env::getInstance()->getModuleInfo($module_name);
            $module_i18n_file = ($module_info['base_path'] ?? '') . '/i18n/' . $lang . '.csv';
            $cache_key = $lang . '|' . $module_name . '|' . self::getFileVersion($module_i18n_file);
            if (isset(self::$workerModuleWordsCache[$cache_key])) {
                return self::$workerModuleWordsCache[$cache_key];
            }

            if (!$module_info || !isset($module_info['base_path'])) {
                return self::$workerModuleWordsCache[$cache_key] = $words;
            }

            if (!is_file($module_i18n_file)) {
                return self::$workerModuleWordsCache[$cache_key] = $words;
            }
            
            $handle = @fopen($module_i18n_file, 'r');
            if ($handle === false) {
                return self::$workerModuleWordsCache[$cache_key] = $words;
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
            
            fclose($handle);
        } catch (\Exception $e) {
            // 静默处理错误
        }
        
        return self::$workerModuleWordsCache[$cache_key] = $words;
    }
    
    /**
     * 获取完整的模块名（如 Weline_I18n）
     * 
     * @param string $module_name 模块名（如 I18n）
     * @return string 完整模块名（如 Weline_I18n）
     */
    private static function getFullModuleName(string $module_name): string
    {
        // 如果已经是完整格式，直接返回
        if (strpos($module_name, 'Weline_') === 0) {
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
        $cache_key = $lang . '|' . self::getWordsCacheVersion($lang, $modules) . '|' . \implode(',', $modules) . '|' . ($includeGlobalDictionary ? 'db' : 'file');
        if (isset(self::$workerLocaleWordsCache[$cache_key])) {
            return self::$workerLocaleWordsCache[$cache_key];
        }

        $global_dictionary_words = $includeGlobalDictionary ? self::loadGlobalDictionaryWords($lang) : [];
        $words_file = Env::path_TRANSLATE_FILES_PATH . $lang . '.php';
        if (is_file($words_file)) {
            try {
                $lang_words = (array)include $words_file;
                return self::$workerLocaleWordsCache[$cache_key] = self::mergePreferTranslatedWords(
                    $global_dictionary_words,
                    self::extractModuleWords($lang_words, $modules)
                );
            } catch (\Throwable) {
                // 回退到总词典
            }
        }

        $all_words_file = Env::path_TRANSLATE_ALL_COLLECTIONS_WORDS_FILE;
        if (!is_file($all_words_file)) {
            return self::$workerLocaleWordsCache[$cache_key] = $global_dictionary_words;
        }

        try {
            $all_words_data = (array)include $all_words_file;
            $all_words = [];

            if (isset($all_words_data['all_words']) && is_array($all_words_data['all_words'])) {
                $all_words = array_merge($all_words, $all_words_data['all_words']);
            }

            if (isset($all_words_data[$lang]) && is_array($all_words_data[$lang])) {
                $all_words = array_merge($all_words, self::extractModuleWords($all_words_data[$lang], $modules));
            }

            return self::$workerLocaleWordsCache[$cache_key] = self::mergePreferTranslatedWords($global_dictionary_words, $all_words);
        } catch (\Throwable) {
            return self::$workerLocaleWordsCache[$cache_key] = $global_dictionary_words;
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

    /**
     * Load translations from the global locale dictionary as a fallback.
     *
     * AI translations are written here first, so they must be effective without
     * requiring `translation.mode=online` or leaking unrelated module CSV groups.
     */
    private static function loadGlobalDictionaryWords(string $lang): array
    {
        if (isset(self::$workerGlobalDictionaryWordsCache[$lang])) {
            return self::$workerGlobalDictionaryWordsCache[$lang];
        }

        $dictionaryClass = '\\Weline\\I18n\\Model\\Locale\\Dictionary';
        if (!class_exists($dictionaryClass)) {
            return self::$workerGlobalDictionaryWordsCache[$lang] = [];
        }

        $cachePool = self::getSharedPhraseCachePool();
        $cacheKey = 'global_dictionary_words|' . $lang . '|v1';
        if ($cachePool !== null) {
            try {
                $cached = $cachePool->get($cacheKey);
                if (\is_array($cached)) {
                    return self::$workerGlobalDictionaryWordsCache[$lang] = $cached;
                }

                $words = $cachePool->remember(
                    $cacheKey,
                    3600,
                    static fn(): ?array => self::loadGlobalDictionaryWordsFromDatabase($lang, $dictionaryClass),
                    new RememberOptions(
                        nullTtl: 5,
                        jitter: true,
                        jitterRatio: 0.10,
                        singleFlight: true,
                        singleFlightTimeoutMs: 10000
                    )
                );

                return self::$workerGlobalDictionaryWordsCache[$lang] = \is_array($words) ? $words : [];
            } catch (\Throwable) {
                if (Runtime::isPersistent()) {
                    return self::$workerGlobalDictionaryWordsCache[$lang] = [];
                }
                // CLI / non-persistent fallback can still read DB directly.
            }
        }

        if (Runtime::isPersistent()) {
            return self::$workerGlobalDictionaryWordsCache[$lang] = [];
        }

        return self::$workerGlobalDictionaryWordsCache[$lang] =
            self::loadGlobalDictionaryWordsFromDatabase($lang, $dictionaryClass) ?? [];
    }

    private static function getSharedPhraseCachePool(): ?\Weline\Framework\Cache\Contract\CachePoolInterface
    {
        try {
            /** @var CacheManager $cacheManager */
            $cacheManager = ObjectManager::getInstance(CacheManager::class);
            return $cacheManager->pool('phrase');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param class-string $dictionaryClass
     * @return array<string,string>|null null means the DB read failed and should only be cached briefly.
     */
    private static function loadGlobalDictionaryWordsFromDatabase(string $lang, string $dictionaryClass): ?array
    {
        try {
            /** @var object $localeDictionary */
            $localeDictionary = ObjectManager::getInstance($dictionaryClass);
            $rows = $localeDictionary->reset()
                ->where($dictionaryClass::schema_fields_LOCALE_CODE, $lang)
                ->where($dictionaryClass::schema_fields_TRANSLATE, '', '!=')
                ->select()
                ->fetchArray();
        } catch (\Throwable $throwable) {
            if (\function_exists('w_log_warning')) {
                \w_log_warning('[Phrase] global dictionary DB load failed: ' . $throwable->getMessage(), [
                    'lang' => $lang,
                ], 'phrase');
            }
            return null;
        }

        $words = [];
        foreach ($rows as $row) {
            $word = $row[$dictionaryClass::schema_fields_WORD] ?? '';
            $translate = $row[$dictionaryClass::schema_fields_TRANSLATE] ?? '';
            if (is_string($word) && is_string($translate) && $word !== '' && $translate !== '') {
                $words[$word] = $translate;
            }
        }

        return $words;
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
