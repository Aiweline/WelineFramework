<?php

declare(strict_types=1);

namespace Weline\Websites\Api\AiWorkbench;

/** Optional persistence boundary implemented by a theme module. */
interface VirtualThemeStoreInterface
{
    /** @return array{theme_id:int,config:array<string,mixed>}|null */
    public function saveTheme(
        int $themeId,
        int $sessionId,
        string $themeName,
        array $configPatch,
    ): ?array;

    /** @return array{theme_id:int,page_type:string,layout:array<string,mixed>}|null */
    public function savePageTypeLayout(
        int $themeId,
        int $sessionId,
        string $themeName,
        string $pageType,
        array $layoutPayload,
    ): ?array;

    /** @return array{component_id:int,component_code:string,version_id:int}|null */
    public function saveComponent(int $themeId, array $payload, string $publicId): ?array;
}
