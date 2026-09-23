<?php

declare(strict_types=1);

namespace Weline\Theme\Console\Theme\Layout;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator;

/** 从权威布局身份生成第二版绑定，保留旧文件供兼容读取与核对。 */
final class MigrateBindings extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): void
    {
        $coordinator = ObjectManager::getInstance(ThemeLayoutEntityBakeCoordinator::class);
        $this->printer->note('开始迁移主题布局绑定：按数据库有效身份固化，不删除旧产物。');
        $count = $coordinator->rebakeAfterInjectionCollect(null, []);
        $this->printer->success('主题布局绑定迁移完成，处理数量：' . $count);

        $report = $coordinator->getLastRebakeReport();
        $unmapped = $report['unmapped'] ?? [];
        $this->printer->note('无法映射的历史身份数量：' . count($unmapped));
        foreach ($unmapped as $identity) {
            $detail = is_array($identity)
                ? json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : (string)$identity;
            $this->printer->note('无法映射的历史身份：' . $detail);
        }
        if ($unmapped !== []) {
            $this->printer->note('这些旧产物已保留，请按上述身份和原因核对历史版本记录。');
        }
    }

    public function tip(): string
    {
        return '将数据库有效主题布局迁移为结构与配置分离的绑定，保留旧产物';
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp('theme:layout:migrate-bindings', $this->tip(), [], [], []);
    }
}
