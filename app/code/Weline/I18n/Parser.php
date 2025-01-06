<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\I18n;

use Weline\Framework\App\Env;
use Weline\Framework\Http\Cookie;

class Parser
{
    public static function parse(string $words, array $args): string
    {
        # --当前语言词
        $translate_words = [];
        $translate_words_file = Env::path_TRANSLATE_FILES_PATH . Cookie::getLangLocal() . '.php';
        if (file_exists($translate_words_file)) {
            $translate_words = (include $translate_words_file) ?? [];
        }
        if (empty($translate_words)) {
            // --默认翻译-收集词组位置
            $filename = \Weline\Framework\App\Env::path_TRANSLATE_ALL_COLLECTIONS_WORDS_FILE;
            if (!file_exists($filename)) {
                touch($filename);
            }
            try {
                $default_all_words = (array)include $filename;
            } catch (\Weline\Framework\App\Exception $exception) {
                throw new \Weline\Framework\App\Exception($exception->getMessage());
            }
            $translate_words = $default_all_words;
        }
        $words = $translate_words[$words] ?? $words;
        if ($args) {
            foreach ($args as $key => $arg) {
                $words = str_replace('%' . (is_integer($key) ? $key + 1 : $key), $arg, $words);
            }
        }

        return $words;
    }
}
