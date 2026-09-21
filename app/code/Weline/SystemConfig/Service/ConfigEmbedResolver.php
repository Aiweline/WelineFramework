<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Service;

use Weline\Acl\Api\Authorization\BackendObjectAuthorizationGuardInterface;
use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Model\SystemConfig;

/**
 * Resolve <w:config:embed> selection into per-field view DTOs.
 *
 * Scope: URL only (no session fallback). Undeclared keys stay in the list as red errors.
 */
final class ConfigEmbedResolver
{
    public const STATUS_OK = 'ok';
    public const STATUS_UNDECLARED = 'undeclared';
    public const STATUS_FORBIDDEN = 'forbidden';
    public const STATUS_SCOPE_DENIED = 'scope_denied';
    public const STATUS_SENSITIVE_READONLY = 'sensitive_readonly';

    public function __construct(
        private readonly SystemConfigTemplateService $templateService,
        private readonly SystemConfigCenterService $configCenterService,
        private readonly SystemConfigTargetScopeService $targetScopeService,
        private readonly BackendObjectAuthorizationGuardInterface $objectAuthorizationGuard,
        private readonly SystemConfig $systemConfig,
    ) {
    }

    /**
     * @param array<string, mixed> $attributes Taglib attributes (already resolved scalars)
     * @param array<string, mixed>|null $urlInput Override URL scope input (tests); null = current request GET
     * @return array<string, mixed>
     */
    public function resolve(array $attributes, ?array $urlInput = null): array
    {
        $module = trim((string)($attributes['module'] ?? ''));
        $area = trim((string)($attributes['area'] ?? SystemConfig::area_BACKEND));
        if ($area === '') {
            $area = SystemConfig::area_BACKEND;
        }
        $layout = strtolower(trim((string)($attributes['layout'] ?? 'vertical')));
        if (!in_array($layout, ['vertical', 'horizontal', 'inline'], true)) {
            $layout = 'vertical';
        }
        $customTemplate = trim((string)($attributes['template'] ?? ''));
        $group = trim((string)($attributes['group'] ?? ''));
        $locale = trim((string)($attributes['locale'] ?? SystemConfig::LOCALE_DEFAULT));
        if ($locale === '') {
            $locale = SystemConfig::LOCALE_DEFAULT;
        }

        if ($module === '') {
            return $this->blockError(
                (string)__('配置嵌入标签缺少 module 属性。'),
                $layout,
                $customTemplate,
            );
        }

        $urlInput ??= $this->scopeInputFromRequest();
        $forced = $this->scopeInputFromAttributes($attributes);
        if ($forced !== []) {
            // 实体页（网站/店/渠）锁定写目标：属性优先于 URL，避免误写 Global
            $urlInput = $forced;
        }
        $target = $this->targetScopeService->resolveFromInput($urlInput, allowSessionFallback: false);
        $storageScope = $this->systemConfig->normalizeScope((string)$target['storage_scope']);
        $normalizedLocale = $this->systemConfig->normalizeLocale($locale);

        $viewGrant = $this->objectAuthorizationGuard->check(ObjectAction::VIEW, $target['identity']);
        $updateGrant = $this->objectAuthorizationGuard->check(ObjectAction::UPDATE, $target['identity']);
        $expectedGrantVersion = max(
            (int)$viewGrant->matchedGrantVersion,
            (int)$updateGrant->matchedGrantVersion,
        );

        if (!$viewGrant->allowed) {
            return [
                'ok' => false,
                'error' => (string)__('无权查看该配置。请联系上级管理员授予对象 Scope 的 VIEW 权限（当前范围：%{1}）。', [$storageScope]),
                'module' => $module,
                'area' => $area,
                'layout' => $layout,
                'template' => $customTemplate,
                'target' => $this->packTarget($target),
                'storage_scope' => $storageScope,
                'locale' => $normalizedLocale,
                'can_view' => false,
                'can_update' => false,
                'expected_grant_version' => $expectedGrantVersion,
                'deny_tip' => (string)__('无权查看该配置。需要对象 Scope 动作 VIEW；当前范围 %{1}。请联系上级管理员授权。', [$storageScope]),
                'items' => [],
            ];
        }

        $definitions = $this->indexDefinitions($module, $area);
        $selection = $this->selectKeys($attributes, $definitions, $group);

        if ($selection['error'] !== null && $selection['keys'] === [] && $selection['extras'] === []) {
            return $this->blockError(
                $selection['error'],
                $layout,
                $customTemplate,
                [
                    'module' => $module,
                    'area' => $area,
                    'target' => $this->packTarget($target),
                    'storage_scope' => $storageScope,
                    'locale' => $normalizedLocale,
                    'can_view' => true,
                    'can_update' => $updateGrant->allowed,
                    'expected_grant_version' => $expectedGrantVersion,
                    'deny_tip' => $this->updateDenyTip($updateGrant->allowed, $storageScope),
                ],
            );
        }

        $items = [];
        foreach ($selection['keys'] as $key) {
            $items[] = $this->buildFieldItem(
                key: $key,
                module: $module,
                area: $area,
                storageScope: $storageScope,
                locale: $normalizedLocale,
                definitions: $definitions,
                canUpdate: $updateGrant->allowed,
                target: $target,
            );
        }
        foreach ($selection['extras'] as $extra) {
            $items[] = $extra;
        }

        return [
            'ok' => true,
            'error' => $selection['error'],
            'module' => $module,
            'area' => $area,
            'layout' => $layout,
            'template' => $customTemplate,
            'target' => $this->packTarget($target),
            'storage_scope' => $storageScope,
            'locale' => $normalizedLocale,
            'can_view' => true,
            'can_update' => $updateGrant->allowed,
            'expected_grant_version' => $expectedGrantVersion,
            'deny_tip' => $this->updateDenyTip($updateGrant->allowed, $storageScope),
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function scopeInputFromRequest(): array
    {
        /** @var Request $request */
        $request = ObjectManager::getInstance(Request::class);
        $input = [];
        $targetScope = trim((string)$request->getGet('target_scope', ''));
        $scope = trim((string)$request->getGet('scope', ''));
        if ($targetScope !== '') {
            $input['target_scope'] = $targetScope;
        } elseif ($scope !== '') {
            $input['scope'] = $scope;
        }
        foreach (['website_code', 'store_code', 'channel_code'] as $key) {
            if (method_exists($request, 'hasGet') && $request->hasGet($key)) {
                $input[$key] = (string)$request->getGet($key, '');
            } elseif ($request->getGet($key, null) !== null) {
                $input[$key] = (string)$request->getGet($key, '');
            }
        }

        return $input;
    }

    /**
     * Tag 属性强制 Scope（实体编辑页）。空属性忽略，回落到 URL。
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function scopeInputFromAttributes(array $attributes): array
    {
        $input = [];
        $targetScope = trim((string)($attributes['target_scope'] ?? ''));
        if ($targetScope !== '') {
            $input['target_scope'] = $targetScope;
        }
        foreach (['website_code', 'store_code', 'channel_code', 'scope_kind'] as $key) {
            if (!\array_key_exists($key, $attributes)) {
                continue;
            }
            $value = trim((string)($attributes[$key] ?? ''));
            // 标签编译器总会带上这些键。空值不是「明确选 Global」，否则会盖掉 target_scope。
            if ($value === '') {
                continue;
            }
            $input[$key] = $value;
        }

        return $input;
    }

    /**
     * @param array<string, array{field: array<string, mixed>, template: array<string, mixed>}> $definitions
     * @return array{keys: list<string>, extras: list<array<string, mixed>>, error: ?string}
     */
    private function selectKeys(array $attributes, array $definitions, string $group): array
    {
        $field = trim((string)($attributes['field'] ?? ''));
        $fieldsRaw = trim((string)($attributes['fields'] ?? ''));
        $keys = [];
        if ($field !== '') {
            $keys[] = $field;
        }
        if ($fieldsRaw !== '') {
            foreach (preg_split('/[,;\s]+/', $fieldsRaw) ?: [] as $part) {
                $part = trim((string)$part);
                if ($part !== '') {
                    $keys[] = $part;
                }
            }
        }
        $keys = array_values(array_unique($keys));

        if ($keys !== []) {
            return ['keys' => $keys, 'extras' => [], 'error' => null];
        }

        if ($group !== '') {
            $groupKeys = [];
            $extras = [];
            foreach ($definitions as $key => $definition) {
                $fieldDef = $definition['field'];
                if ((string)($fieldDef['group'] ?? '') === $group) {
                    $groupKeys[] = $key;
                }
            }
            foreach ($this->templateService->getTemplates(
                module: (string)($attributes['module'] ?? ''),
                area: (string)($attributes['area'] ?? SystemConfig::area_BACKEND),
            ) as $summary) {
                $meta = $this->templateService->getTemplateMeta(
                    (string)$summary['module'],
                    (string)$summary['area'],
                    (string)$summary['code'],
                );
                if ($meta === null) {
                    continue;
                }
                foreach (($meta['hints'] ?? []) as $hint) {
                    if (!is_array($hint) || (string)($hint['group'] ?? '') !== $group) {
                        continue;
                    }
                    $extras[] = [
                        'kind' => 'hint',
                        'status' => self::STATUS_OK,
                        'editable' => false,
                        'type' => (string)($hint['type'] ?? 'info'),
                        'text' => (string)($hint['text'] ?? ($hint['description'] ?? '')),
                        'label' => (string)($hint['label'] ?? ''),
                        'group' => $group,
                    ];
                }
                foreach (($meta['adapters'] ?? []) as $adapter) {
                    if (!is_array($adapter) || (string)($adapter['group'] ?? '') !== $group) {
                        continue;
                    }
                    $extras[] = [
                        'kind' => 'adapter',
                        'status' => self::STATUS_OK,
                        'editable' => false,
                        'code' => (string)($adapter['code'] ?? ''),
                        'label' => (string)($adapter['label'] ?? ($adapter['code'] ?? '')),
                        'description' => (string)($adapter['description'] ?? ''),
                        'manage_url' => (string)($adapter['manage-url'] ?? ($adapter['manage_url'] ?? '')),
                        'group' => $group,
                    ];
                }
            }

            if ($groupKeys === [] && $extras === []) {
                return [
                    'keys' => [],
                    'extras' => [],
                    'error' => (string)__('配置嵌入分组不存在或为空：%{1}', [$group]),
                ];
            }

            return ['keys' => $groupKeys, 'extras' => $extras, 'error' => null];
        }

        $allKeys = array_keys($definitions);
        if ($allKeys === []) {
            return [
                'keys' => [],
                'extras' => [],
                'error' => (string)__('模块 %{1} 在区域 %{2} 下没有已声明的配置字段。', [
                    (string)($attributes['module'] ?? ''),
                    (string)($attributes['area'] ?? SystemConfig::area_BACKEND),
                ]),
            ];
        }

        return ['keys' => $allKeys, 'extras' => [], 'error' => null];
    }

    /**
     * @return array<string, array{field: array<string, mixed>, template: array<string, mixed>}>
     */
    private function indexDefinitions(string $module, string $area): array
    {
        $definitions = [];
        foreach ($this->templateService->getTemplates(module: $module, area: $area) as $summary) {
            $meta = $this->templateService->getTemplateMeta(
                (string)$summary['module'],
                (string)$summary['area'],
                (string)$summary['code'],
            );
            if ($meta === null) {
                continue;
            }
            foreach (($meta['fields'] ?? []) as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $key = trim((string)($field['key'] ?? ''));
                if ($key === '' || isset($definitions[$key])) {
                    continue;
                }
                $definitions[$key] = [
                    'field' => $field,
                    'template' => $meta,
                ];
            }
        }

        return $definitions;
    }

    /**
     * @param array<string, array{field: array<string, mixed>, template: array<string, mixed>}> $definitions
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    private function buildFieldItem(
        string $key,
        string $module,
        string $area,
        string $storageScope,
        string $locale,
        array $definitions,
        bool $canUpdate,
        array $target,
    ): array {
        if (!isset($definitions[$key])) {
            return [
                'kind' => 'field',
                'status' => self::STATUS_UNDECLARED,
                'editable' => false,
                'key' => $key,
                'label' => $key,
                'description' => (string)__('没有这个字段：未在配置模板中声明，无法修改。'),
                'type' => '',
                'value' => null,
                'display_value' => '',
                'options' => [],
                'is_sensitive' => false,
                'base_version' => 0,
                'field_found' => false,
                'deeplink' => $this->configCenterDeeplink($module, $area, $key, $target),
            ];
        }

        $fieldObject = $this->configCenterService->getFieldObject(
            module: $module,
            area: $area,
            key: $key,
            scope: $storageScope,
            locale: $locale,
        );

        $type = strtolower((string)($fieldObject['type'] ?? 'text'));
        $isSensitive = (bool)($fieldObject['is_sensitive'] ?? false)
            || in_array($type, ['password', 'secret'], true);

        $status = self::STATUS_OK;
        $editable = true;
        $denyTip = '';

        if (!$canUpdate) {
            $status = self::STATUS_FORBIDDEN;
            $editable = false;
            $denyTip = $this->updateDenyTip(false, $storageScope);
        } elseif ($isSensitive) {
            $status = self::STATUS_SENSITIVE_READONLY;
            $editable = false;
            $denyTip = (string)__('敏感配置请在统一配置中心修改（需二次鉴权）。');
        } elseif (!$this->fieldAllowsScope((array)$definitions[$key]['field'], $storageScope)) {
            $status = self::STATUS_SCOPE_DENIED;
            $editable = false;
            $denyTip = (string)__('当前 URL 范围不允许修改此字段（字段 scope=%{1}，当前=%{2}）。', [
                (string)($definitions[$key]['field']['scope'] ?? 'global'),
                $storageScope,
            ]);
        }

        return [
            'kind' => 'field',
            'status' => $status,
            'editable' => $editable,
            'key' => $key,
            'label' => (string)($fieldObject['label'] ?? $key),
            'description' => (string)($fieldObject['description'] ?? ''),
            'type' => $type !== '' ? $type : 'text',
            'value_type' => (string)($fieldObject['value_type'] ?? 'string'),
            'value' => $fieldObject['value'] ?? null,
            'display_value' => (string)($fieldObject['display_value'] ?? ''),
            'options' => is_array($fieldObject['options'] ?? null) ? $fieldObject['options'] : [],
            'is_sensitive' => $isSensitive,
            'base_version' => (int)($fieldObject['base_version'] ?? 0),
            'field_found' => true,
            'has_override' => (bool)($fieldObject['has_override'] ?? false),
            'source' => is_array($fieldObject['source'] ?? null) ? $fieldObject['source'] : null,
            'group' => (string)($fieldObject['group'] ?? ''),
            'deny_tip' => $denyTip,
            'deeplink' => $this->configCenterDeeplink($module, $area, $key, $target),
            'template_code' => (string)(($fieldObject['template']['code'] ?? '') ?: ''),
            // 媒体选择器：透传声明 path/lock，并按当前 target 解析落盘目录
            ...$this->resolveMediaPickerMeta((array)($definitions[$key]['field'] ?? []), $target),
        ];
    }

    /**
     * @param array<string, mixed> $fieldDecl
     * @param array<string, mixed> $target
     * @return array{
     *   path?:string,
     *   path-global?:string,
     *   lock-path?:string,
     *   lock-root?:string,
     *   lock-root-global?:string,
     *   ext?:string,
     *   media_path?:string,
     *   media_lock_path?:string,
     *   media_lock_root?:string
     * }
     */
    private function resolveMediaPickerMeta(array $fieldDecl, array $target): array
    {
        $meta = [];
        foreach (['path', 'path-global', 'media-path', 'media-path-global', 'lock-path', 'lockPath', 'lock-root', 'lockRoot', 'lock-root-global', 'lockRootGlobal', 'ext'] as $attr) {
            if (!array_key_exists($attr, $fieldDecl)) {
                continue;
            }
            $meta[$attr] = (string)$fieldDecl[$attr];
        }

        $pathTpl = trim((string)($fieldDecl['path'] ?? $fieldDecl['media-path'] ?? ''));
        $pathGlobal = trim((string)($fieldDecl['path-global'] ?? $fieldDecl['media-path-global'] ?? ''));
        if ($pathTpl === '' && $pathGlobal === '') {
            return $meta;
        }

        $kind = strtolower((string)($target['kind'] ?? 'global'));
        $isGlobal = $kind === 'global'
            || (
                (string)($target['website_code'] ?? 'default') === 'default'
                && (string)($target['store_code'] ?? 'default') === 'default'
                && (string)($target['channel_code'] ?? 'default') === 'default'
            );
        $website = trim((string)($target['website_code'] ?? 'default')) ?: 'default';
        $store = trim((string)($target['store_code'] ?? 'default')) ?: 'default';
        $channel = trim((string)($target['channel_code'] ?? 'default')) ?: 'default';
        if ($store === '') {
            $store = 'default';
        }
        if ($channel === '') {
            $channel = 'default';
        }

        $usingGlobal = false;
        if ($isGlobal && $pathGlobal !== '') {
            $mediaPath = $pathGlobal;
            $usingGlobal = true;
        } elseif ($pathTpl !== '') {
            $mediaPath = strtr($pathTpl, [
                '{website}' => $isGlobal ? 'default' : $website,
                '{store}' => $isGlobal ? 'default' : $store,
                '{channel}' => $isGlobal ? 'default' : $channel,
            ]);
        } else {
            $mediaPath = $pathGlobal;
            $usingGlobal = true;
        }
        $mediaPath = trim((string)$mediaPath, '/');

        $lockPathRaw = $fieldDecl['lock-path'] ?? $fieldDecl['lockPath'] ?? '1';
        $lockPath = in_array(strtolower((string)$lockPathRaw), ['0', 'false', 'off', 'no'], true) ? '0' : '1';
        $lockRoot = '';
        if ($usingGlobal) {
            $lockRootGlobal = trim((string)($fieldDecl['lock-root-global'] ?? $fieldDecl['lockRootGlobal'] ?? ''));
            $lockRoot = $lockRootGlobal !== '' ? trim($lockRootGlobal, '/') : (str_starts_with($mediaPath, 'mail/') ? 'mail' : '');
        } else {
            $lockRootTpl = trim((string)($fieldDecl['lock-root'] ?? $fieldDecl['lockRoot'] ?? ''));
            if ($lockRootTpl !== '') {
                $lockRoot = trim(strtr($lockRootTpl, [
                    '{website}' => $website,
                    '{store}' => $store,
                    '{channel}' => $channel,
                ]), '/');
            }
        }

        $meta['media_path'] = $mediaPath;
        $meta['media_lock_path'] = $lockPath;
        if ($lockRoot !== '') {
            $meta['media_lock_root'] = $lockRoot;
        }

        // MediaReferenceIdentity.v1 — config media fields require identity meta
        $scope = strtolower($website . '.' . $store . '.' . $channel);
        $moduleNs = trim((string)($fieldDecl['module'] ?? $target['module'] ?? ''));
        $configKey = trim((string)($fieldDecl['key'] ?? $fieldDecl['code'] ?? ''));
        if ($configKey === '') {
            $configKey = trim((string)($meta['path'] ?? $mediaPath));
            $configKey = (string)(preg_replace('#[^a-zA-Z0-9._-]+#', '_', $configKey) ?: 'config_media');
        }
        $meta['identity_root'] = 'config';
        $meta['identity_code'] = $configKey;
        $meta['identity_scope'] = $scope;
        $meta['identity_kind'] = 'media';
        $meta['identity_field'] = $configKey;
        if ($moduleNs !== '') {
            $meta['identity_ns'] = $moduleNs;
        }
        $meta['ref_mode'] = 'single';
        $meta['strong_ref'] = '1';

        return $meta;
    }

    /**
     * @param array<string, mixed> $field
     */
    private function fieldAllowsScope(array $field, string $storageScope): bool
    {
        $allowedRaw = strtolower((string)($field['scope'] ?? 'global,website,store'));
        $allowed = array_values(array_filter(array_map('trim', explode(',', $allowedRaw))));
        if ($allowed === []) {
            $allowed = ['global'];
        }
        $level = $this->scopeLevel($storageScope);

        return in_array($level, $allowed, true);
    }

    private function scopeLevel(string $scope): string
    {
        $normalized = $this->systemConfig->normalizeScope($scope);
        [$website, $store, $extra] = explode('.', $normalized) + ['default', 'default', 'default'];
        if ($website === 'default' && $store === 'default' && $extra === 'default') {
            return 'global';
        }
        if ($store === 'default' && $extra === 'default') {
            return 'website';
        }

        return 'store';
    }

    /**
     * @param array<string, mixed> $target
     */
    private function configCenterDeeplink(string $module, string $area, string $key, array $target): string
    {
        try {
            /** @var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            $query = [
                'module' => $module,
                'area' => $area,
                'guide_key' => $key,
                'guide_locate' => $key,
                'target_scope' => (string)($target['storage_scope'] ?? SystemConfig::SCOPE_GLOBAL),
            ];
            foreach (['website_code', 'store_code', 'channel_code'] as $seg) {
                $value = trim((string)($target[$seg] ?? ''));
                if ($value !== '') {
                    $query[$seg] = $value;
                }
            }

            return (string)$request->getUrlBuilder()->getBackendUrl('weline_systemconfig/backend/config', $query);
        } catch (\Throwable) {
            return '';
        }
    }

    private function updateDenyTip(bool $canUpdate, string $storageScope): string
    {
        if ($canUpdate) {
            return '';
        }

        return (string)__('无权修改该配置。需要对象 Scope 动作 UPDATE；当前范围 %{1}。请联系上级管理员授权。', [$storageScope]);
    }

    /**
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    private function packTarget(array $target): array
    {
        return [
            'kind' => (string)($target['kind'] ?? 'global'),
            'website_code' => (string)($target['website_code'] ?? ''),
            'store_code' => (string)($target['store_code'] ?? ''),
            'channel_code' => (string)($target['channel_code'] ?? ''),
            'storage_scope' => (string)($target['storage_scope'] ?? SystemConfig::SCOPE_GLOBAL),
        ];
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function blockError(string $message, string $layout, string $template, array $extra = []): array
    {
        return array_merge([
            'ok' => false,
            'error' => $message,
            'module' => '',
            'area' => '',
            'layout' => $layout,
            'template' => $template,
            'target' => [
                'kind' => 'global',
                'website_code' => '',
                'store_code' => '',
                'channel_code' => '',
                'storage_scope' => SystemConfig::SCOPE_GLOBAL,
            ],
            'storage_scope' => SystemConfig::SCOPE_GLOBAL,
            'locale' => SystemConfig::LOCALE_DEFAULT,
            'can_view' => false,
            'can_update' => false,
            'expected_grant_version' => 0,
            'deny_tip' => '',
            'items' => [],
        ], $extra);
    }
}
