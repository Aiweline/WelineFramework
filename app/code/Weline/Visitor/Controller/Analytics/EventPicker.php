<?php

declare(strict_types=1);

namespace Weline\Visitor\Controller\Analytics;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\PixelEventVendor;
use Weline\Visitor\Service\DiscoveredEventService;
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
        return $this->handle(false);
    }

    public function postRecord(): string
    {
        return $this->handle(true);
    }

    /**
     * 已录入/已搭接事件列表（当前 token 供应商的 event_map）。
     */
    public function postMapped(): string
    {
        return $this->listMapped();
    }

    public function getMapped(): string
    {
        return $this->listMapped();
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
        if ($kind === 'chain' && $publish && $chain !== null) {
            /** @var EventChainService $chainSvc */
            $chainSvc = ObjectManager::getInstance(EventChainService::class);
            $def = $chainSvc->chainFromPickerSteps($weline, $chain, (string)($chain['id'] ?? ''), $weline);
            if ($def !== null) {
                $up = $chainSvc->upsertChain($websiteId, $def);
                $publishedChain = $up['chain'];
                $chainVersion = (int)$up['version'];
                if ($persistMap) {
                    $complete = \trim((string)($def['complete_event'] ?? ''));
                    if ($complete !== '' && $complete !== $weline) {
                        $discovered->add($websiteId, $complete, $storageScope);
                    }
                }
            }
        }

        $overwritten = false;
        if ($persistMap) {
            try {
                /** @var PixelEventVendorManager $manager */
                $manager = ObjectManager::getInstance(PixelEventVendorManager::class);
                $row = $manager->findByWebsiteCode($websiteId, $vendorCode);
                if ($row === null) {
                    // 已写入发现池：降级成功，避免运营只见「失败」
                    return $this->json([
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
                    ]);
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
                return $this->json([
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
                ]);
            }
        }

        return $this->json([
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
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = 200): string
    {
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
