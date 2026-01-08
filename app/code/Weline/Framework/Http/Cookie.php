<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Http;

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
     * @DESC          # 获取语言（已废弃，请使用 State::getLang()）
     * @deprecated 请使用 \Weline\Framework\App\State::getLang() 替代
     * 此方法仅用于向后兼容，实际调用 State::getLang()
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/6/24 22:47
     * 参数区：
     * @return string
     */
    public static function getLang(): string
    {
        return \Weline\Framework\App\State::getLang();
    }

    /**
     * @DESC          # 获取货币（已废弃，请使用 State::getCurrency()）
     * @deprecated 请使用 \Weline\Framework\App\State::getCurrency() 替代
     * 此方法仅用于向后兼容，实际调用 State::getCurrency()
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/6/24 22:47
     * 参数区：
     * @return string
     */
    public static function getCurrency(): string
    {
        return \Weline\Framework\App\State::getCurrency();
    }

    /**
     * @DESC          # 获取语言本地化代码（已废弃，请使用 State::getLangLocal()）
     * @deprecated 请使用 \Weline\Framework\App\State::getLangLocal() 替代
     * 此方法仅用于向后兼容，实际调用 State::getLangLocal()
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
        return \Weline\Framework\App\State::getLangLocal();
    }

    public static function static_file(): void
    {
        if (headers_sent()) return;
        // 设置缓存策略
        header('Cache-Control: public, max-age=31536000, immutable');
        // 设置缓存过期时间
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');
        // 设置缓存策略（兼容旧版浏览器）
        header('Pragma: public');
        // 设置ETag和Last-Modified头部
        header('ETag: "static-file-etag"');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', time()) . ' GMT');
        // 设置文件的MIME类型
        header('Content-Type: application/octet-stream');
        // 设置文件的处理方式（在浏览器中显示）
        header('Content-Disposition: inline');
        // 显式设置不发送任何cookie
//        header('Set-Cookie: ');
        header_remove('Set-Cookie');
        // 防止搜索引擎索引文件
        header('X-Robots-Tag: none');
        // 防止文件被其他网站框架
        header('X-Frame-Options: DENY');
        // 防止MIME嗅探攻击
        header('X-Content-Type-Options: nosniff');
        // 启用XSS保护
        header('X-XSS-Protection: 1; mode=block');
        // 不显示提供者
        header_remove('X-Powered-By');
//        header('X-Powered-By: ');
    }
}
