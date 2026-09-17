<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

/**
 * Runtime helpers for hard-cutover entity templates (FetchFileBefore / LayoutSlotRenderer).
 */
final class ThemeLayoutEntityRuntime
{
    private static bool $skipSlotProcessing = false;
    private static ?string $activeEntityPath = null;

    public function __construct(
        private readonly ThemeLayoutEntityPointerResolver $pointers,
        private readonly ThemeLayoutEntityPaths $paths,
    ) {
    }

    public function tryResolvePageLayoutPath(
        int $themeId,
        string $scope,
        string $identityHash,
        string $structureKey,
        bool $published = true,
        ?int $releaseId = null,
    ): ?string {
        $pointer = $this->pointers->resolvePageEntity(
            $themeId,
            $scope,
            $identityHash,
            $structureKey,
            $published,
            $releaseId,
        );
        if (!\is_array($pointer)) {
            return null;
        }
        $path = (string)($pointer['path'] ?? '');

        return $path !== '' && \is_file($path) ? $path : null;
    }

    public function isEntityTemplate(string $path): bool
    {
        $normalized = \str_replace('\\', '/', $path);

        return $normalized !== ''
            && \str_contains($normalized, '/' . ThemeLayoutEntityPaths::ROOT_SEGMENT . '/');
    }

    public function markEntityTemplateActive(?string $path): void
    {
        self::$activeEntityPath = $path;
        self::$skipSlotProcessing = $path !== null && $this->isEntityTemplate((string)$path);
    }

    public function clearEntityTemplateMark(): void
    {
        self::$activeEntityPath = null;
        self::$skipSlotProcessing = false;
    }

    public function shouldSkipSlotProcessing(): bool
    {
        return self::$skipSlotProcessing;
    }

    public function activeEntityPath(): ?string
    {
        return self::$activeEntityPath;
    }

    public function markSkipSlotProcessing(bool $skip = true): void
    {
        self::$skipSlotProcessing = $skip;
    }

    public static function resetProcessFlags(): void
    {
        self::$skipSlotProcessing = false;
        self::$activeEntityPath = null;
    }
}
