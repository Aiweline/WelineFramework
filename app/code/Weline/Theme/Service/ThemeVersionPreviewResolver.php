<?php
declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Model\ThemeScopeWorkspace;

/**
 * Authorized editor / Token preview selection via explicit ThemeScopeVersion (V/mode/R).
 * Never guesses page/chrome by nodeProjection similarity.
 */
final class ThemeVersionPreviewResolver
{
    public const REQUEST_KEY = 'theme.layout_entity.preview_entity';

    /**
     * Overlay optional Token owner/V/mode/R onto a resolve() result.
     *
     * @param array<string,mixed> $tokenData
     * @param array<string,mixed> $resolved
     * @return array<string,mixed>
     */
    public function applyTokenVersionCursor(array $tokenData, array $resolved): array
    {
        $themeVersionId = (int)($tokenData['theme_version_id'] ?? $tokenData['version_id'] ?? 0);
        if ($themeVersionId > 0) {
            $resolved['theme_version_id'] = $themeVersionId;
            $resolved['version_id'] = $themeVersionId;
            $resolved['chrome_version_id'] = $themeVersionId;
        }
        $mode = \trim((string)($tokenData['mode'] ?? ''));
        if ($mode !== '' && \in_array($mode, ThemeVersionIdentity::MODES, true)) {
            $resolved['mode'] = $mode;
        }
        $contentRevision = (int)($tokenData['content_revision'] ?? 0);
        if ($contentRevision > 0) {
            $resolved['content_revision'] = $contentRevision;
        }
        foreach (['canonical_scope', 'store_mode', 'area', 'owner_hash'] as $key) {
            $value = \trim((string)($tokenData[$key] ?? ''));
            if ($value !== '') {
                $resolved[$key] = $value;
            }
        }
        if (($resolved['version_identity'] ?? null) instanceof ThemeVersionIdentity
            && ((int)($resolved['theme_version_id'] ?? 0) > 0 || $mode !== '' || $contentRevision > 0)
        ) {
            /** @var ThemeVersionIdentity $identity */
            $identity = $resolved['version_identity'];
            $resolved['version_identity'] = $identity->withVersion(
                (int)($resolved['theme_version_id'] ?? $identity->themeVersionId),
                (string)($resolved['mode'] ?? $identity->mode),
                (int)($resolved['content_revision'] ?? $identity->contentRevision),
            );
        }

        return $resolved;
    }

    /**
     * Resolve preview identity from an explicit ThemeScopeVersion id and/or Token cursor.
     * Missing/ambiguous identity returns unresolved — no structure-equality fallback.
     *
     * @param array<string,mixed> $identity Layout identity (scope/layout_option/target_*)
     * @param array<string,mixed> $cursor Optional Token/selection cursor (theme_version_id/mode/content_revision/owner fields)
     * @return array<string,mixed>
     */
    public function resolve(
        int $themeId,
        string $pageType,
        string $area,
        array $identity,
        int $versionId,
        array $cursor = [],
    ): array {
        $context = ObjectManager::getInstance(ThemeRuntimeLayoutResolver::class)
            ->buildContext($themeId, $pageType, $area, $identity);
        $identityHash = $context->identityHash();
        $base = [
            'resolved' => false,
            'reason' => 'preview_theme_version_id_required',
            'theme_id' => $themeId,
            'scope' => $context->scope->storageScope,
            'identity_key' => $identityHash,
            'identity_hash' => $identityHash,
            'entity_key' => '',
            'chrome_version_id' => 0,
            'chrome_scope' => $context->scope->storageScope,
            'version_id' => $versionId,
            'theme_version_id' => null,
            'mode' => null,
            'content_revision' => null,
            'version_identity' => null,
            'nodes' => [],
            'release_id' => null,
            'draft_revision_id' => 0,
        ];

        $themeVersionId = (int)($cursor['theme_version_id'] ?? 0);
        if ($themeVersionId < 1) {
            $themeVersionId = $versionId;
        }
        if ($themeVersionId < 1) {
            return $base;
        }

        $version = clone ObjectManager::getInstance(ThemeScopeVersion::class);
        $version->load($themeVersionId);
        if ($version->getVersionId() < 1 || $version->getThemeId() !== $themeId) {
            return \array_replace($base, [
                'reason' => 'preview_theme_version_missing',
                'theme_version_id' => $themeVersionId,
                'version_id' => $themeVersionId,
            ]);
        }

        $ownerMismatch = $this->ownerMismatchReason($version, $cursor, $area, $context->scope->storageScope);
        if ($ownerMismatch !== null) {
            return \array_replace($base, [
                'reason' => $ownerMismatch,
                'theme_version_id' => $themeVersionId,
                'version_id' => $themeVersionId,
                'chrome_scope' => $version->getScope(),
            ]);
        }

        $versionIdentity = $version->toVersionIdentity();
        $mode = \trim((string)($cursor['mode'] ?? ''));
        if ($mode === '' || !\in_array($mode, ThemeVersionIdentity::MODES, true)) {
            $mode = $versionIdentity->mode;
        }
        $contentRevision = (int)($cursor['content_revision'] ?? 0);
        if ($contentRevision < 1) {
            $contentRevision = \max(1, $versionIdentity->contentRevision);
        }
        $versionIdentity = $versionIdentity->withVersion($themeVersionId, $mode, $contentRevision);

        $page = $this->loadWorkspaceNodes($context, $mode === ThemeVersionIdentity::MODE_FORMAL);
        $nodes = $page['nodes'];
        if ($nodes === []) {
            $nodes = $version->getChromePayload();
        }

        return [
            'resolved' => true,
            'reason' => '',
            'theme_id' => $themeId,
            'scope' => $version->getScope() !== '' ? $version->getScope() : $context->scope->storageScope,
            'identity_key' => $identityHash,
            'identity_hash' => $identityHash,
            // New path: never invent r/d/s entity keys.
            'entity_key' => '',
            'chrome_version_id' => $themeVersionId,
            'chrome_scope' => $version->getScope() !== '' ? $version->getScope() : $context->scope->storageScope,
            'version_id' => $themeVersionId,
            'theme_version_id' => $themeVersionId,
            'mode' => $mode,
            'content_revision' => $contentRevision,
            'lifecycle' => $version->getLifecycle(),
            'version_identity' => $versionIdentity,
            'nodes' => $nodes,
            'release_id' => $page['release_id'],
            'draft_revision_id' => $page['draft_revision_id'],
            'canonical_scope' => $versionIdentity->canonicalScope,
            'store_mode' => $versionIdentity->storeMode,
            'area' => $versionIdentity->area,
            'owner_hash' => $versionIdentity->ownerHash(),
        ];
    }

    /**
     * Explicit page release/draft binding only — no nodeProjection matching.
     * entity_key stays empty (v3 paths use ThemeVersionIdentity, not r/d/s keys).
     *
     * @param list<array<string,mixed>>|array<string,mixed> $releases
     * @param list<array<string,mixed>>|array<string,mixed> $draft
     * @return array<string,mixed>
     */
    public function selectPageBinding(
        array $nodes,
        array $releases,
        array $draft,
        ?int $explicitReleaseId = null,
        ?int $explicitDraftRevisionId = null,
    ): array {
        unset($nodes);
        if ($explicitReleaseId !== null && $explicitReleaseId > 0) {
            foreach ($releases as $release) {
                if ((int)($release['release_id'] ?? 0) === $explicitReleaseId) {
                    return [
                        'resolved' => true,
                        'reason' => '',
                        'entity_key' => '',
                        'release_id' => $explicitReleaseId,
                        'draft_revision_id' => 0,
                    ];
                }
            }

            return [
                'resolved' => false,
                'reason' => 'preview_page_release_missing',
                'entity_key' => '',
                'candidate_release_ids' => [],
            ];
        }
        if ($explicitDraftRevisionId !== null && $explicitDraftRevisionId > 0) {
            foreach (\array_is_list($draft) ? $draft : [$draft] as $state) {
                if ((int)($state['draft_revision_id'] ?? 0) === $explicitDraftRevisionId) {
                    return [
                        'resolved' => true,
                        'reason' => '',
                        'entity_key' => '',
                        'release_id' => null,
                        'draft_revision_id' => $explicitDraftRevisionId,
                    ];
                }
            }

            return [
                'resolved' => false,
                'reason' => 'preview_page_draft_missing',
                'entity_key' => '',
                'candidate_release_ids' => [],
            ];
        }

        return [
            'resolved' => false,
            'reason' => 'preview_page_version_unresolved',
            'entity_key' => '',
            'candidate_release_ids' => [],
        ];
    }

    /**
     * @param array<string,mixed> $cursor
     */
    private function ownerMismatchReason(
        ThemeScopeVersion $version,
        array $cursor,
        string $requestArea,
        string $requestScope,
    ): ?string {
        $cursorScope = \trim((string)($cursor['canonical_scope'] ?? $cursor['scope'] ?? ''));
        if ($cursorScope !== '' && $version->getScope() !== '' && $cursorScope !== $version->getScope()) {
            return 'preview_theme_version_scope_mismatch';
        }
        $cursorArea = \trim((string)($cursor['area'] ?? $requestArea));
        if ($cursorArea !== '' && $version->getArea() !== '' && $cursorArea !== $version->getArea()) {
            return 'preview_theme_version_area_mismatch';
        }
        $cursorStoreMode = \trim((string)($cursor['store_mode'] ?? ''));
        if ($cursorStoreMode !== '' && $version->getStoreMode() !== '' && $cursorStoreMode !== $version->getStoreMode()) {
            return 'preview_theme_version_store_mode_mismatch';
        }
        $ownerHash = \trim((string)($cursor['owner_hash'] ?? ''));
        if ($ownerHash !== '' && $ownerHash !== $version->toVersionIdentity()->ownerHash()) {
            return 'preview_theme_version_owner_mismatch';
        }
        unset($requestScope);

        return null;
    }

    /**
     * Load workspace page nodes for the layout identity — never by structure projection.
     *
     * @return array{nodes:array,release_id:?int,draft_revision_id:int}
     */
    private function loadWorkspaceNodes(object $context, bool $formal): array
    {
        $empty = ['nodes' => [], 'release_id' => null, 'draft_revision_id' => 0];
        try {
            $rows = $this->rows(ThemeScopeWorkspace::class, ['identity_hash' => $context->identityHash()]);
            $row = $rows[0] ?? [];
            if ($row === []) {
                return $empty;
            }
            $workspaceService = ObjectManager::getInstance(\Weline\Theme\Service\Scoped\ThemeScopedWorkspace::class);
            $state = $workspaceService->load($context, true);
            if ($formal) {
                $payload = $state['published_payload'] ?? $state['effective_payload'] ?? [];
                $nodes = \is_array($payload['nodes'] ?? null) ? $payload['nodes'] : (\is_array($payload) ? $payload : []);
                $releaseId = (int)($state['effective_release_id'] ?? $state['published_release_id'] ?? 0);

                return [
                    'nodes' => $nodes,
                    'release_id' => $releaseId > 0 ? $releaseId : null,
                    'draft_revision_id' => 0,
                ];
            }
            $payload = $state['draft_payload'] ?? [];
            $nodes = \is_array($payload['nodes'] ?? null) ? $payload['nodes'] : (\is_array($payload) ? $payload : []);

            return [
                'nodes' => $nodes,
                'release_id' => null,
                'draft_revision_id' => (int)($state['draft_revision_id'] ?? 0),
            ];
        } catch (\Throwable) {
            return $empty;
        }
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    private function rows(string $model, array $filters): array
    {
        $query = (clone ObjectManager::getInstance($model))->clearQuery()->clearData();
        foreach ($filters as $field => $value) {
            $query->where($field, $value);
        }
        $rows = $query->select()->fetchArray();

        return !\is_array($rows) || $rows === [] ? [] : (\array_is_list($rows) ? $rows : [$rows]);
    }
}
