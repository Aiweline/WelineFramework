<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Service;

use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface;
use Weline\SystemConfig\Api\Scope\ScopeSelectorCatalogInterface;

/** Builds the canonical Global → Website → Store → Channel selector model. */
final class ScopeSelectorCatalog implements ScopeSelectorCatalogInterface
{
    public function __construct(
        private readonly ScopeHierarchyInterface $scopes,
        private readonly ScopeIdentityCatalogInterface $catalog,
    ) {
    }

    public function build(string $selectedScope, ?array $catalogOptions = null, array $claims = []): array
    {
        $catalogOptions ??= $this->catalog->options();
        $identity = $claims !== []
            ? $this->identityFromClaims($claims)
            : $this->identityFromStorage($selectedScope, $catalogOptions);
        $legacyReadonly = false;
        if (!$identity instanceof ScopeIdentity) {
            $legacyReadonly = \trim($selectedScope) !== '';
            $identity = ScopeIdentity::global();
        }

        $context = $this->scopes->contextFromIdentity($identity);
        $websiteOptions = [];
        $storeOptions = [];
        $channelOptions = [];
        $globalLabel = (string)__('Global（全部网站、店铺和渠道）');
        $flat = [[
            'value' => $this->scopes->toStorageScope(ScopeIdentity::global()),
            'label' => $globalLabel,
            'kind' => ScopeIdentity::KIND_GLOBAL,
        ]];
        $tree = [[
            'value' => $flat[0]['value'],
            'label' => $globalLabel,
            'display_label' => $globalLabel,
            'kind' => ScopeIdentity::KIND_GLOBAL,
            'children' => [],
        ]];

        foreach ($catalogOptions as $website) {
            if (!\is_array($website)) {
                continue;
            }
            $websiteCode = \strtolower(\trim((string)($website['code'] ?? '')));
            $websiteId = (int)($website['website_id'] ?? 0);
            if ($websiteCode === '' || $websiteId < 0) {
                continue;
            }
            try {
                $websiteIdentity = ScopeIdentity::website($websiteId, $websiteCode);
            } catch (\Throwable) {
                continue;
            }
            $websiteName = \trim((string)($website['name'] ?? $websiteCode)) ?: $websiteCode;
            $websiteScope = $this->scopes->toStorageScope($websiteIdentity);
            $websiteLabel = (string)__('网站：%{1}', [$websiteName]);
            $websiteOptions[] = [
                'value' => $websiteCode,
                'label' => $websiteName,
                'meta' => $websiteCode,
                'website_id' => $websiteId,
            ];
            $flat[] = ['value' => $websiteScope, 'label' => $websiteLabel, 'kind' => ScopeIdentity::KIND_WEBSITE];
            $websiteNode = [
                'value' => $websiteScope,
                'label' => $websiteLabel,
                'display_label' => $websiteLabel,
                'kind' => ScopeIdentity::KIND_WEBSITE,
                'children' => [],
            ];

            foreach ((array)($website['stores'] ?? []) as $store) {
                if (!\is_array($store)) {
                    continue;
                }
                $storeCode = \strtolower(\trim((string)($store['code'] ?? '')));
                $storeMode = \strtolower(\trim((string)($store['store_mode'] ?? ScopeIdentity::MODE_NORMAL)));
                $storeId = (int)($store['id'] ?? 0);
                if ($storeCode === '') {
                    continue;
                }
                try {
                    $storeIdentity = ScopeIdentity::store($websiteId, $websiteCode, $storeCode, $storeMode);
                } catch (\Throwable) {
                    continue;
                }
                $storeName = \trim((string)($store['name'] ?? $storeCode)) ?: $storeCode;
                $storeScope = $this->scopes->toStorageScope($storeIdentity);
                $storeLabel = (string)__('店铺：%{1}', [$storeName]);
                $storeDisplay = (string)__('店铺：%{1} / %{2}', [$websiteName, $storeName]);
                if ($identity->websiteCode === $websiteCode) {
                    $storeOptions[] = [
                        'value' => $storeCode,
                        'label' => $storeName,
                        'meta' => $storeCode,
                        'store_id' => $storeId,
                        'store_mode' => $storeMode,
                    ];
                }
                $flat[] = ['value' => $storeScope, 'label' => $storeDisplay, 'kind' => ScopeIdentity::KIND_STORE];
                $storeNode = [
                    'value' => $storeScope,
                    'label' => $storeLabel,
                    'display_label' => $storeDisplay,
                    'kind' => ScopeIdentity::KIND_STORE,
                    'children' => [],
                ];

                foreach ((array)($store['channels'] ?? []) as $channel) {
                    if (!\is_array($channel)) {
                        continue;
                    }
                    $channelCode = \strtolower(\trim((string)($channel['code'] ?? '')));
                    if ($channelCode === '') {
                        continue;
                    }
                    try {
                        $channelIdentity = ScopeIdentity::channel(
                            $websiteId,
                            $websiteCode,
                            $storeCode,
                            $channelCode,
                            $storeMode,
                        );
                    } catch (\Throwable) {
                        continue;
                    }
                    $channelName = \trim((string)($channel['name'] ?? $channelCode)) ?: $channelCode;
                    $channelScope = $this->scopes->toStorageScope($channelIdentity);
                    $channelLabel = (string)__('渠道：%{1}', [$channelName]);
                    $channelDisplay = (string)__('渠道：%{1} / %{2} / %{3}', [
                        $websiteName,
                        $storeName,
                        $channelName,
                    ]);
                    if ($identity->websiteCode === $websiteCode && $identity->storeCode === $storeCode) {
                        $channelOptions[] = [
                            'value' => $channelCode,
                            'label' => $channelName,
                            'meta' => $channelCode,
                            'channel_id' => (int)($channel['id'] ?? 0),
                        ];
                    }
                    $flat[] = ['value' => $channelScope, 'label' => $channelDisplay, 'kind' => ScopeIdentity::KIND_CHANNEL];
                    $storeNode['children'][] = [
                        'value' => $channelScope,
                        'label' => $channelLabel,
                        'display_label' => $channelDisplay,
                        'kind' => ScopeIdentity::KIND_CHANNEL,
                        'children' => [],
                    ];
                }
                $websiteNode['children'][] = $storeNode;
            }
            $tree[] = $websiteNode;
        }

        $selectedLabel = $context->storageScope;
        foreach ($flat as $option) {
            if ((string)($option['value'] ?? '') === $context->storageScope) {
                $selectedLabel = (string)($option['label'] ?? $selectedLabel);
                break;
            }
        }

        return [
            'selected_scope' => $context->storageScope,
            'selected_label' => $selectedLabel,
            'selected_identity' => $identity->toArray(),
            'selected_kind' => $identity->scopeKind,
            'selected_website_code' => (string)$identity->websiteCode,
            'selected_store_code' => (string)$identity->storeCode,
            'selected_channel_code' => (string)$identity->channelCode,
            'selected_store_mode' => (string)($identity->storeMode ?? ScopeIdentity::MODE_NORMAL),
            'website_options' => $websiteOptions,
            'store_options' => $storeOptions,
            'channel_options' => $channelOptions,
            'catalog_options' => $catalogOptions,
            'options' => $flat,
            'tree_options' => $tree,
            'legacy_readonly' => $legacyReadonly,
            'legacy_scope' => $legacyReadonly ? $selectedScope : null,
        ];
    }

    private function identityFromClaims(array $claims): ScopeIdentity
    {
        return $this->catalog->authoritativeIdentity(ScopeIdentity::fromArray($claims));
    }

    private function identityFromStorage(string $scope, array $catalogOptions): ?ScopeIdentity
    {
        $decoded = $this->scopes->fromStorageScope($scope, true);
        if (!$decoded instanceof ScopeIdentity || $decoded->isGlobal()) {
            return $decoded;
        }
        foreach ($catalogOptions as $website) {
            if (!\is_array($website)
                || \strtolower((string)($website['code'] ?? '')) !== \strtolower((string)$decoded->websiteCode)
            ) {
                continue;
            }
            $websiteId = (int)($website['website_id'] ?? 0);
            if ($decoded->scopeKind === ScopeIdentity::KIND_WEBSITE) {
                return ScopeIdentity::website($websiteId, (string)$website['code']);
            }
            foreach ((array)($website['stores'] ?? []) as $store) {
                if (!\is_array($store)
                    || \strtolower((string)($store['code'] ?? '')) !== \strtolower((string)$decoded->storeCode)
                ) {
                    continue;
                }
                $storeMode = (string)($store['store_mode'] ?? ScopeIdentity::MODE_NORMAL);
                if ($decoded->scopeKind === ScopeIdentity::KIND_STORE) {
                    return ScopeIdentity::store($websiteId, (string)$website['code'], (string)$store['code'], $storeMode);
                }
                foreach ((array)($store['channels'] ?? []) as $channel) {
                    if (\is_array($channel)
                        && \strtolower((string)($channel['code'] ?? '')) === \strtolower((string)$decoded->channelCode)
                    ) {
                        return ScopeIdentity::channel(
                            $websiteId,
                            (string)$website['code'],
                            (string)$store['code'],
                            (string)$channel['code'],
                            $storeMode,
                        );
                    }
                }
            }
        }

        return null;
    }
}
