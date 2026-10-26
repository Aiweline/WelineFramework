<?php

declare(strict_types=1);

namespace Weline\Cdn\Console\Command;

use Weline\Cdn\Model\Domain;
use Weline\Cdn\Service\CloudflareCrawlerPolicyPublisher;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;

class CrawlerPolicySync extends CommandAbstract implements CommandInterface
{
    public function tip(): string
    {
        return __('同步站点爬虫策略到 Cloudflare WAF');
    }

    /**
     * @return list<string>
     */
    public function aliases(): array
    {
        return [
            'cdn:crawler-policy:sync',
            'cdn:crawler-policy-sync',
        ];
    }

    public function help(): array|string
    {
        return [
            __('用法: php bin/w cdn:crawler-policy:sync [选项]'),
            __(''),
            __('选项:'),
            __('  --website=ID   指定网站 ID'),
            __('  --all          同步所有已绑定 Cloudflare 域名的网站'),
            __('  --dry-run      只输出计划，不调用 Cloudflare'),
            __('  --force        忽略 hash 强制重推'),
            __(''),
            __('示例:'),
            __('  php bin/w cdn:crawler-policy:sync --website=12 --dry-run'),
            __('  php bin/w cdn:crawler-policy:sync --all'),
        ];
    }

    public function execute(array $args = [], array $data = [])
    {
        $websiteId = $this->websiteArg($args, $data);
        $all = isset($data['all']) || \in_array('--all', $args, true);
        $dryRun = isset($data['dry-run']) || \in_array('--dry-run', $args, true);
        $force = isset($data['force']) || \in_array('--force', $args, true);
        if (!$all && $websiteId === null) {
            $this->printer->warning(__('请指定 --website=ID 或 --all'));
            return;
        }

        $ids = $all ? $this->websiteIds() : [(int)$websiteId];
        if ($ids === []) {
            $this->printer->warning(__('没有找到已绑定 Cloudflare 域名的网站'));
            return;
        }

        /** @var CloudflareCrawlerPolicyPublisher $publisher */
        $publisher = ObjectManager::getInstance(CloudflareCrawlerPolicyPublisher::class);

        foreach ($ids as $id) {
            $this->printer->note(__('网站 #%{1}', [$id]));
            try {
                $result = $publisher->publish($id, $dryRun, $force);
                $this->printer->note((string)\json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            } catch (\Throwable $throwable) {
                $this->printer->error(__('同步失败：%{1}', [$throwable->getMessage()]));
            }
        }

        if ($dryRun) {
            $this->printer->success(__('Dry-run 完成：未调用 Cloudflare'));
        }
    }

    /**
     * @param array<int|string, mixed> $args
     * @param array<string, mixed> $data
     */
    private function websiteArg(array $args, array $data): ?int
    {
        if (isset($data['website']) && $data['website'] !== '') {
            return (int)$data['website'];
        }
        foreach ($args as $arg) {
            if (\is_string($arg) && \str_starts_with($arg, '--website=')) {
                return (int)\substr($arg, \strlen('--website='));
            }
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private function websiteIds(): array
    {
        try {
            /** @var Domain $model */
            $model = ObjectManager::getInstance(Domain::class, [], false);
            $items = $model->reset()
                ->where(Domain::schema_fields_ENABLED, 1)
                ->select()
                ->fetch()
                ->getItems();
        } catch (\Throwable) {
            return [];
        }

        $ids = [];
        foreach (\is_array($items) ? $items : [] as $item) {
            if (!$item instanceof Domain) {
                continue;
            }
            $adapter = \strtolower((string)$item->getData(Domain::schema_fields_ADAPTER));
            if ($adapter !== '' && !\str_contains($adapter, 'cloudflare')) {
                continue;
            }
            $ids[(int)$item->getData(Domain::schema_fields_SITE_ID)] = true;
        }

        return \array_keys($ids);
    }
}
