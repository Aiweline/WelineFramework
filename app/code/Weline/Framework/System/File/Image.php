<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\System\File;

class Image
{
    /**
     * @DESC          # 把图片转化成base64编码
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/7/23 18:52
     * 参数区：
     *
     * @param string $file
     *
     * @return string
     */
    public static function base64(string $file)
    {
        $base64_file = '';
        if (file_exists($file)) {
            $mime_type   = mime_content_type($file);
            $base64_data = base64_encode(file_get_contents($file));
            $base64_file = 'data:' . $mime_type . ';base64,' . $base64_data;
        }
        return $base64_file;
    }
}
