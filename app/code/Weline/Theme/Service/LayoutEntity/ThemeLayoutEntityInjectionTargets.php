<?php
declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\ThemeLayoutVersion;
use Weline\Theme\Model\ThemeScopeRelease;
use Weline\Theme\Model\ThemeScopeRevision;
use Weline\Theme\Model\ThemeScopeWorkspace;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\Scoped\ThemeLayoutSnapshotNormalizer;
use Weline\Theme\Service\ThemeRuntimeLayoutResolver;

/** Enumerates authoritative layout identities, including ones with no generated files. */
final class ThemeLayoutEntityInjectionTargets
{
    /** Reviewable migration diagnostics; never include widget configuration in reports. */
    public function reportForTargets(array $targets): array
    {
        $unresolved = [];
        foreach ($targets as $target) {
            if (!empty($target['version_resolved'])) {
                continue;
            }
            $unresolved[] = array_intersect_key($target, array_flip([
                'theme_id', 'scope', 'identity_hash', 'layout_type', 'layout_option',
                'release_id', 'draft_revision_id', 'version_id', 'reason',
            ])) + ['reason' => 'layout_version_unresolved'];
        }
        return ['target_count' => count($targets), 'unresolved' => $unresolved];
    }

    public static function affects(array $changes, string $layoutType, string $layoutOption = 'default'): bool
    {
        if ($changes === []) {
            return true;
        }
        foreach ($changes as $change) {
            if (($change['widget_identity']['area'] ?? 'frontend') !== 'frontend') {
                continue;
            }
            foreach (array_merge($change['before'] ?? [], $change['after'] ?? []) as $item) {
                $type = (string)($item['layout_type'] ?? '*');
                $option = (string)($item['layout_option'] ?? 'default');
                $slot = (string)($item['slot'] ?? '');
                $chrome = preg_match('/^(header|footer|delivery)([-_]|$)/', $slot) === 1;
                if ($chrome || (($type === '*' || $type === '' || $type === $layoutType)
                    && ($option === '*' || $option === '' || $option === $layoutOption))) {
                    return true;
                }
            }
        }
        return false;
    }

    public function resolve(array $changes, ?int $themeId = null): array
    {
        $workspaces = $this->rows(ThemeScopeWorkspace::class, ['resource_type' => 'layout', 'area' => 'frontend']);
        $releases = $this->rows(ThemeScopeRelease::class, ['resource_type' => 'layout', 'area' => 'frontend', 'status' => 'effective']);
        $versions = $this->rows(ThemeLayoutVersion::class);
        $revisions = $this->rows(ThemeScopeRevision::class);
        $byDraftWorkspace = [];
        foreach ($revisions as $revision) {
            $byDraftWorkspace[(int)$revision['workspace_id']][] = $revision;
        }
        $byWorkspace = [];
        foreach ($releases as $release) {
            $byWorkspace[(int)$release['workspace_id']][] = $release;
        }
        $resolver = ObjectManager::getInstance(ThemeRuntimeLayoutResolver::class);
        $workspaceService = ObjectManager::getInstance(ThemeScopedWorkspaceInterface::class);
        $targets = [];
        $known = [];
        foreach ($workspaces as $row) {
            $tid = (int)($row['theme_id'] ?? 0);
            $type = (string)($row['layout_type'] ?? 'default');
            $option = (string)($row['layout_option'] ?? 'default');
            if ($tid < 1 || ($themeId !== null && $themeId > 0 && $tid !== $themeId)
                || !self::affects($changes, $type, $option)) {
                continue;
            }
            $identity = ['scope' => $row['scope'], 'layout_option' => $option,
                'target_type' => $row['target_type'] ?? 'global', 'target_id' => (int)($row['target_id'] ?? 0), 'locale_code' => 'default'];
            $context = $resolver->buildContext($tid, $type, 'frontend', $identity);
            $known[$tid . '|' . $type . '|' . $option] = true;
            foreach ($byWorkspace[(int)$row['workspace_id']] ?? [] as $release) {
                $payload = ObjectManager::getInstance(\Weline\Theme\Service\Scoped\ThemeScopedWorkspace::class)
                    ->readHistoricalLayoutRelease($context, (int)$release['release_id'])
                    ?? $this->decode($release['effective_payload_json'] ?? []);
                $targets[] = $this->target($context, $payload, true, (int)$release['release_id'], 0, $versions,
                    (int)$release['release_id'] === (int)($row['published_release_id'] ?? 0));
            }
            foreach ($byDraftWorkspace[(int)$row['workspace_id']] ?? [] as $revision) {
                if (($revision['status'] ?? '') === 'conflict') { continue; }
                $revisionId = (int)$revision['revision_id'];
                $isCurrent = $revisionId === (int)($row['draft_revision_id'] ?? 0);
                $state = $isCurrent ? $workspaceService->load($context, true)
                    : ObjectManager::getInstance(\Weline\Theme\Service\Scoped\ThemeScopedWorkspace::class)
                        ->readHistoricalLayoutRevision($context, $revisionId);
                $target = $this->target($context, (array)($state['draft_payload'] ?? []), false, null,
                    $revisionId, $versions, $isCurrent);
                if (!is_array($state['draft_payload'] ?? null)) {
                    $target['version_resolved'] = false;
                    $target['reason'] = $state['reason'] ?? 'historical_draft_baseline_missing';
                }
                $targets[] = $target;
            }
        }
        // Package defaults under unactivated themes have no workspace/artifact yet.
        $types = array_fill_keys(array_keys(ThemeLayout::getPageTypes()), ['default']);
        foreach ($changes as $change) {
            foreach (array_merge($change['before'] ?? [], $change['after'] ?? []) as $item) {
                $type = (string)($item['layout_type'] ?? '*');
                $option = (string)($item['layout_option'] ?? 'default');
                if ($type !== '' && $type !== '*') {
                    $types[$type][] = $option === '*' ? 'default' : $option;
                }
            }
        }
        foreach ($this->rows(WelineTheme::class) as $theme) {
            $tid = (int)($theme['theme_id'] ?? $theme['id'] ?? 0);
            if ($tid < 1 || ($themeId !== null && $themeId > 0 && $tid !== $themeId)) {
                continue;
            }
            foreach ($types as $type => $options) {
                foreach (array_unique($options) as $option) {
                    if (isset($known[$tid . '|' . $type . '|' . $option]) || !self::affects($changes, $type, $option)) {
                        continue;
                    }
                    $context = $resolver->buildContext($tid, $type, 'frontend', ['scope' => 'default', 'layout_option' => $option]);
                    $state = $workspaceService->load($context, false);
                    $payload = $state['published_payload'] ?? null;
                    if (is_array($payload)) {
                        $targets[] = $this->target($context, $payload, true, isset($state['effective_release_id']) ? (int)$state['effective_release_id'] : null, 0, $versions, true);
                    }
                }
            }
        }
        return $targets;
    }

    private function target($context, array $payload, bool $published, ?int $releaseId, int $draftRevisionId, array $versions, bool $current): array
    {
        $versionId = (int)($payload['theme_layout_version_id'] ?? $payload['version_id'] ?? 0);
        $resolved = $versionId > 0;
        $candidates = array_values(array_filter($versions, static fn(array $v): bool =>
            (int)($v['theme_id'] ?? 0) === $context->themeId
            && ($v['page_type'] ?? '') === $context->layoutType
            && ($v['scope'] ?? 'default') === $context->scope->storageScope
            && ($v['layout_option'] ?? 'default') === $context->layoutOption
            && ($v['target_type'] ?? 'global') === $context->targetType
            && (int)($v['target_id'] ?? 0) === $context->targetId));
        if (!$resolved && $candidates === []) {
            $resolved = true;
        }
        $matchedVersions = [];
        foreach ($candidates as $version) {
            if ($resolved) {
                break;
            }
            $snapshot = $this->decode($version['snapshot_data'] ?? []);
            $matches = $current && !empty($version[$published ? 'is_published' : 'is_current']);
            foreach ($snapshot as $area) {
                foreach ($area['widgets'] ?? [] as $widget) {
                    if ($releaseId !== null && (int)($widget['config']['_theme_release_id'] ?? 0) === $releaseId) {
                        $matches = true;
                    }
                }
            }
            if (!$matches && $snapshot !== []) {
                $normalized = ObjectManager::getInstance(ThemeLayoutSnapshotNormalizer::class)->normalize($context, $snapshot);
                $matches = ($normalized['nodes'] ?? []) == ($payload['nodes'] ?? []);
            }
            if ($matches) {
                $matchedId = (int)($version['version_id'] ?? 0);
                if ($matchedId > 0) {
                    $matchedVersions[$matchedId] = true;
                }
            }
        }
        if (!$resolved && count($matchedVersions) === 1) {
            $versionId = (int)array_key_first($matchedVersions);
            $resolved = true;
        }
        return ['theme_id' => $context->themeId, 'scope' => $context->scope->storageScope, 'area' => 'frontend',
            'identity_hash' => $context->identityHash(), 'layout_type' => $context->layoutType,
            'layout_option' => $context->layoutOption, 'nodes' => (array)($payload['nodes'] ?? []),
            'published' => $published, 'release_id' => $releaseId, 'draft_revision_id' => $draftRevisionId,
            'version_id' => $versionId, 'version_resolved' => $resolved, 'current' => $current];
    }

    private function rows(string $modelClass, array $filters = []): array
    {
        $query = (clone ObjectManager::getInstance($modelClass))->clearQuery()->clearData();
        foreach ($filters as $field => $value) {
            $query->where($field, $value);
        }
        $rows = $query->select()->fetchArray();
        return !is_array($rows) || $rows === [] ? [] : (array_is_list($rows) ? $rows : [$rows]);
    }

    private function decode(mixed $value): array
    {
        $value = is_string($value) ? json_decode($value, true) : $value;
        return is_array($value) ? $value : [];
    }
}
