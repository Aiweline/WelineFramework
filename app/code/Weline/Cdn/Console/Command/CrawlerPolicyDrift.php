<?php

declare(strict_types=1);

namespace Weline\Cdn\Console\Command;

use Weline\Cdn\Model\Domain;
use Weline\Cdn\Service\CloudflareCrawlerPolicyPublisher;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;

class CrawlerPolicyDrift extends CommandAbstract implements CommandInterface
{
    public function tip(): string
    {
        return __('检查站点爬虫策略与 Cloudflare 边缘规则的漂移');
    }

    /**
     * @return list<string>
     */
    public function aliases(): array
    {
        return [
            'cdn:crawler-policy:drift',
            'cdn:crawler-policy-drift',
        ];
    }

    public function help(): array|string
    {
        return [
            __('用法: php bin/w cdn:crawler-policy:drift [选项]'),
            __(''),
            __('选项:'),
            __('  --website=ID   指定网站 ID'),
            __('  --all          检查全部'),
            __('  --json         输出 JSON'),
        ];
    }

    public function execute(array $args = [], array $data = [])
    {
        $websiteId = $this->websiteArg($args, $data);
        $all = isset($data['all']) || \in_array('--all', $args, true);
        $json = isset($data['json']) || \in_array('--json', $args, true);
        if (!$all && $websiteId === null) {
            $this->printer->warning(__('请指定 --website=ID 或 --all'));
            return;
        }

        $ids = $all ? $this->websiteIds() : [(int)$websiteId];
        /** @var CloudflareCrawlerPolicyPublisher $publisher */
        $publisher = ObjectManager::getInstance(CloudflareCrawlerPolicyPublisher::class);

        $drift = 0;
        $results = [];
        foreach ($ids as $id) {
            try {
                $result = $publisher->inspect($id);
                $results[] = $result;
                foreach ((array)($result['results'] ?? []) as $row) {
                    if (($row['status'] ?? '') === 'drift') {
                        $drift++;
                    }
                }
            } catch (\Throwable $throwable) {
                $results[] = ['website_id' => $id, 'error' => $throwable->getMessage()];
            }
        }

        if ($json) {
            $this->printer->note((string)\json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            foreach ($results as $result) {
                foreach ((array)($result['results'] ?? []) as $row) {
                    $this->printer->note(\sprintf(
                        'website=%d domain=%d status=%s desired=%d applied=%d',
                        (int)($result['website_id'] ?? 0),
                        (int)($row['domain_id'] ?? 0),
                        (string)($row['status'] ?? ''),
                        (int)($row['desired_rules'] ?? 0),
                        (int)($row['applied_rules'] ?? 0),
                    ));
                }
            }
        }

        if ($drift > 0) {
            $this->printer->warning(__('发现 %{1} 个域名存在漂移', [$drift]));
            return;
        }
        $this->printer->success(__('未发现漂移'));
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
