<?php

declare(strict_types=1);

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
        return (string)__('当前未配置可用的 Weline 文件管理器，请在后台文件管理器配置中选择 weline_media。');
    }

    public function getConnector(array $params = []): string
    {
        return '';
    }

}
