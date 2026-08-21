<?php

declare(strict_types=1);

namespace Weline\Theme\Console\Theme\Ui;

use Weline\Backend\Setup\Ui\IconDataMigrator;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;

final class RestoreIcons extends CommandAbstract
{
    public function __construct(private readonly IconDataMigrator $migrator)
    {
    }

    public function execute(array $args = [], array $data = []): int
    {
        if (!isset($args['yes']) && !isset($args['y']) && !isset($args['-y'])) {
            $this->printer->error(__('恢复会写回迁移前的字体图标值；请显式使用 --yes 确认。'));
            return 2;
        }

        $snapshot = trim((string)($args['snapshot'] ?? '')) ?: null;
        $result = $this->migrator->restore($snapshot);
        $this->printer->success(__('已从快照恢复 %{1} 个图标值，跳过 %{2} 个已被后续修改的值。', [
            $result['restored'],
            $result['skipped'],
        ]));
        $this->printer->note(__('快照：%{1}', [$result['snapshot']]));
        return 0;
    }

    public function tip(): string
    {
        return __('按 Weline UI 2.0 图标迁移快照恢复数据');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'theme:ui:restore-icons',
            $this->tip(),
            [
                '--snapshot=<path>' => __('可选快照路径；默认使用 var/migration/weline-ui-2.0-icons.json'),
                '--yes' => __('确认执行数据恢复'),
            ],
            [],
            [__('恢复默认快照') => 'php bin/w theme:ui:restore-icons --yes'],
        );
    }
}
