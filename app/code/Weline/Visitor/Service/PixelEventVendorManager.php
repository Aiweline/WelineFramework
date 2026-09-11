<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Api\ConfigReader as SystemConfig;
use Weline\Visitor\Interface\PixelEventVendorInterface;
use Weline\Visitor\Model\PixelEventVendor;

class PixelEventVendorManager
{
    public function __construct(
        private readonly PixelEventVendorScanner $scanner,
        private readonly ObjectManager $objectManager,
        private readonly ?SystemConfig $systemConfig = null,
        private readonly ?EventDictionaryService $eventDictionary = null,
    ) {
    }

    /**
     * Sync module providers into DB for website; preserve custom rows and user overrides.
     *
     * @return array{synced:int,created:int,updated:int}
     */
    public function syncModuleProviders(int $websiteId = 0, bool $forceReload = true): array
    {
        $created = 0;
        $updated = 0;
        foreach ($this->scanner->getProviderInstances($forceReload) as $provider) {
            $code = \trim($provider->getCode());
            if ($code === '') {
                continue;
            }
            $row = $this->findByWebsiteCode($websiteId, $code);
            $defaultMap = $this->filterMappableMap($provider->getDefaultEventMap());
            $schema = $provider->getConfigSchema();
            $credentials = $this->seedCredentialsFromLegacy($code, $websiteId, $provider);

            if ($row === null) {
                $row = $this->newModel();
                $row->setData(PixelEventVendor::schema_fields_WEBSITE_ID, $websiteId);
                $row->setData(PixelEventVendor::schema_fields_CODE, $code);
                $row->setData(PixelEventVendor::schema_fields_NAME, $provider->getDisplayName());
                $row->setData(PixelEventVendor::schema_fields_SOURCE, PixelEventVendor::SOURCE_MODULE);
                $row->setData(PixelEventVendor::schema_fields_PROVIDER_MODULE, 'Weline_Visitor');
                $row->setData(PixelEventVendor::schema_fields_PROVIDER_CLASS, $provider::class);
                $row->setData(PixelEventVendor::schema_fields_ENABLED, $this->legacyEnabledSeed($code, $websiteId) ? 1 : 0);
                $row->setData(PixelEventVendor::schema_fields_MODE, $provider->getDefaultMode() ?: PixelEventVendor::MODE_SANDBOX);
                $row->setData(PixelEventVendor::schema_fields_DEFAULT_MODE, $provider->getDefaultMode() ?: PixelEventVendor::MODE_SANDBOX);
                $row->setData(PixelEventVendor::schema_fields_USE_DEFAULT_MAP, 1);
                $row->setCredentials($credentials);
                $row->setEventMap($defaultMap);
                $row->setScope(PixelEventVendorScope::defaultScope());
                $row->setData(PixelEventVendor::schema_fields_SANDBOX_JS, $this->defaultSandboxJs($code));
                $row->setData(PixelEventVendor::schema_fields_INJECT_JS, '');
                $row->setData(PixelEventVendor::schema_fields_CONFIG_SCHEMA_JSON, \json_encode($schema, JSON_UNESCAPED_UNICODE));
                $row->save();
                $created++;
            } else {
                // Refresh class/module/schema/name; do not clobber user credentials/map/mode unless empty.
                $row->setData(PixelEventVendor::schema_fields_NAME, $provider->getDisplayName());
                $row->setData(PixelEventVendor::schema_fields_SOURCE, PixelEventVendor::SOURCE_MODULE);
                $row->setData(PixelEventVendor::schema_fields_PROVIDER_CLASS, $provider::class);
                $row->setData(PixelEventVendor::schema_fields_CONFIG_SCHEMA_JSON, \json_encode($schema, JSON_UNESCAPED_UNICODE));
                if ($this->isSystemVendorCode($code)) {
                    $row->setData(PixelEventVendor::schema_fields_ENABLED, 1);
                }
                if ($row->getCredentials() === [] && $credentials !== []) {
                    $row->setCredentials($credentials);
                }
                if ($row->getEventMap() === []) {
                    $row->setEventMap($defaultMap);
                } elseif ((int)$row->getData(PixelEventVendor::schema_fields_USE_DEFAULT_MAP) === 1) {
                    $current = $this->filterMappableMap($this->remapLegacyEventKeys($row->getEventMap()));
                    $extra = \array_diff_key($current, $defaultMap);
                    if ($extra !== []) {
                        // 误标「使用默认」却含自定义键：保留并关闭默认，避免刷新丢搭接
                        $row->setData(PixelEventVendor::schema_fields_USE_DEFAULT_MAP, 0);
                        $row->setEventMap($current);
                    } else {
                        // 使用默认 map：刷新为字典对齐后的 Vendor 默认（修复 login_success 漂移）
                        $row->setEventMap($defaultMap);
                    }
                } else {
                    $row->setEventMap($this->filterMappableMap($this->remapLegacyEventKeys($row->getEventMap())));
                }
                if ((string)$row->getData(PixelEventVendor::schema_fields_SCOPE_JSON) === '') {
                    $row->setScope(PixelEventVendorScope::defaultScope());
                }
                if ((string)$row->getData(PixelEventVendor::schema_fields_SANDBOX_JS) === '') {
                    $row->setData(PixelEventVendor::schema_fields_SANDBOX_JS, $this->defaultSandboxJs($code));
                }
                $row->save();
                $updated++;
            }
        }

        return [
            'synced' => $created + $updated,
            'created' => $created,
            'updated' => $updated,
        ];
    }

    /**
     * @return list<PixelEventVendor>
     */
    public function listForWebsite(int $websiteId = 0): array
    {
        /** @var PixelEventVendor $model */
        $model = $this->newModel();
        $model->reset()
            ->where(PixelEventVendor::schema_fields_WEBSITE_ID, $websiteId)
            ->order(PixelEventVendor::schema_fields_SOURCE, 'ASC')
            ->order(PixelEventVendor::schema_fields_CODE, 'ASC')
            ->select()
            ->fetch();

        /** @var list<PixelEventVendor> $items */
        $items = $model->getItems() ?: [];
        \usort($items, static function (PixelEventVendor $a, PixelEventVendor $b): int {
            $ac = (string)$a->getData(PixelEventVendor::schema_fields_CODE);
            $bc = (string)$b->getData(PixelEventVendor::schema_fields_CODE);
            if ($ac === PixelEventVendor::CODE_SYSTEM && $bc !== PixelEventVendor::CODE_SYSTEM) {
                return -1;
            }
            if ($bc === PixelEventVendor::CODE_SYSTEM && $ac !== PixelEventVendor::CODE_SYSTEM) {
                return 1;
            }
            $as = (string)$a->getData(PixelEventVendor::schema_fields_SOURCE);
            $bs = (string)$b->getData(PixelEventVendor::schema_fields_SOURCE);
            if ($as !== $bs) {
                return $as <=> $bs;
            }

            return $ac <=> $bc;
        });

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRuntimeVendors(int $websiteId = 0): array
    {
        $rows = $this->listForWebsite($websiteId);
        if ($rows === []) {
            try {
                $this->syncModuleProviders($websiteId, false);
                $rows = $this->listForWebsite($websiteId);
            } catch (\Throwable) {
                $rows = [];
            }
        }

        $vendors = [];
        foreach ($rows as $row) {
            $vendors[] = $row->toRuntimeVendor();
        }

        return $this->applyDualInjectGuard($vendors);
    }

    public function findByWebsiteCode(int $websiteId, string $code): ?PixelEventVendor
    {
        /** @var PixelEventVendor $model */
        $model = $this->newModel();
        $model->reset()
            ->where(PixelEventVendor::schema_fields_WEBSITE_ID, $websiteId)
            ->where(PixelEventVendor::schema_fields_CODE, $code)
            ->find()
            ->fetch();
        $id = (int)$model->getData(PixelEventVendor::schema_fields_ID);

        return $id > 0 ? $model : null;
    }

    public function findById(int $id): ?PixelEventVendor
    {
        if ($id <= 0) {
            return null;
        }
        /** @var PixelEventVendor $model */
        $model = $this->newModel();
        $model->load($id);
        $loaded = (int)$model->getData(PixelEventVendor::schema_fields_ID);

        return $loaded > 0 ? $model : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function saveCustom(int $websiteId, array $payload, ?int $id = null): PixelEventVendor
    {
        $code = \trim((string)($payload['code'] ?? ''));
        if ($code === '') {
            throw new \InvalidArgumentException((string)__('供应商代码不能为空'));
        }
        if (!\preg_match('/^[a-z][a-z0-9_]{1,62}$/', $code)) {
            throw new \InvalidArgumentException((string)__('供应商代码须为小写字母开头的 snake_case'));
        }

        $row = $id ? $this->findById($id) : $this->findByWebsiteCode($websiteId, $code);
        if ($row === null) {
            $row = $this->newModel();
            $row->setData(PixelEventVendor::schema_fields_WEBSITE_ID, $websiteId);
            $row->setData(PixelEventVendor::schema_fields_CODE, $code);
            $row->setData(PixelEventVendor::schema_fields_SOURCE, PixelEventVendor::SOURCE_CUSTOM);
        } elseif ((string)$row->getData(PixelEventVendor::schema_fields_SOURCE) === PixelEventVendor::SOURCE_MODULE
            && empty($payload['allow_module_edit'])) {
            // module rows still editable for credentials/mode/map/js
        }

        $name = \trim((string)($payload['name'] ?? $row->getData(PixelEventVendor::schema_fields_NAME) ?? $code));
        $mode = $this->normalizeMode((string)($payload['mode'] ?? $row->getData(PixelEventVendor::schema_fields_MODE) ?? PixelEventVendor::MODE_SANDBOX));
        $defaultMode = $this->normalizeMode((string)($payload['default_mode'] ?? $row->getData(PixelEventVendor::schema_fields_DEFAULT_MODE) ?? PixelEventVendor::MODE_SANDBOX));
        $enabled = !empty($payload['enabled']) ? 1 : 0;
        if ($this->isSystemVendorCode($code)) {
            $enabled = 1;
        }

        $row->setData(PixelEventVendor::schema_fields_NAME, $name !== '' ? $name : $code);
        $row->setData(PixelEventVendor::schema_fields_ENABLED, $enabled);
        $row->setData(PixelEventVendor::schema_fields_MODE, $mode);
        $row->setData(PixelEventVendor::schema_fields_DEFAULT_MODE, $defaultMode);

        if (isset($payload['credentials']) && \is_array($payload['credentials'])) {
            $row->setCredentials($payload['credentials']);
        }

        $requestedDefaultMap = !empty($payload['use_default_map']);
        $useDefaultMap = $requestedDefaultMap;
        if ($requestedDefaultMap) {
            // 「使用默认 map」：以供应商默认覆盖表单行，避免勾选默认却仍带自定义行
            $defaultMap = $this->providerDefaultEventMap($code);
            if ($defaultMap !== null) {
                $row->setEventMap($defaultMap);
                $useDefaultMap = true;
            } elseif (isset($payload['event_map']) && \is_array($payload['event_map'])) {
                // 无模块默认（自定义供应商）：仍写入提交 map，但不靠 sync 覆盖
                $map = [];
                foreach ($payload['event_map'] as $k => $v) {
                    if (\is_array($v)) {
                        $wk = \trim((string)($v['weline_event'] ?? ''));
                        $tv = \trim((string)($v['third_party_event'] ?? ''));
                        if ($wk !== '' && $tv !== '') {
                            $map[$wk] = $tv;
                        }
                    } else {
                        $wk = \trim((string)$k);
                        $tv = \trim((string)$v);
                        if ($wk !== '' && $tv !== '') {
                            $map[$wk] = $tv;
                        }
                    }
                }
                $row->setEventMap($this->filterMappableMap($map));
                $useDefaultMap = true;
            }
        } elseif (isset($payload['event_map']) && \is_array($payload['event_map'])) {
            $map = [];
            foreach ($payload['event_map'] as $k => $v) {
                if (\is_array($v)) {
                    $wk = \trim((string)($v['weline_event'] ?? ''));
                    $tv = \trim((string)($v['third_party_event'] ?? ''));
                    if ($wk !== '' && $tv !== '') {
                        $map[$wk] = $tv;
                    }
                } else {
                    $wk = \trim((string)$k);
                    $tv = \trim((string)$v);
                    if ($wk !== '' && $tv !== '') {
                        $map[$wk] = $tv;
                    }
                }
            }
            $row->setEventMap($this->filterMappableMap($map));
            // 自定义搭接门禁：写入非默认 map 必须关闭 use_default_map，否则 index 同步会覆盖丢失
            $useDefaultMap = false;
        }
        $row->setData(PixelEventVendor::schema_fields_USE_DEFAULT_MAP, $useDefaultMap ? 1 : 0);
        if (\array_key_exists('sandbox_js', $payload)) {
            $row->setData(PixelEventVendor::schema_fields_SANDBOX_JS, (string)$payload['sandbox_js']);
        }
        if (\array_key_exists('inject_js', $payload)) {
            $row->setData(PixelEventVendor::schema_fields_INJECT_JS, (string)$payload['inject_js']);
        }
        if (isset($payload['scope']) && \is_array($payload['scope'])) {
            $row->setScope($payload['scope']);
        } elseif ((string)$row->getData(PixelEventVendor::schema_fields_SCOPE_JSON) === '') {
            $row->setScope(PixelEventVendorScope::defaultScope());
        }

        if ((string)$row->getData(PixelEventVendor::schema_fields_SOURCE) === PixelEventVendor::SOURCE_CUSTOM) {
            $row->setData(PixelEventVendor::schema_fields_PROVIDER_MODULE, '');
            $row->setData(PixelEventVendor::schema_fields_PROVIDER_CLASS, '');
        }

        $this->assertNoDualInject($websiteId, $row);
        $row->save();

        return $row;
    }

    public function deleteCustom(int $id): bool
    {
        $row = $this->findById($id);
        if ($row === null) {
            return false;
        }
        if ((string)$row->getData(PixelEventVendor::schema_fields_SOURCE) !== PixelEventVendor::SOURCE_CUSTOM) {
            throw new \InvalidArgumentException((string)__('模块供应商不可删除，只能停用'));
        }
        $row->delete();

        return true;
    }

    /**
     * 模块供应商默认 event_map；无对应 provider 时返回 null。
     *
     * @return array<string, string>|null
     */
    public function providerDefaultEventMap(string $code): ?array
    {
        $code = \trim($code);
        if ($code === '') {
            return null;
        }
        try {
            foreach ($this->scanner->getProviderInstances(false) as $provider) {
                if (\trim($provider->getCode()) === $code) {
                    return $this->filterMappableMap($provider->getDefaultEventMap());
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @param array<string, string> $map
     * @return array<string, string>
     */
    public function filterMappableMap(array $map): array
    {
        $skip = ['page_view' => true, 'page_enter' => true];
        try {
            $dict = $this->eventDictionary()?->toRuntimeFragment() ?? [];
            foreach ((array)($dict['events'] ?? []) as $entry) {
                if (!\is_array($entry)) {
                    continue;
                }
                if (!empty($entry['skip_gtm_push'])) {
                    $skip[(string)($entry['weline_event'] ?? '')] = true;
                }
            }
        } catch (\Throwable) {
            // keep baseline skip
        }

        $normalize = static function (string $name): string {
            $name = \strtolower(\trim($name));
            $name = \str_replace('-', '_', $name);
            $name = (string)\preg_replace('/[^a-z0-9_]/', '', $name);

            return \substr($name, 0, 64);
        };
        try {
            $svc = $this->eventDictionary();
            if ($svc !== null) {
                $normalize = static fn(string $name): string => $svc->normalizeEventName($name);
            }
        } catch (\Throwable) {
            // reflection/uninitialized ctor in unit tests
        }
        $out = [];
        foreach ($map as $k => $v) {
            $wk = $normalize((string)$k);
            $tv = \trim((string)$v);
            if ($wk === '' || $tv === '' || isset($skip[$wk])) {
                continue;
            }
            $out[$wk] = $tv;
        }

        return $this->remapLegacyEventKeys($out);
    }

    /**
     * 历史漂移：login_success/register_success → login/register。
     *
     * @param array<string, string> $map
     * @return array<string, string>
     */
    public function remapLegacyEventKeys(array $map): array
    {
        $aliases = [
            'login_success' => 'login',
            'register_success' => 'register',
        ];
        $out = [];
        foreach ($map as $k => $v) {
            $wk = (string)$k;
            if (isset($aliases[$wk])) {
                $wk = $aliases[$wk];
            }
            if ($wk === '' || $v === '') {
                continue;
            }
            if (!isset($out[$wk])) {
                $out[$wk] = $v;
            }
        }

        return $out;
    }

    private function assertNoDualInject(int $websiteId, PixelEventVendor $saving): void
    {
        $code = (string)$saving->getData(PixelEventVendor::schema_fields_CODE);
        $mode = (string)$saving->getData(PixelEventVendor::schema_fields_MODE);
        $enabled = (int)$saving->getData(PixelEventVendor::schema_fields_ENABLED) === 1;
        if (!$enabled || $mode !== PixelEventVendor::MODE_INJECT) {
            return;
        }
        if ($code !== 'ga4' && $code !== 'gtm') {
            return;
        }
        $other = $code === 'ga4' ? 'gtm' : 'ga4';
        $peer = $this->findByWebsiteCode($websiteId, $other);
        if ($peer === null) {
            return;
        }
        if ((int)$peer->getData(PixelEventVendor::schema_fields_ENABLED) === 1
            && (string)$peer->getData(PixelEventVendor::schema_fields_MODE) === PixelEventVendor::MODE_INJECT) {
            throw new \InvalidArgumentException((string)__('双 inject 硬拦：ga4 与 gtm 不可同时 inject'));
        }
    }

    /**
     * @param list<array<string, mixed>> $vendors
     * @return list<array<string, mixed>>
     */
    private function applyDualInjectGuard(array $vendors): array
    {
        $injectCodes = [];
        foreach ($vendors as $v) {
            if (!empty($v['enabled']) && ($v['mode'] ?? '') === PixelEventVendor::MODE_INJECT
                && \in_array($v['code'] ?? '', ['ga4', 'gtm'], true)) {
                $injectCodes[] = (string)$v['code'];
            }
        }
        if (\count($injectCodes) < 2) {
            return $vendors;
        }
        // Prefer GTM inject, demote GA4 to sandbox at runtime.
        foreach ($vendors as &$v) {
            if (($v['code'] ?? '') === 'ga4' && ($v['mode'] ?? '') === PixelEventVendor::MODE_INJECT) {
                $v['mode'] = PixelEventVendor::MODE_SANDBOX;
                $v['dual_inject_demoted'] = true;
            }
        }
        unset($v);

        return $vendors;
    }

    /**
     * @return array<string, mixed>
     */
    private function seedCredentialsFromLegacy(string $code, int $websiteId, PixelEventVendorInterface $provider): array
    {
        $schema = $provider->getConfigSchema();
        $credentials = [];
        foreach ($schema as $key => $meta) {
            $credentials[(string)$key] = (string)($meta['default'] ?? '');
        }
        $cfg = $this->systemConfig;
        if (!$cfg) {
            return $credentials;
        }
        $scope = 'website.' . $websiteId;
        try {
            if ($code === 'ga4') {
                $id = (string)$cfg->get(
                    'visitor/tracking/ga4_measurement_id',
                    'Weline_Visitor',
                    SystemConfig::area_BACKEND,
                    '',
                    $scope
                );
                if ($id !== '') {
                    $credentials['measurement_id'] = \strtoupper(\trim($id));
                }
            }
            if ($code === 'gtm') {
                $id = (string)$cfg->get(
                    'visitor/tracking/gtm_container_id',
                    'Weline_Visitor',
                    SystemConfig::area_BACKEND,
                    '',
                    $scope
                );
                if ($id !== '') {
                    $credentials['container_id'] = \strtoupper(\trim($id));
                }
            }
        } catch (\Throwable) {
            // ignore
        }

        return $credentials;
    }

    private function isSystemVendorCode(string $code): bool
    {
        return $code === PixelEventVendor::CODE_SYSTEM || $code === 'weline';
    }

    private function legacyEnabledSeed(string $code, int $websiteId): bool
    {
        // 系统像素：站内一等公民，默认启用且不可关闭
        if ($this->isSystemVendorCode($code)) {
            return true;
        }
        $cfg = $this->systemConfig;
        if (!$cfg) {
            return false;
        }
        $scope = 'website.' . $websiteId;
        try {
            if ($code === 'ga4') {
                return (bool)$cfg->get(
                    'visitor/tracking/ga4_enabled',
                    'Weline_Visitor',
                    SystemConfig::area_BACKEND,
                    false,
                    $scope
                );
            }
            if ($code === 'gtm') {
                return (bool)$cfg->get(
                    'visitor/tracking/gtm_enabled',
                    'Weline_Visitor',
                    SystemConfig::area_BACKEND,
                    false,
                    $scope
                );
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }


    private function defaultSandboxJs(string $code): string
    {
        if ($code === PixelEventVendor::CODE_SYSTEM || $code === 'weline') {
            return "// Weline system pixel sandbox (storefront event page)\nwindow.addEventListener('message', function (e) {\n  if (!e.data || e.data.channel !== 'weline-pixel-sandbox/v1') return;\n  // customize first-party handling; Pixel still persists server-side\n});\n";
        }
        if ($code === 'ga4') {
            return "// GA4 sandbox bridge (dry_run in DEV)\nwindow.addEventListener('message', function (e) {\n  if (!e.data || e.data.channel !== 'weline-pixel-sandbox/v1') return;\n  // map envelope.ga4_event then gtag in live; log in dry_run\n});\n";
        }
        if ($code === 'gtm') {
            return "// GTM sandbox bridge\nwindow.dataLayer = window.dataLayer || [];\nwindow.addEventListener('message', function (e) {\n  if (!e.data || e.data.channel !== 'weline-pixel-sandbox/v1') return;\n  // push mapped event into isolated dataLayer\n});\n";
        }

        return "// custom vendor sandbox handler\nwindow.addEventListener('message', function (e) {\n  if (!e.data || e.data.channel !== 'weline-pixel-sandbox/v1') return;\n});\n";
    }

    private function normalizeMode(string $mode): string
    {
        return $mode === PixelEventVendor::MODE_INJECT
            ? PixelEventVendor::MODE_INJECT
            : PixelEventVendor::MODE_SANDBOX;
    }

    private function newModel(): PixelEventVendor
    {
        /** @var PixelEventVendor $model */
        $model = $this->objectManager->getInstance(PixelEventVendor::class);

        return $model;
    }

    private function eventDictionary(): ?EventDictionaryService
    {
        return $this->eventDictionary;
    }
}
