<?php
declare(strict_types=1);
namespace Weline\Theme\Service\Version;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Model\ThemeScopeVersionSelection;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator;
use Weline\Theme\Service\ThemeScopeVersionService;

/** Adapts the existing resource-release transaction to the scope publication plan. */
final class ThemeScopedVersionPublicationBridge
{
    public function publish(ThemeEditorContext $context, array $receipts): array
    {
        $receipts = array_values(array_filter($receipts, static fn(array $row): bool =>
            ($row['context']['resource_type'] ?? '') !== ThemeEditorContext::RESOURCE_THEME_BINDING
            && (int)($row['release_id'] ?? 0) > 0));
        if ($receipts === [] || $context->themeId < 1) { return []; }
        $versions = ObjectManager::getInstance(ThemeScopeVersionService::class);
        $snapshots = ObjectManager::getInstance(ThemeVersionResourceSnapshotService::class);
        $planner = ObjectManager::getInstance(ThemeVersionPublicationService::class);
        $version = $versions->getCurrent($context->themeId, $context->scope->storageScope, $context->scope->storeMode, $context->area);
        $alreadyPublished = $versions->getPublished($context->themeId, $context->scope->storageScope, $context->scope->storeMode, $context->area);
        if ($alreadyPublished !== null && $alreadyPublished->getScope() === $context->scope->storageScope) {
            $references = array_column($snapshots->resources($alreadyPublished->toVersionIdentity()), null, 'resource_identity_hash');
            $matches = true;
            foreach ($receipts as $receipt) {
                $hash = (string)($receipt['identity_hash'] ?? $receipt['context']['identity_hash']);
                if ((int)($references[$hash]['release_id'] ?? 0) !== (int)$receipt['release_id']) { $matches = false; break; }
            }
            if ($matches) { return ['theme_version_id'=>$alreadyPublished->getVersionId(), 'content_revision'=>$alreadyPublished->getContentRevision(),
                'published_version_id'=>$alreadyPublished->getVersionId(), 'draft_version_id'=>$version?->getVersionId(), 'idempotent'=>true]; }
        }
        if ($version === null || $version->getLifecycle() === ThemeScopeVersion::LIFECYCLE_SEALED) { $version = $snapshots->beginWrite($context); }
        $version = (clone $version)->clearData()->clearQuery()->load($version->getVersionId());
        $snapshots->captureCurrent($version, $context);
        $identity = $version->toVersionIdentity();
        $published = $versions->getPublished($identity->themeId, $identity->canonicalScope, $identity->storeMode, $identity->area);
        $publishedRows = $published === null ? [] : $snapshots->resources($published->toVersionIdentity());
        $publishedByHash = array_column($publishedRows, null, 'resource_identity_hash');
        $selected = [];
        foreach ($receipts as $receipt) {
            $selected[] = (string)($receipt['identity_hash'] ?? $receipt['context']['identity_hash']);
            if (($receipt['context']['resource_type'] ?? '') === 'layout' && ($receipt['context']['layout_type'] ?? '') === 'homepage') { $selected[] = 'chrome'; }
        }
        $draftResources = $selected;
        foreach ($snapshots->resources($identity) as $row) {
            $hash = (string)$row['resource_identity_hash'];
            $before = $publishedByHash[$hash] ?? [];
            if ([(int)($row['intent_revision_id'] ?? 0),(int)($row['release_id'] ?? 0)] !==
                [(int)($before['intent_revision_id'] ?? 0),(int)($before['release_id'] ?? 0)]) { $draftResources[] = $hash; }
        }
        $draftHead = $snapshots->head($identity);
        $publishedHead = $published === null ? null : $snapshots->head($published->toVersionIdentity());
        if (($draftHead['chrome_intent_json'] ?? '[]') !== ($publishedHead['chrome_intent_json'] ?? '[]')) { $draftResources[] = 'chrome'; }
        $draftDescriptor = json_decode((string)($draftHead['package_default_json'] ?? '{}'), true);
        $publishedDescriptor = json_decode((string)($publishedHead['package_default_json'] ?? '{}'), true);
        if (($draftDescriptor['configuration'] ?? []) !== ($publishedDescriptor['configuration'] ?? [])) { $draftResources[] = 'appearance'; }
        $options = ['publish_set'=>array_values(array_unique($selected)), 'draft_resources'=>array_values(array_unique($draftResources)),
            'prepared_published_resources'=>array_keys($publishedByHash)];
        $plan = $planner->planPublishedResources($options);
        $prepared = $snapshots->preparePublication($version, $context, $options['publish_set'], $plan['remaining_draft_resources'] !== [], $receipts);
        $version = $prepared['version'];
        $prime = $prepared['draft_prime'];
        $version->setLifecycle(ThemeScopeVersion::LIFECYCLE_SEALED)->save();
        ObjectManager::getInstance(ThemeLayoutEntityBakeCoordinator::class)->bakePublishArtifactsForVersion($context, $version->getVersionId(),
            ['draft_prime_identity'=>$prime?->toVersionIdentity()->toArray()]);

        $selection = (clone ObjectManager::getInstance(ThemeScopeVersionSelection::class))->clearData()->clearQuery()
            ->where('theme_id',$identity->themeId)->where('scope',$identity->canonicalScope)->where('store_mode',$identity->storeMode)->where('area',$identity->area)->find()->fetch();
        $result = $planner->publish($version->toVersionIdentity(), $options + [
            'actual_selection_revision'=>$selection->getSelectionRevision(), 'expected_selection_revision'=>$selection->getSelectionRevision(),
            'current_published_version_id'=>$selection->getPublishedVersionId(), 'current_draft_version_id'=>$identity->themeVersionId,
            'sealed_content_revision'=>$version->getContentRevision(), 'allocated_draft_prime_id'=>$prime?->getVersionId() ?? 0]);
        $selection->setData(['theme_id'=>$identity->themeId,'scope'=>$identity->canonicalScope,'store_mode'=>$identity->storeMode,'area'=>$identity->area,
            'published_version_id'=>$version->getVersionId(),'draft_version_id'=>$prime?->getVersionId(),'selection_revision'=>$result['selection_revision']])->save();
        if ($prime === null) {
            $selectionId = $selection->getSelectionId();
            $selection->clearData()->clearQuery()->where('selection_id',$selectionId)->update(['draft_version_id'=>null])->fetch();
        }
        $versions->invalidateOwner($identity->themeId,$identity->canonicalScope,$identity->storeMode,$identity->area);
        return $result;
    }
}
