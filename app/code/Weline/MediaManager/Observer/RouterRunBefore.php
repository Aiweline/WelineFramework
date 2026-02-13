<?php


namespace Weline\MediaManager\Observer;

use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Event\Event;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\Core;

class RouterRunBefore implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $request_uri = $_SERVER['REQUEST_URI']??'';
        # 移除查询字符串
        $path_original = parse_url($request_uri, PHP_URL_PATH);
        $path = $path_original !== false ? strtolower($path_original) : '';
        # 匹配静态资源/static/
        if (str_starts_with($path, '/static/')) {
            $file_path = BP .'/pub' . $path;
            if(IS_WIN){
                $file_path = str_replace('/','\\',$file_path);
                $file_path = str_replace('\\\\','\\',$file_path);
            }else{
                $file_path = str_replace('//','/',$file_path);
            }
            if (is_file($file_path)) {
                /**@var Core $core */
                $core = ObjectManager::getInstance(Core::class);
                // 传递包含pub目录的完整路径
                $full_path = '/pub' . $path;
                $core->StaticFile($full_path, true);
                exit;
            }
        }
        
        # 匹配模块静态资源（开发环境下直接从模块目录加载）
        # 路径格式: /Weline/ModuleName/view/statics/... 或 /Vendor/ModuleName/view/statics/...
        if (preg_match('#^/([a-z0-9_]+)/([a-z0-9_]+)/view/statics/(.+)$#', $path, $matches)) {
            $vendor = $matches[1];
            $module = $matches[2];
            $file = $matches[3];
            $module_file_path = BP . '/app/code/' . $vendor . '/' . $module . '/view/statics/' . $file;
            if (IS_WIN) {
                $module_file_path = str_replace('/', '\\', $module_file_path);
                $module_file_path = str_replace('\\\\', '\\', $module_file_path);
            } else {
                $module_file_path = str_replace('//', '/', $module_file_path);
            }
            if (is_file($module_file_path)) {
                /**@var Core $core */
                $core = ObjectManager::getInstance(Core::class);
                $static_url = '/app/code/' . $vendor . '/' . $module . '/view/statics/' . $file;
                $core->StaticFile($static_url, true);
                exit;
            }
        }
        # 匹配主题资源（开发环境下直接从模块 view/theme 目录加载，如 theme:css）
        # 路径格式: /Vendor/ModuleName/view/theme/...
        if (preg_match('#^/([a-z0-9_]+)/([a-z0-9_]+)/view/theme/(.+)$#', $path, $matches) && $path_original !== false) {
            $file = $matches[3];
            $suffix = '/view/theme/' . $file;
            $prefix_len = strlen($path_original) - strlen($suffix);
            $vendor_module = $prefix_len > 0 ? substr($path_original, 1, $prefix_len - 1) : '';
            $theme_file_path = BP . '/app/code/' . str_replace('/', DIRECTORY_SEPARATOR, $vendor_module) . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'theme' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);
            if (IS_WIN) {
                $theme_file_path = str_replace('/', '\\', $theme_file_path);
                $theme_file_path = str_replace('\\\\', '\\', $theme_file_path);
            } else {
                $theme_file_path = str_replace('//', '/', $theme_file_path);
            }
            if (is_file($theme_file_path)) {
                /**@var Core $core */
                $core = ObjectManager::getInstance(Core::class);
                $core->StaticFile($path_original, true);
                exit;
            }
        }
        # 匹配媒介资源
        if (str_starts_with($path, '/pub/media/')) {
            $file_path = BP.urldecode($path);
            if(IS_WIN){
                $file_path = str_replace('/','\\',$file_path);
                $file_path = str_replace('\\\\','\\',$file_path);
            }else{
                $file_path = str_replace('//','/',$file_path);
            }
            if (is_file($file_path)) {
                /**@var Core $core */
                $core = ObjectManager::getInstance(Core::class);
                $core->StaticFile( $path, true);
                exit;
            }
        }
        // 跳过解析 
        // 图片
        if (str_starts_with($path, '/media/image/')) {
            $_SERVER['WELINE_PARSER_URL'] = false;
            $_SERVER['WELINE_IS_MEDIA'] = true;
        }
        // 文件
        if (str_starts_with($path, '/media/file/')) {
            $_SERVER['WELINE_PARSER_URL'] = false;
            $_SERVER['WELINE_IS_MEDIA'] = true;
        }
    }
}