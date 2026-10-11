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
        return __('内置占位回退（非可用富文本编辑器）。名称 local 表示缺省占位，不是本机已安装的本地编辑器。已安装 CKEditor 等模块时系统会优先使用它们；仅当无可用编辑器时显示此提示。安装：composer require weline/module-ckeditor-editor-manager');
    }

}
