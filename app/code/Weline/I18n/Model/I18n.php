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

class I18n
{
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
        $this->i18nCache->set($locale_code, 'zh_CN');
        return 'zh_CN';
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

    public function getCountryFlagWithLocal(string $local_code = 'zh_Hans_CN', int $width = 42, int $height = 0)
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
     * @return array
     * @throws Exception
     */
    public function getLocalsWords()
    {
        // 所有语言
        $locals_names = Locales::getNames();
        // 所有语言对应存在的翻译词
        $locals_words = [];
        // 模块翻译覆盖语言包翻译
        $all_i18ns = $this->reader->getAllI18ns();
        foreach ($all_i18ns as $module_name => $i18n_files) {
            /**@var $i18n_file File */
            foreach ($i18n_files as $local => $i18n_file) {
                if (isset($locals_names[$local])) {
                    $handle = fopen($i18n_file, 'r');
                    $is_utf8 = false;
                    $line = 1;
                    while (($data = fgetcsv($handle, 100000, ',', '"', '\\')) !== false) {
                        if (!isset($data[0])) {
                            throw new Exception(PHP_EOL . __('i18n翻译文件格式错误：%i18n_file 错误行号：%line  错误消息：没有翻译原文! 读取内容：%content', [
                                    'i18n_file' => $i18n_file,
                                    'line' => $line,
                                    'content' => PHP_EOL . w_var_export($data, true)
                                ]));
                        }
                        $data[0] = trim($data[0]);
                        if (!isset($data[1])) {
                            throw new Exception(PHP_EOL . __('i18n翻译文件格式错误：%i18n_file 错误行号：%line  错误消息：没有翻译内容! 读取内容：%content', [
                                    'i18n_file' => $i18n_file,
                                    'line' => $line,
                                    'content' => PHP_EOL . w_var_export($data, true)
                                ]));
                        }
                        $data[1] = trim($data[1]);
                        if (!$is_utf8) {
                            if (md5(mb_convert_encoding($data[0], 'utf-8', 'utf-8')) === md5($data[0])) {
                                $is_utf8 = true;
                            } else {
                                throw new Exception(__('i18n翻译文件 %i18n_file 未匹配到任何local代码：支持的local代码[%codes]', [
                                    'i18n_file' => $i18n_file,
                                    'codes' => w_var_export($locals_names, true),
                                ]));
                            }
                        }
                        $locals_words[$local][$data[0]] = $data[1];
                        $line += 1;
                    }

                    fclose($handle);
                } else {
                    throw new Exception(__('i18n翻译文件 %i18n_file 未能找到可用翻译词，仅支持utf-8格式的文件。', [
                            'i18n_file' => $i18n_file,
                        ]
                    ));
                }
            }
        }
        # 收集项目下的所有被__()函数包裹的翻译词
        # --1 检索目录
        // 定义要搜索的目录
        $directories = [
            BP . 'app',
            BP . 'vendor',
        ];
        // 初始化翻译词数组
        $translations = [];
        // 遍历目录
        foreach ($directories as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
            # FIXME 未能更加精准搜索到词语
            foreach ($iterator as $file) {
                if ($file->isFile() && in_array($file->getExtension(), ['php', 'phtml', 'js'])) {
                    $content = file_get_contents($file->getPathname());
                    $content = str_replace('<lang>', '__(', $content);
                    $content = str_replace('</lang>', ')', $content);
                    # 正则替换@lang()和@lang{}情况
                    if (preg_match_all('/@lang\((.*?)\)/', $content, $matches)) {
                        foreach ($matches[1] as $match) {
                            if ($match) {
                                $translations[$match] = $match;
                            }
                        }
                    }
                    if (preg_match_all('/@lang\{(.*?)}/', $content, $matches)) {
                        foreach ($matches[1] as $match) {
                            if ($match) {
                                $translations[$match] = $match;
                            }
                        }
                    }
                    // 使用正则表达式匹配__()
                    if (preg_match_all('/__\(([\'"])(.*?)(?<!\\))\1/', $content, $matches)) {
                        foreach ($matches[2] as $match) {
                            // 提取第一个参数
//                            $filename = str_replace(BP, '', $file->getPathname());
                            $translations[$match] = $match;
                        }
                    }
                }
            }
        }
        if ($translations or isset($locals_words[Env::default_LANGUAGE_CODE])) {
            $default_local_words = array_merge($translations, $locals_words[Env::default_LANGUAGE_CODE]);
            $default_local_file = Env::path_TRANSLATE_ALL_COLLECTIONS_WORDS_FILE;
            $file = fopen($default_local_file, 'w+');
            $text = '<?php return ' . var_export($default_local_words, true) . ';';
            fwrite($file, $text);
            fclose($file);
        }
        if ($translations and isset($locals_words[Env::default_LANGUAGE_CODE])) {
            $locals_words[Env::default_LANGUAGE_CODE] = array_merge($translations, $locals_words[Env::default_LANGUAGE_CODE]);
        }
        return $locals_words;
    }

    /**
     * @DESC         |默认汉语
     *
     * 参数区：
     *
     * @param string $local_code
     *
     * @return array|mixed
     * @throws Exception
     */
    public function getLocalWords(string $local_code = 'zh_Hans_CN')
    {
        $words = [];
        if (isset($this->getLocalsWords()[$local_code])) {
            $words = $this->getLocalsWords()[$local_code];
        } elseif (isset($this->getLocalsWords()['zh_Hans_CN'])) {
            $words = $this->getLocalsWords()['zh_Hans_CN'];
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
    public function convertToLanguageFile(): void
    {
        $locals_words = $this->getLocalsWords();
        foreach ($locals_words as $local => $locals_word) {
            $words_filename = Env::path_TRANSLATE_FILES_PATH . $local . '.php';
            $file = new \Weline\Framework\System\File\Io\File();
            $file->open($words_filename, $file::mode_w);
            $text = '<?php return ' . var_export($locals_word, true) . ';?>';

            try {
                $file->write($text);
            } catch (Exception $e) {
                throw new Exception(__('错误：' . $e->getMessage()));
            }
            $file->close();
        }
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
        $cache_key = __FUNCTION__;
        $locals = $this->i18nCache->get($cache_key);
        if ($locals) {
            return $locals;
        }
        /**@var Locals $LocalsModel */
        $LocalsModel = ObjectManager::getInstance(Locals::class)->where('target_code', $target_local);
        return $LocalsModel;
    }
}
