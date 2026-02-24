<?php

declare(strict_types=1);

namespace Weline\Database\Console\Db\Migrate;

use Weline\Database\Service\BackupService;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

class Restore implements CommandInterface
{
    private Printing $printing;

    public function __construct(Printing $printing)
    {
        $this->printing = $printing;
    }

    public function execute(array $args = [], array $data = []): void
    {
        /** @var BackupService $backupService */
        $backupService = ObjectManager::getInstance(BackupService::class);

        $backupId    = (int) ($args['backup-id'] ?? 0);
        $migrationId = (int) ($args['migration-id'] ?? 0);

        if ($backupId <= 0 && $migrationId <= 0) {
            $this->printing->error(__('请指定 --backup-id=N 或 --migration-id=N'));
            return;
        }

        if ($backupId > 0) {
            $this->printing->note(__("正在恢复备份 (backup_id: %{1})...", $backupId));
            $result = $backupService->restoreByBackupId($backupId);
            if ($result) {
                $this->printing->success(__("备份恢复完成 (backup_id: %{1})", $backupId));
            } else {
                $this->printing->error(__("备份恢复失败 (backup_id: %{1})", $backupId));
            }
            return;
        }

        $backups = $backupService->getBackupsByMigrationId($migrationId);
        if (empty($backups)) {
            $this->printing->warning(__("未找到迁移 %{1} 关联的备份记录", $migrationId));
            return;
        }

        $this->printing->note(__("正在恢复迁移 %{1} 的 %{2} 条备份...", [$migrationId, count($backups)]));

        $success = 0;
        $failed  = 0;
        foreach ($backups as $backup) {
            $bid = (int) $backup->getId();
            if ($backupService->restoreByBackupId($bid)) {
                $success++;
            } else {
                $failed++;
            }
        }

        $this->printing->success(__("恢复完成: 成功 %{1}，失败 %{2}", [$success, $failed]));
    }

    public function tip(): string
    {
        return __('数据库迁移备份恢复命令');
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            '',
            $this->tip(),
            [
                '--backup-id'    => __('备份记录 ID（恢复单条备份）'),
                '--migration-id' => __('迁移记录 ID（恢复该迁移的所有备份）'),
                '-h, --help'     => __('显示帮助信息'),
            ],
            [
                'php bin/w db:migrate:restore --backup-id=123',
                'php bin/w db:migrate:restore --migration-id=5',
            ],
            []
        );
    }
}
