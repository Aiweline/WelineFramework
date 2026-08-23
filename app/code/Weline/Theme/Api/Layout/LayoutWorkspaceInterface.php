<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Layout;

use Weline\Framework\Runtime\ScopeIdentity;

/**
 * Public Theme layout mutation boundary.
 *
 * Consumers exchange scalar layout data and immutable identities only. Theme
 * ORM models, version models and internal services never cross this boundary.
 */
interface LayoutWorkspaceInterface
{
    public function resolveActiveThemeId(
        string $area,
        bool $allowPreview = false,
        ?ScopeIdentity $scopeIdentity = null,
    ): int;

    public function initializeVersionIfNeeded(
        int $themeId,
        string $pageType,
        ?int $userId,
        LayoutIdentity $identity,
    ): void;

    /**
     * @param array<string,list<array<string,mixed>>> $layoutData
     */
    public function replaceLayout(
        int $themeId,
        string $pageType,
        array $layoutData,
        LayoutStatus $status,
        LayoutIdentity $identity,
    ): bool;

    public function publishLayout(
        int $themeId,
        string $pageType,
        LayoutIdentity $identity,
        bool $allowEmpty = false,
    ): bool;

    public function copyLayout(
        int $themeId,
        string $pageType,
        LayoutIdentity $sourceIdentity,
        LayoutIdentity $targetIdentity,
    ): LayoutCopyResult;

    public function hasLayout(int $themeId, string $pageType, LayoutIdentity $identity): bool;

    public function deleteLayout(int $themeId, string $pageType, LayoutIdentity $identity): int;

    /** @return array<string,mixed>|null */
    public function resolveLayoutSelection(
        string $targetType,
        int $targetId,
        string $layoutType,
        ?string $scope = null,
        ?string $localeCode = null,
    ): ?array;

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function saveLayoutSelection(
        string $targetType,
        int $targetId,
        string $layoutType,
        string $layoutOption,
        ?string $scope = null,
        ?string $localeCode = null,
        array $options = [],
    ): array;

    /** @param array<string,mixed> $context */
    public function validateTargetVariant(
        string $pageType,
        LayoutIdentity $identity,
        array $context,
    ): void;

    /** @param array<string,mixed> $context */
    /** @return array{success:bool,theme_id:int} */
    public function publishTargetVariant(
        string $pageType,
        LayoutIdentity $identity,
        array $context,
        bool $allowEmpty = false,
    ): array;

    public function copyTargetLayoutData(
        string $pageType,
        LayoutIdentity $sourceIdentity,
        LayoutIdentity $targetIdentity,
        ScopeIdentity $sourceScopeIdentity,
        ScopeIdentity $targetScopeIdentity,
    ): LayoutCopyResult;
}
