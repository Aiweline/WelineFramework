<?php

declare(strict_types=1);

/*
 * 主题预览图片生成器
 * 使用浏览器自动截图功能生成主题预览图
 */

namespace Weline\Theme\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\WelineTheme;

class ThemePreviewGenerator
{
    /**
     * 预览图片保存目录
     */
    public const PREVIEW_DIR = 'theme_previews';

    /**
     * 生成主题预览图片
     * 
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域（frontend/backend）
     * @param bool $force 是否强制重新生成
     * @return string|false 图片路径或false
     */
    public static function generatePreviewImage(WelineTheme $theme, string $area = 'frontend', bool $force = false): string|false
    {
        $themeId = $theme->getId();
        if (!$themeId) {
            return false;
        }

        // 检查是否已存在预览图
        $previewPath = self::getPreviewImagePath($themeId, $area);
        if (!$force && is_file($previewPath)) {
            return $previewPath;
        }

        // 生成预览URL
        $previewUrl = self::getPreviewUrl($themeId, $area);

        // 使用浏览器截图
        try {
            $imagePath = self::captureScreenshot($previewUrl, $previewPath);
            return $imagePath;
        } catch (\Exception $e) {
            Env::log_error('theme_preview', __('生成主题预览图失败：%{1}', [$e->getMessage()]));
            return false;
        }
    }

    /**
     * 获取预览图片存储路径
     * 
     * @param int $themeId 主题ID
     * @param string $area 区域
     * @return string 图片路径
     */
    public static function getPreviewImagePath(int $themeId, string $area = 'frontend'): string
    {
        $uploadDir = Env::VAR_DIR . self::PREVIEW_DIR;
        $filename = "theme_{$themeId}_{$area}.png";
        return $uploadDir . DS . $filename;
    }

    /**
     * 获取主题预览URL
     * 
     * @param int $themeId 主题ID
     * @param string $area 区域
     * @return string 预览URL
     */
    public static function getPreviewUrl(int $themeId, string $area = 'frontend'): string
    {
        /** @var \Weline\Framework\Http\Url $url */
        $url = ObjectManager::getInstance(\Weline\Framework\Http\Url::class);
        
        if ($area === 'backend') {
            return $url->getBackendUrl('admin', [
                'preview_theme' => $themeId,
                'preview_gen' => '1' // 标记为预览图生成模式
            ]);
        } else {
            return $url->getFrontendUrl('index/index', [
                'preview_theme' => $themeId,
                'preview_gen' => '1'
            ]);
        }
    }

    /**
     * 使用浏览器截图
     * 
     * @param string $url 要截图的URL
     * @param string $savePath 保存路径
     * @return string 图片路径
     * @throws \Exception
     */
    private static function captureScreenshot(string $url, string $savePath): string
    {
        // 确保目录存在
        $dir = dirname($savePath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                throw new \Exception(__('无法创建预览图片目录：%{1}', [$dir]));
            }
        }

        // 检查Chrome/Chromium路径
        $chromePath = self::getChromePath();
        if (!$chromePath) {
            throw new \Exception(__('未找到 Chrome/Chromium 浏览器'));
        }

        // 生成时间戳参数避免缓存
        $timestamp = time();
        $fullUrl = $url . (str_contains($url, '?') ? '&' : '?') . 't=' . $timestamp;

        // 构建命令
        // 使用 Chrome 的 --screenshot 参数截图
        $command = sprintf(
            '"%s" --headless --disable-gpu --no-sandbox --screenshot="%s" --window-size=1200,800 "%s" 2>&1',
            $chromePath,
            $savePath,
            $fullUrl
        );

        // 执行命令
        $output = [];
        $returnCode = 0;
        
        // Windows 和 Linux 兼容
        if (PHP_OS_FAMILY === 'Windows') {
            $command = 'cmd /c "' . $command . '"';
        }
        
        exec($command, $output, $returnCode);

        // 检查截图是否生成成功
        if ($returnCode !== 0 || !is_file($savePath)) {
            $errorOutput = implode("\n", $output);
            throw new \Exception(__('浏览器截图失败，返回码：%{1}，错误：%{2}', [$returnCode, $errorOutput]));
        }

        // 优化图片大小（可选）
        self::optimizeImage($savePath);

        return $savePath;
    }

    /**
     * 获取 Chrome/Chromium 可执行文件路径
     * 
     * @return string|null 路径或null
     */
    private static function getChromePath(): ?string
    {
        // Windows 路径
        if (PHP_OS_FAMILY === 'Windows') {
            $envPaths = [
                getenv('PROGRAMFILES') ? getenv('PROGRAMFILES') . '\\Google\\Chrome\\Application\\chrome.exe' : null,
                getenv('PROGRAMFILES(X86)') ? getenv('PROGRAMFILES(X86)') . '\\Google\\Chrome\\Application\\chrome.exe' : null,
                getenv('LOCALAPPDATA') ? getenv('LOCALAPPDATA') . '\\Google\\Chrome\\Application\\chrome.exe' : null,
            ];

            foreach ($envPaths as $path) {
                if ($path && file_exists($path)) {
                    return $path;
                }
            }
        }

        // Linux 路径
        if (PHP_OS_FAMILY === 'Linux') {
            $linuxPaths = [
                '/usr/bin/google-chrome',
                '/usr/bin/chromium',
                '/usr/bin/chromium-browser',
                '/usr/bin/google-chrome-stable',
                '/snap/bin/chromium',
            ];

            foreach ($linuxPaths as $path) {
                if (file_exists($path) && is_executable($path)) {
                    return $path;
                }
            }
        }

        // macOS 路径
        if (PHP_OS_FAMILY === 'Darwin') {
            $macPaths = [
                '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
                '/Applications/Chromium.app/Contents/MacOS/Chromium',
            ];

            foreach ($macPaths as $path) {
                if (file_exists($path) && is_executable($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * 优化图片大小
     * 
     * @param string $imagePath 图片路径
     * @return void
     */
    private static function optimizeImage(string $imagePath): void
    {
        // 使用 GD 库优化图片
        if (function_exists('imagecreatefrompng')) {
            $image = imagecreatefrompng($imagePath);
            if ($image) {
                // 重新保存以优化
                imagepng($image, $imagePath, 9); // 最高压缩比
                imagedestroy($image);
            }
        }
    }

    /**
     * 删除主题预览图片
     * 
     * @param int $themeId 主题ID
     * @param string|null $area 区域，null为删除所有区域
     * @return bool
     */
    public static function deletePreviewImage(int $themeId, ?string $area = null): bool
    {
        if ($area === null) {
            // 删除所有区域的预览图
            $areas = ['frontend', 'backend'];
            $deleted = true;
            foreach ($areas as $a) {
                $path = self::getPreviewImagePath($themeId, $a);
                if (is_file($path)) {
                    $deleted = unlink($path) && $deleted;
                }
            }
            return $deleted;
        }

        $path = self::getPreviewImagePath($themeId, $area);
        if (is_file($path)) {
            return unlink($path);
        }

        return false;
    }
}
