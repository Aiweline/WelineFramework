<?php

declare(strict_types=1);

namespace Weline\AppStore\Console\Appstore\Sync;

use Weline\AppStore\Service\InstalledModuleMetaService;
use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;

class Tags extends CommandAbstract
{
    public const ALIASES = ['appstore:sync-tags'];

    public function __construct(
        private readonly InstalledModuleMetaService $metaService
    ) {
    }

    public function execute(array $args = [], array $data = [])
    {
        $locale = trim((string)($args['locale'] ?? $args['l'] ?? ''));
        if ($locale === '') {
            $locale = (string)w_env('user.lang', Env::get('user.lang', 'zh_Hans_CN'));
        }

        $result = $this->metaService->syncPlatformTags($locale);
        if (empty($result['success'])) {
            $this->printer->error((string)($result['message'] ?? __('标签注册表同步失败')));
            return;
        }

        $this->printer->success((string)($result['message'] ?? __('标签注册表已同步')));
        $this->printer->note('count: ' . (int)($result['count'] ?? 0));
        if (!empty($result['path'])) {
            $this->printer->note('path: ' . (string)$result['path']);
        }
    }

    public function tip(): string
    {
        return '同步 AppStore Marketplace 标签注册表';
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'appstore:sync-tags',
            $this->tip(),
            [
                '-l, --locale' => '平台标签 locale，默认使用 user.lang',
            ],
            [
                'php bin/w appstore:sync-tags --locale=zh_Hans_CN' => '同步平台标签注册表缓存',
            ],
            []
        );
    }
}
