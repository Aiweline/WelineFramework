<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\I18n;

use Weline\Framework\Http\Cookie;

class Parser
{
    public static function parse(string $words, array $args): string
    {
        // 读取语言环境 TODO 翻译环境 数据库 对应用户的翻译
        /**@var \Weline\Framework\Http\Request $request */
        $request = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Http\Request::class);
        $lang    = Cookie::getLangLocal();
//        p($request);
        // 只缓存 收集来的 翻译文件 以及翻译包
        /**@var $cache \Weline\Framework\Cache\CacheInterface */
        $cache = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\I18n\Cache\I18NCache::class)->create();
        if (!CLI && !DEV && $cache_words = $cache->get($words)) {
            $words = $cache_words;
        } else {
            // 如果没有缓存就收集到词组中
            // 收集词组位置
            $filename = \Weline\Framework\App\Env::path_TRANSLATE_ALL_COLLECTIONS_WORDS_FILE;
            if (!file_exists($filename)) {
                touch($filename);
            }

            try {
                /** @noinspection PhpIncludeInspection */
                $all_words = (array)include $filename;
            } catch (\Weline\Framework\App\Exception $exception) {
                throw new \Weline\Framework\App\Exception($exception->getMessage());
            }
            $all_words[] = $words;
            $file        = fopen($filename, 'w+');
            $text        = '<?php return ' . var_export($all_words, true) . ';';
            fwrite($file, $text);
            fclose($file);
        }
        if ($args) {
            foreach ($args as $key => $arg) {
                $words = str_replace('%' . (is_integer($key) ? $key + 1 : $key), $arg, $words);
            }
        }

        return $words;
    }
}
