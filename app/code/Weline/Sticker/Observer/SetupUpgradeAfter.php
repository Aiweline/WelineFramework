<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Sticker\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Sticker\Model\StickerLog;
use Weline\Sticker\Service\ConflictDetector;
use Weline\Sticker\Service\NotificationService;
use Weline\Sticker\Service\RuleParser;
use Weline\Sticker\Service\RuleScanner;
use Weline\Sticker\Service\StickerRegistry;

/**
 * setup:upgrade 后观察者
 * 检测冲突、更新注册表、记录日志
 */
class SetupUpgradeAfter implements ObserverInterface
{
    private RuleScanner $ruleScanner;
    private RuleParser $ruleParser;
    private StickerRegistry $stickerRegistry;
    private ConflictDetector $conflictDetector;
    private NotificationService $notificationService;

    public function __construct(
        RuleScanner $ruleScanner,
        RuleParser $ruleParser,
        StickerRegistry $stickerRegistry,
        ConflictDetector $conflictDetector,
        NotificationService $notificationService
    ) {
        $this->ruleScanner = $ruleScanner;
        $this->ruleParser = $ruleParser;
        $this->stickerRegistry = $stickerRegistry;
        $this->conflictDetector = $conflictDetector;
        $this->notificationService = $notificationService;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        try {
            // 1. 扫描所有 Sticker 文件
            $scannedStickers = $this->ruleScanner->scanAllStickers();

            if (empty($scannedStickers)) {
                return; // 没有 Sticker，无需处理
            }

            // 2. 构建注册表
            $registry = $this->stickerRegistry->buildRegistryFromScanned($scannedStickers, $this->ruleParser);

            // 3. 检测冲突
            $conflicts = $this->conflictDetector->detectConflicts($registry);

            if (!empty($conflicts)) {
                // 发送通知
                foreach ($conflicts as $conflict) {
                    $this->notificationService->notifyConflict(
                        $conflict['target_module'],
                        $conflict['target_file'],
                        $conflict['conflicts']
                    );
                }

                // 记录日志
                foreach ($conflicts as $conflict) {
                    /** @var StickerLog $log */
                    $log = ObjectManager::getInstance(StickerLog::class);
                    foreach ($conflict['conflicts'] as $fileConflict) {
                        foreach ($fileConflict['conflicts'] as $indexConflict) {
                            foreach ($indexConflict['sticker_actions'] as $action) {
                                $log->log(
                                    'error',
                                    $conflict['target_module'],
                                    $conflict['target_file'],
                                    $action['source_module'],
                                    $action['sticker_file'],
                                    "Sticker 冲突：索引 {$indexConflict['index']} 被多个 Sticker 修改",
                                    [
                                        'index' => $indexConflict['index'],
                                        'conflicting_actions' => $indexConflict['sticker_actions']
                                    ]
                                );
                            }
                        }
                    }
                }

                // 抛出异常，中断升级流程
                $message = $this->conflictDetector->formatConflictMessage($conflicts);
                throw new \Exception($message);
            }

            // 4. 保存注册表
            $this->stickerRegistry->saveRegistry($registry);

        } catch (\Exception $e) {
            // 如果是冲突异常，直接抛出
            if (strpos($e->getMessage(), '检测到 Sticker 冲突') !== false) {
                throw $e;
            }

            // 其他异常记录日志
            error_log("Sticker setup_upgrade_after Observer 执行失败: " . $e->getMessage());
        }
    }
}

