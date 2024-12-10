<?php

namespace Weline\EditorManager\EditorManager;

use Weline\EditorManager\EditorManager;

class Local extends EditorManager
{
    public static function name(): string
    {
        return 'local';
    }

    public function render(): string
    {
        return '暂无标记器。推荐安装WelineFramework框架CKEditor编辑器管理器模块。composer require weline/module-ckeditor-editor-manager';
    }

}
