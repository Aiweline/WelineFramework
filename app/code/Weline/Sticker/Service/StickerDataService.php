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
use Weline\Sticker\Service\Compiler;
use Weline\Sticker\Service\NotificationService;

/**
 * Sticker 数据服务
 * 提供Sticker信息的读取和统计功能
 */
class StickerDataService
{
    private StickerRegistry $stickerRegistry;
    private Compiler $compiler;
    private ConflictDetector $conflictDetector;
    private NotificationService $notificationService;
    /** @var array<string,bool> */
    private array $notifiedInvalidStickers = [];

    public function __construct(
        StickerRegistry $stickerRegistry,
        Compiler $compiler,
        ConflictDetector $conflictDetector,
        NotificationService $notificationService
    ) {
        $this->stickerRegistry = $stickerRegistry;
        $this->compiler = $compiler;
        $this->conflictDetector = $conflictDetector;
        $this->notificationService = $notificationService;
    }

    /**
     * 获取所有Sticker信息
     *
     * @return array
     */
    public function getAllStickers(): array
    {
        $registry = $this->stickerRegistry->getRegistry();
        $result = [];

        foreach ($registry as $targetModule => $files) {
            foreach ($files as $targetFile => $stickerInfos) {
                foreach ($stickerInfos as $stickerInfo) {
                    $sourceModule = $stickerInfo['source_module'] ?? '';
                    $stickerFile = $stickerInfo['sticker_file'] ?? '';
                    $actions = $stickerInfo['actions'] ?? [];
                    
                    // 检查Sticker状态
                    $status = $this->checkStickerStatus($targetModule, $targetFile, $sourceModule, $stickerFile);
                    
                    // 检查是否有冲突
                    $hasConflict = $this->conflictDetector->hasConflict($targetModule, $targetFile, $sourceModule);
                    
                    $result[] = [
                        'target_module' => $targetModule,
                        'target_file' => $targetFile,
                        'source_module' => $sourceModule,
                        'sticker_file' => $stickerFile,
                        'sticker_relative_path' => $stickerInfo['sticker_relative_path'] ?? '',
                        'actions' => $actions,
                        'actions_count' => count($actions),
                        'status' => $status,
                        'has_conflict' => $hasConflict,
                        'is_active' => $status['is_active'] ?? false,
                        'error_message' => $status['error_message'] ?? ''
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * 获取Sticker统计信息
     *
     * @return array
     */
    public function getStickerStats(): array
    {
        $stickers = $this->getAllStickers();
        
        $stats = [
            'total_stickers' => count($stickers),
            'active_stickers' => 0,
            'inactive_stickers' => 0,
            'stickers_with_conflicts' => 0,
            'total_target_modules' => 0,
            'total_source_modules' => 0,
            'total_actions' => 0,
            'target_modules' => [],
            'source_modules' => []
        ];

        foreach ($stickers as $sticker) {
            // 统计活跃/非活跃Sticker
            if ($sticker['is_active']) {
                $stats['active_stickers']++;
            } else {
                $stats['inactive_stickers']++;
            }
            
            // 统计有冲突的Sticker
            if ($sticker['has_conflict']) {
                $stats['stickers_with_conflicts']++;
            }
            
            // 统计操作数
            $stats['total_actions'] += $sticker['actions_count'];
            
            // 统计目标模块
            $targetModule = $sticker['target_module'];
            if (!isset($stats['target_modules'][$targetModule])) {
                $stats['target_modules'][$targetModule] = 0;
            }
            $stats['target_modules'][$targetModule]++;
            
            // 统计来源模块
            $sourceModule = $sticker['source_module'];
            if (!isset($stats['source_modules'][$sourceModule])) {
                $stats['source_modules'][$sourceModule] = 0;
            }
            $stats['source_modules'][$sourceModule]++;
        }
        
        $stats['total_target_modules'] = count($stats['target_modules']);
        $stats['total_source_modules'] = count($stats['source_modules']);

        return $stats;
    }

    /**
     * 搜索Sticker
     *
     * @param string $searchTerm
     * @param string $searchType all|target_module|target_file|source_module
     * @return array
     */
    public function searchStickers(string $searchTerm, string $searchType = 'all'): array
    {
        $stickers = $this->getAllStickers();
        $results = [];

        foreach ($stickers as $sticker) {
            $matched = false;
            $matchReasons = [];

            if (empty($searchTerm)) {
                $results[] = $sticker;
                continue;
            }

            // 按类型搜索
            if ($searchType === 'all' || $searchType === 'target_module') {
                if (stripos($sticker['target_module'], $searchTerm) !== false) {
                    $matched = true;
                    $matchReasons[] = '目标模块';
                }
            }

            if ($searchType === 'all' || $searchType === 'target_file') {
                if (stripos($sticker['target_file'], $searchTerm) !== false) {
                    $matched = true;
                    $matchReasons[] = '目标文件';
                }
            }

            if ($searchType === 'all' || $searchType === 'source_module') {
                if (stripos($sticker['source_module'], $searchTerm) !== false) {
                    $matched = true;
                    $matchReasons[] = '来源模块';
                }
            }

            if ($matched) {
                $sticker['match_reasons'] = array_unique($matchReasons);
                $results[] = $sticker;
            }
        }

        return $results;
    }

    /**
     * 按模块筛选Sticker
     *
     * @param string $moduleName 模块名
     * @param string $moduleType target|source
     * @return array
     */
    public function getStickersByModule(string $moduleName, string $moduleType = 'target'): array
    {
        $stickers = $this->getAllStickers();
        $results = [];

        foreach ($stickers as $sticker) {
            if ($moduleType === 'target' && $sticker['target_module'] === $moduleName) {
                $results[] = $sticker;
            } elseif ($moduleType === 'source' && $sticker['source_module'] === $moduleName) {
                $results[] = $sticker;
            }
        }

        return $results;
    }

    /**
     * 获取模块统计信息
     *
     * @param string $moduleName 模块名
     * @return array
     */
    public function getModuleStats(string $moduleName): array
    {
        $stickers = $this->getAllStickers();
        
        $stats = [
            'module' => $moduleName,
            'target_stickers' => 0,
            'source_stickers' => 0,
            'active_target_stickers' => 0,
            'active_source_stickers' => 0,
            'conflicts_as_target' => 0,
            'conflicts_as_source' => 0,
            'target_files' => [],
            'source_files' => []
        ];

        foreach ($stickers as $sticker) {
            // 统计作为目标模块的Sticker
            if ($sticker['target_module'] === $moduleName) {
                $stats['target_stickers']++;
                if ($sticker['is_active']) {
                    $stats['active_target_stickers']++;
                }
                if ($sticker['has_conflict']) {
                    $stats['conflicts_as_target']++;
                }
                
                $targetFile = $sticker['target_file'];
                if (!isset($stats['target_files'][$targetFile])) {
                    $stats['target_files'][$targetFile] = 0;
                }
                $stats['target_files'][$targetFile]++;
            }

            // 统计作为来源模块的Sticker
            if ($sticker['source_module'] === $moduleName) {
                $stats['source_stickers']++;
                if ($sticker['is_active']) {
                    $stats['active_source_stickers']++;
                }
                if ($sticker['has_conflict']) {
                    $stats['conflicts_as_source']++;
                }
                
                $stickerFile = $sticker['sticker_relative_path'];
                if (!isset($stats['source_files'][$stickerFile])) {
                    $stats['source_files'][$stickerFile] = 0;
                }
                $stats['source_files'][$stickerFile]++;
            }
        }

        return $stats;
    }

    /**
     * 检查Sticker状态
     *
     * @param string $targetModule 目标模块
     * @param string $targetFile 目标文件
     * @param string $sourceModule 来源模块
     * @return array
     */
    public function checkStickerStatus(string $targetModule, string $targetFile, string $sourceModule, string $stickerFile = ''): array
    {
        $status = [
            'is_active' => false,
            'error_message' => '',
            'compiled_file_exists' => false,
            'source_file_exists' => false,
            'sticker_file_exists' => false
        ];

        try {
            // 检查源文件是否存在
            $modules = Env::getInstance()->getModuleList();
            if (isset($modules[$targetModule])) {
                $basePath = $modules[$targetModule]['base_path'] ?? '';
                $sourceFilePath = $basePath . str_replace('/', DIRECTORY_SEPARATOR, $targetFile);
                $status['source_file_exists'] = file_exists($sourceFilePath);
            }

            // 检查Sticker文件是否存在
            $registry = $this->stickerRegistry->getRegistry();
            if (isset($registry[$targetModule][$targetFile])) {
                foreach ($registry[$targetModule][$targetFile] as $stickerInfo) {
                    if (($stickerInfo['source_module'] ?? '') === $sourceModule) {
                        $stickerFile = $stickerInfo['sticker_file'] ?? '';
                        $status['sticker_file_exists'] = file_exists($stickerFile);
                        break;
                    }
                }
            }

            // 检查编译后的文件是否存在
            $compiledFilePath = $this->compiler->getCompiledFilePath($targetModule, $targetFile);
            $status['compiled_file_exists'] = file_exists($compiledFilePath);

            // 判断Sticker是否生效
            $status['is_active'] = $status['source_file_exists'] && 
                                   $status['sticker_file_exists'] && 
                                   $status['compiled_file_exists'];

            // 如果不活跃，设置错误消息
            if (!$status['is_active']) {
                $errors = [];
                if (!$status['source_file_exists']) {
                    $errors[] = '源文件不存在';
                }
                if (!$status['sticker_file_exists']) {
                    $errors[] = 'Sticker文件不存在';
                }
                if (!$status['compiled_file_exists']) {
                    $errors[] = '编译文件不存在，可能需要重新编译';
                }
                $status['error_message'] = implode('; ', $errors);
                if (!empty($status['error_message'])) {
                    $this->notifyStickerInvalid($targetModule, $targetFile, $sourceModule, $stickerFile, $status['error_message']);
                }
            }
        } catch (\Exception $e) {
            $status['error_message'] = '检查状态时发生错误: ' . $e->getMessage();
            $this->notifyStickerInvalid($targetModule, $targetFile, $sourceModule, $stickerFile, $status['error_message']);
        }

        return $status;
    }

    /**
     * 当 Sticker 不生效时发送通知
     */
    private function notifyStickerInvalid(string $targetModule, string $targetFile, string $sourceModule, string $stickerFile, string $reason): void
    {
        $key = implode('|', [$targetModule, $targetFile, $sourceModule]);
        if (isset($this->notifiedInvalidStickers[$key])) {
            return;
        }
        $this->notifiedInvalidStickers[$key] = true;
        if (empty($reason)) {
            $reason = __('未知原因');
        }
        $this->notificationService->notifyRuleInvalid(
            $targetModule,
            $targetFile,
            $sourceModule,
            $stickerFile,
            $reason
        );
    }

    /**
     * 刷新Sticker注册表
     *
     * @return array
     */
    public function refreshRegistry(): array
    {
        $result = [
            'success' => false,
            'message' => '',
            'stats' => []
        ];

        try {
            // 强制重新加载注册表
            $this->stickerRegistry->getRegistry(true);
            
            // 获取刷新后的统计信息
            $result['stats'] = $this->getStickerStats();
            $result['success'] = true;
            $result['message'] = 'Sticker注册表已成功刷新';
        } catch (\Exception $e) {
            $result['message'] = '刷新Sticker注册表失败: ' . $e->getMessage();
        }

        return $result;
    }
}
