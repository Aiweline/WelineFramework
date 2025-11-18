<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\I18n\Model;

use Symfony\Component\Intl\Countries;
use Symfony\Component\Intl\Locales;
use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\File\Data\File;
use Weline\Framework\System\File\Scan;
use Weline\I18n\Cache\I18NCache;
use Weline\I18n\Config\Reader;
use Weline\I18n\Observer\ParserWordsRegister;
use Weline\I18n\Model\Locale\Dictionary as LocaleDictionary;
use Weline\I18n\Service\TranslationCollector;

class I18n
{

    private static array $local_words = [];
    /**
     * @var Reader
     */
    private Reader $reader;

    public CacheInterface $i18nCache;

    /**
     * I18n 初始函数...
     *
     * @param Reader $reader
     * @param array $data
     */
    public function __construct(
        Reader    $reader,
        I18NCache $i18nCache
    )
    {
        $this->reader = $reader;
        $this->i18nCache = $i18nCache->create();
    }

    /**
     * @DESC          # 返回Local代码
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/6/24 23:01
     * 参数区：
     *
     * @param string $locale_code
     *
     * @return string
     */
    public function getLocalByCode(string $locale_code): string
    {
        if ($data = $this->i18nCache->get($locale_code)) {
            return $data;
        }
        $locales = Locales::getLocales();
        foreach ($locales as $locale) {
            if (strtolower($locale_code) === strtolower($locale)) {
                $this->i18nCache->set($locale_code, $locale);
                return $locale;
            }
        }
        $this->i18nCache->set($locale_code, 'zh_Hans_CN');
        return 'zh_Hans_CN';
    }

    /**
     * @DESC         |获取当地码
     *
     * 参数区：
     *
     * @param string $lang_code
     *
     * @return string[]
     * @throws Exception
     * @throws \ReflectionException
     */
    public function getLocals(string $lang_code = 'zh_Hans_CN'): array
    {
        $cache_key = 'getLocals' . $lang_code;
        if ($data = $this->i18nCache->get($cache_key)) {
            return $data;
        }
        $locals = Locales::getNames($lang_code);
        $this->i18nCache->set($cache_key, $locals);
        return $locals;
    }

    public function getLocaleName(string $locale_code, string $displace_locale_code = 'zh_Hans_CN'): string
    {
        $name = $locale_code;
        if (Locales::exists($locale_code)) {
            $name = Locales::getName($locale_code, $displace_locale_code);
        }
        return $name;
    }

    public function getLocalesWithFlags(int $width = 42, int $height = 0, string $lang_code = 'zh_Hans_CN', bool $installed = true)
    {
        $cache_key = 'getLocalesWithFlags' . $lang_code . $width . $height . (string)$installed;
        if ($data = $this->i18nCache->get($cache_key)) {
            return $data;
        }
        if ($installed) {
            # 排除非启用的语言包
            /**@var Scan $scan */
            $install_packs_path = glob(Env::path_LANGUAGE_PACK . '*' . DS . '*', GLOB_ONLYDIR);
            $install_packs = [];
            foreach ($install_packs_path as $path) {
                $path_arr = explode(DS, $path);
                $install_packs[] = array_pop($path_arr);
            }
        }
        $no_scale = false;
        if ($width == 0 && $height == 0) {
            $no_scale = true;
        }
        $locals = [];
        $lang_locals = $this->getLocals($lang_code);
        foreach (countries() as $code => $country) {
            $country = country($code);
            foreach ($country->getLocales() as $locale) {
                if ($installed && !in_array($locale, $install_packs)) {
                    continue;
                }
                $svg = $country->getFlag();
                $svg_xml = simplexml_load_string($svg);
                $o_width = $svg_xml->attributes()->width ?? 42;
                $o_height = $svg_xml->attributes()->height ?? 32;
                if (!$no_scale) {
                    if ($width === 0) {
                        $scale = intval($o_height) / $height;
                        $width = intval($o_width) / $scale;
                    }
                    if ($height === 0) {
                        $scale = intval($o_width) / $width;
                        $height = intval($o_height) / $scale;
                    }
                }

                $svg_xml->attributes()->width = $width;
                $svg_xml->attributes()->height = $height;
                $svg = $svg_xml->asXML();
                if (isset($lang_locals[$locale])) {
                    $locals[$locale] = ['name' => $lang_locals[$locale], 'flag' => $svg];
                }
            }
        }
        $this->i18nCache->set($cache_key, $locals, 0);
        return $locals;
    }

    public function getLocalesWithFlagsDisplaySelf(string $display_locale_code = 'zh_Hans_CN', int $width = 42, int $height = 0, bool $installed = true)
    {
        $cache_key = 'getLocalesWithFlags' . $width . $height . (string)$installed . $display_locale_code;
        if ($data = $this->i18nCache->get($cache_key)) {
            return $data;
        }
        if ($installed) {
            # 排除非启用的语言包
            /**@var Scan $scan */
            $install_packs_path = glob(Env::path_LANGUAGE_PACK . '*' . DS . '*', GLOB_ONLYDIR);
            $install_packs = [];
            foreach ($install_packs_path as $path) {
                $path_arr = explode(DS, $path);
                $install_packs[] = array_pop($path_arr);
            }
        }
        $no_scale = false;
        if ($width == 0 && $height == 0) {
            $no_scale = true;
        }
        $locals = [];
        $lang_locals = $this->getLocals();
        foreach (countries() as $code => $country) {
            $country = country($code);
            foreach ($country->getLocales() as $locale) {
                if ($installed && !in_array($locale, $install_packs)) {
                    continue;
                }
                $svg = $country->getFlag();
                $svg_xml = simplexml_load_string($svg);
                $o_width = $svg_xml->attributes()->width ?? 42;
                $o_height = $svg_xml->attributes()->height ?? 32;
                if (!$no_scale) {
                    if ($width === 0) {
                        $scale = intval($o_height) / $height;
                        $width = intval($o_width) / $scale;
                    }
                    if ($height === 0) {
                        $scale = intval($o_width) / $width;
                        $height = intval($o_height) / $scale;
                    }
                }

                $svg_xml->attributes()->width = $width;
                $svg_xml->attributes()->height = $height;
                $svg = $svg_xml->asXML();
                if (isset($lang_locals[$locale])) {
                    if ($display_locale_code === $locale) {
                        $name = $this->getLocaleName($locale, $locale);
                    } else {
                        $name = $this->getLocaleName($locale, $display_locale_code) . "({$this->getLocaleName($locale, $locale)})";
                    }
                    $locals[$locale] = ['name' => $name, 'flag' => $svg];
                }
            }
        }
        $this->i18nCache->set($cache_key, $locals, 0);
        return $locals;
    }

    public function getCountryFlagWithLocal(string $local_code = 'zh_Hans_CN', int $width = 42, int $height = 0): array
    {
        $cache_key = 'getCountryFlagWithLocal' . $local_code . $width . $height;
        if ($data = $this->i18nCache->get($cache_key)) {
            if (is_array($data)) {
                return $data;
            }
        }
        $no_scale = false;
        if ($width == 0 && $height == 0) {
            $no_scale = true;
        }
        $lang_locals = $this->getLocals($local_code);
        foreach (countries() as $code => $country) {
            $country = country($code);
            foreach ($country->getLocales() as $locale) {
                if ($locale === $local_code) {
                    $svg = $country->getFlag();
                    $svg_xml = simplexml_load_string($svg);
                    $o_width = $svg_xml->attributes()->width ?? 42;
                    $o_height = $svg_xml->attributes()->height ?? 32;
                    if (!$no_scale) {
                        if ($width === 0) {
                            $scale = intval($o_height) / $height;
                            $width = intval($o_width) / $scale;
                        }
                        if ($height === 0) {
                            $scale = intval($o_width) / $width;
                            $height = intval($o_height) / $scale;
                        }
                    }

                    $svg_xml->attributes()->width = $width;
                    $svg_xml->attributes()->height = $height;
                    $svg = $svg_xml->asXML();
                    $local = ['name' => $lang_locals[$locale], 'flag' => $svg];
                    $this->i18nCache->set($cache_key, $local, 0);
                    return $local;
                }
            }
        }
        $this->i18nCache->set($cache_key, [], 0);
        return [];
    }

    /**
     * @DESC          # 获取国家旗帜
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/12/22 15:52
     * 参数区：
     *
     * @param string $country_code
     * @param int $width
     * @param int $height
     *
     * @return mixed
     */
    public function getCountryFlag(string $country_code = 'CN', int $width = 42, int $height = 0): mixed
    {
        $country = country($country_code);
        $svg = $country->getFlag();
        $svg_xml = simplexml_load_string($svg);
        $o_width = $svg_xml->attributes()->width ?? 42;
        $o_height = $svg_xml->attributes()->height ?? 32;
        $no_scale = false;
        if ($width == 0 && $height == 0) {
            $no_scale = true;
        }
        if (!$no_scale) {
            if ($width === 0) {
                $scale = intval($o_height) / $height;
                $width = intval($o_width) / $scale;
            }
            if ($height === 0) {
                $scale = intval($o_width) / $width;
                $height = intval($o_height) / $scale;
            }
        }

        $svg_xml->attributes()->width = $width;
        $svg_xml->attributes()->height = $height;
        return $svg_xml->asXML();
    }

    public function getCountry(string $country_code = 'CN'): \Rinvex\Country\Country|array
    {
        return country($country_code);
    }

    public function localeExists(string $locale_code): bool
    {
        return Locales::exists($locale_code);
    }

    /**
     * @DESC         |获取所有翻译
     *
     * 参数区：
     *
     * @param bool $cache
     * @param string|null $moduleName 指定模块名，如果为null则收集所有模块
     * @return array
     * @throws Exception
     */
    public function getLocalsWords(bool $cache = true, ?string $moduleName = null): array
    {
        if (self::$local_words and $cache) {
            return self::$local_words;
        }
        $all_locals_words_file = Env::path_TRANSLATE_ALL_COLLECTIONS_WORDS_FILE;
        // 获取翻译模式
        $translate_mode = Env::get('translation.mode', 'default');
        
        if ($cache) {
            if (!file_exists($all_locals_words_file)) {
                touch($all_locals_words_file);
                $text = '<?php return ' . w_var_export([], true) . ';';
                file_put_contents($all_locals_words_file, $text);
            }
            $all_locals_words = (array)(include $all_locals_words_file);
            if (!empty($all_locals_words)) {
                // 在 online 模式下，即使使用缓存，也需要合并数据库翻译
                if ($translate_mode === 'online') {
                    // 合并数据库翻译（见后面的逻辑）
                    // 这里先设置 locals_words，后面会合并数据库翻译
                    $locals_words = $all_locals_words;
                } else {
                    // 非 online 模式，直接返回缓存
                    self::$local_words = $all_locals_words;
                    return $all_locals_words;
                }
            }
        }
        
        // 所有语言
        $locals_names = Locales::getNames();
        // 所有语言对应存在的翻译词（如果使用缓存且是 online 模式，已经在上面的 if 中设置了）
        if (!isset($locals_words)) {
            $locals_words = [];
        }
        $error_count = 0;
        $first_error = true;
        
        // 模块翻译覆盖语言包翻译
        // 用于记录每个词的模块名，用于总词典中标记重复词
        $word_modules = []; // [locale][word] => [modules]
        // 用于记录每个词的翻译来源模块（用于优先使用当前模块的词）
        $word_translate_modules = []; // [locale][word] => module_name
        // 用于记录每个词在每个模块中的翻译（用于生成CSV时每个模块一行）
        $word_module_translations = []; // [locale][word][module] => translate
        $all_i18ns = $this->reader->getAllI18ns();
        foreach ($all_i18ns as $module_name => $i18n_files) {
            // 获取完整的模块名（如 Weline_I18n）
            $full_module_name = $this->getFullModuleName($module_name);
            /**@var $i18n_file File */
            foreach ($i18n_files as $local => $i18n_file) {
                if (isset($locals_names[$local])) {
                    $handle = @fopen($i18n_file, 'r');
                    if ($handle === false) {
                        // 输出表格头（仅第一次）
                        if ($first_error && php_sapi_name() === 'cli') {
                            echo "\n" . str_repeat("=", 80) . "\n";
                            echo "i18n 文件格式问题\n";
                            echo str_repeat("=", 80) . "\n";
                            $first_error = false;
                        }
                        // 使用相对路径，去掉开头的斜杠
                        $relative_path = ltrim(str_replace(BP, '', $i18n_file), '/');
                        if (php_sapi_name() === 'cli') {
                            echo $relative_path . "  【无法打开文件】\n";
                        } else {
                            error_log($relative_path . "  【无法打开文件】");
                        }
                        $error_count++;
                        continue;
                    }
                    $is_utf8 = false;
                    $line = 1;
                    // 使用相对路径，去掉开头的斜杠
                    $relative_path = ltrim(str_replace(BP, '', $i18n_file), '/');
                    
                    while (($data = fgetcsv($handle, 100000, ',', '"', '\\')) !== false) {
                        // 格式错误的行直接跳过，不抛出异常
                        if (!isset($data[0]) || empty(trim($data[0]))) {
                            // 输出表格头（仅第一次）
                            if ($first_error && php_sapi_name() === 'cli') {
                                echo "\n" . str_repeat("=", 80) . "\n";
                                echo "i18n 文件格式问题\n";
                                echo str_repeat("=", 80) . "\n";
                                $first_error = false;
                            }
                            // 记录警告日志
                            if (php_sapi_name() === 'cli') {
                                echo $relative_path . ":" . $line . "  【没有翻译原文】\n";
                            } else {
                                error_log($relative_path . ":" . $line . "  【没有翻译原文】");
                            }
                            $error_count++;
                            $line += 1;
                            continue;
                        }
                        
                        $data[0] = trim($data[0]);
                        
                        if (!isset($data[1])) {
                            // 输出表格头（仅第一次）
                            if ($first_error && php_sapi_name() === 'cli') {
                                echo "\n" . str_repeat("=", 80) . "\n";
                                echo "i18n 文件格式问题\n";
                                echo str_repeat("=", 80) . "\n";
                                $first_error = false;
                            }
                            // 记录警告日志，跳过该行
                            if (php_sapi_name() === 'cli') {
                                echo $relative_path . ":" . $line . "  【没有翻译内容】\n";
                            } else {
                                error_log($relative_path . ":" . $line . "  【没有翻译内容】");
                            }
                            $error_count++;
                            $line += 1;
                            continue;
                        }
                        
                        $data[1] = trim($data[1]);
                        // 第三列是模块名（可选），如果存在则使用，否则使用当前完整模块名
                        $word_module = isset($data[2]) && !empty(trim($data[2])) ? trim($data[2]) : $full_module_name;
                        
                        if (!$is_utf8) {
                            if (md5(mb_convert_encoding($data[0], 'utf-8', 'utf-8')) === md5($data[0])) {
                                $is_utf8 = true;
                            } else {
                                // 输出表格头（仅第一次）
                                if ($first_error && php_sapi_name() === 'cli') {
                                    echo "\n" . str_repeat("=", 80) . "\n";
                                    echo "i18n 文件格式问题\n";
                                    echo str_repeat("=", 80) . "\n";
                                    $first_error = false;
                                }
                                // 记录警告日志，跳过该行
                                if (php_sapi_name() === 'cli') {
                                    echo $relative_path . ":" . $line . "  【编码不是UTF-8】\n";
                                } else {
                                    error_log($relative_path . ":" . $line . "  【编码不是UTF-8】");
                                }
                                $error_count++;
                                $line += 1;
                                continue;
                            }
                        }
                        
                        // 记录词的模块信息（使用完整模块名）
                        if (!isset($word_modules[$local])) {
                            $word_modules[$local] = [];
                        }
                        if (!isset($word_modules[$local][$data[0]])) {
                            $word_modules[$local][$data[0]] = [];
                        }
                        if (!in_array($word_module, $word_modules[$local][$data[0]])) {
                            $word_modules[$local][$data[0]][] = $word_module;
                        }
                        
                        // 记录每个词在每个模块中的翻译（用于生成CSV时每个模块一行）
                        if (!isset($word_module_translations[$local])) {
                            $word_module_translations[$local] = [];
                        }
                        if (!isset($word_module_translations[$local][$data[0]])) {
                            $word_module_translations[$local][$data[0]] = [];
                        }
                        $word_module_translations[$local][$data[0]][$word_module] = $data[1];
                        
                        // 存储翻译（当前模块优先：如果词已存在，优先使用当前模块的词）
                        // 如果词不存在，直接添加
                        // 如果词已存在，检查是否来自当前模块，如果是则覆盖（确保当前模块的词优先）
                        if (!isset($locals_words[$local][$data[0]])) {
                            // 词不存在，直接添加
                            $locals_words[$local][$data[0]] = $data[1];
                            // 记录翻译来源模块
                            if (!isset($word_translate_modules[$local])) {
                                $word_translate_modules[$local] = [];
                            }
                            $word_translate_modules[$local][$data[0]] = $word_module;
                        } else {
                            // 词已存在，检查是否来自当前模块
                            // 如果当前模块在模块列表中，使用当前模块的词（覆盖）
                            if (in_array($word_module, $word_modules[$local][$data[0]])) {
                                $locals_words[$local][$data[0]] = $data[1];
                                // 更新翻译来源模块
                                if (!isset($word_translate_modules[$local])) {
                                    $word_translate_modules[$local] = [];
                                }
                                $word_translate_modules[$local][$data[0]] = $word_module;
                            }
                            // 如果当前模块不在模块列表中，保留原有翻译（不覆盖）
                        }
                        $line += 1;
                    }

                    fclose($handle);
                } else {
                    // 输出表格头（仅第一次）
                    if ($first_error && php_sapi_name() === 'cli') {
                        echo "\n" . str_repeat("=", 80) . "\n";
                        echo "i18n 文件格式问题\n";
                        echo str_repeat("=", 80) . "\n";
                        $first_error = false;
                    }
                    // locale代码无效，记录警告并跳过
                    // 使用相对路径，去掉开头的斜杠
                    $relative_path = ltrim(str_replace(BP, '', $i18n_file), '/');
                    if (php_sapi_name() === 'cli') {
                        echo $relative_path . "  【语言代码 " . $local . " 无效】\n";
                    } else {
                        error_log($relative_path . "  【语言代码 " . $local . " 无效】");
                    }
                    $error_count++;
                }
            }
        }
        
        // 输出统计信息
        if ($error_count > 0 && php_sapi_name() === 'cli') {
            echo str_repeat("=", 80) . "\n";
            echo "共发现 " . $error_count . " 个问题\n";
            echo str_repeat("=", 80) . "\n\n";
        }
        
        # 收集项目下的所有被__()函数包裹的翻译词
        # --1 检索目录
        // 定义要搜索的目录
//        $directories = [
//            BP . 'app',
//            BP . 'vendor',
//        ];
        $directories = [];
        Env::getInstance()->getActiveModules();
        foreach (Env::getInstance()->getActiveModules() as $module) {
            // 如果指定了模块名，只收集该模块
            if ($moduleName !== null && $module['name'] !== $moduleName) {
                continue;
            }
            $directories[$module['name']] = $module['base_path'];
        }
        // 初始化翻译词数组
        $translations = [];
        // 使用统一的翻译收集服务
        $collector = ObjectManager::getInstance(TranslationCollector::class);
        
        // 遍历目录
        foreach ($directories as $module => $directory) {
            // 获取完整的模块名（如 Weline_I18n）
            $full_module_name = $this->getFullModuleName($module);
            
            // 使用统一的翻译收集服务收集模块的翻译字符串
            $collectedStrings = $collector->collect($directory, $module);
            
            $module_words = [];
            foreach ($collectedStrings as $original => $info) {
                $translations[$original] = $original;
                $module_words[$original] = $original;
                // 注意：收集到的词还没有翻译，不需要为所有语言创建记录
                // 模块信息会在读取CSV文件时记录（CSV文件中才有实际的翻译）
            }
            // 遍历模组i8n目录中的csv翻译文件
            $i18n_dir = $directory . '/i18n';
            if (is_dir($i18n_dir)) {
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($i18n_dir));
                foreach ($iterator as $file) {
                    // 如果是CSV文件
                    if ($file->isFile() && $file->getExtension() === 'csv') {
                        // 读取csv文件内容，如果翻译词不存在翻译文件中则添加到文件中，
                        //文件形式：第一列为翻译词，第二列为翻译
                        $file_words = [];
                        $handle = @fopen($file->getPathname(), 'r');
                        if ($handle !== false) {
                            while (($data = fgetcsv($handle, 100000, ',', '"', '\\')) !== false) {
                                if(isset($data[0]) && isset($data[1])){
                                    $file_words[$data[0]] = $data[1];
                                }
                            }
                            fclose($handle);
                        }

                        // 将翻译词写入csv翻译文件
                        $file_translations = array_merge($module_words, $file_words);
                        $file_translations = array_unique($file_translations);
                        // 将翻译词写入csv文件
                        $csv_file = @fopen($file->getPathname(), 'w+');
                        if ($csv_file !== false) {
                            foreach ($file_translations as $key => $value) {
                                fputcsv($csv_file, [$key, $value], ',', '"', '\\');
                            }
                            fclose($csv_file);
                        }
                    }
                }
            }
        }
        if ($translations or isset($locals_words[Env::default_LANGUAGE_CODE])) {
            $default_local_words = array_merge($translations, $locals_words[Env::default_LANGUAGE_CODE]);
            $default_local_file = Env::path_TRANSLATE_ALL_COLLECTIONS_WORDS_FILE;
            
            // 确保目录存在
            $dir = dirname($default_local_file);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            
            $file = @fopen($default_local_file, 'w+');
            if ($file === false) {
                error_log(__("警告：无法创建翻译文件 %{file}", ['file' => $default_local_file]));
            } else {
                $text = '<?php return ' . var_export($default_local_words, true) . ';';
                fwrite($file, $text);
                fclose($file);
            }
        }
        if ($translations and isset($locals_words[Env::default_LANGUAGE_CODE])) {
            $locals_words[Env::default_LANGUAGE_CODE] = array_merge($translations, $locals_words[Env::default_LANGUAGE_CODE]);
        }
        
        // 在线翻译模式：从数据库读取翻译并合并到CSV翻译中
        $translate_mode = Env::get('translation.mode', 'default');
        if ($translate_mode === 'online') {
            try {
                /**@var LocaleDictionary $localeDictionary */
                $localeDictionary = ObjectManager::getInstance(LocaleDictionary::class);
                foreach ($locals_names as $local_code => $local_name) {
                    if (!isset($locals_words[$local_code])) {
                        $locals_words[$local_code] = [];
                    }
                    // 从数据库读取该语言的翻译
                    $db_translations = $localeDictionary->reset()
                        ->where(LocaleDictionary::fields_LOCALE_CODE, $local_code)
                        ->select()
                        ->fetchArray();
                    // 合并数据库翻译（数据库翻译优先级高于CSV）
                    foreach ($db_translations as $db_trans) {
                        $word = $db_trans[LocaleDictionary::fields_WORD] ?? '';
                        $translate = $db_trans[LocaleDictionary::fields_TRANSLATE] ?? '';
                        if ($word && $translate) {
                            $locals_words[$local_code][$word] = $translate;
                        }
                    }
                }
            } catch (\Exception $e) {
                // 数据库读取失败时静默处理，继续使用CSV翻译
                // 注意：这里不能使用 __() 函数，因为可能会在翻译加载过程中触发循环调用
                error_log("在线翻译模式：从数据库读取翻译失败：" . $e->getMessage());
            }
        }
        
        if ($locals_words) {
            // 确保目录存在
            $words_file = Env::path_TRANSLATE_ALL_COLLECTIONS_WORDS_FILE;
            $dir = dirname($words_file);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            
            // 生成总词典，按模块组织，提升性能
            // 格式：['all_words' => [...], 'locale' => ['Weline_I18n' => [...], 'Weline_Cdn' => [...]]]
            $words_by_module = [
                'all_words' => []  // 全局唯一词（所有语言共享，没有模块标记的词）
            ];
            $all_words_global = [];  // 收集所有语言的唯一词
            
            foreach ($locals_words as $locale => $words) {
                $words_by_module[$locale] = [];
                
                // 按模块组织词
                foreach ($words as $word => $translate) {
                    // 过滤掉代码片段
                    if (!self::isValidTranslationString($word)) {
                        continue;
                    }
                    
                    $modules = $word_modules[$locale][$word] ?? [];
                    
                    if (count($modules) > 1) {
                        // 多个模块重复，为每个模块单独记录
                        foreach ($modules as $module_name) {
                            if (!isset($words_by_module[$locale][$module_name])) {
                                $words_by_module[$locale][$module_name] = [];
                            }
                            // 获取该模块的翻译
                            $module_translate = $word_module_translations[$locale][$word][$module_name] ?? $translate;
                            $words_by_module[$locale][$module_name][$word] = $module_translate;
                        }
                    } elseif (count($modules) === 1) {
                        // 只有一个模块，记录到该模块下
                        $module_name = $modules[0];
                        if (!isset($words_by_module[$locale][$module_name])) {
                            $words_by_module[$locale][$module_name] = [];
                        }
                        $words_by_module[$locale][$module_name][$word] = $translate;
                    } else {
                        // 没有模块信息，记录到全局 all_words（唯一词）
                        // 使用第一个语言的翻译作为全局翻译（通常所有语言的唯一词翻译相同）
                        if (!isset($all_words_global[$word])) {
                            $all_words_global[$word] = $translate;
                        }
                    }
                }
            }
            
            // 将全局唯一词放到顶层
            $words_by_module['all_words'] = $all_words_global;
            
            $text = '<?php return ' . w_var_export($words_by_module, true) . ';';
            $result = @file_put_contents($words_file, $text);
            if ($result === false) {
                error_log(__("警告：无法写入翻译文件 %{file}", ['file' => $words_file]));
            }
            
            // 同时生成每个语言的PHP文件（按模块组织，不包含 all_words）
            foreach ($words_by_module as $locale => $module_words_data) {
                // 跳过顶层的 all_words
                if ($locale === 'all_words') {
                    continue;
                }
                
                $words_filename = Env::path_TRANSLATE_FILES_PATH . $locale . '.php';
                // 确保目录存在
                $words_dir = dirname($words_filename);
                if (!is_dir($words_dir)) {
                    mkdir($words_dir, 0755, true);
                }
                $file = new \Weline\Framework\System\File\Io\File();
                $file->open($words_filename, $file::mode_w);
                $text = '<?php return ' . var_export($module_words_data, true) . ';?>';

                try {
                    $file->write($text);
                } catch (Exception $e) {
                    error_log(__("警告：无法写入语言文件 %{file}", ['file' => $words_filename]));
                }
                $file->close();
            }
            
            // 同时生成CSV格式的总库文件（每个语言一个文件）
            // 格式：原文,译文,模块名
            // 如果词在多个模块中重复，每个模块单独一行
            foreach ($words_by_module as $locale => $module_words_data) {
                // 跳过顶层的 all_words
                if ($locale === 'all_words') {
                    continue;
                }
                
                $csv_file_path = dirname($words_file) . DS . $locale . '_total.csv';
                $csv_handle = @fopen($csv_file_path, 'w+');
                if ($csv_handle !== false) {
                    // 写入CSV文件，格式：原文,译文,模块名
                    // 先写入全局唯一词（all_words）
                    if (isset($words_by_module['all_words'])) {
                        foreach ($words_by_module['all_words'] as $word => $translate) {
                            // CSV格式：原文,译文,模块名（唯一词模块名为空）
                            fputcsv($csv_handle, [$word, $translate, ''], ',', '"', '\\');
                        }
                    }
                    // 再写入各模块的词
                    foreach ($module_words_data as $module_name => $words) {
                        foreach ($words as $word => $translate) {
                            // CSV格式：原文,译文,模块名
                            fputcsv($csv_handle, [$word, $translate, $module_name], ',', '"', '\\');
                        }
                    }
                    fclose($csv_handle);
                }
            }
        }
        self::$local_words = $locals_words;
        return $locals_words;
    }

    /**
     * @DESC         |默认汉语
     *
     * 参数区：
     *
     * @param string $local_code
     *
     * @return array
     * @throws Exception
     */
    public function getLocalWords(string $local_code = 'zh_Hans_CN'): array
    {
        $words = [];
        if (isset($this->getLocalsWords()[$local_code])) {
            $words = (array)($this->getLocalsWords()[$local_code]);
        } elseif (isset($this->getLocalsWords()['zh_Hans_CN'])) {
            $words = (array)($this->getLocalsWords()['zh_Hans_CN']);
        }
        return $words;
    }

    /**
     * @DESC         |将翻译词组写入翻译文件
     *
     * 参数区：
     *
     * @throws Exception
     */
    public function convertToLanguageFile(bool $cache = true, ?string $moduleName = null): void
    {
        // 调用 getLocalsWords 会生成总词典和语言文件，这里只需要确保生成即可
        // getLocalsWords 已经会生成按模块组织的结构
        $this->getLocalsWords($cache, $moduleName);
        
        // 如果需要单独生成语言文件，可以从总词典读取并转换格式
        // 但通常 getLocalsWords 已经处理了，这里主要是为了兼容性
    }

    /**
     * @DESC          # 获取所有收集词
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/12/29 21:49
     * 参数区：
     * @return array
     * @throws \ReflectionException
     * @throws \Weline\Framework\App\Exception
     */
    function getCollectedWords(): array
    {
        return ObjectManager::getInstance(ParserWordsRegister::class)->getWords();
    }
    
    /**
     * 获取完整的模块名（如 Weline_I18n）
     * 
     * @param string $module_name 模块名（如 I18n）
     * @return string 完整模块名（如 Weline_I18n）
     */
    private function getFullModuleName(string $module_name): string
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
     * 验证是否是有效的翻译字符串
     * 过滤掉代码片段、变量、函数调用等
     * 
     * @param string $str
     * @return bool
     * @deprecated 使用 TranslationCollector::isValidTranslationString() 代替
     */
    private static function isValidTranslationString(string $str): bool
    {
        // 使用统一的收集服务进行验证
        $collector = ObjectManager::getInstance(TranslationCollector::class);
        return $collector->isValidTranslationString($str);
    }

    /**
     * @DESC          # 获取国家
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/12/22 14:38
     * 参数区：
     */
    public function getCountries(string $display_local_code = 'zh_Hans_CN'): array
    {
        return Countries::getNames($display_local_code);
    }

    /**
     * @DESC          # 获取安装模型
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/7/4 23:41
     * 参数区：
     * @return \Weline\I18n\Model\Locals
     */
    public function getActiveLocalsModel(string $target_local = 'zh_Hans_CN'): Locals
    {
        $cache_key = __FUNCTION__.'_'.$target_local;
        $locals = $this->i18nCache->get($cache_key);
        if ($locals) {
            return $locals;
        }
        /**@var Locals $LocalsModel */
        $LocalsModel = ObjectManager::getInstance(Locals::class)->where('target_code', $target_local);
        $this->i18nCache->set($cache_key,$LocalsModel);
        return $LocalsModel;
    }
}

