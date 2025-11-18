<?php

namespace Weline\Framework\System\OS;

class Win
{
    static function command_convert_gbk(string $str, $in_coding = 'UTF-8'): string
    {
        if (function_exists('mb_detect_encoding')) {
            $in_coding = mb_detect_encoding($str);
            if ($in_coding == 'GBK') {
                return $str;
            }
        }
        return iconv($in_coding, 'GBK', $str);
    }
}