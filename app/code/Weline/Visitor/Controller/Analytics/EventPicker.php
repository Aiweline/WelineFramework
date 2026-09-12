<?php

declare(strict_types=1);

namespace Weline\Visitor\Controller\Analytics;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\PixelEventVendor;
use Weline\Visitor\Service\DiscoveredEventService;
use Weline\Visitor\Service\EventAnnotationService;
use Weline\Visitor\Service\EventChainService;
use Weline\Visitor\Service\EventDictionaryService;
use Weline\Visitor\Service\EventPickerTokenService;
use Weline\Visitor\Service\PixelEventVendorManager;

/**
 * 前台事件拾取（与 Analytics/Test 同路由族，便于热更新发现）。
 * POST /visitor/analytics/event-picker/postObserve
 * POST /visitor/analytics/event-picker/postRecord
 * POST /visitor/analytics/event-picker/postMapped
 */
class EventPicker extends FrontendController
{
    public function postObserve(): string
    {
        // 全量沙盒流继电（含 click）：复用已注册的 observe 路由，避免新 action 404
        if ((string)($this->request->getPost('sandbox_stream') ?? '') === '1'
            || (string)($this->request->getGet('sandbox_stream') ?? '') === '1') {
            return $this->postStream();
        }

        return $this->handle(false);
    }

    public function postRecord(): string
    {
        return $this->handle(true);
    }

    /**
     * 已录入/已搭接事件列表（当前 token 供应商的 event_map）。
     * 兼：`?runtime_config=1` 返回 tracking runtime（复用已注册 mapped 路由，避免新 action 404）。
     * 兼：`register_auto=1` 沙盒自动发现注册（同理复用 mapped，避免新 action 404）。
     */
    public function postMapped(): string
    {
        if ($this->wantsRegisterAuto()) {
            return $this->postRegisterAuto();
        }
        if ($this->wantsRuntimeConfig()) {
            return $this->runtimeConfig();
        }

        return $this->listMapped();
    }

    public function getMapped(): string
    {
        if ($this->wantsRegisterAuto()) {
            return $this->postRegisterAuto();
        }
        if ($this->wantsRuntimeConfig()) {
            return $this->runtimeConfig();
        }

        return $this->listMapped();
    }

    /**
     * 沙盒自动发现：独立写入当前 storage_scope 自定义池并锁定（不走 observe/stream）。
     * POST /visitor/analytics/event-picker/mapped （register_auto=1&sandbox=1）
     */
    public function postRegisterAuto(): string
    {
        $sandbox = (string)($this->request->getPost('sandbox') ?? $this->request->getGet('sandbox') ?? '');
        if ($sandbox !== '1') {
            return $this->json(['ok' => false, 'error' => 'sandbox_required'], 403);
        }

        $websiteId = (int)($this->request->getPost('website_id') ?? $this->request->getGet('website_id') ?? 0);
        if ($websiteId < 0) {
            return $this->json(['ok' => false, 'error' => 'website_id_required'], 400);
        }
        $storageScope = \trim((string)($this->request->getPost('storage_scope') ?? $this->request->getGet('storage_scope') ?? ''));
        if ($storageScope === '') {
            return $this->json(['ok' => false, 'error' => 'storage_scope_required'], 400);
        }
        $storageScope = \strtolower((string)(\preg_replace('/[^a-z0-9._-]+/', '_', $storageScope) ?: ''));
        if ($storageScope === '' || \substr_count($storageScope, '.') < 2) {
            return $this->json(['ok' => false, 'error' => 'storage_scope_invalid'], 400);
        }

        if (!$this->allowRegisterAutoRateLimit($websiteId, $storageScope)) {
            return $this->json(['ok' => false, 'error' => 'rate_limited'], 429);
        }

        /** @var EventDictionaryService $dict */
        $dict = ObjectManager::getInstance(EventDictionaryService::class);
        $rawName = (string)($this->request->getPost('weline_event')
            ?? $this->request->getPost('name')
            ?? $this->request->getPost('event')
            ?? '');
        $name = $dict->normalizeEventName($rawName);
        if ($name === '' || \strlen($name) > 64) {
            return $this->json(['ok' => false, 'error' => 'invalid_event_name'], 400);
        }
        if ($this->isGenericAutoDiscoverName($name)) {
            return $this->json([
                'ok' => true,
                'skipped' => true,
                'reason' => 'generic_name',
                'weline_event' => $name,
                'website_id' => $websiteId,
                'storage_scope' => $storageScope,
            ]);
        }

        $system = [];
        foreach ($dict->getEvents() as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $n = $dict->normalizeEventName((string)($entry['weline_event'] ?? ''));
            if ($n !== '') {
                $system[$n] = true;
            }
        }
        if (isset($system[$name])) {
            return $this->json([
                'ok' => true,
                'skipped' => true,
                'reason' => 'dictionary',
                'already' => true,
                'weline_event' => $name,
                'website_id' => $websiteId,
                'storage_scope' => $storageScope,
            ]);
        }

        /** @var DiscoveredEventService $discovered */
        $discovered = ObjectManager::getInstance(DiscoveredEventService::class);
        /** @var EventAnnotationService $annotations */
        $annotations = ObjectManager::getInstance(EventAnnotationService::class);
        $existing = $discovered->list($websiteId, $storageScope);
        if (\in_array($name, $existing, true)) {
            $meta = $annotations->get($websiteId, $name, $storageScope);

            return $this->json([
                'ok' => true,
                'already' => true,
                'created' => false,
                'origin' => (string)($meta['origin'] ?? EventAnnotationService::ORIGIN_MANUAL),
                'deletable' => !empty($meta['deletable']),
                'weline_event' => $name,
                'website_id' => $websiteId,
                'storage_scope' => $storageScope,
            ]);
        }

        $ok = $discovered->add($websiteId, $name, $storageScope);
        if (!$ok) {
            return $this->json([
                'ok' => false,
                'error' => 'add_failed',
                'weline_event' => $name,
                'website_id' => $websiteId,
                'storage_scope' => $storageScope,
            ], 500);
        }

        $annotations->patch($websiteId, $name, [
            'origin' => EventAnnotationService::ORIGIN_AUTO_DISCOVERED,
            'deletable' => false,
            'category' => 'custom',
            'match_type' => 'event_name',
            'match_conditions' => [
                ['param' => 'event_name', 'op' => 'equals', 'value' => $name],
            ],
            'copy_params' => true,
            'param_mappings' => [],
        ], $storageScope);

        $scope = $websiteId > 0 ? ('website.' . $websiteId) : null;
        $rev = 0;
        try {
            /** @var \Weline\Visitor\Service\VisitorTrackingConfig $cfg */
            $cfg = ObjectManager::getInstance(\Weline\Visitor\Service\VisitorTrackingConfig::class);
            $rev = $cfg->invalidateAfterMutation($scope);
        } catch (\Throwable) {
            $rev = 0;
        }

        $meta = $annotations->get($websiteId, $name, $storageScope);

        return $this->json([
            'ok' => true,
            'already' => false,
            'created' => true,
            'origin' => EventAnnotationService::ORIGIN_AUTO_DISCOVERED,
            'deletable' => false,
            'origin_label' => EventAnnotationService::ORIGIN_LABELS[EventAnnotationService::ORIGIN_AUTO_DISCOVERED] ?? '自动发现',
            'weline_event' => $name,
            'match_type' => $meta['match_type'] ?? 'event_name',
            'match_conditions' => $meta['match_conditions'] ?? [],
            'website_id' => $websiteId,
            'storage_scope' => $storageScope,
            'configRevision' => $rev,
            'config_revision' => $rev,
        ]);
    }

    /**
     * @return list<string>
     */
    private function genericAutoDiscoverNames(): array
    {
        return [
            'click', 'page_view', 'page_enter', 'page_leave', 'page_exit',
            'scroll', 'mousemove', 'mouseover', 'mouseout', 'hover',
            'focus', 'blur', 'input', 'change', 'submit', 'load', 'unload',
            'resize', 'keydown', 'keyup', 'keypress', 'touchstart', 'touchend',
            'visibilitychange', 'popstate', 'hashchange',
        ];
    }

    private function isGenericAutoDiscoverName(string $name): bool
    {
        return \in_array($name, $this->genericAutoDiscoverNames(), true);
    }

    private function allowRegisterAutoRateLimit(int $websiteId, string $storageScope): bool
    {
        $ip = '';
        try {
            $ip = (string)($this->request->getClientIp() ?? '');
        } catch (\Throwable) {
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        }
        $bucket = \md5($ip . '|' . $websiteId . '|' . $storageScope);
        $now = \time();
        $window = 60;
        $max = 30;
        static $mem = [];
        if (!isset($mem[$bucket]) || !\is_array($mem[$bucket])) {
            $mem[$bucket] = [];
        }
        $mem[$bucket] = \array_values(\array_filter(
            $mem[$bucket],
            static fn(int $t): bool => ($now - $t) < $window
        ));
        if (\count($mem[$bucket]) >= $max) {
            return false;
        }
        $mem[$bucket][] = $now;

        return true;
    }

    private function wantsRegisterAuto(): bool
    {
        return (string)($this->request->getGet('register_auto') ?? '') === '1'
            || (string)($this->request->getPost('register_auto') ?? '') === '1';
    }

    private function wantsRuntimeConfig(): bool
    {
        return (string)($this->request->getGet('runtime_config') ?? '') === '1'
            || (string)($this->request->getPost('runtime_config') ?? '') === '1';
    }

    /**
     * 店面拉取最新 tracking runtime（vendors / customEvents / configRevision）。
     * GET .../mapped?runtime_config=1
     * GET .../mapped?runtime_config=1&revision_only=1  （线上长停留：轻量探版本，可短缓存）
     */
    private function runtimeConfig(): string
    {
        $websiteId = (int)($this->request->getGet('website_id') ?? $this->request->getPost('website_id') ?? 0);
        if ($websiteId <= 0) {
            $websiteId = (int)(\Weline\Framework\Env\WelineEnv::server('WELINE_WEBSITE_ID', '0') ?: 0);
        }
        $scope = $websiteId > 0 ? ('website.' . $websiteId) : null;
        $storageScopeHint = \trim((string)($this->request->getGet('storage_scope') ?? $this->request->getPost('storage_scope') ?? ''));
        $since = (int)($this->request->getGet('since') ?? $this->request->getGet('revision') ?? $this->request->getPost('since') ?? 0);
        $revisionOnly = (string)($this->request->getGet('revision_only') ?? $this->request->getPost('revision_only') ?? '') === '1';

        /** @var \Weline\Visitor\Service\VisitorTrackingConfig $cfg */
        $cfg = ObjectManager::getInstance(\Weline\Visitor\Service\VisitorTrackingConfig::class);
        $revision = $cfg->getRuntimeRevision($scope);
        $changed = $since <= 0 || $revision > $since;

        try {
            if ($revisionOnly) {
                // 短 TTL：浏览器/边缘可合并请求；发布后最多延迟约 15s 感知新 revision
                $this->request->getResponse()->setHeader('Cache-Control', 'public, max-age=15, stale-while-revalidate=30');
                $this->request->getResponse()->setHeader('ETag', '"vtr-' . $revision . '"');
            } else {
                $this->request->getResponse()->setHeader('Cache-Control', 'private, no-store');
            }
        } catch (\Throwable) {
        }

        if ($revisionOnly) {
            return $this->json([
                'ok' => true,
                'website_id' => $websiteId,
                'configRevision' => $revision,
                'config_revision' => $revision,
                'changed' => $changed,
                'revision_only' => true,
            ]);
        }

        $runtime = $cfg->getRuntimeConfig($scope);
        // 请求显式带 storage_scope 时，保证 customEvents 与后台编辑范围一致
        if ($storageScopeHint !== '' && \is_array($runtime)) {
            $hintNorm = \strtolower(\preg_replace('/[^a-z0-9._-]+/', '_', $storageScopeHint) ?: '');
            $current = \strtolower((string)($runtime['storageScope'] ?? $runtime['storage_scope'] ?? ''));
            if ($hintNorm !== '' && $hintNorm !== $current) {
                try {
                    /** @var \Weline\Visitor\Service\DiscoveredEventService $discovered */
                    $discovered = ObjectManager::getInstance(\Weline\Visitor\Service\DiscoveredEventService::class);
                    /** @var \Weline\Visitor\Service\EventAnnotationService $annotations */
                    $annotations = ObjectManager::getInstance(\Weline\Visitor\Service\EventAnnotationService::class);
                    $rows = $discovered->listCustomEvents($websiteId, $storageScopeHint);
                    $runtime['customEvents'] = $annotations->enrichRows($websiteId, $storageScopeHint, $rows);
                    $runtime['custom_events'] = $runtime['customEvents'];
                    $runtime['storageScope'] = $storageScopeHint;
                    $runtime['storage_scope'] = $storageScopeHint;
                } catch (\Throwable) {
                }
            }
        }

        return $this->json([
            'ok' => true,
            'website_id' => $websiteId,
            'configRevision' => $revision,
            'config_revision' => $revision,
            'changed' => $changed,
            'config' => $runtime,
        ]);
    }

    private function currentRuntimeRevisionMeta(int $websiteId = 0): array
    {
        $scope = $websiteId > 0 ? ('website.' . $websiteId) : null;
        try {
            /** @var \Weline\Visitor\Service\VisitorTrackingConfig $cfg */
            $cfg = ObjectManager::getInstance(\Weline\Visitor\Service\VisitorTrackingConfig::class);
            $rev = $cfg->getRuntimeRevision($scope);
        } catch (\Throwable) {
            $rev = 0;
        }

        return [
            'configRevision' => $rev,
            'config_revision' => $rev,
        ];
    }

    /**
     * 录入/持久化成功：强制 bump runtime 版本，保证店面立即感知并重载事件配置。
     *
     * @return array{configRevision:int,config_revision:int}
     */
    private function bumpRuntimeRevisionMeta(int $websiteId = 0): array
    {
        $scope = $websiteId > 0 ? ('website.' . $websiteId) : null;
        try {
            /** @var \Weline\Visitor\Service\VisitorTrackingConfig $cfg */
            $cfg = ObjectManager::getInstance(\Weline\Visitor\Service\VisitorTrackingConfig::class);
            $rev = $cfg->invalidateAfterMutation($scope);
        } catch (\Throwable) {
            return $this->currentRuntimeRevisionMeta($websiteId);
        }

        return [
            'configRevision' => $rev,
            'config_revision' => $rev,
        ];
    }

    /**
     * 沙盒全量流继电（含 click 透传）→ 后台本会话沙盒 accumulate。
     * POST /visitor/analytics/event-picker/stream
     */
    public function postStream(): string
    {
        $websiteId = (int)($this->request->getPost('website_id') ?? $this->request->getGet('website_id') ?? 0);
        if ($websiteId < 0) {
            return $this->json(['ok' => false, 'error' => 'website_id_required'], 400);
        }

        /** @var EventDictionaryService $dict */
        $dict = ObjectManager::getInstance(EventDictionaryService::class);
        $name = $dict->normalizeEventName((string)($this->request->getPost('weline_event')
            ?? $this->request->getPost('name')
            ?? $this->request->getPost('event')
            ?? ''));
        if ($name === '') {
            $name = 'event';
        }

        $paramsRaw = (string)($this->request->getPost('params_json') ?? '');
        $params = [];
        if ($paramsRaw !== '') {
            $decoded = \json_decode($paramsRaw, true);
            if (\is_array($decoded)) {
                $params = \array_slice($decoded, 0, 40, true);
            }
        }

        $source = \trim((string)($this->request->getPost('source') ?? 'sandbox_stream'));
        if ($source === '') {
            $source = 'sandbox_stream';
        }
        $hitKind = \strtolower(\trim((string)($this->request->getPost('hit_kind') ?? '')));
        if ($hitKind !== 'system' && $hitKind !== 'custom') {
            $hitKind = '';
        }
        $path = \mb_substr((string)($this->request->getPost('path') ?? ''), 0, 500);
        $third = \trim((string)($this->request->getPost('third_party_event') ?? ''));
        $at = \trim((string)($this->request->getPost('at') ?? ''));
        if ($at === '') {
            $at = \date('c');
        }
        $eventHit = (string)($this->request->getPost('event_hit') ?? '') === '1'
            || $hitKind === 'system'
            || $hitKind === 'custom';
        $valueRaw = $this->request->getPost('value');
        $summary = \mb_substr((string)($this->request->getPost('summary') ?? ''), 0, 200);
        if ($summary === '' && $source === 'click') {
            $summary = 'click passthrough';
        }

        /** @var EventPickerTokenService $picker */
        $picker = ObjectManager::getInstance(EventPickerTokenService::class);
        $picker->pushAccumulate($websiteId, [
            'weline_event' => $name,
            'third_party_event' => $third !== '' ? $third : $name,
            'source' => $source,
            'summary' => $summary,
            'path' => $path,
            'at' => $at,
            'params' => $params,
            'hit_kind' => $hitKind,
            'custom' => $hitKind === 'custom',
            'event_hit' => $eventHit,
            'value' => $valueRaw,
            'has_value' => $valueRaw !== null && $valueRaw !== '' && (float)$valueRaw != 0.0,
        ]);

        return $this->json([
            'ok' => true,
            'weline_event' => $name,
        ]);
    }

    private function listMapped(): string
    {
        $token = \trim((string)($this->request->getPost('token') ?? $this->request->getGet('token') ?? ''));
        /** @var EventPickerTokenService $picker */
        $picker = ObjectManager::getInstance(EventPickerTokenService::class);
        $session = $picker->validate($token);
        if ($session === null) {
            return $this->json(['ok' => false, 'error' => 'invalid_token'], 403);
        }

        $websiteId = (int)$session['website_id'];
        $vendorCode = (string)$session['vendor_code'];
        /** @var EventDictionaryService $dict */
        $dict = ObjectManager::getInstance(EventDictionaryService::class);
        /** @var PixelEventVendorManager $manager */
        $manager = ObjectManager::getInstance(PixelEventVendorManager::class);
        $row = $manager->findByWebsiteCode($websiteId, $vendorCode);
        $map = $row !== null ? $row->getEventMap() : [];
        if (!\is_array($map)) {
            $map = [];
        }

        $system = [];
        foreach ($dict->getEvents() as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $n = $dict->normalizeEventName((string)($entry['weline_event'] ?? ''));
            if ($n !== '') {
                $system[$n] = true;
            }
        }

        $events = [];
        foreach ($map as $k => $v) {
            $weline = $dict->normalizeEventName((string)$k);
            if ($weline === '') {
                continue;
            }
            $third = \trim((string)$v);
            if ($third === '') {
                $third = $weline;
            }
            $isSystem = isset($system[$weline]);
            $events[] = [
                'weline_event' => $weline,
                'third_party_event' => $third,
                'custom' => !$isSystem,
                'source' => $isSystem ? 'system' : 'custom',
            ];
        }
        \usort($events, static function (array $a, array $b): int {
            if (($a['custom'] ?? false) !== ($b['custom'] ?? false)) {
                return ($a['custom'] ?? false) ? -1 : 1;
            }

            return \strcmp((string)$a['weline_event'], (string)$b['weline_event']);
        });

        return $this->json([
            'ok' => true,
            'vendor_code' => $vendorCode,
            'website_id' => $websiteId,
            'count' => \count($events),
            'events' => $events,
        ]);
    }

    private function handle(bool $persistMap): string
    {
        if ((string)($this->request->getPost('sandbox_stream') ?? '') === '1'
            || (string)($this->request->getGet('sandbox_stream') ?? '') === '1') {
            return $this->postStream();
        }

        $token = \trim((string)($this->request->getPost('token') ?? $this->request->getGet('token') ?? ''));
        /** @var EventPickerTokenService $picker */
        $picker = ObjectManager::getInstance(EventPickerTokenService::class);
        $session = $picker->validate($token);
        if ($session === null) {
            return $this->json(['ok' => false, 'error' => 'invalid_token'], 403);
        }

        /** @var EventDictionaryService $dict */
        $dict = ObjectManager::getInstance(EventDictionaryService::class);
        $weline = $dict->normalizeEventName((string)($this->request->getPost('weline_event') ?? ''));
        if ($weline === '') {
            return $this->json(['ok' => false, 'error' => 'weline_event_required'], 400);
        }
        $third = \trim((string)($this->request->getPost('third_party_event') ?? ''));
        if ($third === '') {
            $third = $weline;
        }
        $summary = \trim((string)($this->request->getPost('summary') ?? ''));
        $path = \trim((string)($this->request->getPost('path') ?? ''));
        $kind = \trim((string)($this->request->getPost('kind') ?? 'single'));
        if ($kind !== 'chain') {
            $kind = 'single';
        }
        $force = (string)($this->request->getPost('force') ?? '') === '1';
        $valueRaw = $this->request->getPost('value') ?? null;
        $paramsRaw = (string)($this->request->getPost('params_json') ?? $this->request->getPost('params') ?? '');
        $params = $paramsRaw !== '' ? \json_decode($paramsRaw, true) : null;
        if (!\is_array($params)) {
            $params = [];
        }
        $chainRaw = (string)($this->request->getPost('chain_json') ?? '');
        $chain = $chainRaw !== '' ? \json_decode($chainRaw, true) : null;
        if (!\is_array($chain)) {
            $chain = null;
        }
        $chainSteps = 0;
        if ($chain !== null) {
            $steps = $chain['steps'] ?? $chain;
            if (\is_array($steps)) {
                $chainSteps = \count($steps);
                // 限制体积，避免缓存膨胀
                if ($chainSteps > 40) {
                    $steps = \array_slice(\array_values($steps), 0, 40);
                    $chain['steps'] = $steps;
                    $chainSteps = 40;
                }
            }
        }
        $websiteId = (int)$session['website_id'];
        $vendorCode = (string)$session['vendor_code'];
        $storageScope = (string)($session['storage_scope'] ?? '');

        if ($kind === 'chain' && $summary === '' && $chainSteps > 0) {
            $summary = '操作链 ' . $chainSteps . ' 步';
        }

        $picker->pushAccumulate($websiteId, [
            'weline_event' => $weline,
            'third_party_event' => $third,
            'source' => $persistMap ? ($kind === 'chain' ? 'picker_chain_record' : 'picker_record') : ($kind === 'chain' ? 'picker_chain_observe' : 'picker_observe'),
            'summary' => $summary,
            'path' => $path,
            'at' => \date('c'),
            'kind' => $kind,
            'chain' => $chain,
            'chain_steps' => $chainSteps,
            'value' => $valueRaw,
            'params' => $params,
            'storage_scope' => $storageScope,
        ]);

        /** @var DiscoveredEventService $discovered */
        $discovered = ObjectManager::getInstance(DiscoveredEventService::class);
        // 自定义池门禁：仅「输入名字并录入」或「事件链封录」写入；observe/自动发现不进池
        if ($persistMap) {
            $discovered->add($websiteId, $weline, $storageScope);
        }

        $publishedChain = null;
        $chainVersion = 0;
        $publish = (string)($this->request->getPost('publish_chain') ?? '') === '1'
            || (string)($this->request->getPost('publish') ?? '') === '1';
        // 封链（仅录入或发布）只要有步骤就写入运行时定义，否则前台按路径无法推进闭环。
        if ($kind === 'chain' && $persistMap && $chain !== null) {
            /** @var EventChainService $chainSvc */
            $chainSvc = ObjectManager::getInstance(EventChainService::class);
            $def = $chainSvc->chainFromPickerSteps($weline, $chain, (string)($chain['id'] ?? ''), $weline);
            if ($def !== null) {
                $up = $chainSvc->upsertChain($websiteId, $def);
                $publishedChain = $up['chain'];
                $chainVersion = (int)$up['version'];
                $complete = \trim((string)($def['complete_event'] ?? $weline));
                if ($complete !== '') {
                    $discovered->add($websiteId, $complete, $storageScope);
                }
                try {
                    /** @var EventAnnotationService $annotations */
                    $annotations = ObjectManager::getInstance(EventAnnotationService::class);
                    $annotations->patch($websiteId, $complete !== '' ? $complete : $weline, [
                        'category' => 'chain',
                    ], $storageScope);
                    if ($weline !== '' && $weline !== $complete) {
                        $annotations->patch($websiteId, $weline, [
                            'category' => 'chain',
                        ], $storageScope);
                    }
                } catch (\Throwable) {
                }
            } elseif ($publish) {
                // keep publishedChain null
            }
        }

        $overwritten = false;
        if ($persistMap) {
            try {
                /** @var PixelEventVendorManager $manager */
                $manager = ObjectManager::getInstance(PixelEventVendorManager::class);
                $row = $manager->findByWebsiteCode($websiteId, $vendorCode);
                if ($row === null) {
                    // 已写入发现池：降级成功，避免运营只见「失败」；仍 bump 使池变更立即生效
                    return $this->json(\array_merge([
                        'ok' => true,
                        'weline_event' => $weline,
                        'third_party_event' => $third,
                        'persisted' => false,
                        'warning' => 'vendor_not_found',
                        'kind' => $kind,
                        'chain_steps' => $chainSteps,
                        'chain_published' => $publishedChain !== null,
                        'chain_version' => $chainVersion,
                        'published_chain' => $publishedChain,
                        'website_id' => $websiteId,
                    ], $this->bumpRuntimeRevisionMeta($websiteId)));
                }
                $map = $row->getEventMap();
                if (!\is_array($map)) {
                    $map = [];
                }
                $existed = \array_key_exists($weline, $map);
                $existingThird = $existed ? \trim((string)$map[$weline]) : '';
                if ($existed && !$force) {
                    return $this->json([
                        'ok' => false,
                        'error' => 'duplicate',
                        'duplicate' => true,
                        'weline_event' => $weline,
                        'third_party_event' => $third,
                        'existing_third_party_event' => $existingThird !== '' ? $existingThird : $weline,
                        'message' => '事件已录入，确认后可覆盖',
                        'kind' => $kind,
                        'chain_steps' => $chainSteps,
                    ], 409);
                }
                $overwritten = $existed;
                $map[$weline] = $third;
                $manager->saveCustom($websiteId, [
                    'code' => $vendorCode,
                    'name' => (string)$row->getData(PixelEventVendor::schema_fields_NAME),
                    'enabled' => (int)$row->getData(PixelEventVendor::schema_fields_ENABLED) === 1,
                    'mode' => (string)$row->getData(PixelEventVendor::schema_fields_MODE),
                    'default_mode' => (string)$row->getData(PixelEventVendor::schema_fields_DEFAULT_MODE),
                    'use_default_map' => false,
                    'credentials' => $row->getCredentials(),
                    'event_map' => $map,
                    'scope' => $row->getScope(),
                    'sandbox_js' => (string)$row->getData(PixelEventVendor::schema_fields_SANDBOX_JS),
                    'inject_js' => (string)$row->getData(PixelEventVendor::schema_fields_INJECT_JS),
                    'allow_module_edit' => true,
                ], (int)$row->getData(PixelEventVendor::schema_fields_ID));
            } catch (\Throwable $e) {
                return $this->json(\array_merge([
                    'ok' => true,
                    'weline_event' => $weline,
                    'third_party_event' => $third,
                    'persisted' => false,
                    'warning' => $e->getMessage(),
                    'kind' => $kind,
                    'chain_steps' => $chainSteps,
                    'chain_published' => $publishedChain !== null,
                    'chain_version' => $chainVersion,
                    'published_chain' => $publishedChain,
                    'website_id' => $websiteId,
                ], $this->bumpRuntimeRevisionMeta($websiteId)));
            }
        }

        $payload = [
            'ok' => true,
            'weline_event' => $weline,
            'third_party_event' => $third,
            'persisted' => $persistMap,
            'overwritten' => $overwritten,
            'kind' => $kind,
            'chain_steps' => $chainSteps,
            'chain_published' => $publishedChain !== null,
            'chain_version' => $chainVersion,
            'published_chain' => $publishedChain,
            'website_id' => $websiteId,
        ];
        // 只要录入（persist）成功就发新版本，保证事件定义立即对店面生效
        if ($persistMap) {
            $payload = \array_merge($payload, $this->bumpRuntimeRevisionMeta($websiteId));
        }

        return $this->json($payload);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = 200): string
    {
        // 实时改事件主路径：凡店面提交响应都带当前 config_revision，客户端见变大再拉全量配置
        if (!\array_key_exists('config_revision', $data) && !\array_key_exists('configRevision', $data)) {
            $websiteId = (int)($data['website_id']
                ?? $this->request->getPost('website_id')
                ?? $this->request->getGet('website_id')
                ?? 0);
            $data = \array_merge($data, $this->currentRuntimeRevisionMeta($websiteId));
        }
        try {
            $this->request->getResponse()->setHeader('Content-Type', 'application/json; charset=utf-8');
            if ($status !== 200 && \method_exists($this->request->getResponse(), 'setCode')) {
                $this->request->getResponse()->setCode($status);
            }
        } catch (\Throwable) {
        }
        $json = \json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return \is_string($json) ? $json : '{"ok":false}';
    }
}
