<?php

namespace Weline\I18n\Model;

use Symfony\Component\Intl\Countries;
use Symfony\Component\Intl\Locales;
use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\File\Data\File;
use Weline\I18n\Cache\I18nCache;
use Weline\I18n\Config\Reader;
use Weline\I18n\Observer\ParserWordsRegister;
use Weline\I18n\Model\Locale\Dictionary as LocaleDictionary;
use Weline\I18n\Service\TranslationCollector;

class I18n
{
    private static array $local_words = [];
    private Reader $reader;
    public CacheInterface $i18nCache;

    public function __construct(
        Reader    $reader,
        I18nCache $i18nCache
    ) {
        $this->reader = $reader;
        $this->i18nCache = $i18nCache->create();
    }

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

    public function getLocals(string $lang_code = 'zh_Hans_CN'): array
    {
        // 未安装 intl 时 Symfony Polyfill 仅支持 en，传 zh_Hans_CN 会抛错，降级为 en
        if (!extension_loaded('intl')) {
            $lang_code = 'en';
        }
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
        if (!extension_loaded('intl')) {
            $displace_locale_code = 'en';
        }
        $name = $locale_code;
        if (Locales::exists($locale_code)) {
            $name = Locales::getName($locale_code, $displace_locale_code);
        }
        return $name;
    }

    public function getLocalesWithFlags(int $width = 24, int $height = 18, string $lang_code = 'zh_Hans_CN', bool $installed = true)
    {
        if (!extension_loaded('intl')) {
            $lang_code = 'en';
        }
        $cache_key = 'getLocalesWithFlags' . $lang_code . $width . $height . (string)$installed;
        if ($data = $this->i18nCache->get($cache_key)) {
            return $data;
        }
        
        $install_packs = [];
        if ($installed) {
            $install_packs_path = glob(Env::path_LANGUAGE_PACK . '*' . DS . '*', GLOB_ONLYDIR);
            foreach ($install_packs_path as $path) {
                $path_arr = explode(DS, $path);
                $install_packs[] = array_pop($path_arr);
            }
        }

        $locals = [];
        $lang_locals = $this->getLocals($lang_code);
        $allLocales = Locales::getLocales();
        
        foreach ($allLocales as $locale) {
            if ($installed && !in_array($locale, $install_packs)) {
                continue;
            }
            if (!isset($lang_locals[$locale])) {
                continue;
            }

            $countryCode = $this->getCountryCodeFromLocale($locale);
            if (!$countryCode) continue;

            $svg = $this->getCountryFlag($countryCode, $width, $height);
            if ($svg) {
                $locals[$locale] = ['name' => $lang_locals[$locale], 'flag' => $svg];
            }
        }
        
        $this->i18nCache->set($cache_key, $locals, 0);
        return $locals;
    }

    public function getLocalesWithFlagsDisplaySelf(string $display_locale_code = 'zh_Hans_CN', int $width = 24, int $height = 18, bool $installed = true, bool $autoSize = false)
    {
        $default_width = 24;
        $default_height = 18;
        
        // 如果width或height为0，使用默认值
        if ($width <= 0) $width = $default_width;
        if ($height <= 0) $height = $default_height;
        
        $cache_key = 'getLocalesWithFlagsDisplaySelf' . $width . $height . (string)$installed . (string)$autoSize . $display_locale_code;
        if ($data = $this->i18nCache->get($cache_key)) {
            return $data;
        }

        $install_packs = [];
        if ($installed) {
            $install_packs_path = glob(Env::path_LANGUAGE_PACK . '*' . DS . '*', GLOB_ONLYDIR);
            foreach ($install_packs_path as $path) {
                $path_arr = explode(DS, $path);
                $install_packs[] = array_pop($path_arr);
            }
        }

        $locals = [];
        $lang_locals = $this->getLocals();
        $allLocales = Locales::getLocales();
        
        // 收集所有需要获取的国家代码
        $countryCodes = [];
        $localeToCountryMap = [];
        foreach ($allLocales as $locale) {
            if ($installed && !in_array($locale, $install_packs)) {
                continue;
            }
            if (!isset($lang_locals[$locale])) {
                continue;
            }

            $countryCode = $this->getCountryCodeFromLocale($locale);
            if (!$countryCode) continue;
            
            $countryCodes[] = $countryCode;
            $localeToCountryMap[$locale] = $countryCode;
        }
        
        // 批量获取国旗SVG
        $flags = $this->getCountryFlagsBatch(array_unique($countryCodes), $width, $height, $autoSize);

        // 组装结果
        foreach ($allLocales as $locale) {
            if ($installed && !in_array($locale, $install_packs)) {
                continue;
            }
            if (!isset($lang_locals[$locale])) {
                continue;
            }

            $countryCode = $localeToCountryMap[$locale] ?? null;
            if (!$countryCode) continue;

            $svg = $flags[$countryCode] ?? '';
            if ($svg) {
                if ($display_locale_code === $locale) {
                    $name = $this->getLocaleName($locale, $locale);
                } else {
                    $name = $this->getLocaleName($locale, $display_locale_code) . "({$this->getLocaleName($locale, $locale)})";
                }
                $locals[$locale] = ['name' => $name, 'flag' => $svg];
            }
        }
        $this->i18nCache->set($cache_key, $locals, 0);
        return $locals;
    }

    public function getCountryFlagWithLocal(string $local_code = 'zh_Hans_CN', int $width = 24, int $height = 18): array
    {
        $cache_key = 'getCountryFlagWithLocal' . $local_code . $width . $height;
        if ($data = $this->i18nCache->get($cache_key)) {
            if (is_array($data)) {
                return $data;
            }
        }

        $lang_locals = $this->getLocals($local_code);
        $countryCode = $this->getCountryCodeFromLocale($local_code);
        
        if ($countryCode) {
            $svg = $this->getCountryFlag($countryCode, $width, $height);
            if ($svg) {
                $local = ['name' => $lang_locals[$local_code] ?? $local_code, 'flag' => $svg];
                $this->i18nCache->set($cache_key, $local, 0);
                return $local;
            }
        }

        $this->i18nCache->set($cache_key, [], 0);
        return [];
    }

    /**
     * 批量获取多个国家的国旗SVG
     * 
     * @param array $country_codes 国家代码数组
     * @param int $width 宽度，0表示使用默认值
     * @param int $height 高度，0表示使用默认值
     * @param bool $autoSize 是否自适应
     * @return array 返回 ['country_code' => 'svg_content'] 格式的数组
     */
    public function getCountryFlagsBatch(array $country_codes, int $width = 24, int $height = 18, bool $autoSize = false): array
    {
        $default_width = 24;
        $default_height = 18;
        
        // 如果width或height为0，使用默认值
        if ($width <= 0) $width = $default_width;
        if ($height <= 0) $height = $default_height;
        
        $results = [];
        $cache_prefix = 'flag_' . $width . '_' . $height . '_' . ($autoSize ? 'auto' : 'fixed') . '_';
        
        // 批量检查缓存
        $uncached_codes = [];
        foreach ($country_codes as $code) {
            $cache_key = $cache_prefix . strtolower($code);
            $cached = $this->i18nCache->get($cache_key);
            if ($cached !== false && $cached !== null) {
                $results[$code] = $cached;
            } else {
                $uncached_codes[] = $code;
            }
        }
        
        // 批量处理未缓存的
        if (!empty($uncached_codes)) {
            foreach ($uncached_codes as $code) {
                $flag = $this->getCountryFlag($code, $width, $height, $autoSize);
                $results[$code] = $flag;
                // 缓存结果
                $cache_key = $cache_prefix . strtolower($code);
                $this->i18nCache->set($cache_key, $flag, 3600);
            }
        }
        
        return $results;
    }

    public function getCountryFlag(string $country_code = 'CN', int $width = 24, int $height = 18, bool $autoSize = false): string
    {
        $default_width = 24;
        $default_height = 18;
        
        // 如果width或height为0，使用默认值
        if ($width <= 0) $width = $default_width;
        if ($height <= 0) $height = $default_height;
        
        $country_code = strtolower($country_code);
        $cache_key = 'flag_' . $country_code . '_' . $width . '_' . $height . '_' . ($autoSize ? 'auto' : 'fixed');
        
        // 检查缓存
        if ($cached = $this->i18nCache->get($cache_key)) {
            return $cached;
        }
        
        $flag_path = BP . 'vendor' . DS . 'lipis' . DS . 'flag-icons' . DS . 'flags' . DS . '4x3' . DS . $country_code . '.svg';
        
        // 从本地文件获取
        if (!file_exists($flag_path)) {
            return '';
        }

        $svg = @file_get_contents($flag_path);
        if (!$svg) {
            return '';
        }

        $svg_xml = @simplexml_load_string($svg);
        if (!$svg_xml) {
            // 如果无法解析为XML，直接返回原始SVG
            return $svg;
        }

        $o_width = (float)($svg_xml->attributes()->width ?? 0);
        $o_height = (float)($svg_xml->attributes()->height ?? 0);

        if ($autoSize) {
            // 自适应模式：直接修改XML字符串，移除固定尺寸，添加样式使其自适应容器
            $svg = $svg_xml->asXML();
            // 先移除可能存在的style属性
            $svg = preg_replace('/\s+style\s*=\s*["\'][^"\']*["\']/i', '', $svg);
            // 移除width和height属性（处理单引号和双引号，以及可能的空格）
            $svg = preg_replace('/\s+width\s*=\s*["\'][^"\']*["\']/i', '', $svg);
            $svg = preg_replace('/\s+height\s*=\s*["\'][^"\']*["\']/i', '', $svg);
            // 在<svg标签中添加完整的style属性
            $styleAttr = 'style="width: auto; height: 1.2em; max-height: 20px; vertical-align: middle; display: inline-block;"';
            $svg = preg_replace('/(<svg)([^>]*)(>)/i', '$1$2 ' . $styleAttr . '$3', $svg, 1);
            // 缓存结果
            $this->i18nCache->set($cache_key, $svg, 3600);
            return $svg;
        } else {
            // 固定尺寸模式：按照指定的宽高调整，移除style属性
            // 直接修改XML字符串，确保属性正确设置
            $svg = $svg_xml->asXML();
            
            // 计算实际要设置的宽高
            $final_width = $width;
            $final_height = $height;
            
            // 如果原始SVG没有width/height，但有viewBox，从viewBox计算比例
            if ($o_width <= 0 || $o_height <= 0) {
                // 尝试从viewBox获取尺寸
                $viewBox = (string)($svg_xml->attributes()->viewBox ?? '');
                if ($viewBox && preg_match('/\s+(\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)\s*$/', $viewBox, $matches)) {
                    $o_width = (float)$matches[1];
                    $o_height = (float)$matches[2];
                }
            }
            
            // 根据参数计算最终尺寸
            if ($width > 0 && $height > 0) {
                // 两个参数都有值，直接使用
                $final_width = $width;
                $final_height = $height;
            } elseif ($width > 0 && $o_width > 0 && $o_height > 0) {
                // 只有width，按比例计算height
                $scale = $width / $o_width;
                $final_width = $width;
                $final_height = (int)($o_height * $scale);
            } elseif ($height > 0 && $o_width > 0 && $o_height > 0) {
                // 只有height，按比例计算width
                $scale = $height / $o_height;
                $final_width = (int)($o_width * $scale);
                $final_height = $height;
            }
            
            // 移除可能存在的style属性
            $svg = preg_replace('/\s+style\s*=\s*["\'][^"\']*["\']/i', '', $svg);
            // 移除可能存在的width和height属性
            $svg = preg_replace('/\s+width\s*=\s*["\'][^"\']*["\']/i', '', $svg);
            $svg = preg_replace('/\s+height\s*=\s*["\'][^"\']*["\']/i', '', $svg);
            
            // 添加width和height属性
            if ($final_width > 0 && $final_height > 0) {
                $sizeAttr = 'width="' . $final_width . '" height="' . $final_height . '"';
                $svg = preg_replace('/(<svg)([^>]*)(>)/i', '$1$2 ' . $sizeAttr . '$3', $svg, 1);
            } elseif ($final_width > 0) {
                $svg = preg_replace('/(<svg)([^>]*)(>)/i', '$1$2 width="' . $final_width . '"$3', $svg, 1);
            } elseif ($final_height > 0) {
                $svg = preg_replace('/(<svg)([^>]*)(>)/i', '$1$2 height="' . $final_height . '"$3', $svg, 1);
            }
            
            // 缓存结果
            $this->i18nCache->set($cache_key, $svg, 3600);
            return $svg;
        }
    }

    public function getCountry(string $country_code = 'CN'): array
    {
        if (!Countries::exists($country_code)) {
            return [];
        }

        return [
            'code' => $country_code,
            'name' => Countries::getName($country_code),
            'locales' => $this->getLocalesForCountry($country_code)
        ];
    }

    private function getLocalesForCountry(string $countryCode): array
    {
        $locales = Locales::getLocales();
        $countryLocales = [];
        $countryCode = strtoupper($countryCode);
        
        foreach ($locales as $locale) {
            if (str_ends_with($locale, '_' . $countryCode)) {
                $countryLocales[] = $locale;
            }
        }
        return $countryLocales;
    }

    private function getCountryCodeFromLocale(string $locale): ?string
    {
        $parts = explode('_', $locale);
        $lastPart = end($parts);
        if (strlen($lastPart) === 2 && strtoupper($lastPart) === $lastPart) {
            return $lastPart;
        }
        return null;
    }

    public function localeExists(string $locale_code): bool
    {
        return Locales::exists($locale_code);
    }

    public function getLocalsWords(bool $cache = true, ?string $moduleName = null): array
    {
        if (self::$local_words and $cache) {
            return self::$local_words;
        }
        $all_locals_words_file = Env::path_TRANSLATE_ALL_COLLECTIONS_WORDS_FILE;
        $translate_mode = Env::get('translation.mode', 'default');
        
        if ($cache) {
            if (!file_exists($all_locals_words_file)) {
                touch($all_locals_words_file);
                $text = '<?php return ' . w_var_export([], true) . ';';
                file_put_contents($all_locals_words_file, $text);
            }
            $all_locals_words = (array)(include $all_locals_words_file);
            if (!empty($all_locals_words)) {
                if ($translate_mode === 'online') {
                    $locals_words = $all_locals_words;
                } else {
                    self::$local_words = $all_locals_words;
                    return $all_locals_words;
                }
            }
        }
        
        $locals_names = extension_loaded('intl') ? Locales::getNames() : Locales::getNames('en');
        if (!isset($locals_words)) {
            $locals_words = [];
        }
        $error_count = 0;
        $first_error = true;
        
        $word_modules = [];
        $word_translate_modules = [];
        $word_module_translations = [];
        $all_i18ns = $this->reader->getAllI18ns();
        foreach ($all_i18ns as $module_name => $i18n_files) {
            $full_module_name = $this->getFullModuleName($module_name);
            foreach ($i18n_files as $local => $i18n_file) {
                if (isset($locals_names[$local])) {
                    $this->ensureLocaleInstalled($local);
                    
                    $handle = @fopen($i18n_file, 'r');
                    if ($handle === false) {
                        if ($first_error && php_sapi_name() === 'cli') {
                            echo "\n" . str_repeat("=", 80) . "\n";
                            echo "i18n 文件格式问题\n";
                            echo str_repeat("=", 80) . "\n";
                            $first_error = false;
                        }
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
                    $relative_path = ltrim(str_replace(BP, '', $i18n_file), '/');
                    
                    while (($data = fgetcsv($handle, 100000, ',', '"', '\\')) !== false) {
                        if (!isset($data[0]) || empty(trim($data[0]))) {
                            if ($first_error && php_sapi_name() === 'cli') {
                                echo "\n" . str_repeat("=", 80) . "\n";
                                echo "i18n 文件格式问题\n";
                                echo str_repeat("=", 80) . "\n";
                                $first_error = false;
                            }
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
                            if ($first_error && php_sapi_name() === 'cli') {
                                echo "\n" . str_repeat("=", 80) . "\n";
                                echo "i18n 文件格式问题\n";
                                echo str_repeat("=", 80) . "\n";
                                $first_error = false;
                            }
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
                        $word_module = isset($data[2]) && !empty(trim($data[2])) ? trim($data[2]) : $full_module_name;
                        
                        if (!$is_utf8) {
                            if (md5(mb_convert_encoding($data[0], 'utf-8', 'utf-8')) === md5($data[0])) {
                                $is_utf8 = true;
                            } else {
                                if ($first_error && php_sapi_name() === 'cli') {
                                    echo "\n" . str_repeat("=", 80) . "\n";
                                    echo "i18n 文件格式问题\n";
                                    echo str_repeat("=", 80) . "\n";
                                    $first_error = false;
                                }
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
                        
                        if (!isset($word_modules[$local])) {
                            $word_modules[$local] = [];
                        }
                        if (!isset($word_modules[$local][$data[0]])) {
                            $word_modules[$local][$data[0]] = [];
                        }
                        if (!in_array($word_module, $word_modules[$local][$data[0]])) {
                            $word_modules[$local][$data[0]][] = $word_module;
                        }
                        
                        if (!isset($word_module_translations[$local])) {
                            $word_module_translations[$local] = [];
                        }
                        if (!isset($word_module_translations[$local][$data[0]])) {
                            $word_module_translations[$local][$data[0]] = [];
                        }
                        $word_module_translations[$local][$data[0]][$word_module] = $data[1];
                        
                        if (!isset($locals_words[$local][$data[0]])) {
                            $locals_words[$local][$data[0]] = $data[1];
                            if (!isset($word_translate_modules[$local])) {
                                $word_translate_modules[$local] = [];
                            }
                            $word_translate_modules[$local][$data[0]] = $word_module;
                        } else {
                            if (in_array($word_module, $word_modules[$local][$data[0]])) {
                                $locals_words[$local][$data[0]] = $data[1];
                                if (!isset($word_translate_modules[$local])) {
                                    $word_translate_modules[$local] = [];
                                }
                                $word_translate_modules[$local][$data[0]] = $word_module;
                            }
                        }
                        $line += 1;
                    }

                    fclose($handle);
                } else {
                    if ($first_error && php_sapi_name() === 'cli') {
                        echo "\n" . str_repeat("=", 80) . "\n";
                        echo "i18n 文件格式问题\n";
                        echo str_repeat("=", 80) . "\n";
                        $first_error = false;
                    }
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
        
        if ($error_count > 0 && php_sapi_name() === 'cli') {
            echo str_repeat("=", 80) . "\n";
            echo "共发现 " . $error_count . " 个问题\n";
            echo str_repeat("=", 80) . "\n\n";
        }
        
        $directories = [];
        foreach (Env::getInstance()->getActiveModules() as $module) {
            if ($moduleName !== null && $module['name'] !== $moduleName) {
                continue;
            }
            $directories[$module['name']] = $module['base_path'];
        }
        $translations = [];
        $collector = ObjectManager::getInstance(TranslationCollector::class);
        
        foreach ($directories as $module => $directory) {
            $full_module_name = $this->getFullModuleName($module);
            
            // 使用 collectLazy() 惰性生成器，逐条消费翻译字符串，避免内存中累积完整数组
            $module_words = [];
            foreach ($collector->collectLazy($directory, $module) as $original => $info) {
                $translations[$original] = $original;
                $module_words[$original] = $original;
            }
            
            $i18n_dir = $directory . '/i18n';
            if (is_dir($i18n_dir)) {
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($i18n_dir));
                foreach ($iterator as $file) {
                    if ($file->isFile() && $file->getExtension() === 'csv') {
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

                        $file_translations = array_merge($module_words, $file_words);
                        unset($file_words); // 释放中间变量
                        $file_translations = array_unique($file_translations);
                        $csv_file = @fopen($file->getPathname(), 'w+');
                        if ($csv_file !== false) {
                            foreach ($file_translations as $key => $value) {
                                fputcsv($csv_file, [$key, $value], ',', '"', '\\');
                            }
                            fclose($csv_file);
                        }
                        unset($file_translations);
                    }
                }
            }
            unset($module_words); // 每个模块处理完后释放
        }
        // 翻译数据量大，后续多处 var_export 需要较多内存；在此统一提升，方法结束时恢复
        $_prevMemLimit = ini_get('memory_limit');
        ini_set('memory_limit', '512M');
        
        if ($translations or isset($locals_words[Env::default_LANGUAGE_CODE])) {
            $default_local_words = array_merge($translations, $locals_words[Env::default_LANGUAGE_CODE] ?? []);
            $default_local_file = Env::path_TRANSLATE_ALL_COLLECTIONS_WORDS_FILE;
            
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
                unset($text);
            }
            unset($default_local_words);
        }
        if ($translations and isset($locals_words[Env::default_LANGUAGE_CODE])) {
            $locals_words[Env::default_LANGUAGE_CODE] = array_merge($translations, $locals_words[Env::default_LANGUAGE_CODE]);
        }
        unset($translations); // 释放 $translations，后面不再需要
        
        $translate_mode = Env::get('translation.mode', 'default');
        if ($translate_mode === 'online') {
            try {
                $localeDictionary = ObjectManager::getInstance(LocaleDictionary::class);
                foreach ($locals_names as $local_code => $local_name) {
                    if (!isset($locals_words[$local_code])) {
                        $locals_words[$local_code] = [];
                    }
                    $db_translations = $localeDictionary->reset()
                        ->where(LocaleDictionary::fields_LOCALE_CODE, $local_code)
                        ->select()
                        ->fetchArray();
                    foreach ($db_translations as $db_trans) {
                        $word = $db_trans[LocaleDictionary::fields_WORD] ?? '';
                        $translate = $db_trans[LocaleDictionary::fields_TRANSLATE] ?? '';
                        if ($word && $translate) {
                            $locals_words[$local_code][$word] = $translate;
                        }
                    }
                }
            } catch (\Exception $e) {
                error_log("在线翻译模式：从数据库读取翻译失败：" . $e->getMessage());
            }
        }
        
        if ($locals_words) {
            $words_file = Env::path_TRANSLATE_ALL_COLLECTIONS_WORDS_FILE;
            $dir = dirname($words_file);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            
            $words_by_module = [
                'all_words' => []
            ];
            $all_words_global = [];
            
            // 在循环外创建一次 collector 实例，避免每个 word 都重新创建
            $collector = ObjectManager::getInstance(TranslationCollector::class);
            
            foreach ($locals_words as $locale => $words) {
                $words_by_module[$locale] = [];
                foreach ($words as $word => $translate) {
                    if (!$collector->isValidTranslationString($word)) {
                        continue;
                    }
                    
                    $modules = $word_modules[$locale][$word] ?? [];
                    if (count($modules) > 1) {
                        foreach ($modules as $module_name) {
                            if (!isset($words_by_module[$locale][$module_name])) {
                                $words_by_module[$locale][$module_name] = [];
                            }
                            $module_translate = $word_module_translations[$locale][$word][$module_name] ?? $translate;
                            $words_by_module[$locale][$module_name][$word] = $module_translate;
                        }
                    } elseif (count($modules) === 1) {
                        $module_name = $modules[0];
                        if (!isset($words_by_module[$locale][$module_name])) {
                            $words_by_module[$locale][$module_name] = [];
                        }
                        $words_by_module[$locale][$module_name][$word] = $translate;
                    } else {
                        if (!isset($all_words_global[$word])) {
                            $all_words_global[$word] = $translate;
                        }
                    }
                }
            }
            
            $words_by_module['all_words'] = $all_words_global;
            unset($all_words_global); // 释放中间变量
            
            // 使用 var_export 替代 w_var_export，避免正则处理导致的额外内存开销
            $text = '<?php return ' . var_export($words_by_module, true) . ';';
            $result = @file_put_contents($words_file, $text);
            unset($text); // 及时释放导出字符串
            if ($result === false) {
                error_log(__("警告：无法写入翻译文件 %{file}", ['file' => $words_file]));
            }
            
            foreach ($words_by_module as $locale => $module_words_data) {
                if ($locale === 'all_words') {
                    continue;
                }
                
                $words_filename = Env::path_TRANSLATE_FILES_PATH . $locale . '.php';
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
                unset($text);
            }
            
            foreach ($words_by_module as $locale => $module_words_data) {
                if ($locale === 'all_words') {
                    continue;
                }
                
                $csv_file_path = dirname($words_file) . DS . $locale . '_total.csv';
                $csv_handle = @fopen($csv_file_path, 'w+');
                if ($csv_handle !== false) {
                    if (isset($words_by_module['all_words'])) {
                        foreach ($words_by_module['all_words'] as $word => $translate) {
                            fputcsv($csv_handle, [$word, $translate, ''], ',', '"', '\\');
                        }
                    }
                    foreach ($module_words_data as $module_name => $words) {
                        foreach ($words as $word => $translate) {
                            fputcsv($csv_handle, [$word, $translate, $module_name], ',', '"', '\\');
                        }
                    }
                    fclose($csv_handle);
                }
            }
        }
        self::$local_words = $locals_words;
        // 恢复原始内存限制（确保不低于当前内存使用量）
        $restoreLimit = $_prevMemLimit ?: '128M';
        $currentUsage = memory_get_usage(true);
        $restoreLimitBytes = $this->parseMemoryLimit($restoreLimit);
        if ($restoreLimitBytes > 0 && $restoreLimitBytes < $currentUsage) {
            // 原始限制低于当前使用量，保持 512M 或设为当前使用量的 1.5 倍
            $restoreLimit = max($restoreLimitBytes, (int)($currentUsage * 1.5));
            $restoreLimit = ceil($restoreLimit / 1024 / 1024) . 'M';
        }
        @ini_set('memory_limit', $restoreLimit);
        return $locals_words;
    }

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

    public function convertToLanguageFile(bool $cache = true, ?string $moduleName = null): void
    {
        $this->getLocalsWords($cache, $moduleName);
    }

    public function getCollectedWords(): array
    {
        return ObjectManager::getInstance(ParserWordsRegister::class)->getWords();
    }
    
    private function getFullModuleName(string $module_name): string
    {
        if (str_starts_with($module_name, 'Weline_')) {
            return $module_name;
        }
        try {
            $module_info = Env::getInstance()->getModuleInfo($module_name);
            if ($module_info && isset($module_info['name'])) {
                return $module_info['name'];
            }
        } catch (\Exception $e) {}
        return 'Weline_' . $module_name;
    }

    private function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        if ($limit === '-1') {
            return -1;
        }
        $value = (int)$limit;
        $unit = strtoupper(substr($limit, -1));
        switch ($unit) {
            case 'G':
                $value *= 1024 * 1024 * 1024;
                break;
            case 'M':
                $value *= 1024 * 1024;
                break;
            case 'K':
                $value *= 1024;
                break;
        }
        return $value;
    }

    public function getCountries(string $display_local_code = 'zh_Hans_CN'): array
    {
        return Countries::getNames($display_local_code);
    }

    public function getActiveLocalsModel(string $target_local = 'zh_Hans_CN'): Locals
    {
        $cache_key = __FUNCTION__.'_'.$target_local;
        $locals = $this->i18nCache->get($cache_key);
        if ($locals) {
            return $locals;
        }
        $LocalsModel = ObjectManager::getInstance(Locals::class)->where('target_code', $target_local);
        $this->i18nCache->set($cache_key,$LocalsModel);
        return $LocalsModel;
    }

    public function ensureLocaleInstalled(string $localeCode): void
    {
        static $installedLocales = [];
        if (isset($installedLocales[$localeCode])) {
            return;
        }
        $installedLocales[$localeCode] = true;

        try {
            $countryCode = $this->getCountryCodeFromLocale($localeCode);
            if ($countryCode && Countries::exists($countryCode)) {
                $countriesModel = ObjectManager::getInstance(\Weline\I18n\Model\Countries::class);
                $country = $countriesModel->reset()
                    ->where(\Weline\I18n\Model\Countries::fields_CODE, $countryCode)
                    ->find()
                    ->fetch();
                
                if (!$country->getId()) {
                    $flag = (string)$this->getCountryFlag($countryCode);
                    $countriesModel->reset()
                        ->setData([
                            \Weline\I18n\Model\Countries::fields_CODE => $countryCode,
                            \Weline\I18n\Model\Countries::fields_FLAG => $flag,
                            \Weline\I18n\Model\Countries::fields_IS_INSTALL => 1,
                            \Weline\I18n\Model\Countries::fields_IS_ACTIVE => 1,
                        ])
                        ->save();
                    if (php_sapi_name() === 'cli') {
                        echo "  [+] 自动注册并激活国家: {$countryCode}\n";
                    }
                } else {
                    $needUpdate = false;
                    if (!$country->getData(\Weline\I18n\Model\Countries::fields_IS_INSTALL)) {
                        $country->setData(\Weline\I18n\Model\Countries::fields_IS_INSTALL, 1);
                        $needUpdate = true;
                    }
                    if (!$country->getData(\Weline\I18n\Model\Countries::fields_IS_ACTIVE)) {
                        $country->setData(\Weline\I18n\Model\Countries::fields_IS_ACTIVE, 1);
                        $needUpdate = true;
                    }
                    if ($needUpdate) {
                        $country->save();
                        if (php_sapi_name() === 'cli') {
                            echo "  [*] 启用并激活国家: {$countryCode}\n";
                        }
                    }
                }
            }

            $localeModel = ObjectManager::getInstance(Locale::class);
            $locale = $localeModel->reset()
                ->where(Locale::fields_CODE, $localeCode)
                ->find()
                ->fetch();
            
            if (!$locale->getId()) {
                $flag = '';
                if ($countryCode) {
                    $flag = (string)$this->getCountryFlag($countryCode);
                }
                $localeModel->reset()
                    ->setData([
                        Locale::fields_CODE => $localeCode,
                        Locale::fields_COUNTRY_CODE => $countryCode,
                        Locale::fields_FLAG => $flag,
                        Locale::fields_IS_ACTIVE => 1,
                        Locale::fields_IS_INSTALL => 1,
                    ])
                    ->save();
                if (php_sapi_name() === 'cli') {
                    echo "  [+] 自动注册并激活语言: {$localeCode}\n";
                }
            } else {
                $needUpdate = false;
                if (!$locale->getData(Locale::fields_IS_INSTALL)) {
                    $locale->setData(Locale::fields_IS_INSTALL, 1);
                    $needUpdate = true;
                }
                if (!$locale->getData(Locale::fields_IS_ACTIVE)) {
                    $locale->setData(Locale::fields_IS_ACTIVE, 1);
                    $needUpdate = true;
                }
                if ($needUpdate) {
                    $locale->save();
                    if (php_sapi_name() === 'cli') {
                        echo "  [*] 启用并激活语言: {$localeCode}\n";
                    }
                }
            }
        } catch (\Throwable $e) {
            if (php_sapi_name() === 'cli') {
                echo "  [!] 注册语言 {$localeCode} 失败: " . $e->getMessage() . "\n";
            }
        }
    }
}
