<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Layout;

/**
 * Public Theme layout mutation boundary.
 *
 * Consumers exchange scalar layout data and immutable identities only. Theme
 * ORM models, version models and internal services never cross this boundary.
 */
interface LayoutWorkspaceInterface
{
    public function resolveActiveThemeId(string $area, bool $allowPreview = false): int;

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
}
