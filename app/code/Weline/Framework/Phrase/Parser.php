<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Phrase;

use Weline\Framework\App\Debug;
use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Exception\Core;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

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
                $words = str_replace('%{' . $key . '}', $arg ?? '', $words);
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
        // 防止循环调用：如果正在加载翻译文件，直接返回空数组或已加载的词
        if (self::$isLoadingWords) {
            return self::$words ?? [];
        }
        
        // 仅加载一次翻译到对象self::$words
        if (empty(self::$words) and !self::$loaded) {
            // 设置加载标志，防止循环调用
            self::$isLoadingWords = true;
            
            try {
                // 先访问缓存
                /**@var \Weline\Framework\Cache\CacheInterface $phraseCache */
                $phraseCache = ObjectManager::getInstance(\Weline\Framework\Phrase\Cache\PhraseCache::class . 'Factory');
                // 获取翻译模式（支持 translation.mode 和 i18n.translate_mode）
                $translate_mode = Env::get('translation.mode','default');

                // 获取当前请求的模块名
                $current_module = '';
                try {
                    /**@var Request $request */
                    $request = ObjectManager::getInstance(Request::class);
                    $current_module = $request->getModuleName();
                } catch (\Exception $e) {
                    // 如果无法获取模块名，继续使用总词典
                }

                $lang = Cookie::getLangLocal() ?: Env::default_LANGUAGE_CODE;
                $cache_key = 'phrase_locale_words_' . $lang . ($current_module ? '_' . $current_module : '');
                
                # 非实时翻译
                if ($translate_mode !== 'online' && $phrase_words = $phraseCache->get($cache_key)) {
                    self::$words = $phrase_words;
                } else {
                    // 优先从模块词典读取
                    $module_words = [];
                    if ($current_module) {
                        $module_words = self::loadModuleWords($current_module, $lang);
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
                            
                            // 然后加载当前语言的模块词
                            if (isset($all_words_data[$lang])) {
                                $lang_words = $all_words_data[$lang];
                                
                                // 如果当前模块存在，加载该模块的词
                                if ($current_module) {
                                    $current_full_module = self::getFullModuleName($current_module);
                                    // 尝试完整模块名和简短模块名
                                    if (isset($lang_words[$current_full_module]) && is_array($lang_words[$current_full_module])) {
                                        $all_words = array_merge($all_words, $lang_words[$current_full_module]);
                                    } elseif (isset($lang_words[$current_module]) && is_array($lang_words[$current_module])) {
                                        $all_words = array_merge($all_words, $lang_words[$current_module]);
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
                                    // 如果当前模块存在，加载该模块的词
                                    if ($current_module) {
                                        $current_full_module = self::getFullModuleName($current_module);
                                        if (isset($lang_words[$current_full_module]) && is_array($lang_words[$current_full_module])) {
                                            $all_words = array_merge($all_words, $lang_words[$current_full_module]);
                                        } elseif (isset($lang_words[$current_module]) && is_array($lang_words[$current_module])) {
                                            $all_words = array_merge($all_words, $lang_words[$current_module]);
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
                                // 如果当前模块存在，加载该模块的词
                                if ($current_module) {
                                    $current_full_module = self::getFullModuleName($current_module);
                                    if (isset($lang_words[$current_full_module]) && is_array($lang_words[$current_full_module])) {
                                        $all_words = array_merge($all_words, $lang_words[$current_full_module]);
                                    } elseif (isset($lang_words[$current_module]) && is_array($lang_words[$current_module])) {
                                        $all_words = array_merge($all_words, $lang_words[$current_module]);
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
