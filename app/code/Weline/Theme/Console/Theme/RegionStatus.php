<?php

declare(strict_types=1);

namespace Weline\Theme\Console\Theme;

use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Theme\Service\Region\GlobalRegionConfigService;

/**
 * 全局区契约的只读状态：站点面板的数据面命令行版本。
 *
 * 面板 UI 依赖后台入口，本机/CI 里不一定可达；这条命令让契约状态（谁已启用、
 * 谁是 eager、对应哪个部件 code、走哪个水合端点）随时可查，也用于上线前核对。
 */
class RegionStatus implements CommandInterface
{
    public const ALIASES = ['theme:region-status'];

    public function __construct(
        private readonly Printing $printing,
    ) {
    }

    public function command(): string
    {
        return self::ALIASES[0];
    }

    public function tip(): string
    {
        return __('查看全局区片段契约状态（启用项 / eager / 部件 code / 水合端点）');
    }

    public function help(): array
    {
        return [
            'usage' => ['php bin/w theme:region-status [--region=head|body_open|body_end]'],
            'options' => [
                'region' => __('只看某个区'),
            ],
        ];
    }

    public function execute(array $args = [], array $options = []): void
    {
        /** @var GlobalRegionConfigService $service */
        $service = ObjectManager::getInstance(GlobalRegionConfigService::class);
        $only = \trim((string)($options['region'] ?? $args['region'] ?? ''));
        $items = $service->listContributions();
        if ($only !== '') {
            $items = \array_values(\array_filter(
                $items,
                static fn(array $item): bool => $item['region'] === $only,
            ));
        }

        if ($items === []) {
            $this->printing->warning(__('没有找到全局区片段'));

            return;
        }

        $rows = [];
        foreach ($items as $item) {
            $rows[] = [
                $item['id'],
                $item['region'],
                $item['group'],
                $item['eager'] ? 'eager' : 'async',
                $item['enabled'] ? 'on' : 'off',
                $item['widget_code'] !== '' ? $item['widget_code'] : '-',
            ];
        }
        $rows[] = [
            __('合计'),
            (string)\count($items),
            '',
            '',
            (string)\count(\array_filter($items, static fn(array $i): bool => $i['enabled'])),
            '',
        ];

        $this->printing->table(
            [__('片段 id'), __('区'), __('组'), __('加载'), __('开关'), __('部件 code')],
            $rows,
        );
        $this->printing->note(__('水合端点：/theme/frontend/widget/shell?fragment=<片段 id>（只渲染已启用片段）'));
    }
}
