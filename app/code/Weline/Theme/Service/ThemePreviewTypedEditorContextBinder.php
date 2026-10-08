<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Model\ThemeVirtualLayout;

/**
 * Bind typed editor_context into live-preview Token payloads.
 *
 * Mode-2 (version live preview) identity is Token-authoritative. Publish-and-exit
 * requires typed editor_context claims — never PreviewContext shell target_type
 * (layout|path|page). Mint paths that omit editor_context must still produce a
 * publishable Token; already-issued Tokens are healed at publish time.
 */
final class ThemePreviewTypedEditorContextBinder
{
    public function __construct(
        private readonly ScopeHierarchyInterface $hierarchy,
        private readonly PreviewContextService $previewContextService,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function decodeClaims(mixed $raw): ?array
    {
        if (\is_string($raw) && \trim($raw) !== '') {
            try {
                $raw = \json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                return null;
            }
        }
        if (!\is_array($raw)) {
            return null;
        }

        $scope = $raw['scope'] ?? null;
        if (\is_string($scope) && \trim($scope) !== '') {
            try {
                $scope = \json_decode($scope, true, flags: \JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                return null;
            }
        }
        if (!\is_array($scope)) {
            return null;
        }

        $hasIdentity = \is_array($scope['identity'] ?? null);
        $hasStorage = \trim((string)($scope['storage_scope'] ?? $scope['scope_key'] ?? '')) !== '';
        if (!$hasIdentity && !$hasStorage) {
            return null;
        }

        $raw['scope'] = $scope;

        return $raw;
    }

    /**
     * @param array<string, mixed> $previewContext
     * @return array<string, mixed>
     */
    public function materializeClaims(int $themeId, string $pageType, array $previewContext = []): array
    {
        if ($themeId < 1) {
            throw new \InvalidArgumentException('theme_preview_typed_context_theme_required');
        }
        $pageType = \trim($pageType);
        if ($pageType === '') {
            throw new \InvalidArgumentException('theme_preview_typed_context_layout_required');
        }

        $area = $this->previewContextService->normalizeArea(
            (string)($previewContext['editor_area'] ?? $previewContext['area'] ?? PreviewContextService::AREA_FRONTEND),
            PreviewContextService::AREA_FRONTEND
        );
        if (($previewContext['shell'] ?? '') === PreviewContextService::SHELL_PREVIEW) {
            $area = PreviewContextService::AREA_FRONTEND;
        }

        $layoutOption = \trim((string)($previewContext['layout_option'] ?? 'default'));
        if ($layoutOption === '') {
            $layoutOption = 'default';
        }

        $storageScope = \trim((string)($previewContext['canonical_scope']
            ?? $previewContext['scope']
            ?? PreviewContextService::DEFAULT_SCOPE));
        if ($storageScope === '') {
            $storageScope = PreviewContextService::DEFAULT_SCOPE;
        }

        try {
            $scopeIdentity = $this->hierarchy->fromStorageScope($storageScope, true);
        } catch (\Throwable) {
            $scopeIdentity = null;
        }
        if (!$scopeIdentity instanceof ScopeIdentity) {
            $scopeIdentity = ScopeIdentity::global();
        }

        $targetType = \trim((string)(
            $previewContext['theme_layout_target_type']
            ?? $previewContext['theme_layout_source_target_type']
            ?? ThemeVirtualLayout::TARGET_GLOBAL
        ));
        if ($targetType === '' || \in_array($targetType, [
            PreviewContextService::TARGET_TYPE_LAYOUT,
            PreviewContextService::TARGET_TYPE_PATH,
            PreviewContextService::TARGET_TYPE_PAGE,
        ], true)) {
            $targetType = ThemeVirtualLayout::TARGET_GLOBAL;
        }
        $targetId = $targetType === ThemeVirtualLayout::TARGET_GLOBAL
            ? 0
            : \max(0, (int)(
                $previewContext['theme_layout_target_id']
                ?? $previewContext['theme_layout_source_target_id']
                ?? 0
            ));

        return [
            'scope' => ['identity' => $scopeIdentity->toArray()],
            'area' => $area,
            'resource_type' => 'layout',
            'theme_id' => $themeId,
            // Layout structure identity locale is always default (i18n overlays separate).
            'layout_type' => $pageType,
            'layout_option' => $layoutOption,
            'locale' => 'default',
            'target_type' => $targetType,
            'target_id' => $targetId,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function bindIntoPreviewContext(int $themeId, string $pageType, array $context): array
    {
        $existing = $this->decodeClaims($context['editor_context'] ?? null);
        if ($existing !== null) {
            $context['editor_context'] = $existing;

            return $context;
        }

        $context['editor_context'] = $this->materializeClaims($themeId, $pageType, $context);

        return $context;
    }
}
