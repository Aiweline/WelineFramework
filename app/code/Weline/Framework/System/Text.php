<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\System;

class Text
{
    public static function rand_str(): string
    {
        return crypt(md5(microtime()), md5(microtime()));
    }

    static function random_string(int $length = 10) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $random_string = '';
        $bytes = random_bytes($length);
        for ($i = 0; $i < $length; $i++) {
            $random_string .= $characters[ord($bytes[$i]) % strlen($characters)];
        }
        return $random_string;
    }
}
