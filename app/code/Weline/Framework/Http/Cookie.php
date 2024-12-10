<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Http;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\I18n;

class Cookie
{
    public static function set(string $key, string $value, int $expire = 3600 * 24 * 7, array $options = [])
    {
        $_options['path'] = '/';
        if ($options) {
            $_options = array_merge($_options, $options);
        }
        $_COOKIE[$key] = $value;
        setcookie($key, $value, time() + $expire, ...$_options);
    }

    public static function get(string $key, $default = null)
    {
        return $_COOKIE[$key] ?? $default;
    }

    /**
     * @DESC          # 获取语言
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/6/24 22:47
     * 参数区：
     * @return string
     */
    public static function getLang(): string
    {
        // 用户语言优先
        $lang = $_COOKIE['WELINE-USER-LANG'] ?? null;
        // 默认网站语言
        if (empty($lang)) {
            $lang = self::get('WELINE-WEBSITE-LANG', 'zh_Hans_CN');
        }
        return $lang;
    }
    /**
     * @DESC          # 获取语言
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/6/24 22:47
     * 参数区：
     * @return string
     */
    public static function getCurrency(): string
    {
        // 用户货币优先
        $currency = $_COOKIE['WELINE-USER-CURRENCY'] ?? null;
        // 默认网站语言
        if (empty($currency)) {
            $currency = self::get('WELINE-WEBSITE-CURRENCY', '');
        }
        return $currency;
    }

    /**
     * @DESC          # 获取语言
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/6/24 22:47
     * 参数区：
     * @return string
     * @throws Null
     */
    public static function getLangLocal(): string
    {
        $data = new DataObject();
        $data->setData('lang', self::getLang());
        $data->setData('currency', self::getCurrency());
        $data->setData('lang_local', self::getLang());
        ObjectManager::getInstance(EventsManager::class)->dispatch('Framework_Cookie::lang_local', ['data' => $data]);
        return $data->getData('lang_local');
    }
}
