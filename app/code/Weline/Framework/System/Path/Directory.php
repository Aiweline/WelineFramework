<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\System\Path;

class Directory
{
    public function is_empty(string $fp)
    {
        $H = @opendir($fp);
        $i = 0;
        while ($_file = readdir($H)) {
            $i++;
        }
        @closedir($H);
        if ($i > 2) {
            return false;
        }

        return true;
    }
}
