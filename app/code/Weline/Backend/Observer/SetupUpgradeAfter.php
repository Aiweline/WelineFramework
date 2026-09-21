<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Backend\Observer;

use Weline\Backend\Service\ChannelAdapterCollector;
use Weline\Backend\Setup\EnsureAdmin;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * 系统升级完成后，确保默认管理员（admin）存在且拥有 role_id=1。
 * 避免升级后登录提示「用户没有分配角色」。
 * 同时源码级校验通知渠道 Adapter 接口合规，缺方法只告警不 Fatal。
 */
class SetupUpgradeAfter implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        /** @var EnsureAdmin $ensureAdmin */
        $ensureAdmin = ObjectManager::getInstance(EnsureAdmin::class);
        $ensureAdmin->ensure();

        $this->validateNotificationChannelAdapters();
    }

    private function validateNotificationChannelAdapters(): void
    {
        try {
            /** @var ChannelAdapterCollector $collector */
            $collector = ObjectManager::getInstance(ChannelAdapterCollector::class);
            ChannelAdapterCollector::resetCache();
            foreach ($collector->validateRegisteredContracts() as $row) {
                if (!empty($row['ok'])) {
                    continue;
                }
                $missing = implode(', ', $row['missing'] ?? []);
                $class = (string)($row['class'] ?? '');
                $note = (string)($row['note'] ?? '');
                if (function_exists('w_log_warning')) {
                    w_log_warning(
                        "ChannelAdapter interface contract failed on upgrade: {$class}; missing=[{$missing}]; note={$note}",
                        ['file' => $row['file'] ?? null],
                        'notification'
                    );
                }
            }
        } catch (\Throwable $e) {
            if (function_exists('w_log_warning')) {
                w_log_warning(
                    'ChannelAdapter contract validation skipped: ' . $e->getMessage(),
                    [],
                    'notification'
                );
            }
        }
    }
}
