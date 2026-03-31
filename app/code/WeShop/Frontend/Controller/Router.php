<?php

declare(strict_types=1);

namespace WeShop\Frontend\Controller;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\RouterInterface;

class Router implements RouterInterface
{
    public static function process(string &$path, array &$rule): void
    {
        if (!empty($rule['module'])) {
            return;
        }

        $normalizedPath = trim(str_replace('\\', '/', $path), '/');
        $hasPreviewTheme = (int)($_GET['preview_theme'] ?? 0) > 0;
        $activeTheme = self::resolveActiveFrontendTheme();

        if (!self::shouldRewriteRootToWeShop($normalizedPath, self::isBackendRequest(), $hasPreviewTheme, $activeTheme)) {
            return;
        }

        $path = 'weshop';
    }

    /**
     * @param array<string, mixed> $activeTheme
     */
    private static function shouldRewriteRootToWeShop(
        string $normalizedPath,
        bool $isBackend,
        bool $hasPreviewTheme,
        array $activeTheme
    ): bool {
        if ($normalizedPath !== '' || $isBackend || $hasPreviewTheme) {
            return false;
        }

        return self::isWeShopTheme($activeTheme);
    }

    /**
     * @param array<string, mixed> $activeTheme
     */
    private static function isWeShopTheme(array $activeTheme): bool
    {
        $themePath = strtolower(trim(str_replace('\\', '/', (string)($activeTheme['path'] ?? '')), '/'));
        $themeName = strtolower(trim((string)($activeTheme['name'] ?? '')));

        return str_starts_with($themePath, 'weshop/') || str_contains($themeName, 'weshop');
    }

    /**
     * @return array<string, mixed>
     */
    private static function resolveActiveFrontendTheme(): array
    {
        try {
            $theme = w_query('theme', 'getActiveTheme', [], 'frontend');
            return is_array($theme) ? $theme : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private static function isBackendRequest(): bool
    {
        try {
            /** @var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            return $request->isBackend();
        } catch (\Throwable) {
            return false;
        }
    }
}
