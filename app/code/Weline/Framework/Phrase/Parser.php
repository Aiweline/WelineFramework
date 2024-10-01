<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Phrase;

use Weline\Framework\App\Env;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

class Parser
{
    public const PARSER_WORDS_CACHE_KEY = 'PARSER_WORDS_CACHE_KEY';
    protected static array $words = [];

    /**
     * @DESC         # 翻译解析函数
     * DEV环境下解析字词并收集到generated/language/words.php
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/16 22:50
     * 参数区：
     *
     * @param string|array          $words
     * @param int|array|string|null $args
     *
     * @return mixed|string|string[]
     * @throws \ReflectionException
     * @throws \Weline\Framework\App\Exception
     * @throws \Weline\Framework\Exception\Core
     */
    public static function parse(string|array $words, int|array|string $args = null): mixed
    {
        $words = self::processWords($words);
        if (is_array($args)) {
            foreach ($args as $key => $arg) {
                $words = str_replace('%' . (is_integer($key) ? $key + 1 : $key), $arg, $words);
            }
        } elseif ($words && $args) {
            $words = str_replace('%1', $args, $words);
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
     * @throws \Weline\Framework\App\Exception
     */
    protected static function processWords(string $words): string
    {
        self::getWords();
        // 如果有就替换
        if (isset(self::$words[$words])) {
            $words = self::$words[$words];
        } else {
            self::$words[$words] = $words;
        }
        return $words;
    }

    public static function getWords()
    {
        // 仅加载一次翻译到对象self::$words
        if (empty(self::$words)) {
            // 先访问缓存
            /**@var \Weline\Framework\Cache\CacheInterface $phraseCache */
            $phraseCache    = ObjectManager::getInstance(\Weline\Framework\Phrase\Cache\PhraseCache::class . 'Factory');
            $translate_mode = Env::getInstance()->getConfig('translate_mode');

            $cache_key = 'phrase_locale_words_'.Cookie::getLangLocal();
            # 非实时翻译
            if ($translate_mode !== 'online' && $phrase_words = $phraseCache->get($cache_key)) {
                self::$words = $phrase_words;
            } else {
                # 事件分配
                /**@var \Weline\Framework\Event\EventsManager $eventsManager */
                $eventsManager = ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class);
                $file_data     = new DataObject(['file_path' => Env::path_TRANSLATE_DEFAULT_FILE]);
                $eventsManager->dispatch('Framework_phrase::get_words_file', ['file_data' => $file_data]);
                $words_file = $file_data->getData('file_path');
                # 实时翻译
                if (is_file($words_file)) {
                    try {
                        self::$words = (array)include $words_file;
                        $phraseCache->set($cache_key, self::$words);
                    } catch (\Weline\Framework\App\Exception $exception) {
                        throw new \Weline\Framework\App\Exception($exception->getMessage());
                    }
                }
            }
        }
        return self::$words ?? [];
    }
}
