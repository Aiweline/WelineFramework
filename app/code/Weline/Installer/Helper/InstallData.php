<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Installer\Helper;

use Weline\Framework\Console\Console\Command\Upgrade;

class InstallData
{
    public function getData(string $key = '')
    {
        $data = [
            'env'      => [
                'functions' => ['exec', 'putenv'],
                'modules'   => ['PDO', 'exif', 'fileinfo', 'xsl'],
            ],
            'commands' => [
                'bin/m command:upgrade',
                'bin/m deploy:mode:set dev',
                'bin/m setup:upgrade',
            ]
        ];

        return $data[$key] ?: $data;
    }
}
