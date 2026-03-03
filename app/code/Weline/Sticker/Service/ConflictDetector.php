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
use Weline\Sticker\Service\StickerRegistry;

/**
 * 冲突检测服务
 * 检测多模块 Sticker 的交叉修改
 * 只有当同一块代码的同一索引位置交叉时才报错
 */
class ConflictDetector
{
    private CodeMinifier $codeMinifier;
    private StickerRegistry $stickerRegistry;

    public function __construct(CodeMinifier $codeMinifier, StickerRegistry $stickerRegistry)
    {
        $this->codeMinifier = $codeMinifier;
        $this->stickerRegistry = $stickerRegistry;
    }

    /**
     * 检查特定Sticker是否有冲突
     *
     * @param string $targetModule 目标模块
     * @param string $targetFile 目标文件
     * @param string $sourceModule 来源模块
     * @return bool
     */
    public function hasConflict(string $targetModule, string $targetFile, string $sourceModule): bool
    {
        try {
            $registry = $this->stickerRegistry->getRegistry();
            
            // 如果没有该文件的Sticker信息，返回false
            if (!isset($registry[$targetModule][$targetFile])) {
                return false;
            }
            
            $stickers = $registry[$targetModule][$targetFile];
            
            // 如果只有一个Sticker，不会有冲突
            if (count($stickers) <= 1) {
                return false;
            }
            
            // 获取源文件内容
            $modules = Env::getInstance()->getModuleList();
            if (!isset($modules[$targetModule])) {
                return false;
            }
            
            $module = $modules[$targetModule];
            $basePath = $module['base_path'] ?? '';
            $sourceFilePath = $basePath . str_replace('/', DIRECTORY_SEPARATOR, $targetFile);
            
            if (!file_exists($sourceFilePath)) {
                return false;
            }
            
            // 读取并压缩源文件
            $sourceContent = file_get_contents($sourceFilePath);
            $minifiedSource = $this->codeMinifier->minify($sourceContent);
            
            // 检测该文件的冲突
            $conflicts = $this->detectFileConflicts($targetModule, $targetFile, $stickers, $minifiedSource);
            
            // 检查是否有涉及指定来源模块的冲突
            foreach ($conflicts as $conflict) {
                foreach ($conflict['conflicts'] as $indexConflict) {
                    foreach ($indexConflict['sticker_actions'] as $action) {
                        if ($action['source_module'] === $sourceModule) {
                            return true;
                        }
                    }
                }
            }
            
            return false;
        } catch (\Exception $e) {
            // 如果检查过程中出现异常，记录日志但不抛出异常
            w_log_error("检查Sticker冲突时发生错误: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 检测所有冲突
     *
     * @param array $registry Sticker 注册表
     * @return array 冲突列表，格式：[['target_module' => '', 'target_file' => '', 'conflicts' => []]]
     * @throws \Exception 如果发现冲突
     */
    public function detectConflicts(array $registry): array
    {
        $allConflicts = [];
        $modules = Env::getInstance()->getModuleList();

        foreach ($registry as $targetModule => $files) {
            if (!isset($modules[$targetModule])) {
                continue;
            }

            $module = $modules[$targetModule];
            $basePath = $module['base_path'] ?? '';

            foreach ($files as $targetFile => $stickers) {
                $sourceFilePath = $basePath . str_replace('/', DIRECTORY_SEPARATOR, $targetFile);

                if (!file_exists($sourceFilePath)) {
                    continue;
                }

                // 读取并压缩源文件
                $sourceContent = file_get_contents($sourceFilePath);
                $minifiedSource = $this->codeMinifier->minify($sourceContent);

                // 检测该文件的冲突
                $conflicts = $this->detectFileConflicts($targetModule, $targetFile, $stickers, $minifiedSource);

                if (!empty($conflicts)) {
                    $allConflicts[] = [
                        'target_module' => $targetModule,
                        'target_file' => $targetFile,
                        'conflicts' => $conflicts
                    ];
                }
            }
        }

        return $allConflicts;
    }

    /**
     * 检测单个文件的冲突
     *
     * @param string $targetModule 目标模块
     * @param string $targetFile 目标文件
     * @param array $stickers Sticker 列表
     * @param string $minifiedSource 压缩后的源文件内容
     * @return array 冲突列表
     */
    private function detectFileConflicts(
        string $targetModule,
        string $targetFile,
        array $stickers,
        string $minifiedSource
    ): array {
        $conflicts = [];

        // 按目标代码分组
        $targetGroups = [];

        foreach ($stickers as $stickerIndex => $stickerInfo) {
            $sourceModule = $stickerInfo['source_module'];
            $stickerFile = $stickerInfo['sticker_file'];
            $actions = $stickerInfo['actions'] ?? [];

            foreach ($actions as $actionIndex => $action) {
                $target = $action['target'] ?? '';
                $position = $action['position'] ?? 'all';

                if (empty($target)) {
                    continue;
                }

                // 查找所有匹配位置
                $matches = $this->codeMinifier->findMatches($minifiedSource, $target);
                if (empty($matches)) {
                    continue;
                }

                // 获取要匹配的索引列表
                $indexes = $this->codeMinifier->getPositionIndexes($position, count($matches));

                // 使用目标代码作为键（压缩后的）
                $targetKey = md5($target);

                if (!isset($targetGroups[$targetKey])) {
                    $targetGroups[$targetKey] = [
                        'target' => $target,
                        'matches' => $matches,
                        'sticker_actions' => []
                    ];
                }

                // 记录该 Sticker 的修改位置
                $targetGroups[$targetKey]['sticker_actions'][] = [
                    'source_module' => $sourceModule,
                    'sticker_file' => $stickerFile,
                    'sticker_index' => $stickerIndex,
                    'action_index' => $actionIndex,
                    'position' => $position,
                    'indexes' => $indexes
                ];
            }
        }

        // 检测每个目标代码组内的冲突
        foreach ($targetGroups as $targetKey => $group) {
            $stickerActions = $group['sticker_actions'];

            // 如果只有一个 Sticker 修改该目标代码，不冲突
            if (count($stickerActions) <= 1) {
                continue;
            }

            // 检查同一索引位置的冲突
            $indexConflicts = $this->checkIndexConflicts($stickerActions);

            if (!empty($indexConflicts)) {
                $conflicts[] = [
                    'target_code' => substr($group['target'], 0, 100),
                    'target_key' => $targetKey,
                    'total_matches' => count($group['matches']),
                    'conflicts' => $indexConflicts
                ];
            }
        }

        return $conflicts;
    }

    /**
     * 检查索引冲突
     * 只有当同一索引位置被多个 Sticker 修改时才冲突
     *
     * @param array $stickerActions Sticker 操作列表
     * @return array 冲突列表
     */
    private function checkIndexConflicts(array $stickerActions): array
    {
        $conflicts = [];

        // 按索引分组，统计每个索引被哪些 Sticker 修改
        $indexMap = [];

        foreach ($stickerActions as $action) {
            $indexes = $action['indexes'] ?? [];
            foreach ($indexes as $index) {
                if (!isset($indexMap[$index])) {
                    $indexMap[$index] = [];
                }
                $indexMap[$index][] = $action;
            }
        }

        // 找出被多个 Sticker 修改的索引
        foreach ($indexMap as $index => $actions) {
            if (count($actions) > 1) {
                $conflicts[] = [
                    'index' => $index,
                    'sticker_actions' => array_map(function ($action) {
                        return [
                            'source_module' => $action['source_module'],
                            'sticker_file' => $action['sticker_file'],
                            'position' => $action['position']
                        ];
                    }, $actions)
                ];
            }
        }

        return $conflicts;
    }

    /**
     * 生成冲突错误消息
     *
     * @param array $conflicts 冲突列表
     * @return string
     */
    public function formatConflictMessage(array $conflicts): string
    {
        $messages = [];

        foreach ($conflicts as $conflict) {
            $targetModule = $conflict['target_module'];
            $targetFile = $conflict['target_file'];
            $fileConflicts = $conflict['conflicts'];

            $msg = "文件: {$targetModule}::{$targetFile}\n";
            $msg .= "冲突详情:\n";

            foreach ($fileConflicts as $fileConflict) {
                $targetCode = $fileConflict['target_code'];
                $indexConflicts = $fileConflict['conflicts'];

                $msg .= "  目标代码: " . substr($targetCode, 0, 100) . "...\n";
                $msg .= "  匹配总数: {$fileConflict['total_matches']}\n";

                foreach ($indexConflicts as $indexConflict) {
                    $index = $indexConflict['index'];
                    $actions = $indexConflict['sticker_actions'];

                    $msg .= "    索引 {$index} 被以下 Sticker 修改:\n";
                    foreach ($actions as $action) {
                        $msg .= "      - 来源模块: {$action['source_module']}\n";
                        $msg .= "        Sticker 文件: {$action['sticker_file']}\n";
                        $msg .= "        位置参数: {$action['position']}\n";
                    }
                }
            }

            $messages[] = $msg;
        }

        return "检测到 Sticker 冲突:\n\n" . implode("\n---\n\n", $messages);
    }
}

