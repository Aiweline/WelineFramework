<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Scoped;

use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Scoped\ThemePatchCommand;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\ThemeScopeReleaseBatch;
use Weline\Theme\Model\ThemeScopeWorkspace;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\SharedChromeService;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemeLayoutVersionService;
use Weline\Theme\Service\ThemeRuntimeCacheCleaner;
use Weline\Theme\Service\Version\ThemeVersionSnapshotBuilder;

/** Validates HTTP-shaped commands before invoking the scoped workspace API. */
final class ThemeScopedWorkspaceRequestService
{
    private const MAX_COMMANDS = 512;
    private const MAX_CHANGES_JSON_BYTES = 2_097_152;
    private const MAX_COMMAND_JSON_BYTES = 524_288;
    private const MAX_NOTE_BYTES = 255;

    public function __construct(
        private readonly ThemeEditorContextFactory $contexts,
        private readonly ThemeScopedWorkspaceInterface $workspace,
        private readonly WelineTheme $themes,
        private readonly ThemeContextService $themeContext,
        private readonly ThemeRuntimeCacheCleaner $cacheCleaner,
        private readonly ThemeLayoutVersionService $layoutVersions,
        private readonly SharedChromeService $sharedChrome,
        private readonly ThemeScopeWorkspace $workspaceRows,
        private readonly ThemeVersionSnapshotBuilder $versionSnapshots,
    ) {
    }

    /** @param array<string,mixed> $input */
    public function load(array $input): array
    {
        $context = $this->contexts->fromInput($input);
        return $this->workspace->load($context, true);
    }

    /** @param array<string,mixed> $input */
    public function apply(array $input, string $actorId, string $actorName = ''): array
    {
        $context = $this->contexts->fromInput($input);
        // 绑定补丁落库前先确认该绑定未被任何版本占有，防止 theme↔version 循环。
        $this->assertBindingHasNoVersionCycle($context);
        $changes = $input['changes'] ?? null;
        if (\is_string($changes)) {
            if (\strlen($changes) > self::MAX_CHANGES_JSON_BYTES) {
                throw new \InvalidArgumentException('theme_patch_changes_too_large');
            }
            $changes = \json_decode($changes, true, flags: JSON_THROW_ON_ERROR);
        }
        if (!\is_array($changes) || $changes === []) {
            throw new \InvalidArgumentException('theme_patch_changes_required');
        }
        if (!\array_is_list($changes)) {
            throw new \InvalidArgumentException('theme_patch_changes_list_required');
        }
        if (\count($changes) > self::MAX_COMMANDS) {
            throw new \InvalidArgumentException('theme_patch_changes_limit_exceeded');
        }
        $encodedChanges = \json_encode(
            $changes,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        if (\strlen($encodedChanges) > self::MAX_CHANGES_JSON_BYTES) {
            throw new \InvalidArgumentException('theme_patch_changes_too_large');
        }
        $commands = [];
        foreach ($changes as $change) {
            if (!\is_array($change)) {
                throw new \InvalidArgumentException('theme_patch_command_type_invalid');
            }
            $command = ThemePatchCommand::fromArray($change);
            $encodedCommand = \json_encode(
                $command->toArray(),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
            if (\strlen($encodedCommand) > self::MAX_COMMAND_JSON_BYTES) {
                throw new \InvalidArgumentException('theme_patch_command_too_large');
            }
            $this->assertThemeBindingValue($context, $command);
            $commands[] = $command;
        }

        $result = $this->workspace->applyChanges(
            context: $context,
            expectedRevision: $this->requiredRevision($input),
            expectedParentReleaseId: $this->nullableId($input['expected_parent_release_id'] ?? null),
            changes: $commands,
            actorId: $actorId,
            actorName: $actorName,
            summary: $this->note($input['summary'] ?? '', 'summary'),
        );

        return $result;
    }

    /** @param array<string,mixed> $input */
    /** @param array<string,mixed> $input */
    public function publish(array $input, string $actorId, string $actorName = ''): array
    {
        $context = $this->contexts->fromInput($input);
        $result = $this->workspace->publish(
            context: $context,
            expectedRevision: $this->requiredRevision($input),
            expectedParentReleaseId: $this->nullableId($input['expected_parent_release_id'] ?? null),
            actorId: $actorId,
            actorName: $actorName,
            reason: $this->note($input['reason'] ?? '', 'reason'),
        );
        // Structural conflict commits blocked state; do not treat as publish success
        // and do not invalidate storefront Theme caches for an unpublished draft.
        if (!empty($result['blocked'])) {
            return $result;
        }

        $carrier = $this->publishSharedChromeCarrierIfPending(
            $context,
            $actorId,
            $actorName,
            $this->note($input['reason'] ?? '', 'reason'),
        );
        if ($carrier !== null) {
            $result['shared_chrome_carrier'] = $carrier;
        }
        $chromeOverwrite = $this->overwriteNonCarrierChromeAfterCarrierPublish(
            $context,
            $carrier !== null,
            $actorId,
            $actorName,
            $this->note($input['reason'] ?? '', 'reason'),
        );
        if ($chromeOverwrite !== null) {
            $result['shared_chrome_overwrite'] = $chromeOverwrite;
        }

        $publishedThemeId = $context->themeId > 0
            ? $context->themeId
            : (int)($result['payload']['theme_id'] ?? 0);
        $result['static_version'] = $this->layoutVersions->bumpStaticVersion(
            $publishedThemeId > 0 ? $publishedThemeId : 0,
        );
        $result['cache_invalidation'] = $this->cacheCleaner->clearAllThemeRelatedCaches(
            $publishedThemeId > 0 ? $publishedThemeId : null,
            'theme_scoped_publish',
        );
        $this->workspace->invalidateRequestLoadCache();

        return $result;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function publishBatch(array $input, string $actorId, string $actorName = ''): array
    {
        $context = $this->contexts->fromInput($input, ThemeEditorContext::RESOURCE_LAYOUT);
        $claims = $input['resources'] ?? null;
        if (\is_string($claims)) {
            $claims = \json_decode($claims, true, flags: JSON_THROW_ON_ERROR);
        }
        if (!\is_array($claims) || \array_is_list($claims)) {
            throw new \InvalidArgumentException('theme_scope_release_batch_resources_required');
        }
        $expectations = [];
        foreach (ThemeEditorContext::RESOURCES as $resourceType) {
            $claim = $claims[$resourceType] ?? null;
            if (!\is_array($claim)) {
                throw new \InvalidArgumentException('theme_scope_release_batch_resource_set_incomplete');
            }
            $expectations[$resourceType] = [
                'expected_revision' => $this->requiredRevision($claim),
                'expected_parent_release_id' => $this->nullableId(
                    $claim['expected_parent_release_id'] ?? null,
                ),
            ];
        }
        $batch = ThemeScopedReleaseBatch::fromExpectations($context, $expectations);
        $result = $this->workspace->publishBatch(
            batch: $batch,
            actorId: $actorId,
            actorName: $actorName,
            reason: $this->note($input['reason'] ?? '', 'reason'),
        );

        $carrier = $this->publishSharedChromeCarrierIfPending(
            $context,
            $actorId,
            $actorName,
            $this->note($input['reason'] ?? '', 'reason'),
        );
        if ($carrier !== null) {
            $result['shared_chrome_carrier'] = $carrier;
        }
        $chromeOverwrite = $this->overwriteNonCarrierChromeAfterCarrierPublish(
            $context,
            $carrier !== null,
            $actorId,
            $actorName,
            $this->note($input['reason'] ?? '', 'reason'),
        );
        if ($chromeOverwrite !== null) {
            $result['shared_chrome_overwrite'] = $chromeOverwrite;
        }
        $siblingI18n = $this->publishPendingSiblingI18nLocales(
            $context,
            $actorId,
            $actorName,
            $this->note($input['reason'] ?? '', 'reason'),
        );
        if ($siblingI18n !== []) {
            $result['sibling_i18n'] = $siblingI18n;
        }

        $this->workspace->invalidateRequestLoadCache();

        return $this->finalizeBatchCacheState(
            $result,
            $context,
            'theme_scoped_release_batch_publish',
        );
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function readBatch(array $input): array
    {
        $context = $this->contexts->fromInput($input, ThemeEditorContext::RESOURCE_LAYOUT);
        $result = $this->workspace->getReleaseBatch($this->requiredBatchId($input));
        $this->assertBatchMatchesContext($result, $context);

        return $result;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function retryBatchCache(array $input): array
    {
        $context = $this->contexts->fromInput($input, ThemeEditorContext::RESOURCE_LAYOUT);
        $result = $this->workspace->getReleaseBatch($this->requiredBatchId($input));
        $this->assertBatchMatchesContext($result, $context);

        return $this->finalizeBatchCacheState(
            $result,
            $context,
            'theme_scoped_release_batch_cache_retry',
        );
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function rollbackBatch(array $input, string $actorId, string $actorName = ''): array
    {
        $context = $this->contexts->fromInput($input, ThemeEditorContext::RESOURCE_LAYOUT);
        $sourceBatchId = $this->requiredBatchId($input);
        $source = $this->workspace->getReleaseBatch($sourceBatchId);
        $this->assertBatchMatchesContext($source, $context);
        $result = $this->workspace->rollbackReleaseBatch(
            sourceBatchId: $sourceBatchId,
            context: $context,
            actorId: $actorId,
            actorName: $actorName,
            reason: $this->note($input['reason'] ?? 'theme_editor_batch_rollback', 'reason'),
        );

        return $this->finalizeBatchCacheState(
            $result,
            $context,
            'theme_scoped_release_batch_rollback',
        );
    }

    /**
     * Inherit-mode chrome writes land on the homepage carrier workspace.
     * Publishing any other layout must also flush that carrier so storefront
     * mergeSharedChromeSlotWidgets sees the updated global header/footer.
     *
     * @return array<string,mixed>|null
     */
    /**
     * Publish other locales' pending RESOURCE_I18N drafts for the same page identity.
     * Batch publish only covers the editor's current locale; sibling locales otherwise
     * stay draft and storefront keeps showing structure-locale media (e.g. zh banner on /en_US/).
     *
     * @return list<array<string,mixed>>
     */
    private function publishPendingSiblingI18nLocales(
        ThemeEditorContext $context,
        string $actorId,
        string $actorName,
        string $reason,
    ): array {
        $published = [];
        $batchLocale = \trim((string)$context->locale);
        $i18nBase = $context->withResource(ThemeEditorContext::RESOURCE_I18N);
        foreach ($this->siblingI18nLocales($context) as $locale) {
            if ($locale === '' || \strcasecmp($locale, 'default') === 0) {
                continue;
            }
            if ($batchLocale !== '' && \strcasecmp($locale, $batchLocale) === 0) {
                continue;
            }
            $sibling = $i18nBase->withLocale($locale);
            try {
                $state = $this->workspace->load($sibling, true);
            } catch (\Throwable) {
                continue;
            }
            $draftRevisionId = (int)($state['draft_revision_id'] ?? 0);
            $publishedRevisionId = (int)($state['published_revision_id'] ?? 0);
            if ($draftRevisionId <= 0 || $draftRevisionId === $publishedRevisionId) {
                continue;
            }
            $siblingReason = \trim($reason) !== ''
                ? ($reason . '_sibling_i18n_' . $locale)
                : ('sibling_i18n_publish_' . $locale);
            try {
                $receipt = $this->workspace->publish(
                    context: $sibling,
                    expectedRevision: (int)($state['revision'] ?? 0),
                    expectedParentReleaseId: $this->nullableId($state['expected_parent_release_id'] ?? null),
                    actorId: $actorId,
                    actorName: $actorName,
                    reason: $siblingReason,
                );
            } catch (\Throwable $e) {
                if (\function_exists('w_log_warning')) {
                    \w_log_warning(
                        'theme_sibling_i18n_publish_skipped: ' . $e->getMessage(),
                        [
                            'locale' => $locale,
                            'layout_type' => $context->layoutType,
                            'theme_id' => $context->themeId,
                        ],
                        'theme_scoped_i18n',
                    );
                }
                continue;
            }
            if (!empty($receipt['blocked'])) {
                continue;
            }
            $published[] = [
                'locale' => $locale,
                'release_id' => $receipt['release_id'] ?? null,
                'revision' => $receipt['revision'] ?? null,
            ];
        }

        return $published;
    }

    /**
     * @return list<string>
     */
    private function siblingI18nLocales(ThemeEditorContext $context): array
    {
        try {
            $rows = (clone $this->workspaceRows)->clearData()->clearQuery()
                ->where(ThemeScopeWorkspace::schema_fields_THEME_ID, $context->themeId)
                ->where(ThemeScopeWorkspace::schema_fields_AREA, $context->area)
                ->where(ThemeScopeWorkspace::schema_fields_RESOURCE_TYPE, ThemeEditorContext::RESOURCE_I18N)
                ->where(ThemeScopeWorkspace::schema_fields_LAYOUT_TYPE, $context->layoutType)
                ->where(ThemeScopeWorkspace::schema_fields_LAYOUT_OPTION, $context->layoutOption)
                ->where(ThemeScopeWorkspace::schema_fields_SCOPE, $context->scope->storageScope)
                ->where(ThemeScopeWorkspace::schema_fields_TARGET_TYPE, $context->targetType)
                ->where(ThemeScopeWorkspace::schema_fields_TARGET_ID, $context->targetId)
                ->select()
                ->fetch()
                ->getItems();
        } catch (\Throwable) {
            return [];
        }

        $locales = [];
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (!\is_object($row) || !\method_exists($row, 'getData')) {
                continue;
            }
            $locale = \trim((string)$row->getData(ThemeScopeWorkspace::schema_fields_LOCALE));
            if ($locale === '' || \strcasecmp($locale, 'default') === 0) {
                continue;
            }
            $locales[$locale] = $locale;
        }

        return \array_values($locales);
    }

    private function publishSharedChromeCarrierIfPending(
        ThemeEditorContext $context,
        string $actorId,
        string $actorName,
        string $reason,
    ): ?array {
        if ($this->sharedChrome->isChromeCarrierPageType($context->layoutType)) {
            return null;
        }

        $carrier = $context
            ->withLayoutType(ThemeLayout::PAGE_TYPE_HOME)
            ->withResource(ThemeEditorContext::RESOURCE_LAYOUT);
        $state = $this->workspace->load($carrier, true);
        $draftRevisionId = (int)($state['draft_revision_id'] ?? 0);
        $publishedRevisionId = (int)($state['published_revision_id'] ?? 0);
        if ($draftRevisionId <= 0 || $draftRevisionId === $publishedRevisionId) {
            return null;
        }

        $carrierReason = \trim($reason) !== ''
            ? ($reason . '_shared_chrome_carrier')
            : 'shared_chrome_carrier_publish';

        return $this->workspace->publish(
            context: $carrier,
            expectedRevision: (int)($state['revision'] ?? 0),
            expectedParentReleaseId: $this->nullableId($state['expected_parent_release_id'] ?? null),
            actorId: $actorId,
            actorName: $actorName,
            reason: $carrierReason,
        );
    }

    /**
     * 全局头尾发版本覆盖：carrier 直发或顺带 flush 后，清空并发布非载体本地 chrome。
     *
     * @return array<string,mixed>|null
     */
    private function overwriteNonCarrierChromeAfterCarrierPublish(
        ThemeEditorContext $context,
        bool $carrierPublished,
        string $actorId,
        string $actorName,
        string $reason,
    ): ?array {
        $carrierDirect = $this->sharedChrome->isChromeCarrierPageType($context->layoutType);
        if (!$carrierDirect && !$carrierPublished) {
            return null;
        }

        $overwriteReason = \trim($reason) !== ''
            ? ($reason . '_shared_chrome_overwrite')
            : 'shared_chrome_force_inherit_publish';

        return $this->sharedChrome->forceInheritAndPublishNonCarriers(
            $context->withLayoutType(ThemeLayout::PAGE_TYPE_HOME),
            null,
            null,
            $actorId,
            $actorName,
            $overwriteReason,
        );
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private function finalizeBatchCacheState(
        array $result,
        ThemeEditorContext $context,
        string $reason,
    ): array {
        // Database publication is already committed. Cache faults are recorded as
        // a retryable degraded state and must never be surfaced as commit failure.
        $cacheState = ThemeScopeReleaseBatch::STATE_PUBLISHED;
        $cacheError = null;
        $cacheInvalidation = [];
        try {
            $result['static_version'] = $this->layoutVersions->bumpStaticVersion(
                $context->themeId > 0 ? $context->themeId : 0,
            );
            $cacheInvalidation = $this->cacheCleaner->clearAllThemeRelatedCaches(
                $context->themeId > 0 ? $context->themeId : null,
                $reason,
            );
            $failures = \is_array($cacheInvalidation['failures'] ?? null)
                ? $cacheInvalidation['failures']
                : [];
            if ($failures !== []) {
                $cacheState = ThemeScopeReleaseBatch::STATE_PUBLISHED_CACHE_DEGRADED;
                $cacheError = [
                    'code' => 'theme_scope_release_batch_cache_degraded',
                    'failures' => $failures,
                ];
            }
        } catch (\Throwable $e) {
            $cacheState = ThemeScopeReleaseBatch::STATE_PUBLISHED_CACHE_DEGRADED;
            $cacheError = [
                'code' => 'theme_scope_release_batch_cache_degraded',
                'message' => $e->getMessage(),
            ];
        }

        $batchId = (int)($result['batch_id'] ?? 0);
        try {
            $result = $this->workspace->updateReleaseBatchCacheState(
                $batchId,
                $cacheState,
                $cacheError,
            );
        } catch (\Throwable $e) {
            $result['state'] = $cacheState;
            $result['cache_error'] = $cacheError;
            $result['cache_state_update_error'] = $e->getMessage();
        }
        $result['cache_invalidation'] = $cacheInvalidation;
        $result['cache_retryable'] = $cacheState
            === ThemeScopeReleaseBatch::STATE_PUBLISHED_CACHE_DEGRADED;

        return $result;
    }

    /** @param array<string,mixed> $batch */
    private function assertBatchMatchesContext(array $batch, ThemeEditorContext $context): void
    {
        $matches = (string)($batch['scope'] ?? '') === $context->scope->storageScope
            && (string)($batch['store_mode'] ?? '') === $context->scope->storeMode
            && (string)($batch['area'] ?? '') === $context->area
            && (int)($batch['theme_id'] ?? 0) === $context->themeId
            && (string)($batch['layout_type'] ?? '') === $context->layoutType
            && (string)($batch['layout_option'] ?? '') === $context->layoutOption
            && (string)($batch['locale'] ?? '') === $context->locale
            && (string)($batch['target_type'] ?? '') === $context->targetType
            && (int)($batch['target_id'] ?? 0) === $context->targetId;
        if (!$matches) {
            throw new \RuntimeException('theme_scope_release_batch_context_mismatch');
        }
    }

    /**
     * 绑定写入口的版本循环前置断言。
     *
     * 读取已落库的绑定工作区行，把它的版本占有值交给守卫校验：
     * 绑定必须停在版本外，若该行被某个版本占有，说明存量数据已漂移，
     * 此时拒绝写入并要求先修复，而不是静默覆盖掉漂移痕迹。
     */
    private function assertBindingHasNoVersionCycle(ThemeEditorContext $context): void
    {
        if ($context->resourceType !== ThemeEditorContext::RESOURCE_THEME_BINDING) {
            return;
        }
        // 首次写入尚无对应行，按版本外处理；落库强制由模型层 save_before() 兜底。
        $persistedVersionId = ThemeScopeWorkspace::THEME_VERSION_EXTERNAL;
        try {
            $rows = (clone $this->workspaceRows)->clearData()->clearQuery()
                ->where(ThemeScopeWorkspace::schema_fields_BINDING_IDENTITY_KEY, $context->identityHash())
                ->select()
                ->fetch()
                ->getItems();
            $row = \is_array($rows) ? ($rows[0] ?? null) : null;
            if (\is_object($row) && \method_exists($row, 'getData')) {
                $persistedVersionId = (int)$row->getData(ThemeScopeWorkspace::schema_fields_THEME_VERSION_ID);
            }
        } catch (\Throwable) {
            $persistedVersionId = ThemeScopeWorkspace::THEME_VERSION_EXTERNAL;
        }

        $this->versionSnapshots->assertThemeBindingHasNoVersionCycle($context, $persistedVersionId);
    }

    private function assertThemeBindingValue(ThemeEditorContext $context, ThemePatchCommand $command): void
    {
        if ($context->resourceType !== ThemeEditorContext::RESOURCE_THEME_BINDING
            || $command->operation === ThemePatchCommand::OP_INHERIT
        ) {
            return;
        }
        if ($command->operation !== ThemePatchCommand::OP_SET
            || !\is_int($command->value)
            || $command->value <= 0
        ) {
            throw new \InvalidArgumentException('theme_binding_theme_id_invalid');
        }
        $theme = clone $this->themes;
        $theme->clearData()->clearQuery()->load($command->value);
        if ((int)$theme->getId() !== $command->value) {
            throw new \InvalidArgumentException('theme_binding_theme_not_found');
        }
        if (!$this->themeContext->themeSupportsArea($theme, $context->area)) {
            throw new \InvalidArgumentException('theme_binding_theme_area_unsupported');
        }
    }

    /** @param array<string,mixed> $input */
    private function requiredRevision(array $input): int
    {
        if (!\array_key_exists('expected_revision', $input)) {
            throw new \InvalidArgumentException('theme_scope_expected_revision_required');
        }
        $revision = $input['expected_revision'];
        if (\is_string($revision) && \preg_match('/^(?:0|[1-9][0-9]*)$/D', $revision) === 1) {
            $revision = (int)$revision;
        }
        if (!\is_int($revision) || $revision < 0) {
            throw new \InvalidArgumentException('theme_scope_expected_revision_invalid');
        }

        return $revision;
    }

    /** @param array<string,mixed> $input */
    private function requiredBatchId(array $input): int
    {
        $batchId = $input['batch_id'] ?? $input['source_batch_id'] ?? null;
        if (\is_string($batchId) && \preg_match('/^[1-9][0-9]*$/D', $batchId) === 1) {
            $batchId = (int)$batchId;
        }
        if (!\is_int($batchId) || $batchId <= 0) {
            throw new \InvalidArgumentException('theme_scope_release_batch_id_invalid');
        }

        return $batchId;
    }

    private function nullableId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (\is_string($value) && \preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            $value = (int)$value;
        }
        if (!\is_int($value) || $value <= 0) {
            throw new \InvalidArgumentException('theme_scope_parent_release_invalid');
        }

        return $value;
    }

    private function note(mixed $value, string $field): string
    {
        if ($value === null) {
            return '';
        }
        if (!\is_scalar($value)) {
            throw new \InvalidArgumentException('theme_scope_' . $field . '_invalid');
        }
        $value = \trim((string)$value);
        if (\strlen($value) > self::MAX_NOTE_BYTES) {
            throw new \InvalidArgumentException('theme_scope_' . $field . '_too_long');
        }

        return $value;
    }
}
