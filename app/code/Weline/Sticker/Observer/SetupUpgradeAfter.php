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
use Weline\Sticker\Service\StickerRegistry;

/**
 * setup:upgrade 后观察者
 * 检测冲突、更新注册表、记录日志
 * 现在从 ExtendsData 读取数据，不再扫描文件系统
 */
class SetupUpgradeAfter implements ObserverInterface
{
    private RuleParser $ruleParser;
    private StickerRegistry $stickerRegistry;
    private ConflictDetector $conflictDetector;
    private NotificationService $notificationService;

    public function __construct(
        RuleParser $ruleParser,
        StickerRegistry $stickerRegistry,
        ConflictDetector $conflictDetector,
        NotificationService $notificationService
    ) {
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
            // 1. 从 ExtendsData 读取注册表（会自动解析 actions）
            $registry = $this->stickerRegistry->buildRegistryFromScanned([], $this->ruleParser);

            if (empty($registry)) {
                return; // 没有 Sticker，无需处理
            }

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

            // 4. 清除缓存（数据由 ExtendsRegistry 统一管理，这里只需要清除缓存）
            $this->stickerRegistry->clearCache();

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

