<?php

declare(strict_types=1);

namespace Weline\Cdn\Console\Command;

use Weline\Cdn\Model\CrawlerPolicyState;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;

class CrawlerPolicyStatus extends CommandAbstract implements CommandInterface
{
    public function tip(): string
    {
        return __('查看站点爬虫策略边缘同步状态');
    }

    /**
     * @return list<string>
     */
    public function aliases(): array
    {
        return [
            'cdn:crawler-policy:status',
            'cdn:crawler-policy-status',
        ];
    }

    public function help(): array|string
    {
        return [
            __('用法: php bin/w cdn:crawler-policy:status [选项]'),
            __(''),
            __('选项:'),
            __('  --website=ID   只看指定网站'),
            __('  --all          查看全部'),
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

        try {
            /** @var CrawlerPolicyState $model */
            $model = ObjectManager::getInstance(CrawlerPolicyState::class, [], false);
            $query = $model->reset();
            if (!$all) {
                $query->where(CrawlerPolicyState::schema_fields_WEBSITE_ID, (int)$websiteId);
            }
            $items = $query->select()->fetch()->getItems();
        } catch (\Throwable $throwable) {
            $this->printer->error(__('读取状态失败：%{1}', [$throwable->getMessage()]));
            return;
        }

        $rows = [];
        foreach (\is_array($items) ? $items : [] as $item) {
            if (!$item instanceof CrawlerPolicyState) {
                continue;
            }
            $rows[] = [
                'website_id' => (int)$item->getData(CrawlerPolicyState::schema_fields_WEBSITE_ID),
                'domain_id' => (int)$item->getData(CrawlerPolicyState::schema_fields_DOMAIN_ID),
                'zone_id' => (string)$item->getData(CrawlerPolicyState::schema_fields_ZONE_ID),
                'status' => (string)$item->getData(CrawlerPolicyState::schema_fields_STATUS),
                'desired_hash' => (string)$item->getData(CrawlerPolicyState::schema_fields_DESIRED_HASH),
                'applied_hash' => (string)$item->getData(CrawlerPolicyState::schema_fields_APPLIED_HASH),
                'attempts' => (int)$item->getData(CrawlerPolicyState::schema_fields_ATTEMPTS),
                'last_error' => (string)$item->getData(CrawlerPolicyState::schema_fields_LAST_ERROR),
                'synced_at' => (string)$item->getData(CrawlerPolicyState::schema_fields_SYNCED_AT),
            ];
        }

        if ($rows === []) {
            $this->printer->warning(__('暂无同步状态'));
            return;
        }

        if ($json) {
            $this->printer->note((string)\json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            return;
        }

        foreach ($rows as $row) {
            $this->printer->note(\sprintf(
                'website=%d domain=%d zone=%s status=%s attempts=%d hash=%s error=%s synced_at=%s',
                $row['website_id'],
                $row['domain_id'],
                $row['zone_id'],
                $row['status'],
                $row['attempts'],
                \substr($row['desired_hash'], 0, 12),
                $row['last_error'] !== '' ? $row['last_error'] : '-',
                $row['synced_at'] !== '' ? $row['synced_at'] : '-',
            ));
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
}
