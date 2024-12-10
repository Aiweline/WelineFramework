<?php

namespace Weline\EditorManager;

interface EditorManagerInterface
{
    public static function name(): string;

    /**
     * 获取管理器Html内容
     * @return string
     */
    public function render(): string;
}
