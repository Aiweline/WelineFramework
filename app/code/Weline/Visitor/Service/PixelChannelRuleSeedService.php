<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\PixelChannel;
use Weline\Visitor\Model\PixelSource;

/**
 * B08：将 PixelSource 映射为 pixel_channel rule 种子（不做 S3 归因匹配）。
 * 幂等：同 (website_id=0, kind=rule, code) 已存在则跳过或更新 match_value/name。
 */
class PixelChannelRuleSeedService
{
    /** @var list<string> */
    public const SOCIAL_CODES = [
        'facebook', 'twitter', 'pinterest', 'instagram', 'linkedin', 'youtube',
        'twitch', 'snapchat', 'tiktok', 'reddit', 'quora', 'medium',
        'weibo', 'douyin', 'xiaohongshu',
    ];

    /** @var list<string> */
    public const ORGANIC_CODES = ['google', 'bing', 'baidu', 'yahoo', 'duckduckgo'];

    public const DEFAULT_PRIORITY_BASE = 100;

    /**
     * Install 默认目录（PixelSource 为空时的回退种子源）。
     *
     * @return list<array{name: string, code: string, referer_domain_contains: string, description: string}>
     */
    public function defaultSourceCatalog(): array
    {
        return [
            ['name' => 'Facebook', 'code' => 'facebook', 'referer_domain_contains' => 'facebook', 'description' => '来自Facebook的访客'],
            ['name' => 'Google', 'code' => 'google', 'referer_domain_contains' => 'google', 'description' => '来自Google的访客'],
            ['name' => 'Twitter', 'code' => 'twitter', 'referer_domain_contains' => 'twitter', 'description' => '来自Twitter的访客'],
            ['name' => 'Pinterest', 'code' => 'pinterest', 'referer_domain_contains' => 'pinterest', 'description' => '来自Pinterest的访客'],
            ['name' => 'Instagram', 'code' => 'instagram', 'referer_domain_contains' => 'instagram', 'description' => '来自Instagram的访客'],
            ['name' => 'LinkedIn', 'code' => 'linkedin', 'referer_domain_contains' => 'linkedin', 'description' => '来自LinkedIn的访客'],
            ['name' => 'YouTube', 'code' => 'youtube', 'referer_domain_contains' => 'youtube', 'description' => '来自YouTube的访客'],
            ['name' => 'Twitch', 'code' => 'twitch', 'referer_domain_contains' => 'twitch', 'description' => '来自Twitch的访客'],
            ['name' => 'Snapchat', 'code' => 'snapchat', 'referer_domain_contains' => 'snapchat', 'description' => '来自Snapchat的访客'],
            ['name' => 'TikTok', 'code' => 'tiktok', 'referer_domain_contains' => 'tiktok', 'description' => '来自TikTok的访客'],
            ['name' => 'Reddit', 'code' => 'reddit', 'referer_domain_contains' => 'reddit', 'description' => '来自Reddit的访客'],
            ['name' => 'Quora', 'code' => 'quora', 'referer_domain_contains' => 'quora', 'description' => '来自Quora的访客'],
            ['name' => 'Medium', 'code' => 'medium', 'referer_domain_contains' => 'medium', 'description' => '来自Medium的访客'],
        ];
    }

    public function inferTrafficType(string $code): string
    {
        $code = \strtolower(\trim($code));
        if (\in_array($code, self::ORGANIC_CODES, true)) {
            return PixelChannel::TRAFFIC_ORGANIC;
        }
        if (\in_array($code, self::SOCIAL_CODES, true)) {
            return PixelChannel::TRAFFIC_SOCIAL;
        }

        return PixelChannel::TRAFFIC_REFERRAL;
    }

    /**
     * 将一条 PixelSource 映射为 rule 行（不落库）。
     *
     * @param array<string,mixed> $source
     * @return array<string,mixed>|null 无效源返回 null
     */
    public function mapSourceToRule(array $source, int $priority = self::DEFAULT_PRIORITY_BASE): ?array
    {
        $code = \strtolower(\trim((string)($source['code'] ?? '')));
        $name = \trim((string)($source['name'] ?? ''));
        $matchValue = \trim((string)($source['referer_domain_contains'] ?? ''));
        if ($code === '' || $name === '' || $matchValue === '') {
            return null;
        }

        return [
            PixelChannel::schema_fields_KIND => PixelChannel::KIND_RULE,
            PixelChannel::schema_fields_CODE => \substr($code, 0, 64),
            PixelChannel::schema_fields_NAME => \substr($name, 0, 255),
            PixelChannel::schema_fields_TRAFFIC_TYPE => $this->inferTrafficType($code),
            PixelChannel::schema_fields_UTM_SOURCE => null,
            PixelChannel::schema_fields_UTM_MEDIUM => null,
            PixelChannel::schema_fields_UTM_CAMPAIGN => null,
            PixelChannel::schema_fields_MATCH_MODE => PixelChannel::MATCH_REFERER_HOST,
            PixelChannel::schema_fields_MATCH_VALUE => \substr($matchValue, 0, 255),
            PixelChannel::schema_fields_PRIORITY => $priority,
            PixelChannel::schema_fields_ENABLED => 1,
            PixelChannel::schema_fields_WEBSITE_ID => 0,
            PixelChannel::schema_fields_DESCRIPTION => \substr(
                \trim((string)($source['description'] ?? 'seeded from pixel_source')),
                0,
                512
            ),
            PixelChannel::schema_fields_CREATED_AT => \date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param list<array<string,mixed>>|null $sources 注入源；null 则读 PixelSource 表，空则用默认目录
     * @return array{
     *   ok: bool,
     *   inserted: int,
     *   updated: int,
     *   skipped: int,
     *   planned: list<array<string,mixed>>,
     *   errors: list<string>,
     *   source: string
     * }
     */
    public function seed(bool $dryRun = false, ?array $sources = null): array
    {
        $sourceLabel = 'injected';
        if ($sources === null) {
            $loaded = $this->loadPixelSources();
            if ($loaded['rows'] !== []) {
                $sources = $loaded['rows'];
                $sourceLabel = 'pixel_source';
            } else {
                $sources = $this->defaultSourceCatalog();
                $sourceLabel = 'default_catalog';
            }
            if ($loaded['error'] !== '') {
                // 表不可读时仍可用默认目录
                $sourceLabel = $sourceLabel === 'default_catalog'
                    ? 'default_catalog(after_load_error)'
                    : $sourceLabel;
            }
        }

        $planned = [];
        $priority = self::DEFAULT_PRIORITY_BASE;
        foreach ($sources as $source) {
            $rule = $this->mapSourceToRule($source, $priority);
            if ($rule === null) {
                continue;
            }
            $planned[] = $rule;
            $priority += 10;
        }

        $result = [
            'ok' => true,
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'planned' => $planned,
            'errors' => [],
            'source' => $sourceLabel,
        ];

        if ($dryRun) {
            return $result;
        }

        foreach ($planned as $rule) {
            try {
                $outcome = $this->persistRule($rule);
                $result[$outcome]++;
            } catch (\Throwable $e) {
                $result['ok'] = false;
                $result['errors'][] = (string)($rule['code'] ?? '') . ': ' . $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * @return array{rows: list<array<string,mixed>>, error: string}
     */
    public function loadPixelSources(): array
    {
        try {
            /** @var PixelSource $model */
            $model = ObjectManager::getInstance(PixelSource::class);
            $model->reset()->select()->fetch();
            $items = $model->getItems() ?: [];
            $rows = [];
            foreach ($items as $item) {
                $rows[] = \is_object($item) && \method_exists($item, 'getData')
                    ? (array)$item->getData()
                    : (array)$item;
            }

            return ['rows' => $rows, 'error' => ''];
        } catch (\Throwable $e) {
            return ['rows' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * @param array<string,mixed> $rule
     * @return 'inserted'|'updated'|'skipped'
     */
    private function persistRule(array $rule): string
    {
        /** @var PixelChannel $model */
        $model = ObjectManager::getInstance(PixelChannel::class);
        $model->reset()
            ->where(PixelChannel::schema_fields_KIND, PixelChannel::KIND_RULE)
            ->where(PixelChannel::schema_fields_CODE, $rule[PixelChannel::schema_fields_CODE])
            ->where(PixelChannel::schema_fields_WEBSITE_ID, 0)
            ->find()
            ->fetch();

        if ((int)$model->getId() > 0) {
            $changed = false;
            foreach ([
                PixelChannel::schema_fields_NAME,
                PixelChannel::schema_fields_TRAFFIC_TYPE,
                PixelChannel::schema_fields_MATCH_MODE,
                PixelChannel::schema_fields_MATCH_VALUE,
                PixelChannel::schema_fields_PRIORITY,
                PixelChannel::schema_fields_DESCRIPTION,
                PixelChannel::schema_fields_ENABLED,
            ] as $field) {
                $new = $rule[$field] ?? null;
                $old = $model->getData($field);
                if ((string)$old !== (string)$new) {
                    $model->setData($field, $new);
                    $changed = true;
                }
            }
            if ($changed) {
                $model->save();

                return 'updated';
            }

            return 'skipped';
        }

        $model->clear();
        foreach ($rule as $key => $value) {
            $model->setData($key, $value);
        }
        $model->save();

        return 'inserted';
    }
}
