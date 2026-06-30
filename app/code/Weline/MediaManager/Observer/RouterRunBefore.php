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
        $requestPath = \is_string($path_original) ? $path_original : '';
        $decodedPath = $this->decodeStaticRequestPath($requestPath);
        if ($decodedPath === null) {
            return;
        }
        $path = \strtolower($decodedPath);
        # 匹配静态资源/static/
        if (str_starts_with($path, '/static/')) {
            $staticPath = $decodedPath;
            $file_path = $this->resolveExistingFileInRoot(BP . '/pub/static', \substr($staticPath, \strlen('/static/')));
            if ($file_path !== null && is_file($file_path)) {
                /**@var Core $core */
                $core = ObjectManager::getInstance(Core::class);
                // 传递包含pub目录的完整路径
                $full_path = '/pub' . $staticPath;
                $core->StaticFile($full_path, true);
            }

            $publishedThemePath = $this->publishThemeOverrideStaticPath($staticPath);
            if ($publishedThemePath !== null) {
                /** @var Core $core */
                $core = ObjectManager::getInstance(Core::class);
                $core->StaticFile($publishedThemePath, true);
            }
        }
        
        # 匹配模块静态资源（开发环境下直接从模块目录加载）
        # 路径格式: /Weline/ModuleName/view/statics/... 或 /Vendor/ModuleName/view/statics/...
        # 注意：使用 path_original 保留大小写（Linux 区分），Framework 模块实际为 View/statics
        if (preg_match('#^/([A-Za-z0-9_]+)/([A-Za-z0-9_]+)/view/statics/(.+)$#', $decodedPath, $matches)) {
            $vendor = $matches[1];
            $module = $matches[2];
            $file = $matches[3];

            $base = BP . '/app/code/' . $vendor . '/' . $module;
            $candidateRoots = [
                $base . '/view/statics',
                $base . '/View/statics',  // Framework 模块
            ];
            foreach ($candidateRoots as $candidateRoot) {
                $module_file_path = $this->resolveExistingFileInRoot($candidateRoot, $file);
                if ($module_file_path !== null && is_file($module_file_path)) {
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
        if (preg_match('#^/([A-Za-z0-9_]+)/([A-Za-z0-9_]+)/view/theme/(.+)$#', $decodedPath, $matches)) {
            $requestThemePath = $decodedPath;
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
            $themeRoot = BP . '/app/code/' . str_replace('/', DIRECTORY_SEPARATOR, $vendor_module) . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'theme';
            $theme_file_path = $this->resolveExistingFileInRoot($themeRoot, $file);
            if ($theme_file_path !== null && is_file($theme_file_path)) {
                /**@var Core $core */
                $core = ObjectManager::getInstance(Core::class);
                // 主题资源路径本身是 /Vendor/Module/view/theme/...，应走 APP_CODE_PATH 解析链路
                //（is_media=false），否则会按 BP 直拼导致找不到文件并回退为 HTML 响应。
                $core->StaticFile($requestThemePath, false);
            }
        }
        # 匹配媒介资源
        if (str_starts_with($path, '/pub/media/')) {
            $file_path = $this->resolveExistingFileInRoot(BP . '/pub/media', \substr($decodedPath, \strlen('/pub/media/')));
            if ($file_path !== null && is_file($file_path)) {
                /**@var Core $core */
                $core = ObjectManager::getInstance(Core::class);
                $core->StaticFile($decodedPath, true);
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

    private function decodeStaticRequestPath(string $path): ?string
    {
        $decoded = \rawurldecode($path);
        if ($decoded === '' || $decoded[0] !== '/') {
            return null;
        }
        if (\str_contains($decoded, '..') || \str_contains($decoded, '\\') || \preg_match('/[\x00-\x1F\x7F]/', $decoded)) {
            return null;
        }
        return $decoded;
    }

    private function resolveExistingFileInRoot(string $root, string $relativePath): ?string
    {
        $rootReal = \realpath($root);
        if ($rootReal === false) {
            return null;
        }

        $candidate = \rtrim($rootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . \str_replace('/', DIRECTORY_SEPARATOR, \ltrim($relativePath, '/'));
        $real = \realpath($candidate);
        if ($real === false || !\is_file($real)) {
            return null;
        }

        return $this->isPathInsideRoot($real, $rootReal) ? $real : null;
    }

    private function isPathInsideRoot(string $path, string $root): bool
    {
        $path = \rtrim(\str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
        $root = \rtrim(\str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);
        if (IS_WIN) {
            $path = \strtolower($path);
            $root = \strtolower($root);
        }

        return $path === $root || \str_starts_with($path, $root . DIRECTORY_SEPARATOR);
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
