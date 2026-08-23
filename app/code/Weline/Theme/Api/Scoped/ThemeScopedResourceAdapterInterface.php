<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Scoped;

/** Compatibility projection between scoped releases and resource-owning modules. */
interface ThemeScopedResourceAdapterInterface
{
    /** Theme package default used only when no parent Release exists. */
    public function loadBase(ThemeEditorContext $context): array;

    /** Exact legacy published snapshot used only by compatibility migration. */
    public function loadLegacyPublished(ThemeEditorContext $context): array;

    /** Normalize effective schema and produce immutable compilation metadata. */
    public function compile(ThemeEditorContext $context, array $effectivePayload): array;

    /** Project a published effective release into legacy resource owners. */
    public function projectPublished(ThemeEditorContext $context, array $effectivePayload, int $releaseId): void;

    /**
     * Rebuild an expendable editor-only compatibility projection.
     *
     * Draft projections never establish ownership and are never a runtime
     * source. They only give legacy editor actions a current-scope row ID while
     * the scoped workspace remains the sole draft authority.
     */
    public function projectDraft(ThemeEditorContext $context, array $effectivePayload): void;
}
