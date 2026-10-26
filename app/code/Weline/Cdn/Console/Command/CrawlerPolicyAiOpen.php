<?php

declare(strict_types=1);

namespace Weline\Cdn\Console\Command;

use Weline\Cdn\Model\Domain;
use Weline\Cdn\Service\CloudflareZoneAiSettingsService;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * 默认放开 AI 爬虫；显式封禁由 weline-crawler WAF 规则负责。
 */
class CrawlerPolicyAiOpen extends CommandAbstract implements CommandInterface
{
    public function tip(): string
    {
        return __('默认放开 Cloudflare Zone 级 AI 爬虫（显式封禁走 WAF）');
    }

    /**
     * @return list<string>
     */
    public function aliases(): array
    {
        return [
            'cdn:crawler-policy:ai-open',
            'cdn:crawler-policy-ai-open',
        ];
    }

    public function help(): array|string
    {
        return [
            __('用法: php bin/w cdn:crawler-policy:ai-open --website=ID [--apply]'),
            __(''),
            __('默认 dry-run，只输出当前值与最小 diff；加 --apply 才写入。'),
            __('  --website=ID   网站 ID（默认站为 0）'),
            __('  --all          所有已绑定 Cloudflare 域名的网站'),
            __('  --apply        实际写入差异字段'),
            __('  --json         输出 JSON'),
        ];
    }

    public function execute(array $args = [], array $data = [])
    {
        $websiteId = $this->websiteArg($args, $data);
        $all = isset($data['all']) || \in_array('--all', $args, true);
        $apply = isset($data['apply']) || \in_array('--apply', $args, true);
        $json = isset($data['json']) || \in_array('--json', $args, true);
        if (!$all && $websiteId === null) {
            $this->printer->warning(__('请指定 --website=ID 或 --all'));
            return;
        }

        $websiteIds = $all ? $this->websiteIds() : [(int)$websiteId];
        /** @var CloudflareZoneAiSettingsService $service */
        $service = ObjectManager::getInstance(CloudflareZoneAiSettingsService::class);
        $results = [];

        foreach ($websiteIds as $id) {
            foreach ($this->domains($id) as $domain) {
                try {
                    $results[] = ['website_id' => $id] + $service->apply($domain, !$apply);
                } catch (\Throwable $throwable) {
                    $results[] = [
                        'website_id' => $id,
                        'domain_id' => (int)$domain->getData(Domain::schema_fields_DOMAIN_ID),
                        'error' => $throwable->getMessage(),
                    ];
                }
            }
        }

        if ($json) {
            $this->printer->note((string)\json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            return;
        }

        foreach ($results as $row) {
            $this->printer->note((string)\json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        }
        if (!$apply) {
            $this->printer->success(__('Dry-run 完成：加 --apply 才写入'));
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
        $ids = [];
        foreach ($this->domains(null) as $domain) {
            $ids[(int)$domain->getData(Domain::schema_fields_SITE_ID)] = true;
        }

        return \array_keys($ids);
    }

    /**
     * @return list<Domain>
     */
    private function domains(?int $websiteId): array
    {
        try {
            /** @var Domain $model */
            $model = ObjectManager::getInstance(Domain::class, [], false);
            $query = $model->reset()->where(Domain::schema_fields_ENABLED, 1);
            if ($websiteId !== null) {
                $query->where(Domain::schema_fields_SITE_ID, $websiteId);
            }
            $items = $query->select()->fetch()->getItems();
        } catch (\Throwable) {
            return [];
        }

        $domains = [];
        foreach (\is_array($items) ? $items : [] as $item) {
            if (!$item instanceof Domain) {
                continue;
            }
            $adapter = \strtolower((string)$item->getData(Domain::schema_fields_ADAPTER));
            if ($adapter !== '' && !\str_contains($adapter, 'cloudflare')) {
                continue;
            }
            $domains[] = $item;
        }

        return $domains;
    }
}
