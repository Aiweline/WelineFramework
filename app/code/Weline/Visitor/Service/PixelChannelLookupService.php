<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\PixelChannel;

/**
 * B07/B09：归因查库层。
 * - S2 campaign 绑定（有 code 查表）
 * - S3 rule 补全（仍无 code 时按 match_mode 匹配）
 * A03 PixelTrafficAttributionService 保持纯函数。
 */
class PixelChannelLookupService
{
    public const UNREGISTERED_NAME = '未登记';

    /**
     * 用 S0/S1 的 channel_code 绑定 campaign：站点 → 全局。
     * 命中：code/name 以表为准；traffic_type 表非空则覆盖，否则保留 S0。
     * 未命中且 code 非空：展示名「未登记」（S4）。
     *
     * @param array<string,mixed> $attribution
     * @param callable(string $code, int $websiteId): (?array<string,mixed>)|null $finder
     * @return array<string,mixed>
     */
    public function applyCampaignBinding(array $attribution, int $websiteId, ?callable $finder = null): array
    {
        $code = \trim((string)($attribution['channel_code'] ?? ''));
        if ($code === '') {
            return $attribution;
        }

        $finder ??= [$this, 'findCampaignByCode'];
        $campaign = null;
        try {
            $campaign = $finder($code, $websiteId);
        } catch (\Throwable) {
            $campaign = null;
        }

        if (!\is_array($campaign) || $campaign === []) {
            if (\trim((string)($attribution['channel_name'] ?? '')) === '') {
                $attribution['channel_name'] = (string)__('未登记');
            }
            $attribution['campaign_bound'] = false;

            return $attribution;
        }

        $boundCode = \trim((string)($campaign[PixelChannel::schema_fields_CODE] ?? $campaign['code'] ?? $code));
        $boundName = \trim((string)($campaign[PixelChannel::schema_fields_NAME] ?? $campaign['name'] ?? ''));
        $boundType = \trim((string)($campaign[PixelChannel::schema_fields_TRAFFIC_TYPE] ?? $campaign['traffic_type'] ?? ''));

        if ($boundCode !== '') {
            $attribution['channel_code'] = \substr($boundCode, 0, 64);
        }
        if ($boundName !== '') {
            $attribution['channel_name'] = \substr($boundName, 0, 255);
        }
        if ($boundType !== '') {
            $attribution['traffic_type'] = \substr($boundType, 0, 32);
        }
        $attribution['campaign_bound'] = true;
        $attribution['campaign_website_id'] = (int)($campaign[PixelChannel::schema_fields_WEBSITE_ID] ?? $campaign['website_id'] ?? 0);
        $attribution['campaign_enabled'] = (int)($campaign[PixelChannel::schema_fields_ENABLED] ?? $campaign['enabled'] ?? 1);

        return $attribution;
    }

    /**
     * S3：仅当 channel_code 仍为空时，按 enabled rule（优先级升序）匹配。
     * 站点 rule 与全局 rule 合并：同 priority 时站点优先。
     *
     * @param array<string,mixed> $attribution S0/S1/S2 结果（含 referer_host、utm 字段、click ids）
     * @param list<array<string,mixed>>|null $rules 注入规则列表；null 则查库
     * @return array<string,mixed>
     */
    public function applyRuleBinding(array $attribution, int $websiteId, ?array $rules = null): array
    {
        if (\trim((string)($attribution['channel_code'] ?? '')) !== '') {
            return $attribution;
        }

        try {
            $rules ??= $this->loadEnabledRules($websiteId);
        } catch (\Throwable) {
            $rules = [];
        }
        if ($rules === []) {
            $attribution['rule_bound'] = false;

            return $attribution;
        }

        $context = $this->buildMatchContext($attribution);
        foreach ($this->sortRules($rules, $websiteId) as $rule) {
            if (!$this->ruleMatches($rule, $context)) {
                continue;
            }
            $code = \trim((string)($rule[PixelChannel::schema_fields_CODE] ?? $rule['code'] ?? ''));
            $name = \trim((string)($rule[PixelChannel::schema_fields_NAME] ?? $rule['name'] ?? ''));
            $type = \trim((string)($rule[PixelChannel::schema_fields_TRAFFIC_TYPE] ?? $rule['traffic_type'] ?? ''));
            if ($code === '') {
                continue;
            }
            $attribution['channel_code'] = \substr($code, 0, 64);
            if ($name !== '') {
                $attribution['channel_name'] = \substr($name, 0, 255);
            }
            if ($type !== '') {
                $attribution['traffic_type'] = \substr($type, 0, 32);
            }
            $attribution['rule_bound'] = true;
            $attribution['rule_match_mode'] = (string)($rule[PixelChannel::schema_fields_MATCH_MODE] ?? $rule['match_mode'] ?? '');
            $attribution['rule_website_id'] = (int)($rule[PixelChannel::schema_fields_WEBSITE_ID] ?? $rule['website_id'] ?? 0);

            return $attribution;
        }

        $attribution['rule_bound'] = false;

        return $attribution;
    }

    /**
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $context
     */
    public function ruleMatches(array $rule, array $context): bool
    {
        $mode = \trim((string)($rule[PixelChannel::schema_fields_MATCH_MODE] ?? $rule['match_mode'] ?? ''));
        $value = \trim((string)($rule[PixelChannel::schema_fields_MATCH_VALUE] ?? $rule['match_value'] ?? ''));
        if ($mode === '' || $value === '') {
            return false;
        }
        if ((int)($rule[PixelChannel::schema_fields_ENABLED] ?? $rule['enabled'] ?? 1) !== 1) {
            return false;
        }

        return match ($mode) {
            PixelChannel::MATCH_REFERER_HOST => $this->matchRefererHost(
                (string)($context['referer_host'] ?? ''),
                $value
            ),
            PixelChannel::MATCH_UTM_SOURCE => $this->matchEqualsContains(
                (string)($context['utm_source'] ?? ''),
                $value
            ),
            PixelChannel::MATCH_UTM_MEDIUM => $this->matchEqualsContains(
                (string)($context['utm_medium'] ?? ''),
                $value
            ),
            PixelChannel::MATCH_CLICK_ID => $this->matchClickId($context, $value),
            PixelChannel::MATCH_QUERY_PARAM => $this->matchQueryParam($context, $value),
            default => false,
        };
    }

    /**
     * 站点 campaign 优先，再全局 website_id=0；kind=campaign；含停用行。
     *
     * @return array<string,mixed>|null
     */
    public function findCampaignByCode(string $code, int $websiteId): ?array
    {
        $code = \trim($code);
        if ($code === '') {
            return null;
        }

        try {
            if ($websiteId > 0) {
                $site = $this->queryCampaign($code, $websiteId);
                if ($site !== null) {
                    return $site;
                }
            }

            return $this->queryCampaign($code, 0);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function loadEnabledRules(int $websiteId): array
    {
        /** @var PixelChannel $model */
        $model = ObjectManager::getInstance(PixelChannel::class);
        $model->reset()
            ->where(PixelChannel::schema_fields_KIND, PixelChannel::KIND_RULE)
            ->where(PixelChannel::schema_fields_ENABLED, 1);
        if ($websiteId > 0) {
            $model->where(PixelChannel::schema_fields_WEBSITE_ID, [$websiteId, 0], 'IN');
        } else {
            $model->where(PixelChannel::schema_fields_WEBSITE_ID, 0);
        }
        $model->order(PixelChannel::schema_fields_PRIORITY, 'ASC')
            ->order(PixelChannel::schema_fields_WEBSITE_ID, 'DESC')
            ->select()
            ->fetch();
        $items = $model->getItems() ?: [];
        $rows = [];
        foreach ($items as $item) {
            $rows[] = \is_object($item) && \method_exists($item, 'getData')
                ? (array)$item->getData()
                : (array)$item;
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $attribution
     * @return array<string,mixed>
     */
    public function buildMatchContext(array $attribution): array
    {
        return [
            'referer_host' => \strtolower(\trim((string)($attribution['referer_host'] ?? ''))),
            'utm_source' => \strtolower(\trim((string)($attribution['utm_source'] ?? ''))),
            'utm_medium' => \strtolower(\trim((string)($attribution['utm_medium'] ?? ''))),
            'utm_campaign' => \strtolower(\trim((string)($attribution['utm_campaign'] ?? ''))),
            'wch' => \strtolower(\trim((string)($attribution['wch'] ?? ''))),
            'gclid' => \trim((string)($attribution['gclid'] ?? '')),
            'fbclid' => \trim((string)($attribution['fbclid'] ?? '')),
            'msclkid' => \trim((string)($attribution['msclkid'] ?? '')),
            'query' => \is_array($attribution['query'] ?? null) ? $attribution['query'] : [],
        ];
    }

    /**
     * @param list<array<string,mixed>> $rules
     * @return list<array<string,mixed>>
     */
    public function sortRules(array $rules, int $websiteId): array
    {
        \usort($rules, static function (array $a, array $b) use ($websiteId): int {
            $pa = (int)($a[PixelChannel::schema_fields_PRIORITY] ?? $a['priority'] ?? 100);
            $pb = (int)($b[PixelChannel::schema_fields_PRIORITY] ?? $b['priority'] ?? 100);
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            $wa = (int)($a[PixelChannel::schema_fields_WEBSITE_ID] ?? $a['website_id'] ?? 0);
            $wb = (int)($b[PixelChannel::schema_fields_WEBSITE_ID] ?? $b['website_id'] ?? 0);
            // 同 priority：站点优先于全局
            if ($websiteId > 0) {
                if ($wa === $websiteId && $wb !== $websiteId) {
                    return -1;
                }
                if ($wb === $websiteId && $wa !== $websiteId) {
                    return 1;
                }
            }

            return $wb <=> $wa;
        });

        return $rules;
    }

    public function matchRefererHost(string $host, string $matchValue): bool
    {
        $host = \strtolower(\trim($host));
        if ($host === '') {
            return false;
        }
        foreach (\explode(',', $matchValue) as $needle) {
            $needle = \strtolower(\trim($needle));
            if ($needle !== '' && \str_contains($host, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function matchEqualsContains(string $haystack, string $matchValue): bool
    {
        $haystack = \strtolower(\trim($haystack));
        if ($haystack === '') {
            return false;
        }
        foreach (\explode(',', $matchValue) as $needle) {
            $needle = \strtolower(\trim($needle));
            if ($needle === '') {
                continue;
            }
            if ($haystack === $needle || \str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $context */
    public function matchClickId(array $context, string $matchValue): bool
    {
        $value = \strtolower(\trim($matchValue));
        $map = [
            'gclid' => (string)($context['gclid'] ?? ''),
            'fbclid' => (string)($context['fbclid'] ?? ''),
            'msclkid' => (string)($context['msclkid'] ?? ''),
        ];
        if (isset($map[$value])) {
            return $map[$value] !== '';
        }
        // match_value 可为任意非空 click id 字段名列表
        foreach (\explode(',', $value) as $key) {
            $key = \strtolower(\trim($key));
            if ($key !== '' && isset($map[$key]) && $map[$key] !== '') {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $context */
    public function matchQueryParam(array $context, string $matchValue): bool
    {
        $matchValue = \trim($matchValue);
        if ($matchValue === '') {
            return false;
        }
        $query = \is_array($context['query'] ?? null) ? $context['query'] : [];
        // 兼容把常用键摊到 context 顶层
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'wch', 'gclid', 'fbclid', 'msclkid'] as $k) {
            if (!\array_key_exists($k, $query) && ($context[$k] ?? '') !== '') {
                $query[$k] = $context[$k];
            }
        }

        if (\str_contains($matchValue, '=')) {
            [$key, $expect] = \array_pad(\explode('=', $matchValue, 2), 2, '');
            $key = \strtolower(\trim($key));
            $expect = \strtolower(\trim($expect));
            $actual = \strtolower(\trim((string)($query[$key] ?? '')));

            return $key !== '' && $actual !== '' && $actual === $expect;
        }

        $key = \strtolower($matchValue);

        return \trim((string)($query[$key] ?? '')) !== '';
    }

    /**
     * @return array<string,mixed>|null
     */
    private function queryCampaign(string $code, int $websiteId): ?array
    {
        /** @var PixelChannel $model */
        $model = ObjectManager::getInstance(PixelChannel::class);
        $model->reset()
            ->where(PixelChannel::schema_fields_KIND, PixelChannel::KIND_CAMPAIGN)
            ->where(PixelChannel::schema_fields_CODE, $code)
            ->where(PixelChannel::schema_fields_WEBSITE_ID, $websiteId)
            ->find()
            ->fetch();

        if ((int)$model->getId() <= 0) {
            return null;
        }
        $data = $model->getData();

        return \is_array($data) ? $data : null;
    }
}
