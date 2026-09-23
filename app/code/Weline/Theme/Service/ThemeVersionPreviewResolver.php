<?php
declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
use Weline\Theme\Model\ThemeScopeRelease;
use Weline\Theme\Model\ThemeScopeRevision;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Model\ThemeScopeWorkspace;

/** Authorized editor version selection; a layout version is neither a release nor a chrome version. */
final class ThemeVersionPreviewResolver
{
    public const REQUEST_KEY = 'theme.layout_entity.preview_entity';

    public function __construct(private readonly ThemeLayoutVersionBindingResolver $bindings) {}

    public function resolve(int $themeId, string $pageType, string $area, array $identity, int $versionId): array
    {
        $context = ObjectManager::getInstance(ThemeRuntimeLayoutResolver::class)->buildContext($themeId, $pageType, $area, $identity);
        $base = ['resolved' => false, 'reason' => 'preview_layout_version_missing', 'theme_id' => $themeId,
            'scope' => $context->scope->storageScope, 'identity_key' => substr($context->identityHash(), 0, 16),
            'identity_hash' => $context->identityHash(), 'entity_key' => '', 'chrome_version_id' => 0,
            'chrome_scope' => $context->scope->storageScope, 'version_id' => $versionId, 'nodes' => []];
        $exactIdentity = ['scope' => $context->scope->storageScope, 'layout_option' => $context->layoutOption,
            'target_type' => $context->targetType, 'target_id' => $context->targetId, 'locale_code' => ''];
        $version = ObjectManager::getInstance(ThemeLayoutVersionService::class)->getVersion($themeId, $pageType, $versionId, $exactIdentity);
        if ($version === null) {
            return $base;
        }
        $nodes = $this->bindings->snapshotNodes($version->getSnapshotData());
        $base['nodes'] = $nodes;
        $rows = $this->rows(ThemeScopeWorkspace::class, ['identity_hash' => $context->identityHash()]);
        $row = $rows[0] ?? [];
        $releases = $row === [] ? [] : $this->rows(ThemeScopeRelease::class,
            ['workspace_id' => (int)$row['workspace_id'], 'status' => 'effective']);
        $draft = [];
        $historicalDraftsUnresolved = [];
        $workspaceService = ObjectManager::getInstance(\Weline\Theme\Service\Scoped\ThemeScopedWorkspace::class);
        foreach ($releases as &$release) {
            $composed = $workspaceService->readHistoricalLayoutRelease($context, (int)$release['release_id']);
            if ($composed !== null) { $release['effective_payload_json'] = $composed; }
        }
        unset($release);
        if ($row !== []) {
            foreach ($this->rows(ThemeScopeRevision::class, ['workspace_id' => (int)$row['workspace_id']]) as $revision) {
                $revisionId = (int)$revision['revision_id'];
                $state = $revisionId === (int)($row['draft_revision_id'] ?? 0) && $version->isCurrent()
                    ? $workspaceService->load($context, true)
                    : $workspaceService->readHistoricalLayoutRevision($context, $revisionId);
                if (is_array($state['draft_payload'] ?? null)) {
                    $draft[] = $state;
                } else {
                    $historicalDraftsUnresolved[] = ['draft_revision_id' => $revisionId, 'reason' => $state['reason'] ?? 'historical_draft_baseline_missing'];
                }
            }
        }
        $base['unresolved_drafts'] = $historicalDraftsUnresolved;
        $releaseReferences = [];
        foreach ($nodes as $node) {
            $config = $this->decode($node['config'] ?? []);
            if ((int)($config['_theme_release_id'] ?? 0) > 0) {
                $releaseReferences[(int)$config['_theme_release_id']] = true;
            }
        }
        $explicitRelease = count($releaseReferences) === 1 ? (int)array_key_first($releaseReferences) : null;
        $page = $this->selectPageBinding($nodes, $releases, $draft, $explicitRelease);
        if (!$page['resolved']) {
            return array_replace($base, $page);
        }
        $base = array_replace($base, $page, ['resolved' => false, 'reason' => 'preview_chrome_version_unresolved']);
        $expectedChrome = $this->bindings->nodeProjection($nodes, true);
        foreach ($context->scope->fallbackStorageScopes as $scope) {
            $matches = [];
            foreach ($this->rows(ThemeScopeVersion::class, ['theme_id' => $themeId, 'scope' => $scope]) as $chrome) {
                if ($this->bindings->nodeProjection($this->decode($chrome['chrome_payload_json'] ?? []), true) === $expectedChrome) {
                    $matches[] = (int)$chrome['version_id'];
                }
            }
            if (count($matches) === 1) {
                return array_replace($base, ['resolved' => true, 'reason' => '', 'chrome_version_id' => $matches[0], 'chrome_scope' => $scope]);
            }
            if (count($matches) > 1) {
                return array_replace($base, ['reason' => 'preview_chrome_version_ambiguous', 'candidate_chrome_version_ids' => $matches]);
            }
        }
        return $base;
    }

    public function selectPageBinding(array $nodes, array $releases, array $draft, ?int $explicitReleaseId = null): array
    {
        $expected = $this->bindings->nodeProjection($nodes);
        $matches = [];
        foreach ($releases as $release) {
            $id = (int)($release['release_id'] ?? 0);
            if ($id < 1 || ($explicitReleaseId !== null && $id !== $explicitReleaseId)) {
                continue;
            }
            $payload = $this->decode($release['effective_payload_json'] ?? []);
            if ($this->bindings->nodeProjection((array)($payload['nodes'] ?? [])) === $expected) {
                $matches[] = $id;
            }
        }
        if (count($matches) === 1) {
            return ['resolved' => true, 'reason' => '', 'entity_key' => 'r' . $matches[0], 'release_id' => $matches[0], 'draft_revision_id' => 0];
        }
        $draftMatches = [];
        foreach (array_is_list($draft) ? $draft : [$draft] as $state) {
            if ($matches === [] && $explicitReleaseId === null && (int)($state['draft_revision_id'] ?? 0) > 0
                && $this->bindings->nodeProjection((array)($state['draft_payload']['nodes'] ?? [])) === $expected) {
                $draftMatches[(int)$state['draft_revision_id']] = true;
            }
        }
        if (count($draftMatches) === 1) {
            $id = (int)array_key_first($draftMatches);
            return ['resolved' => true, 'reason' => '', 'entity_key' => 'd' . $id,
                'release_id' => null, 'draft_revision_id' => $id];
        }
        return ['resolved' => false, 'reason' => count($matches) > 1 ? 'preview_page_version_ambiguous' : 'preview_page_version_unresolved',
            'entity_key' => '', 'candidate_release_ids' => $matches];
    }

    private function rows(string $model, array $filters): array
    {
        $query = (clone ObjectManager::getInstance($model))->clearQuery()->clearData();
        foreach ($filters as $field => $value) { $query->where($field, $value); }
        $rows = $query->select()->fetchArray();
        return !is_array($rows) || $rows === [] ? [] : (array_is_list($rows) ? $rows : [$rows]);
    }

    private function decode(mixed $value): array
    {
        $value = is_string($value) ? json_decode($value, true) : $value;
        return is_array($value) ? $value : [];
    }
}
