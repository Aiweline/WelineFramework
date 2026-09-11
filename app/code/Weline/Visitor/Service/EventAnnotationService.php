<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Api\ConfigReader as SystemConfig;
use Weline\SystemConfig\Api\ConfigStore;

/**
 * 配置范围级事件注解：星标、分类、有值、GA4 式匹配条件（不改发现池字符串列表）。
 */
final class EventAnnotationService
{
    public const MODULE = 'Weline_Visitor';
    public const KEY_PREFIX = 'visitor/tracking/event_annotations.';

    /** @var array<string, string> */
    public const CATEGORY_LABELS = [
        'page' => '页面',
        'cta' => '互动',
        'ecommerce' => '电商',
        'search' => '搜索',
        'lead' => '线索',
        'account' => '账户',
        'error' => '错误',
        'custom' => '自定义',
        'other' => '其他',
    ];

    /** @var array<string, string> GA4 常用匹配参数 */
    public const MATCH_PARAM_LABELS = [
        'event_name' => 'event_name（事件名）',
        'page_location' => 'page_location（完整 URL）',
        'page_path' => 'page_path（路径）',
        'page_referrer' => 'page_referrer（来源）',
    ];

    /** @var array<string, string> */
    public const MATCH_OP_LABELS = [
        'equals' => '等于',
        'contains' => '包含',
        'starts_with' => '开头是',
        'ends_with' => '结尾是',
    ];

    /** @var array<string, string> */
    public const MATCH_TYPE_LABELS = [
        'event_name' => '事件名匹配',
        'url' => 'URL 匹配',
        'custom' => '自定义条件',
    ];

    public function __construct(
        private readonly ?EventDictionaryService $dictionary = null,
        private readonly ?SystemConfig $systemConfig = null,
        private readonly ?ConfigStore $configStore = null,
    ) {
    }

    public function scopeKeyFromStorage(string $storageScope, int $websiteId = 0): string
    {
        $s = \strtolower(\trim($storageScope));
        $s = \preg_replace('/[^a-z0-9._-]+/', '_', $s) ?: '';
        if ($s !== '') {
            return 'scope.' . $s;
        }

        return 'website.' . \max(0, $websiteId);
    }

    /**
     * @return array<string, array{starred: bool, category: string, has_value: bool, match_type: string, match_conditions: list<array{param: string, op: string, value: string}>, copy_params: bool}>
     */
    public function all(int $websiteId, string $storageScope = ''): array
    {
        $raw = $this->readRaw($this->configKey($this->scopeKeyFromStorage($storageScope, $websiteId)));
        $out = [];
        foreach ($raw as $name => $meta) {
            if (!\is_array($meta)) {
                continue;
            }
            $n = $this->dictionary()->normalizeEventName((string)$name);
            if ($n === '') {
                continue;
            }
            $out[$n] = $this->normalizeMeta($meta, $n);
        }

        return $out;
    }

    /**
     * @return array{starred: bool, category: string, has_value: bool, match_type: string, match_conditions: list<array{param: string, op: string, value: string}>, copy_params: bool}
     */
    public function get(int $websiteId, string $eventName, string $storageScope = ''): array
    {
        $n = $this->dictionary()->normalizeEventName($eventName);
        if ($n === '') {
            return $this->normalizeMeta([], '');
        }
        $all = $this->all($websiteId, $storageScope);

        return $all[$n] ?? $this->normalizeMeta([], $n);
    }

    public function setStarred(int $websiteId, string $eventName, bool $starred, string $storageScope = ''): bool
    {
        return $this->patch($websiteId, $eventName, ['starred' => $starred], $storageScope);
    }

    public function setCategory(int $websiteId, string $eventName, string $category, string $storageScope = ''): bool
    {
        return $this->patch($websiteId, $eventName, ['category' => $this->normalizeCategory($category)], $storageScope);
    }

    public function markHasValue(int $websiteId, string $eventName, string $storageScope = ''): bool
    {
        return $this->patch($websiteId, $eventName, ['has_value' => true], $storageScope);
    }

    /**
     * @param array<string, mixed> $patch
     */
    public function patch(int $websiteId, string $eventName, array $patch, string $storageScope = ''): bool
    {
        $n = $this->dictionary()->normalizeEventName($eventName);
        if ($n === '') {
            return false;
        }
        $scopeKey = $this->scopeKeyFromStorage($storageScope, $websiteId);
        $configKey = $this->configKey($scopeKey);
        $all = $this->readRaw($configKey);
        $current = isset($all[$n]) && \is_array($all[$n]) ? $all[$n] : [];
        $merged = $this->normalizeMeta(\array_merge($current, $patch), $n);
        $all[$n] = $merged;

        return $this->writeRaw($configKey, $all);
    }

    /**
     * 把注解合并进事件行；星标置顶。
     *
     * @param list<array<string, mixed>> $rows
     * @param callable(array):string|null $nameFn
     * @return list<array<string, mixed>>
     */
    public function enrichRows(int $websiteId, string $storageScope, array $rows, ?callable $nameFn = null): array
    {
        $meta = $this->all($websiteId, $storageScope);
        $nameFn ??= static function (array $row): string {
            return (string)($row['name'] ?? $row['weline_event'] ?? '');
        };
        $out = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $n = $this->dictionary()->normalizeEventName($nameFn($row));
            $stored = $meta[$n] ?? null;
            $ann = $stored ?? $this->normalizeMeta([], $n);
            if ($stored === null && isset($row['event_family']) && \is_string($row['event_family']) && $row['event_family'] !== '') {
                $ann['category'] = $this->normalizeCategory((string)$row['event_family']);
            }
            if (!$ann['has_value'] && $this->dictionarySuggestsValued($n)) {
                $ann['has_value'] = true;
            }
            $row['starred'] = $ann['starred'];
            $row['category'] = $ann['category'];
            $row['category_label'] = self::CATEGORY_LABELS[$ann['category']] ?? $ann['category'];
            $row['has_value'] = $ann['has_value'];
            $row['match_type'] = $ann['match_type'];
            $row['match_type_label'] = self::MATCH_TYPE_LABELS[$ann['match_type']] ?? $ann['match_type'];
            $row['match_conditions'] = $ann['match_conditions'];
            $row['match_summary'] = $this->summarizeMatchConditions($ann['match_conditions']);
            $row['copy_params'] = $ann['copy_params'];
            $out[] = $row;
        }
        \usort($out, static function (array $a, array $b): int {
            $sa = !empty($a['starred']) ? 1 : 0;
            $sb = !empty($b['starred']) ? 1 : 0;
            if ($sa !== $sb) {
                return $sb <=> $sa;
            }

            return \strcmp((string)($a['name'] ?? $a['weline_event'] ?? ''), (string)($b['name'] ?? $b['weline_event'] ?? ''));
        });

        return $out;
    }

    /**
     * @param array<string, mixed> $payload params / value / browser fields
     */
    public function detectHasValueFromPayload(array $payload): bool
    {
        if (isset($payload['has_value'])) {
            return (bool)$payload['has_value'];
        }
        $value = $payload['value'] ?? null;
        if ($value !== null && $value !== '' && (float)$value != 0.0) {
            return true;
        }
        foreach (['params', 'properties', 'items', 'ecommerce'] as $k) {
            if (!empty($payload[$k]) && \is_array($payload[$k])) {
                return true;
            }
            if (!empty($payload[$k]) && \is_string($payload[$k]) && \trim($payload[$k]) !== '' && \trim($payload[$k]) !== '{}') {
                return true;
            }
        }
        $summary = \trim((string)($payload['summary'] ?? ''));
        if ($summary !== '' && \preg_match('/value|price|amount|param|qty|quantity/i', $summary)) {
            return true;
        }

        return false;
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function categoryOptions(): array
    {
        $out = [];
        foreach (self::CATEGORY_LABELS as $id => $label) {
            $out[] = ['id' => $id, 'label' => $label];
        }

        return $out;
    }

    private function dictionarySuggestsValued(string $eventName): bool
    {
        if ($eventName === '') {
            return false;
        }
        try {
            foreach ($this->dictionary()->getEvents() as $entry) {
                if (!\is_array($entry)) {
                    continue;
                }
                $n = $this->dictionary()->normalizeEventName((string)($entry['weline_event'] ?? ''));
                if ($n !== $eventName) {
                    continue;
                }
                $params = $entry['required_params'] ?? [];

                return \is_array($params) && $params !== [];
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function matchParamOptions(): array
    {
        $out = [];
        foreach (self::MATCH_PARAM_LABELS as $id => $label) {
            $out[] = ['id' => $id, 'label' => $label];
        }

        return $out;
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function matchOpOptions(): array
    {
        $out = [];
        foreach (self::MATCH_OP_LABELS as $id => $label) {
            $out[] = ['id' => $id, 'label' => $label];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array{starred: bool, category: string, has_value: bool, match_type: string, match_conditions: list<array{param: string, op: string, value: string}>, copy_params: bool}
     */
    private function normalizeMeta(array $meta, string $eventName): array
    {
        $category = $this->normalizeCategory((string)($meta['category'] ?? ($eventName !== '' ? 'custom' : 'other')));
        $matchType = $this->normalizeMatchType((string)($meta['match_type'] ?? ''));
        $conditions = $this->normalizeMatchConditions($meta['match_conditions'] ?? []);
        if ($matchType === '' && $conditions !== []) {
            $matchType = $this->inferMatchType($conditions);
        }
        if ($matchType === '') {
            $matchType = 'custom';
        }

        return [
            'starred' => !empty($meta['starred']),
            'category' => $category,
            'has_value' => !empty($meta['has_value']),
            'match_type' => $matchType,
            'match_conditions' => $conditions,
            'copy_params' => !isset($meta['copy_params']) || !empty($meta['copy_params']),
        ];
    }

    /**
     * @param mixed $raw
     * @return list<array{param: string, op: string, value: string}>
     */
    public function normalizeMatchConditions(mixed $raw): array
    {
        if (\is_string($raw) && $raw !== '') {
            $decoded = \json_decode($raw, true);
            $raw = \is_array($decoded) ? $decoded : [];
        }
        if (!\is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $param = \strtolower(\trim((string)($row['param'] ?? $row['parameter'] ?? '')));
            $param = \preg_replace('/[^a-z0-9_]+/', '', $param) ?: '';
            if ($param === '' || !isset(self::MATCH_PARAM_LABELS[$param])) {
                continue;
            }
            $op = \strtolower(\trim((string)($row['op'] ?? $row['operator'] ?? 'equals')));
            $op = \preg_replace('/[^a-z0-9_]+/', '', $op) ?: '';
            if ($op === '' || !isset(self::MATCH_OP_LABELS[$op])) {
                $op = 'equals';
            }
            $value = \trim((string)($row['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            if (\strlen($value) > 512) {
                $value = \substr($value, 0, 512);
            }
            $out[] = [
                'param' => $param,
                'op' => $op,
                'value' => $value,
            ];
            if (\count($out) >= 8) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param list<array{param: string, op: string, value: string}> $conditions
     */
    public function summarizeMatchConditions(array $conditions): string
    {
        if ($conditions === []) {
            return '';
        }
        $bits = [];
        foreach ($conditions as $c) {
            $op = self::MATCH_OP_LABELS[$c['op']] ?? $c['op'];
            $bits[] = $c['param'] . ' ' . $op . ' ' . $c['value'];
        }

        return \implode(' · ', $bits);
    }

    private function normalizeMatchType(string $type): string
    {
        $t = \strtolower(\trim($type));
        $t = \preg_replace('/[^a-z0-9_]+/', '', $t) ?: '';

        return isset(self::MATCH_TYPE_LABELS[$t]) ? $t : '';
    }

    /**
     * @param list<array{param: string, op: string, value: string}> $conditions
     */
    private function inferMatchType(array $conditions): string
    {
        $params = [];
        foreach ($conditions as $c) {
            $params[$c['param']] = true;
        }
        if (isset($params['page_location']) || isset($params['page_path'])) {
            return 'url';
        }
        if (\count($params) === 1 && isset($params['event_name'])) {
            return 'event_name';
        }

        return 'custom';
    }

    private function normalizeCategory(string $category): string
    {
        $c = \strtolower(\trim($category));
        $c = \preg_replace('/[^a-z0-9_]+/', '', $c) ?: '';
        if ($c === '' || !isset(self::CATEGORY_LABELS[$c])) {
            return 'other';
        }

        return $c;
    }

    private function configKey(string $scopeKey): string
    {
        return self::KEY_PREFIX . $scopeKey;
    }

    /**
     * @return array<string, mixed>
     */
    private function readRaw(string $configKey): array
    {
        try {
            $raw = (string)$this->config()->get(
                $configKey,
                self::MODULE,
                SystemConfig::area_BACKEND,
                '{}',
                SystemConfig::SCOPE_GLOBAL
            );
        } catch (\Throwable) {
            $raw = '{}';
        }
        $decoded = \json_decode($raw !== '' ? $raw : '{}', true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, array{starred: bool, category: string, has_value: bool, match_type?: string, match_conditions?: list<array{param: string, op: string, value: string}>, copy_params?: bool}> $all
     */
    private function writeRaw(string $configKey, array $all): bool
    {
        try {
            return $this->store()->setScopedConfig(
                $configKey,
                \json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                self::MODULE,
                SystemConfig::area_BACKEND,
                SystemConfig::SCOPE_GLOBAL,
                SystemConfig::LOCALE_DEFAULT
            );
        } catch (\Throwable) {
            return false;
        }
    }

    private function dictionary(): EventDictionaryService
    {
        return $this->dictionary ?? ObjectManager::getInstance(EventDictionaryService::class);
    }

    private function config(): SystemConfig
    {
        return $this->systemConfig ?? ObjectManager::getInstance(SystemConfig::class);
    }

    private function store(): ConfigStore
    {
        return $this->configStore ?? ObjectManager::getInstance(ConfigStore::class);
    }
}
