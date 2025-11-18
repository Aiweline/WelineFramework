<?php

namespace Weline\FileManager\FileManager;

use Weline\FileManager\FileManager;

class Local extends FileManager
{
    public static function name(): string
    {
        return 'local';
    }

    public function render(): string
    {
        return '暂无文件管理器。推荐安装WelineFramework框架Elfinder文件管理器模块。composer require weline/module-el-finder-file-manager';
    }

    public function getConnector(array $params = []):string
    {
        return '';
    }

}
