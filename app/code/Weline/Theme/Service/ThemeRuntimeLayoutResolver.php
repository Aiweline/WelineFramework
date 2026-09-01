<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface;
use Weline\Theme\Api\Layout\LayoutIdentity;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\ThemeScopeRelease;
use Weline\Theme\Model\ThemeScopeRevision;
use Weline\Theme\Service\Scoped\ThemeScopedPreviewResolver;

/**
 * Storefront/runtime layout resolution from scoped workspace releases.
 */
final class ThemeRuntimeLayoutResolver
{
    private const NO_PLACEMENTS_WIDGET_MODULE = 'Weline_Theme';
    private const NO_PLACEMENTS_WIDGET_TYPE = 'layout_state';
    private const NO_PLACEMENTS_WIDGET_CODE = '__no_widget_placements__';

    public function __construct(
        private readonly ThemeScopedPreviewResolver $previewResolver,
        private readonly ThemeScopedWorkspaceInterface $workspace,
        private readonly ThemeLayoutScopeNormalizer $scopeNormalizer,
        private readonly ScopeHierarchyInterface $scopes,
        private readonly ScopeIdentityCatalogInterface $catalog,
        private readonly ThemeLayoutService $layoutService,
        private readonly ThemeScopeRelease $releases,
        private readonly ThemeScopeRevision $revisions,
    ) {
    }

    /**
     * @param array<string,mixed> $identity
     * @return array<string,mixed>
     */
    public function resolveLayout(
        int $themeId,
        string $pageType,
        string $status = ThemeLayout::STATUS_PUBLISHED,
        string $area = 'frontend',
        array $identity = [],
    ): array {
        $context = $this->buildContext($themeId, $pageType, $area, $identity);
        try {
            return $this->previewResolver->resolveLayout(
                $context,
                $status === ThemeLayout::STATUS_PUBLISHED
                    ? ThemeLayout::STATUS_PUBLISHED
                    : ThemeLayout::STATUS_DRAFT,
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string,mixed> $identity
     */
    public function buildContext(
        int $themeId,
        string $pageType,
        string $area,
        array $identity = [],
    ): ThemeEditorContext {
        $identity = $this->normalizeIdentity($identity);
        $scopeIdentity = $this->scopeNormalizer->identityFromEncodedScope((string)$identity['scope']);
        $authoritative = $this->catalog->authoritativeIdentity($scopeIdentity);
        $scopeContext = $this->scopes->contextFromClaims($authoritative->toArray(), $authoritative);
        $locale = \trim((string)$identity['locale_code']);

        return new ThemeEditorContext(
            scope: $scopeContext,
            area: $area === 'backend' ? 'backend' : 'frontend',
            resourceType: ThemeEditorContext::RESOURCE_LAYOUT,
            themeId: $themeId,
            layoutType: $pageType,
            layoutOption: (string)$identity['layout_option'],
            locale: $locale !== '' ? $locale : 'default',
            targetType: (string)$identity['target_type'],
            targetId: (int)$identity['target_id'],
        );
    }

    /**
     * @param array<string,mixed> $identity
     */
    public function hasNoWidgetPlacements(
        int $themeId,
        string $pageType,
        string $status,
        array $identity = [],
        string $area = 'frontend',
    ): bool {
        try {
            $context = $this->buildContext($themeId, $pageType, $area, $identity);
            $includeDraft = $status !== ThemeLayout::STATUS_PUBLISHED;
            $state = $this->workspace->load($context, $includeDraft);
            $payloadKey = $includeDraft ? 'draft_payload' : 'published_payload';
            $payload = \is_array($state[$payloadKey] ?? null) ? $state[$payloadKey] : [];
            if ($this->payloadHasNoPlacementsMarker($payload)) {
                return true;
            }
            if ($payload !== []) {
                return false;
            }
        } catch (\Throwable) {
            // Fall through to legacy marker lookup during transition.
        }

        return $this->layoutService->hasNoWidgetPlacements($themeId, $pageType, $status, $identity);
    }

    /**
     * @param array<string,mixed> $identity
     * @return array{themePublishedVersionId:string,themePublishedVersion:string}
     */
    public function resolvePublishedVersionInfo(
        ?int $themeId = null,
        string $pageType = 'homepage',
        array $identity = [],
        string $area = 'frontend',
    ): array {
        $empty = [
            'themePublishedVersionId' => '',
            'themePublishedVersion' => '',
        ];
        if ($themeId === null || $themeId <= 0) {
            return $empty;
        }

        foreach ($this->identityCandidates($identity) as $candidate) {
            try {
                $context = $this->buildContext($themeId, $pageType, $area, $candidate);
                $state = $this->workspace->load($context, false);
                $releaseId = (int)($state['effective_release_id'] ?? $state['published_release_id'] ?? 0);
                if ($releaseId <= 0) {
                    continue;
                }
                $label = $this->releaseLabel($releaseId);

                return [
                    'themePublishedVersionId' => (string)$releaseId,
                    'themePublishedVersion' => $label,
                ];
            } catch (\Throwable) {
                continue;
            }
        }

        return $empty;
    }

    /**
     * @param array<string,mixed> $identity
     * @return array{layout_option:string,scope:string,target_type:string,target_id:int,locale_code:string}
     */
    private function normalizeIdentity(array $identity): array
    {
        if ($identity === []) {
            $installed = RequestContext::get(LayoutIdentity::REQUEST_CONTEXT_KEY);
            if ($installed instanceof LayoutIdentity) {
                return $installed->toArray();
            }
        }

        return [
            'layout_option' => \trim((string)($identity['layout_option'] ?? 'default')) ?: 'default',
            'scope' => \trim((string)($identity['scope'] ?? 'default')) ?: 'default',
            'target_type' => \trim((string)($identity['target_type'] ?? 'global')) ?: 'global',
            'target_id' => \max(0, (int)($identity['target_id'] ?? 0)),
            'locale_code' => \trim((string)($identity['locale_code'] ?? $identity['locale'] ?? '')),
        ];
    }

    /** @param array<string,mixed> $payload */
    private function payloadHasNoPlacementsMarker(array $payload): bool
    {
        $nodes = \is_array($payload['nodes'] ?? null) ? $payload['nodes'] : [];
        foreach ($nodes as $node) {
            if (!\is_array($node)) {
                continue;
            }
            if ((string)($node['widget_module'] ?? '') === self::NO_PLACEMENTS_WIDGET_MODULE
                && (string)($node['widget_type'] ?? '') === self::NO_PLACEMENTS_WIDGET_TYPE
                && (string)($node['widget_code'] ?? '') === self::NO_PLACEMENTS_WIDGET_CODE
            ) {
                return true;
            }
        }

        return (bool)($payload['selection']['no_widget_placements'] ?? false);
    }

    private function releaseLabel(int $releaseId, int $depth = 0): string
    {
        $release = clone $this->releases;
        $release->clearData()->clearQuery()->load($releaseId);
        if (!$release->getId()) {
            return '#' . $releaseId;
        }
        $reason = \trim((string)$release->getData(ThemeScopeRelease::schema_fields_REASON));
        // 继承传播：用父级已发布修订号/摘要，避免露出 parent_release_propagation。
        if ($reason === 'parent_release_propagation' && $depth < 5) {
            $parentId = (int)$release->getData(ThemeScopeRelease::schema_fields_PARENT_RELEASE_ID);
            if ($parentId > 0 && $parentId !== $releaseId) {
                return $this->releaseLabel($parentId, $depth + 1);
            }
        }

        $revisionId = (int)$release->getData(ThemeScopeRelease::schema_fields_REVISION_ID);
        if ($revisionId > 0) {
            $revision = clone $this->revisions;
            $revision->clearData()->clearQuery()->load($revisionId);
            $summary = \trim((string)$revision->getData(ThemeScopeRevision::schema_fields_SUMMARY));
            if ($summary !== '' && $this->isHumanVersionLabel($summary)) {
                return $summary;
            }
            $revisionNo = (int)$revision->getData(ThemeScopeRevision::schema_fields_REVISION_NO);
            if ($revisionNo > 0) {
                return 'r' . $revisionNo;
            }
        }

        // reason 仅当像人读说明时才展示；theme_editor_publish 等机器码一律不用。
        if ($reason !== '' && $this->isHumanVersionLabel($reason)) {
            return $reason;
        }

        return '#' . $releaseId;
    }

    private function isHumanVersionLabel(string $label): bool
    {
        $label = \trim($label);
        if ($label === '') {
            return false;
        }
        // snake_case / code-like：layout_node_added、theme_editor_publish、parent_release_propagation
        if (\preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)+$/', $label) === 1) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string,mixed> $identity
     * @return list<array{layout_option:string,scope:string,locale_code:string,target_type:string,target_id:int}>
     */
    private function identityCandidates(array $identity): array
    {
        if ($identity !== []) {
            return [$this->normalizeIdentity($identity)];
        }

        $list = [];
        $seen = [];
        $push = static function (array $candidate) use (&$list, &$seen): void {
            $key = ($candidate['scope'] ?? '')
                . '|' . ($candidate['locale_code'] ?? '')
                . '|' . ($candidate['layout_option'] ?? 'default')
                . '|' . ($candidate['target_type'] ?? 'global')
                . '|' . (string)($candidate['target_id'] ?? 0);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $list[] = $candidate;
        };

        try {
            $scopeIdentity = RequestContext::scopeIdentity();
            if (!$scopeIdentity instanceof ScopeIdentity) {
                $scopeIdentity = ScopeIdentity::global();
            }
            // 无请求 Scope（CLI / 维护波次）：先对齐前台默认 website/store 已发布指针，避免落到旧的 default.default.default。
            if ($scopeIdentity->isGlobal()) {
                foreach (['default.__store__.default', 'default.__website__.default'] as $frontendScope) {
                    $push([
                        'layout_option' => 'default',
                        'scope' => $frontendScope,
                        'locale_code' => '',
                        'target_type' => 'global',
                        'target_id' => 0,
                    ]);
                }
            }
            $context = $this->scopes->contextFromIdentity($scopeIdentity);
            foreach ($context->fallbackStorageScopes as $storageScope) {
                $storageScope = \trim((string)$storageScope);
                if ($storageScope === '') {
                    continue;
                }
                $push([
                    'layout_option' => 'default',
                    'scope' => $storageScope,
                    'locale_code' => '',
                    'target_type' => 'global',
                    'target_id' => 0,
                ]);
            }
        } catch (\Throwable) {
            foreach (['default.__store__.default', 'default.__website__.default'] as $frontendScope) {
                $push([
                    'layout_option' => 'default',
                    'scope' => $frontendScope,
                    'locale_code' => '',
                    'target_type' => 'global',
                    'target_id' => 0,
                ]);
            }
        }

        $push([
            'layout_option' => 'default',
            'scope' => 'default.default.default',
            'locale_code' => '',
            'target_type' => 'global',
            'target_id' => 0,
        ]);

        return $list;
    }
}
