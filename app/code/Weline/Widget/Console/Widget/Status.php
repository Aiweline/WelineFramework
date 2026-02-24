<?php

declare(strict_types=1);

namespace Weline\Widget\Console\Widget;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Widget\Service\WidgetRegistry;

/**
 * 查看部件注册表状态（类型数、部件总数）
 */
class Status extends CommandAbstract
{
    public function execute(array $args = [], array $data = [])
    {
        /** @var WidgetRegistry $registry */
        $registry = ObjectManager::getInstance(WidgetRegistry::class);
        $data = $registry->getRegistry(true);

        $types = is_array($data) ? array_keys($data) : [];
        $total = 0;
        foreach ($data as $type => $typeWidgets) {
            if (is_array($typeWidgets)) {
                $total += count($typeWidgets);
            }
        }

        if (empty($data)) {
            $this->printer->warning(__('部件注册表为空。请执行：php bin/w widget:refresh'));
            return;
        }

        $this->printer->success(__('部件注册表：%{1} 个类型，共 %{2} 个部件', [count($types), $total]));
        $this->printer->note(__('文件：generated/widgets.php'));
    }

    public function tip(): string
    {
        return __('查看部件注册表状态');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'widget:status',
            __('查看 generated/widgets.php 中的部件类型数与总个数'),
            [],
            [],
            ['查看状态' => 'php bin/w widget:status']
        );
    }
}
