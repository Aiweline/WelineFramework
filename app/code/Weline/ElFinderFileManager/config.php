<?php

namespace Weline\ElfinderFileManager;

use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;

$app_boostrap_file = __DIR__ . '/../../../bootstrap.php';
$included = false;
if (file_exists($app_boostrap_file)) {
    require_once $app_boostrap_file;
    $included = true;
}
if (!$included) {
    $app_boostrap_file = __DIR__ . '/../../../app/bootstrap.php';
    if (file_exists($app_boostrap_file)) {
        require_once $app_boostrap_file;
        $included = true;
    }
}
if (!$included) {
    # 文件找不到
    die('Bootstrap文件找不到！请确保你是在WelineFramework框架中使用此拓展！');
}

# 软件资源
if (!function_exists('exec')) {
    die('exec 方法找不到！请确保PHP没有禁用exec！');
}
//dd(ini_get_all());
$ds = DS;
$vendor_path = VENDOR_PATH . "studio-42{$ds}elfinder";
$target_static_path = __DIR__ . DS . "view{$ds}statics{$ds}";
if (!is_dir($target_static_path)) {
    mkdir($target_static_path, 0755, true);
}

# 搬迁文件
$target_static_dir = __DIR__ . DS . "view{$ds}statics{$ds}";

if (!is_dir($target_static_dir)) {
    mkdir($target_static_dir, 0755, true);
}
if (IS_WIN) {
    exec("xcopy {$vendor_path} {$target_static_path} /E /I /Y /F");
} else {
    exec("cp -r {$vendor_path} {$target_static_path} -f");
}
