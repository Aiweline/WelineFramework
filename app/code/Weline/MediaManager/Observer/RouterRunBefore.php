<?php

declare(strict_types=1);

namespace Weline\MediaManager\Observer;

use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Event\Event;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\Core;

class RouterRunBefore implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        if (headers_sent()) {
            return;
        }
        $ob = ob_start();
        try {
            $this->handleStaticPaths();
        } finally {
            if ($ob) {
                ob_end_clean();
            }
        }
    }

    private function handleStaticPaths(): void
    {
        $request_uri = \Weline\Framework\Env\WelineEnv::server('REQUEST_URI', '');
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
            }

            $publishedThemePath = $this->publishThemeOverrideStaticPath((string)($path_original !== false ? $path_original : $path));
            if ($publishedThemePath !== null) {
                /** @var Core $core */
                $core = ObjectManager::getInstance(Core::class);
                $core->StaticFile($publishedThemePath, true);
            }
        }
        
        # 匹配模块静态资源（开发环境下直接从模块目录加载）
        # 路径格式: /Weline/ModuleName/view/statics/... 或 /Vendor/ModuleName/view/statics/...
        # 注意：使用 path_original 保留大小写（Linux 区分），Framework 模块实际为 View/statics
        if (preg_match('#^/([A-Za-z0-9_]+)/([A-Za-z0-9_]+)/view/statics/(.+)$#', $path_original !== false ? $path_original : $path, $matches)) {
            $vendor = $matches[1];
            $module = $matches[2];
            $file = $matches[3];

            $base = BP . '/app/code/' . $vendor . '/' . $module;
            $candidates = [
                $base . '/view/statics/' . $file,
                $base . '/View/statics/' . $file,  // Framework 模块
            ];
            if (IS_WIN) {
                $candidates = array_map(static fn ($p) => str_replace(['/', '\\\\'], ['\\', '\\'], $p), $candidates);
            } else {
                $candidates = array_map(static fn ($p) => str_replace('//', '/', $p), $candidates);
            }
            foreach ($candidates as $module_file_path) {
                if (is_file($module_file_path)) {
                    /**@var Core $core */
                    $core = ObjectManager::getInstance(Core::class);
                    $static_url = '/app/code/' . $vendor . '/' . $module . '/view/statics/' . $file;
                    // StaticFile() 会直接抛 ResponseTerminateException，Runtime 统一输出文件响应。
                    $core->StaticFile($static_url, true);
                }
            }
        }
        # 匹配主题资源（开发环境下直接从模块 view/theme 目录加载，如 theme:css）
        # 路径格式: /Vendor/ModuleName/view/theme/...
        if (preg_match('#^/([A-Za-z0-9_]+)/([A-Za-z0-9_]+)/view/theme/(.+)$#', $path_original !== false ? $path_original : $path, $matches)) {
            $requestThemePath = (string)($path_original !== false ? $path_original : $path);
            $publishedThemePath = $this->publishThemeOverrideStaticPath($requestThemePath);
            if ($publishedThemePath !== null) {
                /**@var Core $core */
                $core = ObjectManager::getInstance(Core::class);
                $core->StaticFile($publishedThemePath, true);
            }

            $file = $matches[3];
            $suffix = '/view/theme/' . $file;
            $prefix_len = strlen($requestThemePath) - strlen($suffix);
            $vendor_module = $prefix_len > 0 ? substr($requestThemePath, 1, $prefix_len - 1) : '';
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
                // 主题资源路径本身是 /Vendor/Module/view/theme/...，应走 APP_CODE_PATH 解析链路
                //（is_media=false），否则会按 BP 直拼导致找不到文件并回退为 HTML 响应。
                $core->StaticFile($requestThemePath, false);
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
            }
        }
        // 跳过解析 
        // 图片
        if (str_starts_with($path, '/media/image/')) {
            \Weline\Framework\Env\WelineEnv::setServer('WELINE_PARSER_URL', false, 'MediaManager router before');
            \Weline\Framework\Env\WelineEnv::setServer('WELINE_IS_MEDIA', true, 'MediaManager router before');
        }
        // 文件
        if (str_starts_with($path, '/media/file/')) {
            \Weline\Framework\Env\WelineEnv::setServer('WELINE_PARSER_URL', false, 'MediaManager router before');
            \Weline\Framework\Env\WelineEnv::setServer('WELINE_IS_MEDIA', true, 'MediaManager router before');
        }
    }

    private function publishThemeOverrideStaticPath(string $requestPath): ?string
    {
        if (!class_exists(\Weline\Theme\Service\ThemeStaticAssetPublisher::class)) {
            return null;
        }

        try {
            /** @var \Weline\Theme\Service\ThemeStaticAssetPublisher $publisher */
            $publisher = ObjectManager::getInstance(\Weline\Theme\Service\ThemeStaticAssetPublisher::class);
            $publicPath = $publisher->publishForRequestPath($requestPath);
            return is_string($publicPath) && $publicPath !== '' ? $publicPath : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
