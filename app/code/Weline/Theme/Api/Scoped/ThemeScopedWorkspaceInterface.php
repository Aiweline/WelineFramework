<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Scoped;

use Weline\SystemConfig\Api\Scope\ScopeContext;

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

    public function resolveValue(ThemeEditorContext $context, string $path, bool $includeDraft = true): ThemeResolvedValue;

    /** Runtime reads published releases only. */
    public function resolvePublishedTheme(ScopeContext $scope, string $area): ?ThemeResolvedValue;
}
