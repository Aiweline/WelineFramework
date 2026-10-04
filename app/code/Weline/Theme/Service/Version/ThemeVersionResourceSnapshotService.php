<?php
declare(strict_types=1);

namespace Weline\Theme\Service\Version;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Database\TransactionContext;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Model\ThemeScopeVersionRevision;
use Weline\Theme\Model\ThemeScopeVersionResourceSnapshot;
use Weline\Theme\Model\ThemeScopeWorkspace;
use Weline\Theme\Service\Scoped\ThemeScopedWorkspace;
use Weline\Theme\Service\ThemeScopeVersionService;

/** Persists explicit immutable references; no current-workspace fallback for history. */
final class ThemeVersionResourceSnapshotService
{
    /** Called under the owner write lock and the same database transaction as the resource save. */
    public function beginWrite(ThemeEditorContext $context): ThemeScopeVersion
    {
        $versions = ObjectManager::getInstance(ThemeScopeVersionService::class);
        $version = $versions->getCurrent($context->themeId, $context->scope->storageScope, $context->scope->storeMode, $context->area)
            ?? $versions->ensureCurrent($context->themeId, $context->scope->storageScope,
                $context->scope->identity->scopeKind, $context->scope->identity->websiteId, $context->scope->storeMode, $context->area);
        $version = (clone $version)->clearData()->clearQuery()->load($version->getVersionId());
        if ($version->getLifecycle() === ThemeScopeVersion::LIFECYCLE_SEALED) {
            if ($version->getContentRevision() < 1) { $version->setContentRevision(1)->save(); }
            $this->captureCurrent($version, $context);
            $version = $versions->createRevisionFrom($version);
        }
        $initial = $version->getContentRevision() < 1;
        if ($initial) { $version->setContentRevision(1)->save(); }
        $this->captureCurrent($version, $context, $initial);
        return $version;
    }

    /** Freeze the observed current state only; this never invents earlier R values. */
    public function captureCurrent(ThemeScopeVersion $version, ThemeEditorContext $context, bool $initial = false): void
    {
        $identity = $version->toVersionIdentity();
        $head = $this->head($identity);
        $descriptor = json_decode((string)($head['package_default_json'] ?? '{}'), true);
        if ($head !== null && !empty($descriptor['current_package_defaults'])) { return; }
        $current = ObjectManager::getInstance(ThemeScopeVersionService::class)->getCurrent($identity->themeId, $identity->canonicalScope, $identity->storeMode, $identity->area);
        $published = ObjectManager::getInstance(ThemeScopeVersionService::class)->getPublished($identity->themeId, $identity->canonicalScope, $identity->storeMode, $identity->area);
        $publishedOnly = $current?->getVersionId() !== $identity->themeVersionId;
        if ($publishedOnly && ($published?->getVersionId() !== $identity->themeVersionId
            || $published->toVersionIdentity()->ownerHash() !== $identity->ownerHash())) { throw new \RuntimeException('historical_revision_head_missing'); }
        if ($initial && $version->getCreationSourceKind() === \Weline\Theme\Api\Version\ThemeVersionPublicationInterface::CREATION_PACKAGE_DEFAULTS) {
            $this->writeResource($identity, $context, 0, 0, false);
            $this->writeHead($version, 'initial_package_defaults');
            return;
        }
        $sourceId = (int)$version->getCreationSourceVersionId();
        if ($initial && $sourceId > 0 && $sourceId !== $identity->themeVersionId) {
            $source = (clone ObjectManager::getInstance(ThemeScopeVersion::class))->clearData()->clearQuery()->load($sourceId);
            if ($initial && $source->getVersionId() > 0 && $this->head($source->toVersionIdentity()) === null) {
                $sourceContext = $this->contextFor($source->toVersionIdentity(), $context->toArray());
                $this->captureCurrent($source, $sourceContext);
            }
            if ($source->getVersionId() > 0 && $this->head($source->toVersionIdentity()) !== null) {
                foreach ($this->resources($source->toVersionIdentity()) as $row) {
                    $key = json_decode((string)$row['resource_key_json'], true);
                    $targetContext = $this->contextFor($identity, $key);
                    $row['resource_identity_hash'] = $targetContext->identityHash();
                    $row['resource_key_json'] = json_encode($targetContext->toArray() + [
                        'reference_context' => $key['reference_context'] ?? $key,
                        'package_default' => !empty($key['package_default']), 'has_intent' => !empty($key['has_intent']),
                    ], JSON_THROW_ON_ERROR);
                    unset($row['snapshot_id'], $row['create_time']);
                    $row['theme_version_id'] = $identity->themeVersionId;
                    $row['content_revision'] = $identity->contentRevision;
                    (clone ObjectManager::getInstance(ThemeScopeVersionResourceSnapshot::class))->clearData()->clearQuery()->setData($row)->save();
                }
                $sourceHead = $this->head($source->toVersionIdentity());
                $sourceDescriptor = json_decode((string)($sourceHead['package_default_json'] ?? '{}'), true);
                $this->writeHead($version, '', $sourceDescriptor['configuration'] ?? null, $sourceDescriptor['omissions'] ?? null);
                return;
            }
        }
        $filters = ['theme_id' => $identity->themeId, 'scope' => $identity->canonicalScope,
            'store_mode' => $identity->storeMode, 'area' => $identity->area];
        $rows = $this->rows(ThemeScopeWorkspace::class, $filters + ['theme_version_id' => $identity->themeVersionId]);
        if ($rows === []) { $rows = $this->rows(ThemeScopeWorkspace::class, $filters + ['theme_version_id' => 0]); }
        $inputs = $this->migrationResources($identity, $context, $rows, $publishedOnly);
        if (!$initial) {
            // Validate the legacy identity mapping before advancing the cursor.
            // Earlier R stays unresolved; only the newly observed state is frozen.
            $version->setContentRevision($identity->contentRevision + 1)->save();
            $identity = $version->toVersionIdentity();
        }
        $seen = [];
        $existing = array_column($this->resources($identity), null, 'resource_identity_hash');
        foreach ($inputs as $input) {
            $row = $input['row']; $resource = $input['context'];
            $seen[$resource->identityHash()] = true;
            $previous = $existing[$resource->identityHash()] ?? [];
            if ((int)($previous['intent_revision_id'] ?? 0) > 0 || (int)($previous['release_id'] ?? 0) > 0) { continue; }
            $intent = $input['intent'];
            $release = $input['release'];
            if ($intent > 0) {
                $workspaceService = ObjectManager::getInstance(ThemeScopedWorkspace::class);
                $historical = $workspaceService->readHistoricalLayoutRevision($resource, $intent);
                if (empty($historical['resolved'])) {
                    $workspace = (clone ObjectManager::getInstance(ThemeScopeWorkspace::class))->clearData()->setData($row);
                    $release = $workspaceService->captureCurrentResourceRelease($resource, $workspace);
                    $intent = 0;
                }
            }
            if ($previous !== []) {
                (clone ObjectManager::getInstance(ThemeScopeVersionResourceSnapshot::class))->clearData()->clearQuery()->load((int)$previous['snapshot_id'])->delete();
            }
            $this->writeResource($identity, $resource, $intent, $release, $intent > 0 || $release > 0);
        }
        if (!isset($seen[$context->identityHash()]) && !isset($existing[$context->identityHash()])) { $this->writeResource($identity, $context, 0, 0, false); }
        $this->writeHead($version, '');
    }

    /** Freeze a proven current legacy intent at a new R; never repair the missing old baseline. */
    public function captureUnresolvedCurrentIntent(ThemeScopeVersion $version, ThemeEditorContext $context): ?ThemeVersionIdentity
    {
        $identity = $version->toVersionIdentity();
        if ($identity->mode !== ThemeVersionIdentity::MODE_DRAFT) { return null; }
        return \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityOwnerLock::write($identity, function () use ($identity, $context): ?ThemeVersionIdentity {
            $current = ObjectManager::getInstance(ThemeScopeVersionService::class)->getCurrent(
                $identity->themeId, $identity->canonicalScope, $identity->storeMode, $identity->area);
            $fresh = (clone ObjectManager::getInstance(ThemeScopeVersion::class))->clearData()->clearQuery()->load($identity->themeVersionId);
            if ($current?->getVersionId() !== $identity->themeVersionId || $fresh->toVersionIdentity()->cacheKey() !== $identity->cacheKey()) {
                throw new \RuntimeException('theme_version_content_revision_conflict');
            }
            $owner = new ThemeVersionIdentity($context->themeId, $context->scope->storageScope, $context->scope->storeMode, $context->area);
            if ($owner->ownerHash() !== $identity->ownerHash()) { throw new \InvalidArgumentException('theme_version_context_owner_mismatch'); }
            return ObjectManager::getInstance(\Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface::class)
                ->runWrite($fresh->getConnection(), function () use ($identity, $context, $fresh): ?ThemeVersionIdentity {
                    $head = $this->head($identity);
                    if ($head === null) { return null; }
                    $resources = $this->resources($identity);
                    $missing = [];
                    foreach ($resources as $resource) {
                        if ((int)($resource['intent_revision_id'] ?? 0) < 1) { continue; }
                        $key = json_decode((string)$resource['resource_key_json'], true);
                        $read = $this->read($identity, $this->contextFor($identity, $key));
                        if (!empty($read['resolved'])) { continue; }
                        if (($read['reason'] ?? '') !== 'historical_intent_reference_missing') {
                            throw new \RuntimeException((string)$read['reason']);
                        }
                        $missing[(string)$resource['resource_identity_hash']] = $resource;
                    }
                    if ($missing === []) { return null; }
                    $filters = ['theme_id'=>$identity->themeId, 'scope'=>$identity->canonicalScope,
                        'store_mode'=>$identity->storeMode, 'area'=>$identity->area];
                    $rows = $this->rows(ThemeScopeWorkspace::class, $filters + ['theme_version_id'=>$identity->themeVersionId]);
                    if ($rows === []) { $rows = $this->rows(ThemeScopeWorkspace::class, $filters + ['theme_version_id'=>0]); }
                    $inputs = [];
                    foreach ($this->migrationResources($identity, $context, $rows, false) as $input) {
                        $inputs[$input['context']->identityHash()] = $input;
                    }
                    // All broken references need an actual revision and readable owned commands,
                    // not merely an equal integer in the mutable workspace cursor.
                    $hasIntent = [];
                    foreach ($missing as $hash => $resource) {
                        if (!isset($inputs[$hash]) || $inputs[$hash]['intent'] !== (int)$resource['intent_revision_id']) { return null; }
                        $revision = (clone ObjectManager::getInstance(\Weline\Theme\Model\ThemeScopeRevision::class))
                            ->clearData()->clearQuery()->load($inputs[$hash]['intent']);
                        if ($revision->getId() !== $inputs[$hash]['intent']
                            || (int)$revision->getData('workspace_id') !== (int)$inputs[$hash]['row']['workspace_id']) { return null; }
                        $patches = $this->rows(\Weline\Theme\Model\ThemeScopePatch::class, ['revision_id'=>$inputs[$hash]['intent']]);
                        foreach ($patches as $patch) {
                            if ((int)$patch['workspace_id'] !== (int)$inputs[$hash]['row']['workspace_id']) { return null; }
                        }
                        // The existing capture helper parses every command before saving.
                        // Zero commands is an observed restore-to-inherit, not new intent.
                        $hasIntent[$hash] = $patches !== [];
                    }
                    $replacements = [];
                    $workspaceService = ObjectManager::getInstance(ThemeScopedWorkspace::class);
                    foreach ($missing as $hash => $resource) {
                        $input = $inputs[$hash];
                        $workspace = (clone ObjectManager::getInstance(ThemeScopeWorkspace::class))->clearData()->setData($input['row']);
                        // A new observation must not become evidence for the old intent via release lookup.
                        $replacements[$hash] = $workspaceService->captureCurrentResourceRelease($input['context'], $workspace, false);
                    }
                    foreach ($resources as $resource) {
                        $hash = (string)$resource['resource_identity_hash'];
                        unset($resource['snapshot_id'], $resource['create_time']);
                        $resource['content_revision'] = $identity->contentRevision + 1;
                        if (isset($replacements[$hash])) {
                            $resource['intent_revision_id'] = null;
                            $resource['release_id'] = $replacements[$hash];
                            $resource['resource_key_json'] = json_encode($inputs[$hash]['context']->toArray()
                                + ['has_intent'=>$hasIntent[$hash], 'package_default'=>false], JSON_THROW_ON_ERROR);
                            $resource['source_fingerprint'] = hash('sha256', json_encode([0, $replacements[$hash], $hasIntent[$hash]], JSON_THROW_ON_ERROR));
                        }
                        (clone ObjectManager::getInstance(ThemeScopeVersionResourceSnapshot::class))->clearData()->clearQuery()->setData($resource)->save();
                    }
                    $descriptor = json_decode((string)$head['package_default_json'], true);
                    $fresh->setChromePayload((array)json_decode((string)$head['chrome_intent_json'], true));
                    $fresh->setContentRevision($identity->contentRevision + 1)->save();
                    $this->writeHead($fresh, 'current_legacy_intent_observed', (array)($descriptor['configuration'] ?? []), (array)($descriptor['omissions'] ?? []));
                    return $fresh->toVersionIdentity();
                });
        });
    }

    /** Select the same exact identity as the current Workspace reader, never the newest row. */
    private function migrationResources(ThemeVersionIdentity $identity, ThemeEditorContext $context, array $rows, bool $publishedOnly): array
    {
        $groups = [];
        foreach ($rows as $row) {
            if (($row['resource_type'] ?? '') === ThemeEditorContext::RESOURCE_THEME_BINDING) { continue; }
            $resource = new ThemeEditorContext($context->scope, $context->area, (string)$row['resource_type'], $context->themeId,
                (string)($row['layout_type'] ?? 'default'), (string)($row['layout_option'] ?? 'default'),
                (string)($row['locale'] ?? 'default'), (string)($row['target_type'] ?? 'global'), (int)($row['target_id'] ?? 0));
            $hash = $resource->identityHash();
            $groups[$hash]['context'] = $resource;
            $groups[$hash]['rows'][] = $row;
        }
        $publishedOnly = $publishedOnly || $identity->mode === ThemeVersionIdentity::MODE_FORMAL;
        $out = [];
        foreach ($groups as $hash => $group) {
            $candidates = $group['rows'];
            $exact = array_values(array_filter($candidates, static fn(array $row): bool => ($row['identity_hash'] ?? '') === $hash));
            if (count($candidates) > 1 && count($exact) !== 1) {
                throw new \RuntimeException('theme_version_current_resource_ambiguous: V' . $identity->themeVersionId
                    . '/R' . $identity->contentRevision . ' owner=' . $identity->themeId . '/' . $identity->canonicalScope
                    . '/' . $identity->storeMode . '/' . $identity->area . ' resource=' . $hash
                    . ' workspaces=' . implode(',', array_column($candidates, 'workspace_id')));
            }
            $row = $exact[0] ?? $candidates[0];
            $resource = $group['context'];
            // Locale is part of I18N identity only. Preserve the authoritative
            // canonical row's context rather than a collided legacy locale row.
            if ($resource->resourceType !== ThemeEditorContext::RESOURCE_I18N) { $resource = $resource->withLocale('default'); }
            $release = (int)($row['published_release_id'] ?? 0);
            if ($publishedOnly && $release < 1) { continue; }
            $out[] = ['row'=>$row, 'context'=>$resource, 'intent'=>$publishedOnly ? 0 : (int)($row['draft_revision_id'] ?? 0), 'release'=>$release];
        }
        return $out;
    }

    /** Advance from freshly loaded DB R, copying unchanged references into R+1. */
    public function advance(ThemeScopeVersion $version, ThemeEditorContext $context, array $saved, string $actor): ThemeVersionIdentity
    {
        $old = $version->toVersionIdentity();
        $oldHead = $this->head($old);
        $oldDescriptor = json_decode((string)($oldHead['package_default_json'] ?? '{}'), true);
        $omissions = $oldDescriptor['omissions'] ?? $this->captureOmissions($version);
        if ($context->resourceType === ThemeEditorContext::RESOURCE_LAYOUT) {
            $previousPayload = $this->read($old, $context)['payload'] ?? [];
            $nextPayload = $saved['draft_payload'] ?? $saved['payload'] ?? [];
            $omissions[$context->layoutType] = (new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityInputResolver())->omissionsForNodes(
                (array)($previousPayload['nodes'] ?? []), (array)($nextPayload['nodes'] ?? []), (array)($omissions[$context->layoutType] ?? []));
        }
        $next = $old->withVersion($old->themeVersionId, ThemeVersionIdentity::MODE_DRAFT, $old->contentRevision + 1);
        foreach ($this->resources($old) as $row) {
            if ((string)$row['resource_identity_hash'] === $context->identityHash()) { continue; }
            unset($row['snapshot_id'], $row['create_time']);
            $row['content_revision'] = $next->contentRevision;
            (clone ObjectManager::getInstance(ThemeScopeVersionResourceSnapshot::class))->clearData()->clearQuery()->setData($row)->save();
        }
        $this->writeResource($next, $context, (int)($saved['revision_id'] ?? 0), (int)($saved['release_id'] ?? 0),
            !empty($saved['changes']) || !empty($saved['owned_paths']));
        if ($context->resourceType === ThemeEditorContext::RESOURCE_LAYOUT) {
            $payload = $saved['draft_payload'] ?? $saved['payload'] ?? [];
            $nodes = is_array($payload['nodes'] ?? null) ? $payload['nodes'] : $payload;
            $chrome = ObjectManager::getInstance(\Weline\Theme\Service\LayoutEntity\ThemeLayoutSlotTreeBuilder::class)->filterChromeNodes($nodes);
            if ($chrome !== [] || ObjectManager::getInstance(\Weline\Theme\Service\SharedChromeService::class)->isChromeCarrierPageType($context->layoutType)) {
                $version->setChromePayload($chrome);
            }
        }
        // The write lock spans this update and publication; reloading rejects stale callers.
        $fresh = (clone $version)->clearData()->clearQuery()->load($old->themeVersionId);
        if ($fresh->getContentRevision() !== $old->contentRevision) {
            throw new \RuntimeException('theme_version_content_revision_conflict');
        }
        $version->setContentRevision($next->contentRevision)->save();
        $this->writeHead($version, $actor, $oldDescriptor['configuration'] ?? null, $omissions);
        return $next;
    }

    public function advanceConfiguration(ThemeScopeVersion $version, array $configuration): ThemeVersionIdentity
    {
        $old = $version->toVersionIdentity();
        $fresh = (clone $version)->clearData()->clearQuery()->load($old->themeVersionId);
        if ($fresh->getContentRevision() !== $old->contentRevision) { throw new \RuntimeException('theme_version_content_revision_conflict'); }
        foreach ($this->resources($old) as $row) {
            unset($row['snapshot_id'], $row['create_time']);
            $row['content_revision'] = $old->contentRevision + 1;
            (clone ObjectManager::getInstance(ThemeScopeVersionResourceSnapshot::class))->clearData()->clearQuery()->setData($row)->save();
        }
        $version->setContentRevision($old->contentRevision + 1)->save();
        $oldHead = $this->head($old);
        $descriptor = json_decode((string)($oldHead['package_default_json'] ?? '{}'), true);
        $this->writeHead($version, 'theme_configuration_save', $configuration, $descriptor['omissions'] ?? null);
        return $version->toVersionIdentity();
    }

    public function read(ThemeVersionIdentity $identity, ThemeEditorContext $context): array
    {
        $head = $this->head($identity);
        if ($head === null) { return $this->missing('historical_revision_head_missing'); }
        $rows = $this->rows(ThemeScopeVersionResourceSnapshot::class, [
            'theme_version_id' => $identity->themeVersionId, 'content_revision' => $identity->contentRevision,
            'resource_identity_hash' => $context->identityHash(),
        ]);
        $snapshot = $rows[0] ?? null;
        $key = $snapshot === null ? [] : json_decode((string)($snapshot['resource_key_json'] ?? '{}'), true);
        if ($snapshot === null || !empty($key['package_default'])) {
            $descriptor = json_decode((string)($head['package_default_json'] ?? '{}'), true);
            if (empty($descriptor['current_package_defaults'])) { return $this->missing('historical_resource_snapshot_missing'); }
            $payload = ObjectManager::getInstance(\Weline\Theme\Api\Scoped\ThemeScopedResourceAdapterInterface::class)->loadBase($context);
            return ['resolved' => true, 'reason' => '', 'payload' => $payload, 'release_id' => null,
                'draft_revision_id' => 0, 'has_intent' => false];
        }
        $workspace = ObjectManager::getInstance(ThemeScopedWorkspace::class);
        if (is_array($key['reference_context'] ?? null)) {
            $reference = $key['reference_context'];
            $referenceScope = is_array($reference['scope'] ?? null) ? $reference['scope'] : [];
            $referenceOwner = new ThemeVersionIdentity($identity->themeId, (string)($referenceScope['storage_scope'] ?? $identity->canonicalScope),
                (string)($referenceScope['store_mode'] ?? $identity->storeMode), $identity->area, $identity->themeVersionId, $identity->mode, $identity->contentRevision);
            $context = $this->contextFor($referenceOwner, $reference);
        }
        return $this->readReferencedPayload($snapshot,
            static fn(int $id): array => $workspace->readHistoricalLayoutRevision($context, $id),
            static fn(int $id): ?array => $workspace->readHistoricalLayoutRelease($context, $id))
            + ['has_intent' => !empty($key['has_intent'])];
    }

    /** Reference precedence is independent of the currently selected workspace/release. */
    public function readReferencedPayload(array $snapshot, callable $intentReader, callable $releaseReader): array
    {
        $intent = (int)($snapshot['intent_revision_id'] ?? 0);
        $release = (int)($snapshot['release_id'] ?? 0);
        if ($intent > 0) {
            $read = $intentReader($intent);
            if (empty($read['resolved']) || !is_array($read['draft_payload'] ?? null)) { return $this->missing('historical_intent_reference_missing'); }
            return ['resolved' => true, 'reason' => '', 'payload' => $read['draft_payload'], 'release_id' => null, 'draft_revision_id' => $intent];
        }
        if ($release > 0) {
            $payload = $releaseReader($release);
            if (!is_array($payload)) { return $this->missing('historical_release_reference_missing'); }
            return ['resolved' => true, 'reason' => '', 'payload' => $payload, 'release_id' => $release, 'draft_revision_id' => 0];
        }
        return $this->missing('historical_resource_reference_missing');
    }

    public function resources(ThemeVersionIdentity $identity): array
    {
        return $this->readSnapshotInputs($identity, 'resources', fn(): array =>
            $this->rows(ThemeScopeVersionResourceSnapshot::class, ['theme_version_id' => $identity->themeVersionId, 'content_revision' => $identity->contentRevision]));
    }

    public function head(ThemeVersionIdentity $identity): ?array
    {
        return $this->readSnapshotInputs($identity, 'head', fn(): ?array =>
            $this->rows(ThemeScopeVersionRevision::class, ['theme_version_id' => $identity->themeVersionId, 'content_revision' => $identity->contentRevision])[0] ?? null);
    }

    private function readSnapshotInputs(ThemeVersionIdentity $identity, string $part, callable $reader): mixed
    {
        // 事务内的值可能回滚，不能进入请求快照，也不能沿用事务前的读取。
        if (TransactionContext::activeTransactionConnectionCount() > 0) {
            $this->forgetSnapshotInputs($identity);
            return $reader();
        }
        return ObjectManager::getInstance(StorefrontScopeHotCache::class)->rememberForRequest(
            'theme.version_snapshot.' . $part, $identity->cacheKey(), $reader);
    }

    private function forgetSnapshotInputs(ThemeVersionIdentity $identity): void
    {
        $cache = ObjectManager::getInstance(StorefrontScopeHotCache::class);
        foreach (['head', 'resources'] as $part) {
            $cache->forgetRequestMemo('theme.version_snapshot.' . $part, $identity->cacheKey());
        }
    }

    /** Selected resources take D; all other references remain prepared-time P. */
    public function composePublishedReferences(array $draft, array $published, array|string $selected): array
    {
        $out = [];
        foreach ($published as $row) {
            if (!$this->publicationSelects($row, $selected)) { $out[(string)$row['resource_identity_hash']] = $row; }
        }
        foreach ($draft as $row) {
            if ($this->publicationSelects($row, $selected)) { $out[(string)$row['resource_identity_hash']] = $row; }
        }
        ksort($out, SORT_STRING);
        return array_values($out);
    }

    public function publicationSelects(array $row, array|string $selected): bool
    {
        if ($selected === 'all') { return true; }
        if (in_array((string)($row['resource_identity_hash'] ?? ''), $selected, true)) { return true; }
        $key = json_decode((string)($row['resource_key_json'] ?? '{}'), true);
        $type = (string)($row['resource_type'] ?? $key['resource_type'] ?? '');
        if (in_array($type, ['layout', 'meta', 'i18n'], true)) {
            return in_array('layout:' . (string)($key['layout_type'] ?? ''), $selected, true);
        }
        return in_array($type, $selected, true);
    }

    /**
     * Called in the publication transaction under the owner lock. Preserve D's
     * recorded R before assembling the selected view at a fresh R of the same
     * version ID (N=D). Selection is changed by the existing publication caller.
     */
    public function preparePublication(ThemeScopeVersion $draft, ThemeEditorContext $context, array|string $selected,
        bool $hasRemainder, array $releaseReceipts = []): array
    {
        $old = $draft->toVersionIdentity();
        $head = $this->head($old);
        $descriptor = json_decode((string)($head['package_default_json'] ?? '{}'), true);
        if ($head === null || empty($descriptor['current_package_defaults'])) { throw new \RuntimeException('historical_revision_head_missing'); }
        $draftRows = $this->resources($old);
        foreach ($releaseReceipts as $receipt) {
            $claims = (array)($receipt['context'] ?? []);
            if (($claims['resource_type'] ?? '') === ThemeEditorContext::RESOURCE_THEME_BINDING) { continue; }
            $resource = $this->contextFor($old, $claims);
            $replacement = ['resource_identity_hash'=>$resource->identityHash(), 'resource_type'=>$resource->resourceType,
                'resource_key_json'=>json_encode($resource->toArray()+['has_intent'=>true,'package_default'=>false], JSON_THROW_ON_ERROR),
                'intent_revision_id'=>null, 'release_id'=>(int)($receipt['release_id'] ?? 0),
                'source_fingerprint'=>hash('sha256', 'release:' . (int)($receipt['release_id'] ?? 0))];
            if ($replacement['release_id'] < 1) { continue; }
            $draftRows = array_values(array_filter($draftRows, static fn(array $row): bool => $row['resource_identity_hash'] !== $resource->identityHash()));
            $draftRows[] = $replacement;
        }
        $prime = $hasRemainder ? $this->copyToDetachedDraft($draft, $old, $head) : null;
        if ($selected === 'all' && $releaseReceipts === []) { return ['version'=>$draft, 'draft_prime'=>$prime]; }

        $published = ObjectManager::getInstance(ThemeScopeVersionService::class)
            ->getPublished($old->themeId, $old->canonicalScope, $old->storeMode, $old->area);
        $publishedRows = [];
        $publishedHead = null;
        if ($published !== null) {
            $publishedContext = $this->contextFor($published->toVersionIdentity(), $context->toArray());
            \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityOwnerLock::write($published->toVersionIdentity(),
                fn() => $this->captureCurrent($published, $publishedContext));
            $publishedHead = $this->head($published->toVersionIdentity());
            $publishedRows = $this->remapRows($this->resources($published->toVersionIdentity()), $old);
        }
        $publishedDescriptor = json_decode((string)($publishedHead['package_default_json'] ?? '{}'), true);
        $selectedChrome = $selected === 'all' || in_array('chrome', $selected, true);
        $chrome = json_decode((string)(($selectedChrome ? $head : $publishedHead)['chrome_intent_json'] ?? '[]'), true);
        $configuration = $this->publicationConfiguration((array)($descriptor['configuration'] ?? []),
            (array)($publishedDescriptor['configuration'] ?? []), $selected);
        $omissions = (array)($publishedDescriptor['omissions'] ?? []);
        $chromeService = ObjectManager::getInstance(\Weline\Theme\Service\SharedChromeService::class);
        foreach ((array)($descriptor['omissions'] ?? []) as $type => $entries) {
            $pageSelected = $selected === 'all' || in_array('layout:' . $type, $selected, true);
            if (!$pageSelected) {
                foreach ($draftRows as $row) {
                    $key = json_decode((string)$row['resource_key_json'], true);
                    if (($row['resource_type'] ?? '') === 'layout' && ($key['layout_type'] ?? '') === $type && $this->publicationSelects($row, $selected)) { $pageSelected = true; break; }
                }
            }
            $selectOmission = static fn(array $entry): bool => $chromeService->isChromeTarget('', (string)($entry['slot_id'] ?? $entry['slot'] ?? '')) ? $selectedChrome : $pageSelected;
            $omissions[$type] = array_values(array_merge(
                array_filter((array)($omissions[$type] ?? []), static fn(array $entry): bool => !$selectOmission($entry)),
                array_filter((array)$entries, $selectOmission)));
        }
        $fresh = (clone $draft)->clearData()->clearQuery()->load($old->themeVersionId);
        if ($fresh->getContentRevision() !== $old->contentRevision) { throw new \RuntimeException('theme_version_content_revision_conflict'); }
        $draft->setContentRevision($old->contentRevision + 1)->setChromePayload(is_array($chrome) ? $chrome : [])->save();
        $this->insertRows($this->composePublishedReferences($draftRows, $publishedRows, $selected), $draft->toVersionIdentity());
        $this->writeHead($draft, 'publish_selected_resources', $configuration, $omissions);
        return ['version'=>$draft, 'draft_prime'=>$prime];
    }

    private function copyToDetachedDraft(ThemeScopeVersion $source, ThemeVersionIdentity $identity, array $head): ThemeScopeVersion
    {
        $rows = $this->rows(ThemeScopeVersion::class, ['theme_id'=>$identity->themeId, 'scope'=>$identity->canonicalScope]);
        $number = max(array_map(static fn(array $row): int => (int)($row['version_number'] ?? 0), $rows) ?: [0]) + 1;
        $data = $source->getData();
        unset($data['version_id'], $data['create_time'], $data['update_time']);
        $data = array_replace($data, ['version_number'=>$number, 'version_name'=>'v'.$number,
            'lifecycle'=>ThemeScopeVersion::LIFECYCLE_DRAFT, 'content_revision'=>1, 'is_current'=>0, 'is_published'=>0,
            'creation_source_kind'=>'continue_current', 'creation_source_version_id'=>$source->getVersionId(), 'parent_version_id'=>$source->getVersionId()]);
        $prime = (clone ObjectManager::getInstance(ThemeScopeVersion::class))->clearData()->clearQuery()->setData($data);
        $prime->setChromePayload((array)json_decode((string)($head['chrome_intent_json'] ?? '[]'), true))->save();
        $this->insertRows($this->resources($identity), $prime->toVersionIdentity());
        $descriptor = json_decode((string)$head['package_default_json'], true);
        $this->writeHead($prime, 'publication_remainder', $descriptor['configuration'] ?? [], $descriptor['omissions'] ?? []);
        return $prime;
    }

    private function remapRows(array $rows, ThemeVersionIdentity $target): array
    {
        foreach ($rows as &$row) {
            $key = json_decode((string)$row['resource_key_json'], true);
            $context = $this->contextFor($target, $key);
            $row['resource_identity_hash'] = $context->identityHash();
            $row['resource_key_json'] = json_encode($context->toArray()+['reference_context'=>$key['reference_context'] ?? $key,
                'has_intent'=>!empty($key['has_intent']), 'package_default'=>!empty($key['package_default'])], JSON_THROW_ON_ERROR);
        }
        unset($row);
        return $rows;
    }

    private function insertRows(array $rows, ThemeVersionIdentity $identity): void
    {
        foreach ($this->remapRows($rows, $identity) as $row) {
            unset($row['snapshot_id'], $row['create_time']);
            $row['theme_version_id'] = $identity->themeVersionId;
            $row['content_revision'] = $identity->contentRevision;
            (clone ObjectManager::getInstance(ThemeScopeVersionResourceSnapshot::class))->clearData()->clearQuery()->setData($row)->save();
        }
    }

    private function publicationConfiguration(array $draft, array $published, array|string $selected): array
    {
        if ($selected === 'all' || in_array('appearance', $selected, true)) { return $draft; }
        $isSelected = static function (string $key) use ($selected): bool {
            if (str_starts_with($key, 'partials.')) { return in_array('chrome', $selected, true); }
            foreach ($selected as $resource) {
                if (str_starts_with($resource, 'layout:') && str_starts_with($key, 'layouts.' . substr($resource, 7) . '.')) { return true; }
            }
            return false;
        };
        foreach (array_unique(array_merge(array_keys($draft['params'] ?? []), array_keys($published['params'] ?? []))) as $key) {
            if (!$isSelected($key)) { continue; }
            unset($published['params'][$key]);
            if (array_key_exists($key, $draft['params'] ?? [])) { $published['params'][$key] = $draft['params'][$key]; }
        }
        foreach (array_unique(array_merge(array_keys($draft['locale_params'] ?? []), array_keys($published['locale_params'] ?? []))) as $locale) {
            foreach (array_unique(array_merge(array_keys($draft['locale_params'][$locale] ?? []), array_keys($published['locale_params'][$locale] ?? []))) as $key) {
                if (!$isSelected($key)) { continue; }
                unset($published['locale_params'][$locale][$key]);
                if (array_key_exists($key, $draft['locale_params'][$locale] ?? [])) { $published['locale_params'][$locale][$key] = $draft['locale_params'][$locale][$key]; }
            }
        }
        if (in_array('chrome', $selected, true)) { $published['partial_options'] = $draft['partial_options'] ?? []; }
        return $published;
    }

    private function writeHead(ThemeScopeVersion $version, string $actor, ?array $configuration = null, ?array $omissions = null): void
    {
        $identity = $version->toVersionIdentity();
        $this->forgetSnapshotInputs($identity);
        if ($this->head($identity) !== null) { return; }
        $resources = $this->resources($identity);
        if ($configuration === null) {
            $context = ObjectManager::getInstance(\Weline\Theme\Service\ThemeRuntimeLayoutResolver::class)->buildContext($identity->themeId,'homepage',$identity->area,
                ['scope'=>$identity->canonicalScope,'store_mode'=>$identity->storeMode]);
            $configuration = (new \Weline\Theme\Service\LayoutEntity\ThemeLayoutConfigurationSnapshot())->capture($context);
        }
        (clone ObjectManager::getInstance(ThemeScopeVersionRevision::class))->clearData()->clearQuery()->setData([
            'theme_version_id' => $identity->themeVersionId, 'content_revision' => $identity->contentRevision,
            'base_version_id' => max(1, (int)$version->getCreationSourceVersionId() ?: $identity->themeVersionId),
            'kind' => $identity->mode === 'draft' ? 'draft' : 'sealed',
            'chrome_intent_json' => json_encode($version->getChromePayload(), JSON_THROW_ON_ERROR),
            'package_default_json' => json_encode(['current_package_defaults' => true, 'owner' => $identity->ownerHash(), 'configuration' => $configuration, 'omissions' => $omissions ?? $this->captureOmissions($version)], JSON_THROW_ON_ERROR),
            'manifest_digest' => hash('sha256', json_encode($resources, JSON_THROW_ON_ERROR)), 'actor_id' => $actor,
        ])->save();
        $this->forgetSnapshotInputs($identity);
    }

    private function writeResource(ThemeVersionIdentity $identity, ThemeEditorContext $context, int $intent, int $release, bool $hasIntent): void
    {
        (clone ObjectManager::getInstance(ThemeScopeVersionResourceSnapshot::class))->clearData()->clearQuery()->setData([
            'theme_version_id' => $identity->themeVersionId, 'content_revision' => $identity->contentRevision,
            'resource_identity_hash' => $context->identityHash(), 'resource_type' => $context->resourceType,
            'resource_key_json' => json_encode($context->toArray() + ['has_intent' => $hasIntent, 'package_default' => $intent < 1 && $release < 1], JSON_THROW_ON_ERROR),
            'intent_revision_id' => $intent > 0 ? $intent : null, 'release_id' => $release > 0 ? $release : null,
            'source_fingerprint' => hash('sha256', json_encode([$intent, $release, $hasIntent], JSON_THROW_ON_ERROR)),
        ])->save();
        $this->forgetSnapshotInputs($identity);
    }

    private function captureOmissions(ThemeScopeVersion $version): array
    {
        $out = [];
        $types = array_keys(\Weline\Theme\Model\ThemeLayout::getPageTypes());
        foreach ($this->resources($version->toVersionIdentity()) as $resource) {
            $key = json_decode((string)$resource['resource_key_json'], true);
            if (isset($key['layout_type'])) { $types[] = $key['layout_type']; }
        }
        $service = ObjectManager::getInstance(\Weline\Theme\Service\WidgetDefaultInjectionService::class);
        foreach (array_unique($types) as $type) { $out[$type] = $service->uninstalledInjectionsForVersion($version->getThemeId(), $type, $version->getVersionId()); }
        return $out;
    }

    private function missing(string $reason): array
    {
        return ['resolved' => false, 'reason' => $reason, 'payload' => null, 'release_id' => null, 'draft_revision_id' => 0];
    }

    private function contextFor(ThemeVersionIdentity $identity, array $key): ThemeEditorContext
    {
        $context = ObjectManager::getInstance(\Weline\Theme\Service\ThemeRuntimeLayoutResolver::class)->buildContext($identity->themeId,
            (string)($key['layout_type'] ?? 'homepage'), $identity->area,
            ['scope' => $identity->canonicalScope, 'store_mode' => $identity->storeMode, 'layout_option' => $key['layout_option'] ?? 'default',
                'target_type' => $key['target_type'] ?? 'global', 'target_id' => (int)($key['target_id'] ?? 0)]);
        return $context->withResource((string)($key['resource_type'] ?? 'layout'))->withLocale((string)($key['locale'] ?? 'default'));
    }

    private function rows(string $model, array $filters): array
    {
        $query = (clone ObjectManager::getInstance($model))->clearData()->clearQuery();
        foreach ($filters as $field => $value) { $query->where($field, $value); }
        $rows = $query->select()->fetchArray();
        return !is_array($rows) || $rows === [] ? [] : (array_is_list($rows) ? $rows : [$rows]);
    }
}
