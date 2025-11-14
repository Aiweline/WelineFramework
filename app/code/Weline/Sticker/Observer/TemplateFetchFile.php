<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Sticker\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Sticker\Service\Compiler;
use Weline\Sticker\Service\StickerRegistry;

/**
 * 模板文件加载观察者
 * 监听 Framework_View::fetch_file 事件，检查 Sticker 注册表
 * 如果文件有 Sticker，则替换为编译文件路径
 */
class TemplateFetchFile implements ObserverInterface
{
    private StickerRegistry $stickerRegistry;
    private Compiler $compiler;

    // 文件存在性缓存（开发环境使用，1秒有效期）
    private static array $fileExistenceCache = [];
    private static array $fileExistenceCacheTime = [];

    public function __construct(
        StickerRegistry $stickerRegistry,
        Compiler $compiler
    ) {
        $this->stickerRegistry = $stickerRegistry;
        $this->compiler = $compiler;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        /** @var DataObject $fileData */
        $fileData = $event->getData('data');
        if (!$fileData instanceof DataObject) {
            return;
        }

        $filename = $fileData->getData('filename');
        if (empty($filename)) {
            return;
        }

        // 性能优化：快速判断 - 先检查模块是否在注册表中
        $targetModule = $this->extractModuleFromPath($filename);
        if (empty($targetModule)) {
            return; // 无法提取模块，直接返回
        }

        // 检查模块是否有 Sticker（快速判断）
        if (!$this->stickerRegistry->hasModuleStickers($targetModule)) {
            return; // 该模块没有 Sticker，直接返回
        }

        // 提取目标文件路径（相对于模块根目录）
        $targetFile = $this->extractTargetFile($filename, $targetModule);
        if (empty($targetFile)) {
            return;
        }

        // 检查文件是否有 Sticker（需要传入RuleParser以解析actions）
        $ruleParser = ObjectManager::getInstance(\Weline\Sticker\Service\RuleParser::class);
        if (!$this->stickerRegistry->hasSticker($targetModule, $targetFile)) {
            return; // 该文件没有 Sticker，直接返回
        }

        // 获取 Sticker 信息以确定类型（自动解析actions）
        $fileStickers = $this->stickerRegistry->getFileStickers($targetModule, $targetFile, $ruleParser);
        $type = 'module';
        $themeName = null;
        if (!empty($fileStickers)) {
            $firstSticker = reset($fileStickers);
            $type = $firstSticker['type'] ?? 'module';
            $themeName = $firstSticker['theme_name'] ?? null;
        }

        // 检查编译文件是否存在（使用缓存）
        $compiledPath = $this->getCompiledPath($targetModule, $targetFile, $type, $themeName);
        
        if (!$this->checkFileExists($compiledPath)) {
            // 编译文件不存在，需要编译
            $sourcePath = $this->getSourcePath($filename, $targetModule, $targetFile);
            if ($sourcePath && file_exists($sourcePath)) {
                $this->compiler->compile($targetModule, $targetFile, $sourcePath, $type, $themeName);
                // 重新检查编译文件
                if (!$this->checkFileExists($compiledPath)) {
                    return; // 编译失败，使用原始文件
                }
            } else {
                return; // 源文件不存在，使用原始文件
            }
        }

        // 开发环境：检查源文件和Sticker源文件是否更新
        if (defined('DEV') && DEV) {
            $sourcePath = $this->getSourcePath($filename, $targetModule, $targetFile);
            $needsRecompile = false;
            
            // 检查目标源文件是否更新
            if ($sourcePath && file_exists($sourcePath)) {
                $sourceMtime = filemtime($sourcePath);
                $compiledMtime = file_exists($compiledPath) ? filemtime($compiledPath) : 0;
                
                if ($sourceMtime > $compiledMtime) {
                    $needsRecompile = true;
                }
            }
            
            // 检查Sticker源文件是否更新
            if (!$needsRecompile && !empty($fileStickers)) {
                foreach ($fileStickers as $stickerInfo) {
                    $stickerFile = $stickerInfo['sticker_file'] ?? '';
                    if (!empty($stickerFile) && file_exists($stickerFile)) {
                        $stickerMtime = filemtime($stickerFile);
                        $compiledMtime = file_exists($compiledPath) ? filemtime($compiledPath) : 0;
                        
                        if ($stickerMtime > $compiledMtime) {
                            $needsRecompile = true;
                            break;
                        }
                    }
                }
            }
            
            // 如果需要重新编译
            if ($needsRecompile && $sourcePath && file_exists($sourcePath)) {
                $this->compiler->compile($targetModule, $targetFile, $sourcePath, $type, $themeName);
            }
        }

        // 替换文件路径为编译文件路径
        $fileData->setData('filename', $compiledPath);
    }

    /**
     * 从文件路径提取模块名
     *
     * @param string $filePath 文件路径
     * @return string|null
     */
    private function extractModuleFromPath(string $filePath): ?string
    {
        $modules = Env::getInstance()->getModuleList();

        // 尝试匹配模块路径
        foreach ($modules as $moduleName => $module) {
            $basePath = $module['base_path'] ?? '';
            if (empty($basePath)) {
                continue;
            }

            // 标准化路径
            $basePath = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, rtrim($basePath, '/\\'));
            $filePath = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $filePath);

            if (strpos($filePath, $basePath) === 0) {
                return $moduleName;
            }
        }

        return null;
    }

    /**
     * 提取目标文件路径（相对于模块根目录）
     * 例如：Weline/Demo/view/templates/Backend/index.phtml
     *
     * @param string $filePath 文件完整路径
     * @param string $moduleName 模块名
     * @return string|null
     */
    private function extractTargetFile(string $filePath, string $moduleName): ?string
    {
        $modules = Env::getInstance()->getModuleList();
        if (!isset($modules[$moduleName])) {
            return null;
        }

        $module = $modules[$moduleName];
        $basePath = $module['base_path'] ?? '';
        if (empty($basePath)) {
            return null;
        }

        // 标准化路径
        $basePath = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, rtrim($basePath, '/\\'));
        $filePath = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $filePath);

        if (strpos($filePath, $basePath) === 0) {
            $relativePath = substr($filePath, strlen($basePath));
            $relativePath = ltrim($relativePath, DIRECTORY_SEPARATOR);
            return str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
        }

        return null;
    }

    /**
     * 获取源文件路径
     *
     * @param string $originalPath 原始路径
     * @param string $targetModule 目标模块
     * @param string $targetFile 目标文件
     * @return string|null
     */
    private function getSourcePath(string $originalPath, string $targetModule, string $targetFile): ?string
    {
        $modules = Env::getInstance()->getModuleList();
        if (!isset($modules[$targetModule])) {
            return null;
        }

        $module = $modules[$targetModule];
        $basePath = $module['base_path'] ?? '';
        if (empty($basePath)) {
            return null;
        }

        return $basePath . str_replace('/', DIRECTORY_SEPARATOR, $targetFile);
    }

    /**
     * 获取编译文件路径
     *
     * @param string $targetModule 目标模块
     * @param string $targetFile 目标文件路径
     * @param string $type 类型 (module/theme)
     * @param string|null $themeName 主题名（如果是主题类型）
     * @return string
     */
    private function getCompiledPath(string $targetModule, string $targetFile, string $type = 'module', ?string $themeName = null): string
    {
        $modules = Env::getInstance()->getModuleList();
        $module = $modules[$targetModule] ?? null;
        $modulePath = $this->extractModulePathFromBasePath($module['base_path'] ?? '');

        if ($type === 'theme' && $themeName) {
            // 主题 Sticker 输出: generated/extends/theme/Weline_Sticker/{主题名}/{模块名}/{文件路径}
            $basePath = BP . 'generated' . DIRECTORY_SEPARATOR . 'extends' . DIRECTORY_SEPARATOR . 'theme' . DIRECTORY_SEPARATOR . 'Weline_Sticker' . DIRECTORY_SEPARATOR . $themeName . DIRECTORY_SEPARATOR;
            if ($modulePath) {
                $basePath .= str_replace('/', DIRECTORY_SEPARATOR, $modulePath) . DIRECTORY_SEPARATOR;
            }
        } else {
            // 模块 Sticker 输出: generated/extends/module/Weline_Sticker/{模块名}/{文件路径}
            $basePath = BP . 'generated' . DIRECTORY_SEPARATOR . 'extends' . DIRECTORY_SEPARATOR . 'module' . DIRECTORY_SEPARATOR . 'Weline_Sticker' . DIRECTORY_SEPARATOR;
            if ($modulePath) {
                $basePath .= str_replace('/', DIRECTORY_SEPARATOR, $modulePath) . DIRECTORY_SEPARATOR;
            }
        }

        return $basePath . str_replace('/', DIRECTORY_SEPARATOR, $targetFile);
    }

    /**
     * 从模块 base_path 提取模块路径名
     * 例如: app/code/Weline/Sticker -> Weline/Sticker
     *
     * @param string $basePath 模块基础路径
     * @return string
     */
    private function extractModulePathFromBasePath(string $basePath): string
    {
        if (empty($basePath)) {
            return '';
        }

        // 标准化路径
        $basePath = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, rtrim($basePath, '/\\'));

        // 查找 app/code 或 vendor 目录
        $appCodePos = strpos($basePath, DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR);
        $vendorPos = strpos($basePath, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR);

        if ($appCodePos !== false) {
            // app/code/Weline/Sticker -> Weline/Sticker
            $relativePath = substr($basePath, $appCodePos + strlen(DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR));
            return str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
        } elseif ($vendorPos !== false) {
            // vendor/Weline/Sticker -> Weline/Sticker
            $relativePath = substr($basePath, $vendorPos + strlen(DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR));
            return str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
        }

        return '';
    }

    /**
     * 检查文件是否存在（带缓存）
     *
     * @param string $filePath 文件路径
     * @return bool
     */
    private function checkFileExists(string $filePath): bool
    {
        $cacheKey = md5($filePath);
        $now = time();

        // 检查缓存（1秒有效期）
        if (isset(self::$fileExistenceCache[$cacheKey]) && 
            isset(self::$fileExistenceCacheTime[$cacheKey]) &&
            ($now - self::$fileExistenceCacheTime[$cacheKey]) < 1) {
            return self::$fileExistenceCache[$cacheKey];
        }

        // 检查文件
        $exists = file_exists($filePath);
        
        // 更新缓存
        self::$fileExistenceCache[$cacheKey] = $exists;
        self::$fileExistenceCacheTime[$cacheKey] = $now;

        return $exists;
    }
}

