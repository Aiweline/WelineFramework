<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Sticker\Service;

use Weline\Framework\App\Env;
use Weline\Sticker\Helper\CodeMinifier;
use Weline\Sticker\Model\StickerLog;

/**
 * 核心编译服务
 * 压缩源文件并应用 Sticker 规则，输出到 generated/extends/Weline_Sticker
 */
class Compiler
{
    private CodeMinifier $codeMinifier;
    private StickerRegistry $stickerRegistry;
    private RuleParser $ruleParser;
    private NotificationService $notificationService;

    public function __construct(
        CodeMinifier $codeMinifier,
        StickerRegistry $stickerRegistry,
        RuleParser $ruleParser,
        NotificationService $notificationService
    ) {
        $this->codeMinifier = $codeMinifier;
        $this->stickerRegistry = $stickerRegistry;
        $this->ruleParser = $ruleParser;
        $this->notificationService = $notificationService;
    }

    /**
     * 编译文件（应用 Sticker 规则）
     *
     * @param string $targetModule 目标模块
     * @param string $targetFile 目标文件路径（相对路径，如：Weline/Demo/view/templates/Backend/index.phtml）
     * @param string $sourceFilePath 源文件完整路径
     * @param string|null $type 类型 (module/theme)
     * @param string|null $themeName 主题名（如果是主题类型）
     * @return string|null 编译后的文件路径，失败返回 null
     */
    public function compile(string $targetModule, string $targetFile, string $sourceFilePath, ?string $type = null, ?string $themeName = null): ?string
    {
        if (!file_exists($sourceFilePath)) {
            return null;
        }

        // 检查是否有 Sticker
        if (!$this->stickerRegistry->hasSticker($targetModule, $targetFile)) {
            return null;
        }

        // 读取源文件
        $sourceContent = file_get_contents($sourceFilePath);
        if ($sourceContent === false) {
            return null;
        }

        // 压缩源文件
        $minifiedSource = $this->codeMinifier->minify($sourceContent);
        $resultContent = $minifiedSource;

        // 获取该文件的所有 Sticker 规则
        $fileStickers = $this->stickerRegistry->getFileStickers($targetModule, $targetFile);

        // 按从后往前的顺序应用规则（避免位置偏移）
        $allReplacements = [];

        foreach ($fileStickers as $stickerInfo) {
            $sourceModule = $stickerInfo['source_module'];
            $stickerFile = $stickerInfo['sticker_file'];
            $actions = $stickerInfo['actions'] ?? [];

            foreach ($actions as $action) {
                $type = $action['type'] ?? 'replace';
                $target = $action['target'] ?? '';
                $code = $action['code'] ?? '';
                $position = $action['position'] ?? 'all';

                if (empty($target)) {
                    continue;
                }

                // 查找所有匹配位置
                $matches = $this->codeMinifier->findMatches($minifiedSource, $target);
                if (empty($matches)) {
                    // 记录警告：目标代码未找到
                    $this->logTargetNotFound($targetModule, $targetFile, $sourceModule, $stickerFile, $action);
                    continue;
                }

                // 获取要匹配的索引列表
                $indexes = $this->codeMinifier->getPositionIndexes($position, count($matches));

                if (empty($indexes)) {
                    // 记录警告：位置参数无效
                    $this->logPositionInvalid($targetModule, $targetFile, $sourceModule, $stickerFile, $position, count($matches));
                    continue;
                }

                // 收集要替换的位置
                foreach ($matches as $match) {
                    if (in_array($match['index'], $indexes)) {
                        $allReplacements[] = [
                            'start' => $match['start'],
                            'end' => $match['end'],
                            'type' => $type,
                            'code' => $code,
                            'index' => $match['index']
                        ];
                    }
                }
            }
        }

        // 按位置从后往前排序，避免位置偏移
        usort($allReplacements, function ($a, $b) {
            return $b['start'] - $a['start'];
        });

        // 应用所有替换
        foreach ($allReplacements as $replacement) {
            $resultContent = $this->applyReplacement(
                $resultContent,
                $replacement['start'],
                $replacement['end'],
                $replacement['type'],
                $replacement['code']
            );
        }

        // 确定类型和主题名（从第一个 sticker 获取）
        if ($type === null && !empty($fileStickers)) {
            $firstSticker = reset($fileStickers);
            $type = $firstSticker['type'] ?? 'module';
            $themeName = $firstSticker['theme_name'] ?? null;
        }

        // 输出编译文件
        $outputPath = $this->getOutputPath($targetModule, $targetFile, $type, $themeName);
        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        if (file_put_contents($outputPath, $resultContent, LOCK_EX) !== false) {
            return $outputPath;
        }

        return null;
    }

    /**
     * 应用替换规则
     *
     * @param string $content 内容
     * @param int $start 开始位置
     * @param int $end 结束位置
     * @param string $type 类型：replace/before/after
     * @param string $code 新代码
     * @return string
     */
    private function applyReplacement(string $content, int $start, int $end, string $type, string $code): string
    {
        $before = substr($content, 0, $start);
        $target = substr($content, $start, $end - $start + 1);
        $after = substr($content, $end + 1);

        switch ($type) {
            case 'replace':
                return $before . $code . $after;
            case 'before':
                return $before . $code . $target . $after;
            case 'after':
                return $before . $target . $code . $after;
            default:
                return $content;
        }
    }

    /**
     * 获取输出路径
     *
     * @param string $targetModule 目标模块
     * @param string $targetFile 目标文件路径
     * @param string $type 类型 (module/theme)
     * @param string|null $themeName 主题名（如果是主题类型）
     * @return string
     */
    private function getOutputPath(string $targetModule, string $targetFile, string $type = 'module', ?string $themeName = null): string
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
     * 记录目标代码未找到
     */
    private function logTargetNotFound(
        string $targetModule,
        string $targetFile,
        string $sourceModule,
        string $stickerFile,
        array $action
    ): void {
        try {
            /** @var StickerLog $log */
            $log = \Weline\Framework\Manager\ObjectManager::getInstance(StickerLog::class);
            $log->log(
                'warning',
                $targetModule,
                $targetFile,
                $sourceModule,
                $stickerFile,
                '目标代码片段未找到',
                ['target' => substr($action['target_original'] ?? '', 0, 200)]
            );

            // 发送通知
            $this->notificationService->notifyTargetCodeNotFound(
                $targetModule,
                $targetFile,
                $sourceModule,
                $stickerFile,
                $action['target_original'] ?? ''
            );
        } catch (\Exception $e) {
            error_log("记录 Sticker 日志失败: " . $e->getMessage());
        }
    }

    /**
     * 记录位置参数无效
     */
    private function logPositionInvalid(
        string $targetModule,
        string $targetFile,
        string $sourceModule,
        string $stickerFile,
        string $position,
        int $totalMatches
    ): void {
        try {
            /** @var StickerLog $log */
            $log = \Weline\Framework\Manager\ObjectManager::getInstance(StickerLog::class);
            $log->log(
                'warning',
                $targetModule,
                $targetFile,
                $sourceModule,
                $stickerFile,
                "位置参数无效：{$position}，实际匹配数：{$totalMatches}",
                ['position' => $position, 'total_matches' => $totalMatches]
            );
        } catch (\Exception $e) {
            error_log("记录 Sticker 日志失败: " . $e->getMessage());
        }
    }

    /**
     * 编译所有需要编译的文件
     *
     * @return array ['success' => int, 'failed' => int, 'errors' => array]
     */
    public function compileAll(): array
    {
        $result = [
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];

        $registry = $this->stickerRegistry->getRegistry();
        $modules = \Weline\Framework\App\Env::getInstance()->getModuleList();

        foreach ($registry as $targetModule => $files) {
            if (!isset($modules[$targetModule])) {
                continue;
            }

            $module = $modules[$targetModule];
            $basePath = $module['base_path'] ?? '';

            foreach ($files as $targetFile => $stickers) {
                // 构建源文件路径
                $sourceFilePath = $basePath . str_replace('/', DIRECTORY_SEPARATOR, $targetFile);

                if (!file_exists($sourceFilePath)) {
                    $result['failed']++;
                    $result['errors'][] = "源文件不存在: {$sourceFilePath}";
                    continue;
                }

                // 从注册表获取类型信息
                $type = 'module';
                $themeName = null;
                if (!empty($stickers)) {
                    $firstSticker = reset($stickers);
                    $type = $firstSticker['type'] ?? 'module';
                    $themeName = $firstSticker['theme_name'] ?? null;
                }

                $compiledPath = $this->compile($targetModule, $targetFile, $sourceFilePath, $type, $themeName);
                if ($compiledPath !== null) {
                    $result['success']++;
                } else {
                    $result['failed']++;
                    $result['errors'][] = "编译失败: {$targetModule}::{$targetFile}";
                }
            }
        }

        return $result;
    }
}

