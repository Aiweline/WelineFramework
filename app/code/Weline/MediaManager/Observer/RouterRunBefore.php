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
        $path = strtolower(parse_url($request_uri, PHP_URL_PATH));
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
        if (preg_match('#^/([A-Za-z0-9_]+)/([A-Za-z0-9_]+)/view/statics/(.+)$#', $path, $matches)) {
            $vendor = $matches[1];
            $module = $matches[2];
            $file = $matches[3];
            
            // 构建模块静态文件路径
            $module_file_path = BP . '/app/code/' . $vendor . '/' . $module . '/view/statics/' . $file;
            if(IS_WIN){
                $module_file_path = str_replace('/','\\',$module_file_path);
                $module_file_path = str_replace('\\\\','\\',$module_file_path);
            }else{
                $module_file_path = str_replace('//','/',$module_file_path);
            }
            if (is_file($module_file_path)) {
                /**@var Core $core */
                $core = ObjectManager::getInstance(Core::class);
                $static_url = '/app/code/' . $vendor . '/' . $module . '/view/statics/' . $file;
                $core->StaticFile($static_url, true);
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