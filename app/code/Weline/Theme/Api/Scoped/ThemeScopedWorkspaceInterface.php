<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Scoped;

use Weline\SystemConfig\Api\Scope\ScopeContext;
use Weline\Theme\Service\Scoped\ThemeScopedReleaseBatch;

interface ThemeScopedWorkspaceInterface
{
    /** @return array<string, mixed> */
    public function load(ThemeEditorContext $context, bool $includeDraft = true): array;

    /**
     * @param list<ThemePatchCommand> $changes
     * @return array<string, mixed>
     */
    public function applyChanges(
        ThemeEditorContext $context,
        int $expectedRevision,
        ?int $expectedParentReleaseId,
        array $changes,
        string $actorId,
        string $actorName = '',
        string $summary = '',
    ): array;

    /**
     * Replace a legacy full-form/layout-version draft by semantically diffing it
     * against the direct parent's published payload.
     *
     * @return array<string,mixed>
     */
    public function replaceEffectivePayload(
        ThemeEditorContext $context,
        int $expectedRevision,
        ?int $expectedParentReleaseId,
        array $effectivePayload,
        string $actorId,
        string $actorName = '',
        string $summary = '',
    ): array;

    /** @return array<string, mixed> */
    public function publish(
        ThemeEditorContext $context,
        int $expectedRevision,
        ?int $expectedParentReleaseId,
        string $actorId,
        string $actorName = '',
        string $reason = '',
    ): array;

    /**
     * Publish the complete frozen Theme resource set in one database transaction.
     *
     * @return array<string, mixed> Immutable batch receipt
     */
    public function publishBatch(
        ThemeScopedReleaseBatch $batch,
        string $actorId,
        string $actorName = '',
        string $reason = '',
    ): array;

    /** @return array<string,mixed> New batch receipt pointing to the historical snapshot. */
    public function rollbackReleaseBatch(
        int $sourceBatchId,
        ThemeEditorContext $context,
        string $actorId,
        string $actorName = '',
        string $reason = '',
    ): array;

    /** @param array<string,mixed>|null $error @return array<string,mixed> */
    public function updateReleaseBatchCacheState(int $batchId, string $state, ?array $error = null): array;

    /** @return array<string,mixed> */
    public function getReleaseBatch(int $batchId): array;

    public function resolveValue(ThemeEditorContext $context, string $path, bool $includeDraft = true): ThemeResolvedValue;

    /** Runtime reads published releases only. */
    public function resolvePublishedTheme(ScopeContext $scope, string $area): ?ThemeResolvedValue;
}
