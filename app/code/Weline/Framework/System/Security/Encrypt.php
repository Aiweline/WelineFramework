<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\System\Security;

class Encrypt
{
    /**
     * @DESC          # 加盐MD5加密
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/11/9 14:17
     * 参数区：
     *
     * @param $password
     * @param $salt
     *
     * @return string
     */
    public static function md5_salt(string $password, string $salt): string
    {
        $str1 = mb_substr($password, 0, 5);
        $str2 = mb_substr($salt, 0, 2);
        $str3 = mb_substr($salt, -2);
        $str4 = mb_substr($password, -5);
        return crypt(md5($str1 . $str2 . $password . $str3 . $str4), $salt);
    }

    /**
     * @DESC          # 返回随机数字码
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/11/8 22:23
     * 参数区：
     *
     * @param int $length
     *
     * @return string
     */
    public static function get_rand_number_code(int $length = 6): string
    {
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= rand(0, 9);
        }
        return $code;
    }
}
