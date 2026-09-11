<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Api\ConfigReader as SystemConfig;
use Weline\SystemConfig\Api\ConfigStore;

/**
 * Website 级高级事件链定义：带 version，经 runtime 注入前台；无 TTL，只靠 version 清旧。
 *
 * Bundle 形状：
 * {
 *   "version": 3,
 *   "chains": [
 *     {
 *       "id": "demo_cross_page",
 *       "name": "演示跨页漏斗",
 *       "complete_event": "demo_funnel_complete",
 *       "steps": [
 *         {"type":"page","path_prefix":"/"},
 *         {"type":"track","event":"chain_step_b"}
 *       ]
 *     }
 *   ]
 * }
 */
final class EventChainService
{
    public const MODULE = 'Weline_Visitor';
    public const KEY_PREFIX = 'visitor/tracking/event_chains_bundle.';

    public function __construct(
        private readonly ?EventDictionaryService $dictionary = null,
        private readonly ?SystemConfig $systemConfig = null,
        private readonly ?ConfigStore $configStore = null,
    ) {
    }

    private function configKey(int $websiteId): string
    {
        return self::KEY_PREFIX . \max(0, $websiteId);
    }

    /**
     * @return array{version: int, chains: list<array<string, mixed>>}
     */
    public function getBundle(int $websiteId): array
    {
        $raw = '';
        try {
            $raw = (string)$this->config()->get(
                $this->configKey($websiteId),
                self::MODULE,
                SystemConfig::area_BACKEND,
                '',
                SystemConfig::SCOPE_GLOBAL
            );
        } catch (\Throwable) {
            $raw = '';
        }
        if ($raw === '') {
            return ['version' => 0, 'chains' => []];
        }
        $decoded = \json_decode($raw, true);
        if (!\is_array($decoded)) {
            return ['version' => 0, 'chains' => []];
        }
        $version = (int)($decoded['version'] ?? 0);
        $chains = $decoded['chains'] ?? [];
        if (!\is_array($chains)) {
            $chains = [];
        }
        $normalized = [];
        foreach ($chains as $chain) {
            if (!\is_array($chain)) {
                continue;
            }
            $row = $this->normalizeChain($chain);
            if ($row !== null) {
                $normalized[] = $row;
            }
        }

        return [
            'version' => \max(0, $version),
            'chains' => $normalized,
        ];
    }

    /**
     * 已发布链的闭环事件名集合（normalize 后）。
     *
     * @return list<string>
     */
    public function listCompleteEvents(int $websiteId): array
    {
        $out = [];
        $seen = [];
        foreach ($this->getBundle($websiteId)['chains'] as $chain) {
            if (!\is_array($chain)) {
                continue;
            }
            $ev = $this->dictionary()->normalizeEventName((string)($chain['complete_event'] ?? ''));
            if ($ev === '' || isset($seen[$ev])) {
                continue;
            }
            $seen[$ev] = true;
            $out[] = $ev;
        }

        return $out;
    }

    public function isRegisteredCompleteEvent(int $websiteId, string $eventName): bool
    {
        $ev = $this->dictionary()->normalizeEventName($eventName);
        if ($ev === '') {
            return false;
        }

        return \in_array($ev, $this->listCompleteEvents($websiteId), true);
    }

    /**
     * 载荷是否声明「链已凑齐」——仅此时允许写入闭环事件。
     *
     * @param array<string, mixed> $post normalize 后的 track 入参
     */
    public function payloadMarksChainComplete(array $post): bool
    {
        if (!empty($post['__event_chain_complete']) || !empty($post['funnel_complete'])) {
            return true;
        }
        $additional = \is_array($post['additionalInfo'] ?? null) ? $post['additionalInfo'] : [];
        $meta = \is_array($additional['meta'] ?? null) ? $additional['meta'] : [];
        if (!empty($meta['__event_chain_complete']) || !empty($meta['funnel_complete'])) {
            return true;
        }
        // 兼容 meta 嵌套在 additionalInfo 外的扁平字段
        if (!empty($additional['__event_chain_complete']) || !empty($additional['funnel_complete'])) {
            return true;
        }

        return false;
    }

    /**
     * 用完整 chains 列表覆盖并 bump version。
     *
     * @param list<array<string, mixed>> $chains
     * @return array{version: int, chains: list<array<string, mixed>>}
     */
    public function publishBundle(int $websiteId, array $chains): array
    {
        $current = $this->getBundle($websiteId);
        $normalized = [];
        foreach ($chains as $chain) {
            if (!\is_array($chain)) {
                continue;
            }
            $row = $this->normalizeChain($chain);
            if ($row !== null) {
                $normalized[] = $row;
            }
        }
        $bundle = [
            'version' => (int)$current['version'] + 1,
            'chains' => $normalized,
            'updated_at' => \date('c'),
        ];
        if (!$this->writeBundle($websiteId, $bundle)) {
            // 写失败时不虚报新 version
            return $this->getBundle($websiteId);
        }

        return [
            'version' => (int)$bundle['version'],
            'chains' => $normalized,
        ];
    }

    /**
     * 新增或替换单条链并 bump version。
     *
     * @param array<string, mixed> $chain
     * @return array{version: int, chains: list<array<string, mixed>>, chain: array<string, mixed>|null}
     */
    public function upsertChain(int $websiteId, array $chain): array
    {
        $row = $this->normalizeChain($chain);
        if ($row === null) {
            $bundle = $this->getBundle($websiteId);

            return [
                'version' => (int)$bundle['version'],
                'chains' => $bundle['chains'],
                'chain' => null,
            ];
        }
        $bundle = $this->getBundle($websiteId);
        $chains = $bundle['chains'];
        $found = false;
        foreach ($chains as $i => $existing) {
            if ((string)($existing['id'] ?? '') === $row['id']) {
                $chains[$i] = $row;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $chains[] = $row;
        }
        $published = $this->publishBundle($websiteId, $chains);

        return [
            'version' => (int)$published['version'],
            'chains' => $published['chains'],
            'chain' => $row,
        ];
    }

    /**
     * 发布/确保演示跨页链（测试用）：page(/) → track(chain_demo_step_b) → complete demo_funnel_complete。
     *
     * @return array{version: int, chains: list<array<string, mixed>>, chain: array<string, mixed>|null}
     */
    public function ensureDemoCrossPageChain(int $websiteId): array
    {
        return $this->upsertChain($websiteId, [
            'id' => 'demo_cross_page',
            'name' => '演示跨页漏斗',
            'complete_event' => 'demo_funnel_complete',
            'steps' => [
                [
                    'type' => 'page',
                    'label' => '任意页打开（步骤1）',
                ],
                [
                    'type' => 'track',
                    'event' => 'chain_demo_step_b',
                    'label' => '第二步 track（可在另一页触发）',
                ],
            ],
        ]);
    }

    /**
     * 从拾取封链步骤转成可发布定义。
     *
     * @param list<array<string, mixed>>|array<string, mixed> $rawSteps
     * @return array<string, mixed>|null
     */
    public function chainFromPickerSteps(string $completeEvent, array $rawSteps, ?string $id = null, ?string $name = null): ?array
    {
        $complete = $this->dictionary()->normalizeEventName($completeEvent);
        if ($complete === '') {
            return null;
        }
        $stepsIn = $rawSteps;
        if (isset($rawSteps['steps']) && \is_array($rawSteps['steps'])) {
            $stepsIn = $rawSteps['steps'];
        }
        $steps = [];
        foreach ($stepsIn as $st) {
            if (!\is_array($st)) {
                continue;
            }
            $norm = $this->normalizeStep($st);
            if ($norm !== null) {
                $steps[] = $norm;
            }
            if (\count($steps) >= 40) {
                break;
            }
        }
        if ($steps === []) {
            return null;
        }
        $cid = $id !== null && $id !== '' ? $this->slugId($id) : ('chain_' . $complete);
        if ($cid === '') {
            $cid = 'chain_' . \substr(\sha1($complete . \json_encode($steps)), 0, 10);
        }

        return $this->normalizeChain([
            'id' => $cid,
            'name' => $name !== null && $name !== '' ? $name : $complete,
            'complete_event' => $complete,
            'steps' => $steps,
        ]);
    }

    /**
     * 纯函数：信号是否匹配某一步（供 UT / 与前端约定对齐）。
     *
     * @param array<string, mixed> $step
     * @param array{type?: string, event?: string, path?: string, selector?: string} $signal
     */
    public function stepMatches(array $step, array $signal): bool
    {
        $type = (string)($step['type'] ?? '');
        $sigType = (string)($signal['type'] ?? '');
        if ($type === 'page') {
            if ($sigType !== 'page' && $sigType !== 'track') {
                // page 信号；也允许 track=page_view 视作进页
                if ($sigType === 'track' && (string)($signal['event'] ?? '') === 'page_view') {
                    // fall through with path
                } else {
                    return false;
                }
            }
            $path = (string)($signal['path'] ?? '');
            $exact = (string)($step['path'] ?? '');
            $prefix = (string)($step['path_prefix'] ?? '');
            if ($exact !== '') {
                return $path === $exact || $path === \rtrim($exact, '/') || \rtrim($path, '/') === \rtrim($exact, '/');
            }
            if ($prefix !== '') {
                if ($prefix === '/') {
                    return $path === '/' || $path === '';
                }

                return \str_starts_with($path, $prefix);
            }

            return true;
        }
        if ($type === 'track' || $type === 'click') {
            if ($sigType !== 'track' && $sigType !== 'click') {
                return false;
            }
            $want = $this->dictionary()->normalizeEventName((string)($step['event'] ?? ''));
            $got = $this->dictionary()->normalizeEventName((string)($signal['event'] ?? ''));
            if ($want === '' || $got === '' || $want !== $got) {
                return false;
            }
            $sel = \trim((string)($step['selector'] ?? ''));
            if ($sel !== '') {
                $gotSel = \trim((string)($signal['selector'] ?? ''));

                return $gotSel !== '' && ($gotSel === $sel || \str_contains($gotSel, $sel));
            }

            return true;
        }
        if ($type === 'input' || $type === 'submit') {
            return $sigType === $type;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $chain
     * @return array<string, mixed>|null
     */
    private function normalizeChain(array $chain): ?array
    {
        $id = $this->slugId((string)($chain['id'] ?? ''));
        $complete = $this->dictionary()->normalizeEventName(
            (string)($chain['complete_event'] ?? $chain['close_event'] ?? $chain['weline_event'] ?? '')
        );
        if ($complete === '') {
            return null;
        }
        if ($id === '') {
            $id = 'chain_' . $complete;
        }
        $stepsRaw = $chain['steps'] ?? [];
        if (!\is_array($stepsRaw)) {
            return null;
        }
        $steps = [];
        foreach ($stepsRaw as $st) {
            if (!\is_array($st)) {
                continue;
            }
            $norm = $this->normalizeStep($st);
            if ($norm !== null) {
                $steps[] = $norm;
            }
            if (\count($steps) >= 40) {
                break;
            }
        }
        if ($steps === []) {
            return null;
        }
        $name = \trim((string)($chain['name'] ?? ''));
        if ($name === '') {
            $name = $complete;
        }

        return [
            'id' => $id,
            'name' => \mb_substr($name, 0, 120),
            'complete_event' => $complete,
            'steps' => $steps,
        ];
    }

    /**
     * @param array<string, mixed> $step
     * @return array<string, mixed>|null
     */
    private function normalizeStep(array $step): ?array
    {
        $type = \strtolower(\trim((string)($step['type'] ?? '')));
        $allowed = ['page', 'track', 'click', 'input', 'submit'];
        if (!\in_array($type, $allowed, true)) {
            // 拾取器可能只写 event
            if ((string)($step['event'] ?? '') !== '') {
                $type = 'track';
            } else {
                return null;
            }
        }
        $out = ['type' => $type];
        $label = \trim((string)($step['label'] ?? ''));
        if ($label !== '') {
            $out['label'] = \mb_substr($label, 0, 80);
        }
        if ($type === 'page') {
            $path = \trim((string)($step['path'] ?? ''));
            $prefix = \trim((string)($step['path_prefix'] ?? ''));
            if ($path !== '') {
                $out['path'] = $path;
            } elseif ($prefix !== '') {
                $out['path_prefix'] = $prefix;
            }
            // 无 path / path_prefix：匹配任意进页
        } else {
            $event = $this->dictionary()->normalizeEventName((string)($step['event'] ?? ''));
            if ($event !== '') {
                $out['event'] = $event;
            } elseif ($type === 'track' || $type === 'click') {
                return null;
            }
            $sel = \trim((string)($step['selector'] ?? ''));
            if ($sel !== '') {
                $out['selector'] = \mb_substr($sel, 0, 200);
            }
        }

        return $out;
    }

    private function slugId(string $id): string
    {
        $id = \strtolower(\trim($id));
        $id = \preg_replace('/[^a-z0-9_\-]+/', '_', $id) ?? '';
        $id = \trim($id, '_-');

        return \mb_substr($id, 0, 64);
    }

    /**
     * @param array<string, mixed> $bundle
     */
    private function writeBundle(int $websiteId, array $bundle): bool
    {
        try {
            return $this->store()->setScopedConfig(
                $this->configKey($websiteId),
                \json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                self::MODULE,
                SystemConfig::area_BACKEND,
                SystemConfig::SCOPE_GLOBAL,
                SystemConfig::LOCALE_DEFAULT
            );
        } catch (\Throwable $e) {
            if (\defined('DEV') && DEV) {
                w_log_error('EventChainService write failed: ' . $e->getMessage());
            }

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
