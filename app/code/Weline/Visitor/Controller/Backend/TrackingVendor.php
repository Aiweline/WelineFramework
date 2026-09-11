<?php

declare(strict_types=1);

namespace Weline\Visitor\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\SystemConfig\Service\SystemConfigTargetScopeService;
use Weline\Visitor\Model\Pixel;
use Weline\Visitor\Model\PixelEventVendor;
use Weline\Visitor\Service\DiscoveredEventService;
use Weline\Visitor\Service\EventAnnotationService;
use Weline\Visitor\Service\EventChainService;
use Weline\Visitor\Service\EventDictionaryService;
use Weline\Visitor\Service\EventPickerTokenService;
use Weline\Visitor\Service\PixelChannelLandingUrlService;
use Weline\Visitor\Service\PixelEventVendorManager;

/**
 * 事件供应商管理（正式页：变体2 左列表右详情）。
 */
#[Acl('Weline_Visitor::tracking_vendor', '事件供应商', 'plug', '像素第三方事件供应商管理', 'Weline_Backend::data_tools_group')]
class TrackingVendor extends BackendController
{
    #[Acl('Weline_Visitor::tracking_vendor_index', '查看事件供应商', 'plug', '查看事件供应商列表与配置')]
    public function index(): string
    {
        $workScope = $this->assignTrackingWorkScope(false);
        $websiteId = $this->websiteIdFromWorkScope($workScope);
        /** @var PixelEventVendorManager $manager */
        $manager = ObjectManager::getInstance(PixelEventVendorManager::class);

        try {
            if (!(string)($this->request->getGet('nosync') ?? '')) {
                $manager->syncModuleProviders($websiteId, false);
            }
        } catch (\Throwable $e) {
            MessageManager::warning((string)__('同步模块供应商失败：%{1}', [$e->getMessage()]));
        }

        $vendors = [];
        try {
            $vendors = $manager->listForWebsite($websiteId);
        } catch (\Throwable $e) {
            MessageManager::warning((string)__('加载供应商失败（可能需 setup:upgrade）：%{1}', [$e->getMessage()]));
        }

        $selectedCode = (string)($this->request->getGet('vendor') ?? '');
        $selected = null;
        foreach ($vendors as $row) {
            if ($selectedCode !== '' && (string)$row->getData(PixelEventVendor::schema_fields_CODE) === $selectedCode) {
                $selected = $row;
                break;
            }
        }
        if ($selected === null && $vendors !== []) {
            $selected = $vendors[0];
            $selectedCode = (string)$selected->getData(PixelEventVendor::schema_fields_CODE);
        }

        $runtime = [];
        foreach ($vendors as $row) {
            $runtime[] = $row->toRuntimeVendor();
        }

        $storageScope = (string)($workScope['storage_scope'] ?? '');
        $mappableRows = $this->buildMappableEventRows($websiteId, $storageScope);
        // 已保存搭接中的自定义 key 必须进入下拉，否则刷新后 option 缺失，看起来像「没保存」
        if ($selected instanceof PixelEventVendor) {
            $mappableRows = $this->mergeEventMapKeysIntoMappable($mappableRows, $selected->getEventMap());
        }
        $mappableNames = \array_values(\array_map(static fn(array $r): string => (string)$r['name'], $mappableRows));
        $customRows = [];
        try {
            /** @var DiscoveredEventService $discovered */
            $discovered = ObjectManager::getInstance(DiscoveredEventService::class);
            $customRows = $discovered->listCustomEvents($websiteId, $storageScope);
        } catch (\Throwable) {
            $customRows = [];
        }

        $url = $this->request->getUrlBuilder();
        $listUrl = $url->getBackendUrlPath('visitor/backend/pixel-dashboard/list', [
            'websiteId' => $websiteId,
        ]);
        $recentUrl = $url->getBackendUrlPath('visitor/backend/tracking-vendor/getRecentEvents', [
            'website_id' => $websiteId,
        ]);
        $startPickerUrl = $url->getBackendUrlPath('visitor/backend/tracking-vendor/postStartPicker');
        $customEventsUrl = $url->getBackendUrlPath('visitor/backend/tracking-vendor/getCustomEvents');
        $deleteCustomEventUrl = $url->getBackendUrlPath('visitor/backend/tracking-vendor/postDeleteCustomEvent');
        $addCustomEventUrl = $url->getBackendUrlPath('visitor/backend/tracking-vendor/add-custom-event');
        $autosaveUrl = $url->getBackendUrlPath('visitor/backend/tracking-vendor/postAutosave');
        $annotateEventUrl = $url->getBackendUrlPath('visitor/backend/tracking-vendor/postAnnotateEvent');
        $ensureDemoChainUrl = $url->getBackendUrlPath('visitor/backend/tracking-vendor/postEnsureDemoEventChain');
        $eventChainsUrl = $url->getBackendUrlPath('visitor/backend/tracking-vendor/getEventChains', [
            'website_id' => $websiteId,
        ]);

        /** @var EventAnnotationService $annotations */
        $annotations = ObjectManager::getInstance(EventAnnotationService::class);
        $customRows = $annotations->enrichRows($websiteId, $storageScope, $customRows);

        $this->assign('page_title', (string)__('事件供应商'));
        $this->assign('website_id', $websiteId);
        $this->assign('scope_query', $this->trackingScopeQuery($workScope));
        $this->assign('storage_scope', $storageScope);
        $this->assign('vendors', $vendors);
        $this->assign('vendors_runtime', $runtime);
        $this->assign('vendors_json', \json_encode($runtime, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->assign('selected_vendor', $selected);
        $this->assign('selected_code', $selectedCode);
        $this->assign('mappable_events', $mappableNames);
        $this->assign('mappable_event_rows', $mappableRows);
        $this->assign('mappable_events_json', \json_encode($mappableRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->assign('custom_event_rows', $customRows);
        $this->assign('custom_events_json', \json_encode($customRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->assign('pixel_list_url', $listUrl);
        $this->assign('recent_events_url', $recentUrl);
        $this->assign('start_picker_url', $startPickerUrl);
        $this->assign('custom_events_url', $customEventsUrl);
        $this->assign('delete_custom_event_url', $deleteCustomEventUrl);
        $this->assign('add_custom_event_url', $addCustomEventUrl);
        $this->assign('autosave_url', $autosaveUrl);
        $this->assign('annotate_event_url', $annotateEventUrl);
        $this->assign('event_category_options_json', \json_encode($annotations->categoryOptions(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->assign('match_param_options_json', \json_encode($annotations->matchParamOptions(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->assign('match_op_options_json', \json_encode($annotations->matchOpOptions(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->assign('ensure_demo_chain_url', $ensureDemoChainUrl);
        $this->assign('event_chains_url', $eventChainsUrl);
        $this->assign('dev_no_report', \defined('DEV') && DEV);
        $this->assign('ui_layout', 'split');

        return $this->fetch();
    }

    #[Acl('Weline_Visitor::tracking_vendor_sync', '同步模块供应商', 'refresh', '扫描并同步模块事件供应商')]
    public function postSync(): string
    {
        $workScope = $this->assignTrackingWorkScope(true);
        $websiteId = $this->websiteIdFromWorkScope($workScope);
        /** @var PixelEventVendorManager $manager */
        $manager = ObjectManager::getInstance(PixelEventVendorManager::class);
        try {
            $result = $manager->syncModuleProviders($websiteId, true);
            MessageManager::success((string)__('已同步 %{1} 个模块供应商（新建 %{2}，更新 %{3}）', [
                $result['synced'],
                $result['created'],
                $result['updated'],
            ]));
        } catch (\Throwable $e) {
            MessageManager::error((string)__('同步失败：%{1}', [$e->getMessage()]));
        }

        return $this->redirect('visitor/backend/tracking-vendor/index', $this->trackingScopeQuery($workScope));
    }

    #[Acl('Weline_Visitor::tracking_vendor_save', '保存事件供应商', 'save', '保存事件供应商配置')]
    public function postSave(): string
    {
        $workScope = $this->assignTrackingWorkScope(true);
        $websiteId = $this->websiteIdFromWorkScope($workScope);
        $id = (int)($this->request->getPost('id') ?? 0);
        $payload = $this->payloadFromSaveRequest();
        $uiTab = $this->normalizeUiTab((string)($this->request->getPost('ui_tab') ?? ''));

        /** @var PixelEventVendorManager $manager */
        $manager = ObjectManager::getInstance(PixelEventVendorManager::class);
        try {
            $row = $manager->saveCustom($websiteId, $payload, $id > 0 ? $id : null);
            MessageManager::success((string)__('已保存供应商 %{1}', [$row->getData(PixelEventVendor::schema_fields_CODE)]));
            return $this->redirect('visitor/backend/tracking-vendor/index', \array_merge(
                $this->trackingScopeQuery($workScope),
                [
                    'vendor' => (string)$row->getData(PixelEventVendor::schema_fields_CODE),
                    'tab' => $uiTab,
                ],
            ));
        } catch (ResponseTerminateException $e) {
            // redirect() 以 Error 终止响应，不可当业务失败
            throw $e;
        } catch (\Throwable $e) {
            MessageManager::error((string)__('保存失败：%{1}', [$e->getMessage()]));
            return $this->redirect('visitor/backend/tracking-vendor/index', \array_merge(
                $this->trackingScopeQuery($workScope),
                [
                    'vendor' => (string)($payload['code'] ?? ''),
                    'tab' => $uiTab,
                ],
            ));
        }
    }

    /**
     * 各 Tab 自动保存（JSON，不整页跳转，避免丢 Tab/映射观感）。
     */
    #[Acl('Weline_Visitor::tracking_vendor_save', '保存事件供应商', 'save', '保存事件供应商配置')]
    public function postAutosave(): string
    {
        $workScope = $this->assignTrackingWorkScope(true);
        $websiteId = $this->websiteIdFromWorkScope($workScope);
        $id = (int)($this->request->getPost('id') ?? 0);
        $payload = $this->payloadFromSaveRequest();
        /** @var PixelEventVendorManager $manager */
        $manager = ObjectManager::getInstance(PixelEventVendorManager::class);
        try {
            $row = $manager->saveCustom($websiteId, $payload, $id > 0 ? $id : null);
            $map = $row->getEventMap();

            return $this->jsonResponse([
                'ok' => true,
                'code' => (string)$row->getData(PixelEventVendor::schema_fields_CODE),
                'use_default_map' => (int)$row->getData(PixelEventVendor::schema_fields_USE_DEFAULT_MAP) === 1,
                'event_map' => $map,
                'map_count' => \count($map),
                'website_id' => $websiteId,
                'storage_scope' => (string)($workScope['storage_scope'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            return $this->jsonResponse([
                'ok' => false,
                'error' => $e->getMessage() !== '' ? $e->getMessage() : 'autosave_failed',
            ], 500);
        }
    }

    #[Acl('Weline_Visitor::tracking_vendor_create', '新建自定义供应商', 'plus', '新建自定义事件供应商')]
    public function postCreate(): string
    {
        $workScope = $this->assignTrackingWorkScope(true);
        $websiteId = $this->websiteIdFromWorkScope($workScope);
        $code = \trim((string)($this->request->getPost('code') ?? ''));
        $name = \trim((string)($this->request->getPost('name') ?? ''));
        /** @var PixelEventVendorManager $manager */
        $manager = ObjectManager::getInstance(PixelEventVendorManager::class);
        try {
            $row = $manager->saveCustom($websiteId, [
                'code' => $code !== '' ? $code : ('custom_' . \time()),
                'name' => $name !== '' ? $name : (string)__('自定义供应商'),
                'enabled' => false,
                'mode' => PixelEventVendor::MODE_SANDBOX,
                'default_mode' => PixelEventVendor::MODE_SANDBOX,
                'use_default_map' => true,
                'credentials' => ['api_key' => ''],
                'event_map' => ['cta_click' => 'click'],
                'sandbox_js' => "// custom sandbox\n",
                'inject_js' => '',
            ]);
            MessageManager::success((string)__('已创建自定义供应商'));
            return $this->redirect('visitor/backend/tracking-vendor/index', \array_merge(
                $this->trackingScopeQuery($workScope),
                ['vendor' => (string)$row->getData(PixelEventVendor::schema_fields_CODE)],
            ));
        } catch (ResponseTerminateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            MessageManager::error((string)__('创建失败：%{1}', [$e->getMessage()]));
            return $this->redirect('visitor/backend/tracking-vendor/index', $this->trackingScopeQuery($workScope));
        }
    }

    /**
     * 本会话累计：拾取缓冲 + 近期入库像素事件。
     */
    #[Acl('Weline_Visitor::tracking_vendor_index', '查看事件供应商', 'plug', '查看事件供应商列表与配置')]
    public function getRecentEvents(): string
    {
        $websiteId = $this->resolveWebsiteId();
        $limit = (int)($this->request->getGet('limit') ?? 40);
        if ($limit < 1 || $limit > 100) {
            $limit = 40;
        }

        /** @var EventPickerTokenService $picker */
        $picker = ObjectManager::getInstance(EventPickerTokenService::class);
        $acc = $picker->listAccumulate($websiteId, $limit);

        $dbRows = [];
        try {
            $rows = w_obj(Pixel::class)->reset()
                ->where(Pixel::schema_fields_WEBSITE_ID, $websiteId)
                ->order(Pixel::schema_fields_CREATED_AT, 'DESC')
                ->limit($limit)
                ->select()
                ->fetchArray();
            foreach ((array)$rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $dbRows[] = [
                    'weline_event' => (string)($row[Pixel::schema_fields_EVENT] ?? ''),
                    'third_party_event' => '',
                    'source' => 'pixel_db',
                    'summary' => \mb_substr((string)($row[Pixel::schema_fields_URL] ?? ''), 0, 120),
                    'at' => (string)($row[Pixel::schema_fields_CREATED_AT] ?? ''),
                    'path' => (string)($row[Pixel::schema_fields_URL] ?? ''),
                    'value' => $row[Pixel::schema_fields_VALUE] ?? null,
                    'has_value' => ((float)($row[Pixel::schema_fields_VALUE] ?? 0)) != 0.0,
                ];
            }
        } catch (\Throwable) {
            $dbRows = [];
        }

        $merged = [];
        $seen = [];
        foreach (\array_merge($acc, $dbRows) as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $key = ($item['weline_event'] ?? '') . '|' . ($item['at'] ?? '') . '|' . ($item['source'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $merged[] = $item;
            if (\count($merged) >= $limit) {
                break;
            }
        }

        $workScope = $this->assignTrackingWorkScope(false);
        $storageScope = (string)($workScope['storage_scope'] ?? '');
        /** @var EventAnnotationService $annotations */
        $annotations = ObjectManager::getInstance(EventAnnotationService::class);
        foreach ($merged as $item) {
            if (!empty($item['has_value']) || $annotations->detectHasValueFromPayload($item)) {
                try {
                    $annotations->markHasValue($websiteId, (string)($item['weline_event'] ?? ''), $storageScope);
                } catch (\Throwable) {
                }
            }
        }
        $merged = $annotations->enrichRows($websiteId, $storageScope, $merged);

        return $this->jsonResponse([
            'ok' => true,
            'website_id' => $websiteId,
            'events' => $merged,
            'categories' => $annotations->categoryOptions(),
        ]);
    }

    /**
     * 签发前台拾取 URL。
     */
    #[Acl('Weline_Visitor::tracking_vendor_save', '保存事件供应商', 'save', '保存事件供应商配置')]
    public function postStartPicker(): string
    {
        $workScope = $this->assignTrackingWorkScope(true);
        $websiteId = $this->websiteIdFromWorkScope($workScope);
        $storageScope = (string)($workScope['storage_scope'] ?? '');
        $vendorCode = \trim((string)($this->request->getPost('vendor') ?? $this->request->getGet('vendor') ?? ''));
        if ($vendorCode === '') {
            return $this->jsonResponse(['ok' => false, 'error' => 'vendor_required'], 400);
        }

        $adminId = 0;
        try {
            $adminId = (int)($this->session->getData('admin_id') ?? $this->session->getData('user_id') ?? 0);
        } catch (\Throwable) {
            $adminId = 0;
        }

        /** @var EventPickerTokenService $picker */
        $picker = ObjectManager::getInstance(EventPickerTokenService::class);
        $issued = $picker->issue($websiteId, $vendorCode, $adminId, $storageScope);

        /** @var PixelChannelLandingUrlService $landing */
        $landing = ObjectManager::getInstance(PixelChannelLandingUrlService::class);
        $base = \rtrim($landing->resolveBaseUrl($websiteId), '/');
        if ($base === '') {
            $base = \rtrim((string)\Weline\Framework\App\Env::getInstance()->getBaseUrl(), '/');
        }
        $query = \http_build_query([
            'weline_pixel_picker' => '1',
            'token' => $issued['token'],
            'vendor' => $vendorCode,
            'website_id' => $websiteId,
            'storage_scope' => $storageScope,
        ]);
        $url = $base . '/?' . $query;

        return $this->jsonResponse([
            'ok' => true,
            'url' => $url,
            'token' => $issued['token'],
            'expires_at' => $issued['expires_at'],
            'storage_scope' => $storageScope,
        ]);
    }

    /**
     * 当前配置范围下的自定义事件池（不跨范围）。
     */
    #[Acl('Weline_Visitor::tracking_vendor_index', '查看事件供应商', 'plug', '查看事件供应商列表与配置')]
    public function getCustomEvents(): string
    {
        $workScope = $this->assignTrackingWorkScope(false);
        $websiteId = $this->websiteIdFromWorkScope($workScope);
        $storageScope = (string)($workScope['storage_scope'] ?? '');
        /** @var DiscoveredEventService $discovered */
        $discovered = ObjectManager::getInstance(DiscoveredEventService::class);
        $events = $discovered->listCustomEvents($websiteId, $storageScope);
        /** @var EventAnnotationService $annotations */
        $annotations = ObjectManager::getInstance(EventAnnotationService::class);
        $events = $annotations->enrichRows($websiteId, $storageScope, $events);

        return $this->jsonResponse([
            'ok' => true,
            'website_id' => $websiteId,
            'storage_scope' => $storageScope,
            'scope_key' => $discovered->scopeKeyFromStorage($storageScope, $websiteId),
            'count' => \count($events),
            'events' => $events,
            'categories' => $annotations->categoryOptions(),
        ]);
    }

    /**
     * 事件注解：星标 / 分类 / 有值标记（按配置范围）。
     */
    #[Acl('Weline_Visitor::tracking_vendor_save', '保存事件供应商', 'save', '保存事件供应商配置')]
    public function postAnnotateEvent(): string
    {
        $workScope = $this->assignTrackingWorkScope(true);
        $websiteId = $this->websiteIdFromWorkScope($workScope);
        $storageScope = (string)($workScope['storage_scope'] ?? '');
        $name = \trim((string)($this->request->getPost('weline_event') ?? $this->request->getPost('name') ?? ''));
        if ($name === '') {
            return $this->jsonResponse(['ok' => false, 'error' => 'weline_event_required'], 400);
        }
        /** @var EventAnnotationService $annotations */
        $annotations = ObjectManager::getInstance(EventAnnotationService::class);
        $patch = [];
        if ($this->request->getPost('starred') !== null) {
            $patch['starred'] = (string)$this->request->getPost('starred') === '1'
                || (string)$this->request->getPost('starred') === 'true';
        }
        if ($this->request->getPost('category') !== null) {
            $patch['category'] = (string)$this->request->getPost('category');
        }
        if ((string)($this->request->getPost('has_value') ?? '') === '1') {
            $patch['has_value'] = true;
        }
        if ($patch === []) {
            return $this->jsonResponse(['ok' => false, 'error' => 'nothing_to_update'], 400);
        }
        $ok = $annotations->patch($websiteId, $name, $patch, $storageScope);
        $meta = $annotations->get($websiteId, $name, $storageScope);

        return $this->jsonResponse([
            'ok' => $ok,
            'weline_event' => $name,
            'annotation' => $meta,
            'category_label' => EventAnnotationService::CATEGORY_LABELS[$meta['category']] ?? $meta['category'],
            'error' => $ok ? null : 'annotate_failed',
        ], $ok ? 200 : 500);
    }

    /**
     * 删除当前配置范围内的自定义事件（不跨范围）。
     */
    #[Acl('Weline_Visitor::tracking_vendor_save', '保存事件供应商', 'save', '保存事件供应商配置')]
    public function postDeleteCustomEvent(): string
    {
        $workScope = $this->assignTrackingWorkScope(true);
        $websiteId = $this->websiteIdFromWorkScope($workScope);
        $storageScope = (string)($workScope['storage_scope'] ?? '');
        $name = \trim((string)($this->request->getPost('weline_event') ?? $this->request->getPost('name') ?? ''));
        if ($name === '') {
            return $this->jsonResponse(['ok' => false, 'error' => 'weline_event_required'], 400);
        }
        /** @var DiscoveredEventService $discovered */
        $discovered = ObjectManager::getInstance(DiscoveredEventService::class);
        $ok = $discovered->remove($websiteId, $name, $storageScope);
        $events = $discovered->listCustomEvents($websiteId, $storageScope);
        /** @var EventAnnotationService $annotations */
        $annotations = ObjectManager::getInstance(EventAnnotationService::class);
        $events = $annotations->enrichRows($websiteId, $storageScope, $events);

        return $this->jsonResponse([
            'ok' => $ok,
            'deleted' => $name,
            'website_id' => $websiteId,
            'storage_scope' => $storageScope,
            'count' => \count($events),
            'events' => $events,
            'error' => $ok ? null : 'delete_failed',
        ], $ok ? 200 : 500);
    }

    /**
     * GA4 式简易录入：写入当前配置范围自定义事件池（不跨范围）。
     * 系统字典事件名不会进入自定义池，避免「录入成功却看不见」。
     */
    #[Acl('Weline_Visitor::tracking_vendor_save', '保存事件供应商', 'save', '保存事件供应商配置')]
    public function postAddCustomEvent(): string
    {
        $workScope = $this->assignTrackingWorkScope(true);
        $websiteId = $this->websiteIdFromWorkScope($workScope);
        $storageScope = (string)($workScope['storage_scope'] ?? '');
        $name = \trim((string)($this->request->getPost('weline_event') ?? $this->request->getPost('name') ?? ''));
        $third = \trim((string)($this->request->getPost('third_party_event') ?? ''));
        if ($name === '') {
            return $this->jsonResponse(['ok' => false, 'error' => 'weline_event_required'], 400);
        }

        /** @var EventDictionaryService $dict */
        $dict = ObjectManager::getInstance(EventDictionaryService::class);
        $normalized = $dict->normalizeEventName($name);
        if ($normalized === '') {
            return $this->jsonResponse(['ok' => false, 'error' => 'invalid_event_name'], 400);
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
        $isCustom = !isset($system[$normalized]);

        /** @var DiscoveredEventService $discovered */
        $discovered = ObjectManager::getInstance(DiscoveredEventService::class);
        $ok = true;
        if ($isCustom) {
            $ok = $discovered->add($websiteId, $normalized, $storageScope);
        }

        /** @var EventAnnotationService $annotations */
        $annotations = ObjectManager::getInstance(EventAnnotationService::class);
        $conditionsRaw = (string)($this->request->getPost('conditions_json') ?? $this->request->getPost('match_conditions_json') ?? '');
        $conditions = $annotations->normalizeMatchConditions($conditionsRaw !== '' ? $conditionsRaw : []);
        $matchType = \trim((string)($this->request->getPost('match_type') ?? ''));
        $copyParams = (string)($this->request->getPost('copy_params') ?? '1') !== '0';
        if ($isCustom && $ok && ($conditions !== [] || $matchType !== '')) {
            $annotations->patch($websiteId, $normalized, [
                'match_type' => $matchType,
                'match_conditions' => $conditions,
                'copy_params' => $copyParams,
            ], $storageScope);
        }

        $events = $discovered->listCustomEvents($websiteId, $storageScope);
        $events = $annotations->enrichRows($websiteId, $storageScope, $events);

        return $this->jsonResponse([
            'ok' => $ok,
            'weline_event' => $normalized,
            'third_party_event' => $third !== '' ? $third : $normalized,
            'is_custom' => $isCustom,
            'in_custom_pool' => $isCustom,
            'match_type' => $matchType !== '' ? $matchType : ($annotations->get($websiteId, $normalized, $storageScope)['match_type'] ?? 'custom'),
            'match_conditions' => $conditions,
            'match_summary' => $annotations->summarizeMatchConditions($conditions),
            'website_id' => $websiteId,
            'storage_scope' => $storageScope,
            'count' => \count($events),
            'events' => $events,
            'message' => $isCustom
                ? null
                : (string)__('「%{1}」是系统字典事件，已可直接搭接；自定义池只收录非字典名。', [$normalized]),
            'error' => $ok ? null : 'add_failed',
        ], $ok ? 200 : 500);
    }

    /**
     * 发布/更新一条高级事件链（bump version，前台按 version 换新）。
     */
    #[Acl('Weline_Visitor::tracking_vendor_save', '保存事件供应商', 'save', '保存事件供应商配置')]
    public function postPublishEventChain(): string
    {
        $websiteId = $this->resolveWebsiteId();
        $raw = (string)($this->request->getPost('chain_json') ?? '');
        $chain = $raw !== '' ? \json_decode($raw, true) : null;
        if (!\is_array($chain)) {
            $chain = [
                'id' => (string)($this->request->getPost('id') ?? ''),
                'name' => (string)($this->request->getPost('name') ?? ''),
                'complete_event' => (string)($this->request->getPost('complete_event') ?? ''),
                'steps' => \json_decode((string)($this->request->getPost('steps_json') ?? '[]'), true),
            ];
        }
        /** @var EventChainService $svc */
        $svc = ObjectManager::getInstance(EventChainService::class);
        $result = $svc->upsertChain($websiteId, $chain);
        if (($result['chain'] ?? null) === null) {
            return $this->jsonResponse(['ok' => false, 'error' => 'invalid_chain'], 400);
        }

        return $this->jsonResponse([
            'ok' => true,
            'website_id' => $websiteId,
            'version' => $result['version'],
            'chain' => $result['chain'],
            'chains' => $result['chains'],
        ]);
    }

    /**
     * 确保演示跨页事件链（开发/验收用）。
     */
    #[Acl('Weline_Visitor::tracking_vendor_save', '保存事件供应商', 'save', '保存事件供应商配置')]
    public function postEnsureDemoEventChain(): string
    {
        $websiteId = $this->resolveWebsiteId();
        /** @var EventChainService $svc */
        $svc = ObjectManager::getInstance(EventChainService::class);
        $result = $svc->ensureDemoCrossPageChain($websiteId);

        return $this->jsonResponse([
            'ok' => true,
            'website_id' => $websiteId,
            'version' => $result['version'],
            'chain' => $result['chain'],
            'chains' => $result['chains'],
        ]);
    }

    /**
     * 读取当前 website 事件链 bundle。
     */
    #[Acl('Weline_Visitor::tracking_vendor_index', '查看事件供应商', 'plug', '查看事件供应商列表与配置')]
    public function getEventChains(): string
    {
        $websiteId = $this->resolveWebsiteId();
        /** @var EventChainService $svc */
        $svc = ObjectManager::getInstance(EventChainService::class);
        $bundle = $svc->getBundle($websiteId);

        return $this->jsonResponse([
            'ok' => true,
            'website_id' => $websiteId,
            'version' => $bundle['version'],
            'chains' => $bundle['chains'],
        ]);
    }

    /**
     * 解析后台工作范围（Website → Store → Channel）；供应商行仍按 website_id 落库。
     *
     * @return array{kind:string,website_code:string,store_code:string,channel_code:string,store_mode:string,storage_scope:string,identity:ScopeIdentity}
     */
    private function assignTrackingWorkScope(bool $fromPost = false): array
    {
        /** @var SystemConfigTargetScopeService $scopeSvc */
        $scopeSvc = ObjectManager::getInstance(SystemConfigTargetScopeService::class);
        $bag = $fromPost ? (array)$this->request->getPost() : (array)$this->request->getGet();
        $pick = static function (string $key) use ($fromPost, $bag): string {
            if ($fromPost) {
                return (string)($bag[$key] ?? '');
            }

            return (string)($bag[$key] ?? '');
        };

        $input = [
            'target_scope' => $pick('target_scope'),
            'scope' => $pick('scope'),
            'website_code' => $pick('website_code'),
            'store_code' => $pick('store_code'),
            'channel_code' => $pick('channel_code'),
            'scope_kind' => $pick('scope_kind'),
        ];

        $hasExplicit = \trim((string)($input['target_scope'] ?? '')) !== ''
            || \trim((string)($input['scope'] ?? '')) !== ''
            || \trim((string)($input['scope_kind'] ?? '')) !== ''
            || \array_key_exists('website_code', $bag)
            || \array_key_exists('target_website', $bag);

        // 兼容旧链：仅 website_id 时落到对应网站级范围
        if (!$hasExplicit) {
            $legacyRaw = $fromPost
                ? ($this->request->getPost('website_id') ?? null)
                : ($this->request->getGet('website_id') ?? null);
            if ($legacyRaw !== null && \is_numeric($legacyRaw) && (int)$legacyRaw >= 0) {
                $legacyId = (int)$legacyRaw;
                $websiteCode = 'default';
                try {
                    /** @var \Weline\Websites\Model\Website $websiteModel */
                    $websiteModel = ObjectManager::getInstance(\Weline\Websites\Model\Website::class);
                    $websiteModel->load($legacyId);
                    $loaded = \trim((string)$websiteModel->getCode());
                    if ($loaded !== '') {
                        $websiteCode = \strtolower($loaded);
                    }
                } catch (\Throwable) {
                    // 缺模块或未装表时仍用 default / numeric 兜底
                    if ($legacyId > 0) {
                        $websiteCode = 'default';
                    }
                }
                $input = [
                    'website_code' => $websiteCode,
                    'store_code' => '',
                    'channel_code' => '',
                    'scope_kind' => ScopeIdentity::KIND_WEBSITE,
                ];
                $hasExplicit = true;
            }
        }

        $target = $scopeSvc->resolveFromInput($input, !$hasExplicit && !$fromPost);
        $identity = $target['identity'] ?? ScopeIdentity::global();
        $kind = (string)($target['kind'] ?? $identity->scopeKind);
        $websiteId = $identity instanceof ScopeIdentity && !$identity->isGlobal()
            ? (int)$identity->websiteId
            : 0;

        $this->assign('selected_scope', (string)$target['storage_scope']);
        $this->assign('target_scope', (string)$target['storage_scope']);
        $this->assign('scope_website_code', (string)$target['website_code']);
        $this->assign('scope_store_code', (string)$target['store_code']);
        $this->assign('scope_channel_code', (string)$target['channel_code']);
        $this->assign('scope_kind', $kind);
        $this->assign('work_scope_type', $kind);
        $this->assign('work_scope_id', $websiteId);
        $this->assignInheritChainDisplay((string)$target['storage_scope'], $kind);

        try {
            $scopeSvc->rememberSession($target);
        } catch (\Throwable) {
            // 会话记忆失败不挡页
        }

        return $target;
    }

    /**
     * 配置范围继承回退链（对齐 SystemConfig::getFallbackScopes：channel←store←website←global）。
     *
     * @return void
     */
    private function assignInheritChainDisplay(string $storageScope, string $kind): void
    {
        $chain = [];
        try {
            /** @var SystemConfig $cfg */
            $cfg = ObjectManager::getInstance(SystemConfig::class);
            $chain = $cfg->getFallbackScopes($storageScope !== '' ? $storageScope : null);
        } catch (\Throwable) {
            $chain = [];
        }
        if ($chain === []) {
            $chain = match ($kind) {
                ScopeIdentity::KIND_CHANNEL => ['channel', 'store', 'website', 'global'],
                ScopeIdentity::KIND_STORE => ['store', 'website', 'global'],
                ScopeIdentity::KIND_WEBSITE => ['website', 'global'],
                default => ['global'],
            };
        }
        $this->assign('scope_inherit_chain', \array_values(\array_map('strval', $chain)));
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFromSaveRequest(): array
    {
        $eventMapRaw = (string)($this->request->getPost('event_map_json') ?? '');
        $credentialsRaw = (string)($this->request->getPost('credentials_json') ?? '');
        $scopeRaw = (string)($this->request->getPost('scope_json') ?? '');
        $eventMap = \json_decode($eventMapRaw !== '' ? $eventMapRaw : '[]', true);
        $credentials = \json_decode($credentialsRaw !== '' ? $credentialsRaw : '{}', true);
        $scope = \json_decode($scopeRaw !== '' ? $scopeRaw : '{}', true);

        return [
            'code' => (string)($this->request->getPost('code') ?? ''),
            'name' => (string)($this->request->getPost('name') ?? ''),
            'enabled' => (string)($this->request->getPost('enabled') ?? '') === '1',
            'mode' => (string)($this->request->getPost('mode') ?? PixelEventVendor::MODE_SANDBOX),
            'default_mode' => (string)($this->request->getPost('default_mode') ?? PixelEventVendor::MODE_SANDBOX),
            'use_default_map' => (string)($this->request->getPost('use_default_map') ?? '') === '1',
            'credentials' => \is_array($credentials) ? $credentials : [],
            'event_map' => \is_array($eventMap) ? $eventMap : [],
            'scope' => \is_array($scope) ? $scope : [],
            'sandbox_js' => (string)($this->request->getPost('sandbox_js') ?? ''),
            'inject_js' => (string)($this->request->getPost('inject_js') ?? ''),
            'allow_module_edit' => true,
        ];
    }

    private function normalizeUiTab(string $tab): string
    {
        $tab = \strtolower(\trim($tab));
        $allowed = ['config' => true, 'scope' => true, 'map' => true, 'js' => true];

        return isset($allowed[$tab]) ? $tab : 'config';
    }

    /**
     * @param array{identity?:ScopeIdentity} $workScope
     */
    private function websiteIdFromWorkScope(array $workScope): int
    {
        $identity = $workScope['identity'] ?? null;
        if ($identity instanceof ScopeIdentity && !$identity->isGlobal()) {
            return \max(0, (int)$identity->websiteId);
        }

        return 0;
    }

    /**
     * @param array{storage_scope?:string,website_code?:string,store_code?:string,channel_code?:string,kind?:string,identity?:ScopeIdentity} $workScope
     * @return array<string, string>
     */
    private function trackingScopeQuery(array $workScope): array
    {
        return [
            'website_id' => (string)$this->websiteIdFromWorkScope($workScope),
            'target_scope' => (string)($workScope['storage_scope'] ?? 'default.default.default'),
            'website_code' => (string)($workScope['website_code'] ?? ''),
            'store_code' => (string)($workScope['store_code'] ?? ''),
            'channel_code' => (string)($workScope['channel_code'] ?? ''),
            'scope_kind' => (string)($workScope['kind'] ?? ''),
        ];
    }

    /** 兼容其它 action 仍调旧名：从 TargetScope / website_id 解析。 */
    private function resolveWebsiteId(): int
    {
        $fromPost = \method_exists($this->request, 'isPost') && $this->request->isPost();

        return $this->websiteIdFromWorkScope($this->assignTrackingWorkScope($fromPost));
    }

    /**
     * @param list<array{name: string, label_zh: string, event_family?: string, ga4_event?: string, custom?: bool}> $rows
     * @param array<string, string> $eventMap
     * @return list<array{name: string, label_zh: string, event_family: string, ga4_event: string, custom?: bool}>
     */
    private function mergeEventMapKeysIntoMappable(array $rows, array $eventMap): array
    {
        $have = [];
        foreach ($rows as $r) {
            $n = (string)($r['name'] ?? '');
            if ($n !== '') {
                $have[$n] = true;
            }
        }
        foreach ($eventMap as $wk => $_tv) {
            $name = \trim((string)$wk);
            if ($name === '' || isset($have[$name])) {
                continue;
            }
            $rows[] = [
                'name' => $name,
                'label_zh' => $name . '（自定义）',
                'event_family' => 'custom',
                'ga4_event' => $name,
                'custom' => true,
            ];
            $have[$name] = true;
        }

        return $rows;
    }

    /**
     * @return list<array{name: string, label_zh: string, event_family: string, ga4_event: string, custom?: bool}>
     */
    private function buildMappableEventRows(int $websiteId, string $storageScope = ''): array
    {
        try {
            /** @var DiscoveredEventService $discovered */
            $discovered = ObjectManager::getInstance(DiscoveredEventService::class);
            $rows = $discovered->listMappableWithDiscovered($websiteId, $storageScope);
            if ($rows !== []) {
                return $rows;
            }
        } catch (\Throwable) {
            // fall through
        }
        try {
            /** @var EventDictionaryService $dict */
            $dict = ObjectManager::getInstance(EventDictionaryService::class);

            return $dict->listMappableEvents();
        } catch (\Throwable) {
            return [
                ['name' => 'cta_click', 'label_zh' => '通用 CTA 点击', 'event_family' => 'cta', 'ga4_event' => 'cta_click'],
                ['name' => 'lead_submit', 'label_zh' => '线索提交', 'event_family' => 'lead', 'ga4_event' => 'generate_lead'],
                ['name' => 'login', 'label_zh' => '登录成功', 'event_family' => 'account', 'ga4_event' => 'login'],
                ['name' => 'register', 'label_zh' => '注册成功', 'event_family' => 'account', 'ga4_event' => 'sign_up'],
            ];
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonResponse(array $data, int $status = 200): string
    {
        if (\method_exists($this->request, 'getResponse')) {
            try {
                $this->request->getResponse()->setHeader('Content-Type', 'application/json; charset=utf-8');
                if ($status !== 200 && \method_exists($this->request->getResponse(), 'setCode')) {
                    $this->request->getResponse()->setCode($status);
                }
            } catch (\Throwable) {
            }
        }
        $json = \json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return \is_string($json) ? $json : '{"ok":false}';
    }
}
