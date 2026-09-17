<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\WelineTheme;

/**
 * Reverse-resolve a storefront public path from a layout identity.
 *
 * layout → ThemeResourceCatalog.module_name → Env::getModuleInfo()['router']
 * → prefer a path that exists in generated frontend routers
 * (layout as-is when already public; else router + layout for Customer-style hubs).
 */
final class LayoutStorefrontRouteFromModuleRouter
{
    private const THEME_MODULE = 'Weline_Theme';

    /**
     * Hub-style options under a layout type share the type's storefront path
     * (e.g. account + dashboard → customer/account, not …/dashboard).
     *
     * @var list<string>
     */
    private const HUB_OPTIONS = ['', 'default', 'dashboard', 'auth', 'index'];

    /** @var array<string, string> */
    private const LEGACY_LAYOUT_ALIASES = [
        'checkout_success' => 'checkout/success',
        'checkout_failure' => 'checkout/failure',
        'checkout_failer' => 'checkout/failure',
    ];

    /** @var array<string, bool>|null */
    private static ?array $frontendRouteIndex = null;

    public function resolve(
        string $layoutPath,
        string $layoutOption = 'default',
        ?WelineTheme $theme = null,
    ): string {
        [$layoutPath, $layoutOption] = $this->normalizeLayoutIdentity($layoutPath, $layoutOption);

        if ($this->isHomepageLayout($layoutPath)) {
            return '';
        }

        $resource = $this->resolveLayoutResource($layoutPath, $layoutOption, $theme);
        $joinSegment = $this->joinSegmentFromLayout($layoutPath, $layoutOption, $resource);
        if ($joinSegment === '' || $this->isHomepageLayout($joinSegment)) {
            return '';
        }

        $moduleName = trim((string)($resource['module_name'] ?? ''));
        if ($moduleName === '' || $moduleName === self::THEME_MODULE) {
            return $joinSegment;
        }

        $moduleInfo = Env::getInstance()->getModuleInfo($moduleName);
        if (!is_array($moduleInfo)) {
            return $joinSegment;
        }

        $router = strtolower(trim(str_replace('\\', '/', (string)($moduleInfo['router'] ?? '')), '/'));
        if ($router === '') {
            return $joinSegment;
        }

        return $this->pickPublicRoute($joinSegment, $router);
    }

    /**
     * @return array{0:string,1:string}
     */
    public function normalizeLayoutIdentity(string $layoutPath, string $layoutOption = 'default'): array
    {
        $layoutPath = strtolower(trim(str_replace('\\', '/', $layoutPath), '/'));
        $layoutOption = strtolower(trim(str_replace('\\', '/', $layoutOption), '/'));
        if ($layoutOption === '') {
            $layoutOption = 'default';
        }

        if (isset(self::LEGACY_LAYOUT_ALIASES[$layoutPath])) {
            $layoutPath = self::LEGACY_LAYOUT_ALIASES[$layoutPath];
        }

        // Controllers still use dotted aliases (account.dashboard / account.challenge).
        // Always map to type + option so catalog can find account/challenge.phtml etc.
        if (str_contains($layoutPath, '.')) {
            [$base, $suffix] = array_pad(explode('.', $layoutPath, 2), 2, '');
            $base = trim($base);
            $suffix = trim($suffix);
            if ($base !== '' && $suffix !== '') {
                $layoutPath = $base;
                if ($layoutOption === 'default') {
                    $layoutOption = $suffix === 'index' ? 'default' : $suffix;
                }
            }
        }

        return [$layoutPath, $layoutOption === '' ? 'default' : $layoutOption];
    }

    /**
     * Prefer a path that already exists as a frontend route.
     * - products (module router weline_product) → products
     * - account/login (module router customer) → customer/account/login
     */
    private function pickPublicRoute(string $joinSegment, string $router): string
    {
        if ($joinSegment === $router || str_starts_with($joinSegment, $router . '/')) {
            return $joinSegment;
        }

        $prefixed = $router . '/' . $joinSegment;
        $layoutExists = $this->frontendRouteExists($joinSegment);
        $prefixedExists = $this->frontendRouteExists($prefixed);
        // Public routes use slash paths (checkout/success); legacy layout dirs used underscores.
        $slashed = str_contains($joinSegment, '_')
            ? str_replace('_', '/', $joinSegment)
            : '';
        $slashedExists = $slashed !== ''
            && $slashed !== $joinSegment
            && $this->frontendRouteExists($slashed);

        if ($layoutExists && !$prefixedExists) {
            return $joinSegment;
        }
        if ($slashedExists && !$layoutExists) {
            return $slashed;
        }
        if ($prefixedExists) {
            return $prefixed;
        }
        if ($layoutExists) {
            return $joinSegment;
        }
        if ($slashedExists) {
            return $slashed;
        }

        // No router hit: keep layout path (1:1 hubs / rewrite-owned paths).
        return $joinSegment;
    }

    private function frontendRouteExists(string $path): bool
    {
        $path = strtolower(trim(str_replace('\\', '/', $path), '/'));
        if ($path === '') {
            return true;
        }

        $index = $this->frontendRouteIndex();
        if (isset($index[$path])) {
            return true;
        }

        // Accept controller index aliases: customer/account/index → customer/account
        if (str_ends_with($path, '/index')) {
            $hub = substr($path, 0, -strlen('/index'));
            if ($hub !== '' && isset($index[$hub])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, bool>
     */
    private function frontendRouteIndex(): array
    {
        if (self::$frontendRouteIndex !== null) {
            return self::$frontendRouteIndex;
        }

        self::$frontendRouteIndex = [];
        $file = defined('BP')
            ? rtrim((string)BP, '/\\') . '/generated/routers/frontend_pc.php'
            : dirname(__DIR__, 4) . '/generated/routers/frontend_pc.php';
        // Service lives at app/code/Weline/Theme/Service → dirname 4 = app/code? 
        // Theme/Service = 1 Service, 2 Theme, 3 Weline, 4 code, 5 app, 6 BP
        if (!is_file($file)) {
            $file = dirname(__DIR__, 5) . '/generated/routers/frontend_pc.php';
        }
        if (!is_file($file)) {
            return self::$frontendRouteIndex;
        }

        try {
            /** @var mixed $routers */
            $routers = include $file;
        } catch (\Throwable) {
            return self::$frontendRouteIndex;
        }
        if (!is_array($routers)) {
            return self::$frontendRouteIndex;
        }

        foreach (array_keys($routers) as $key) {
            $key = strtolower(trim((string)$key));
            if ($key === '') {
                continue;
            }
            $path = explode('::', $key, 2)[0];
            $path = trim($path, '/');
            if ($path !== '') {
                self::$frontendRouteIndex[$path] = true;
            }
        }

        return self::$frontendRouteIndex;
    }

    /**
     * @param array<string, mixed>|null $resource
     */
    private function joinSegmentFromLayout(string $layoutPath, string $layoutOption, ?array $resource): string
    {
        $layoutPath = strtolower(trim(str_replace('\\', '/', $layoutPath), '/'));
        $layoutOption = strtolower(trim(str_replace('\\', '/', $layoutOption), '/'));
        if ($layoutOption === '') {
            $layoutOption = 'default';
        }

        if (in_array($layoutOption, self::HUB_OPTIONS, true)) {
            return $layoutPath;
        }

        // Nested path layouts: account/login + default already in layoutPath.
        if (str_contains($layoutPath, '/')) {
            return $layoutPath;
        }

        // Shorthand option that is really a public action segment (account + challenge).
        return $layoutPath . '/' . $layoutOption;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveLayoutResource(string $layoutPath, string $layoutOption, ?WelineTheme $theme): ?array
    {
        try {
            /** @var ThemeResourceCatalog $catalog */
            $catalog = ObjectManager::getInstance(ThemeResourceCatalog::class);
            $theme ??= ObjectManager::getInstance(ThemeContextService::class)
                ->resolveTheme('frontend', null, true);

            $resource = $catalog->getLayoutResource('frontend', $theme, $layoutPath, $layoutOption);
            if (is_array($resource)) {
                return $resource;
            }

            // Nested contribution: layoutPath/option/default.phtml
            if ($layoutOption !== 'default' && !str_contains($layoutPath, '/')) {
                $nested = $catalog->getLayoutResource(
                    'frontend',
                    $theme,
                    $layoutPath . '/' . $layoutOption,
                    'default'
                );
                if (is_array($nested)) {
                    return $nested;
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function isHomepageLayout(string $layoutPath): bool
    {
        $layoutPath = strtolower(trim(str_replace('\\', '/', $layoutPath), '/'));

        return $layoutPath === ''
            || $layoutPath === ThemeLayout::PAGE_TYPE_HOME
            || $layoutPath === ThemeLayout::PAGE_TYPE_DEFAULT
            || $layoutPath === 'index'
            || $layoutPath === 'index/index';
    }
}
