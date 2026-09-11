<?php

declare(strict_types=1);

namespace Weline\Visitor\Api\Rest\V1;

use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\PixelEventVendor;
use Weline\Visitor\Service\DiscoveredEventService;
use Weline\Visitor\Service\EventDictionaryService;
use Weline\Visitor\Service\EventPickerTokenService;
use Weline\Visitor\Service\PixelEventVendorManager;

/**
 * 前台事件拾取 API（短时 token）。
 * POST /visitor/rest/v1/event-picker/observe
 * POST /visitor/rest/v1/event-picker/record
 */
class EventPicker extends FrontendRestController
{
    public function postObserve()
    {
        return $this->handle(false);
    }

    public function postRecord()
    {
        return $this->handle(true);
    }

    private function handle(bool $persistMap)
    {
        $body = $this->request->getBodyParams();
        if (!\is_array($body)) {
            $body = [];
        }
        $token = \trim((string)($body['token'] ?? $this->request->getPost('token') ?? $this->request->getGet('token') ?? ''));
        /** @var EventPickerTokenService $picker */
        $picker = ObjectManager::getInstance(EventPickerTokenService::class);
        $session = $picker->validate($token);
        if ($session === null) {
            return $this->error('invalid_token', '', 403);
        }

        /** @var EventDictionaryService $dict */
        $dict = ObjectManager::getInstance(EventDictionaryService::class);
        $weline = $dict->normalizeEventName((string)($body['weline_event'] ?? $this->request->getPost('weline_event') ?? ''));
        if ($weline === '') {
            return $this->error('weline_event_required', '', 400);
        }
        $third = \trim((string)($body['third_party_event'] ?? $this->request->getPost('third_party_event') ?? ''));
        if ($third === '') {
            $third = $weline;
        }
        $summary = \trim((string)($body['summary'] ?? $this->request->getPost('summary') ?? ''));
        $path = \trim((string)($body['path'] ?? $this->request->getPost('path') ?? ''));
        $kind = \trim((string)($body['kind'] ?? $this->request->getPost('kind') ?? 'single'));
        if ($kind !== 'chain') {
            $kind = 'single';
        }
        $valueRaw = $body['value'] ?? $this->request->getPost('value') ?? null;
        $params = $body['params'] ?? null;
        if (!\is_array($params)) {
            $paramsRaw = (string)($body['params_json'] ?? $this->request->getPost('params_json') ?? '');
            $params = $paramsRaw !== '' ? \json_decode($paramsRaw, true) : [];
        }
        if (!\is_array($params)) {
            $params = [];
        }
        $chainRaw = (string)($body['chain_json'] ?? $this->request->getPost('chain_json') ?? '');
        $chain = $chainRaw !== '' ? \json_decode($chainRaw, true) : ($body['chain'] ?? null);
        if (!\is_array($chain)) {
            $chain = null;
        }
        $chainSteps = 0;
        if ($chain !== null) {
            $steps = $chain['steps'] ?? $chain;
            if (\is_array($steps)) {
                $chainSteps = \count($steps);
                if ($chainSteps > 40) {
                    $steps = \array_slice(\array_values($steps), 0, 40);
                    $chain['steps'] = $steps;
                    $chainSteps = 40;
                }
            }
        }
        if ($kind === 'chain' && $summary === '' && $chainSteps > 0) {
            $summary = '操作链 ' . $chainSteps . ' 步';
        }

        $websiteId = (int)$session['website_id'];
        $vendorCode = (string)$session['vendor_code'];
        $storageScope = (string)($session['storage_scope'] ?? '');

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
        // 自定义池门禁：仅 record（命名录入 / 链封录）写入
        if ($persistMap) {
            $discovered->add($websiteId, $weline, $storageScope);
        }

        if ($persistMap) {
            try {
                /** @var PixelEventVendorManager $manager */
                $manager = ObjectManager::getInstance(PixelEventVendorManager::class);
                $row = $manager->findByWebsiteCode($websiteId, $vendorCode);
                if ($row === null) {
                    return $this->error('vendor_not_found', '', 404);
                }
                $map = $row->getEventMap();
                if (!\is_array($map)) {
                    $map = [];
                }
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
                return $this->error($e->getMessage(), '', 500);
            }
        }

        return $this->success((string)__('ok'), [
            'weline_event' => $weline,
            'third_party_event' => $third,
            'persisted' => $persistMap,
            'kind' => $kind,
            'chain_steps' => $chainSteps,
        ]);
    }
}
