<?php

namespace Weline\FileManager;

interface FileManagerInterface
{
    public static function name(): string;

    /**
     * 获取管理器Html内容
     * @return string
     */
    public function render(): string;

    public function getConnector(array $params = []):string;
}
