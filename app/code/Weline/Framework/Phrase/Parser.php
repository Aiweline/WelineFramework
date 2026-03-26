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
use Weline\Framework\Exception\Core;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\StateManager;

class Parser
{

    public static bool $loaded = false;
    public const PARSER_WORDS_CACHE_KEY = 'PARSER_WORDS_CACHE_KEY';
    protected static array $words = [];
    
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
            self::$loaded = false;
            self::$words = [];
            self::$usedWords = [];
            self::$isLoadingWords = false;
            self::$loadedLang = null;
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
        self::getWords();
        // 记录请求生命周期内使用的翻译词（用于按需加载到前端）
        self::$usedWords[$words] = $words;
        
        // 如果有就替换
        if (isset(self::$words[$words])) {
            $words = self::$words[$words];
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
            if (isset(self::$words[$word])) {
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
        // 确保 WLS 状态管理已注册
        self::ensureStateRegistered();
        
        // 防止循环调用：如果正在加载翻译文件，直接返回空数组或已加载的词
        if (self::$isLoadingWords) {
            return self::$words ?? [];
        }
        
        // 获取当前请求的语言
        $currentLang = State::getLangLocal();
        
        // WLS 模式下：检查语言是否变化，如果变化需要重新加载词典
        if (self::$loaded && self::$loadedLang !== null && self::$loadedLang !== $currentLang) {
            self::$loaded = false;
            self::$words = [];
        }
        
        // 仅加载一次翻译到对象self::$words
        if (empty(self::$words) and !self::$loaded) {
            // 设置加载标志，防止循环调用
            self::$isLoadingWords = true;
            
            try {
                // 先访问缓存
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
                $modules_key = !empty($modules) ? '_' . implode('_', $modules) : '';
                $cache_key = 'phrase_locale_words_' . $lang . $modules_key;
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
                    // 从总词典读取（words.php，按模块组织）
                    $all_words_file = Env::path_TRANSLATE_ALL_COLLECTIONS_WORDS_FILE;
                    $all_words = [];
                    
                    if (is_file($all_words_file)) {
                        try {
                            $all_words_data = (array)include $all_words_file;
                            // 新格式：['all_words' => [...], 'locale' => ['Weline_I18n' => [...], ...]]
                            
                            // 先加载全局唯一词（顶层的 all_words）
                            if (isset($all_words_data['all_words']) && is_array($all_words_data['all_words'])) {
                                $all_words = array_merge($all_words, $all_words_data['all_words']);
                            }
                            
                            // 然后加载当前语言的模块词（支持多模块）
                            if (isset($all_words_data[$lang])) {
                                $lang_words = $all_words_data[$lang];
                                
                                // 如果没有指定模块（如编译期），加载所有模块的词
                                if (empty($modules)) {
                                    foreach ($lang_words as $module_name => $module_words_data) {
                                        if (is_array($module_words_data)) {
                                            $all_words = array_merge($all_words, $module_words_data);
                                        }
                                    }
                                } else {
                                    // 遍历所有关联模块，加载它们的词
                                    foreach ($modules as $module_name) {
                                        $full_module_name = self::getFullModuleName($module_name);
                                        // 尝试完整模块名和简短模块名
                                        if (isset($lang_words[$full_module_name]) && is_array($lang_words[$full_module_name])) {
                                            $all_words = array_merge($all_words, $lang_words[$full_module_name]);
                                        } elseif (isset($lang_words[$module_name]) && is_array($lang_words[$module_name])) {
                                            $all_words = array_merge($all_words, $lang_words[$module_name]);
                                        }
                                    }
                                }
                            }
                        } catch (Exception $exception) {
                            // 如果总词典读取失败，尝试从语言文件读取（语言文件不包含 all_words）
                            $words_file = Env::path_TRANSLATE_FILES_PATH . $lang . '.php';
                            if (is_file($words_file)) {
                                try {
                                    $lang_words = (array)include $words_file;
                                    // 语言文件格式：['Weline_I18n' => [...], 'Weline_Cdn' => [...], ...]
                                    if (empty($modules)) {
                                        // 加载所有模块的词
                                        foreach ($lang_words as $module_name => $module_words_data) {
                                            if (is_array($module_words_data)) {
                                                $all_words = array_merge($all_words, $module_words_data);
                                            }
                                        }
                                    } else {
                                        // 遍历所有关联模块，加载它们的词
                                        foreach ($modules as $module_name) {
                                            $full_module_name = self::getFullModuleName($module_name);
                                            if (isset($lang_words[$full_module_name]) && is_array($lang_words[$full_module_name])) {
                                                $all_words = array_merge($all_words, $lang_words[$full_module_name]);
                                            } elseif (isset($lang_words[$module_name]) && is_array($lang_words[$module_name])) {
                                                $all_words = array_merge($all_words, $lang_words[$module_name]);
                                            }
                                        }
                                    }
                                } catch (Exception $e) {
                                    // 静默处理
                                }
                            }
                        }
                    } else {
                        // 如果总词典不存在，从语言文件读取（语言文件不包含 all_words）
                        $words_file = Env::path_TRANSLATE_FILES_PATH . $lang . '.php';
                        if (is_file($words_file)) {
                            try {
                                $lang_words = (array)include $words_file;
                                // 语言文件格式：['Weline_I18n' => [...], 'Weline_Cdn' => [...], ...]
                                if (empty($modules)) {
                                    // 加载所有模块的词
                                    foreach ($lang_words as $module_name => $module_words_data) {
                                        if (is_array($module_words_data)) {
                                            $all_words = array_merge($all_words, $module_words_data);
                                        }
                                    }
                                } else {
                                    // 遍历所有关联模块，加载它们的词
                                    foreach ($modules as $module_name) {
                                        $full_module_name = self::getFullModuleName($module_name);
                                        if (isset($lang_words[$full_module_name]) && is_array($lang_words[$full_module_name])) {
                                            $all_words = array_merge($all_words, $lang_words[$full_module_name]);
                                        } elseif (isset($lang_words[$module_name]) && is_array($lang_words[$module_name])) {
                                            $all_words = array_merge($all_words, $lang_words[$module_name]);
                                        }
                                    }
                                }
                            } catch (Exception $e) {
                                // 静默处理
                            }
                        }
                    }
                    
                    // 合并：模块词典优先，总词典作为补充（模块词典覆盖总词典）
                    // 先加载总词典，再加载模块词典，这样模块词典会覆盖总词典
                    self::$words = array_merge($all_words, $module_words);
                    $phraseCache->set($cache_key, self::$words);
                }
            } finally {
                // 清除加载标志
                self::$isLoadingWords = false;
            }
            self::$loaded = true;
            self::$loadedLang = $currentLang;
        }
        return self::$words ?? [];
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
        $words = [];
        try {
            // 获取模块信息
            $module_info = Env::getInstance()->getModuleInfo($module_name);
            if (!$module_info || !isset($module_info['base_path'])) {
                return $words;
            }
            
            $module_i18n_file = $module_info['base_path'] . '/i18n/' . $lang . '.csv';
            if (!is_file($module_i18n_file)) {
                return $words;
            }
            
            $handle = @fopen($module_i18n_file, 'r');
            if ($handle === false) {
                return $words;
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
                
                $words[$word] = $translate;
            }
            
            fclose($handle);
        } catch (\Exception $e) {
            // 静默处理错误
        }
        
        return $words;
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
}
