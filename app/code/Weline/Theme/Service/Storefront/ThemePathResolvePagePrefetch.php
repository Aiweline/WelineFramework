<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Storefront;

use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\View\Template;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutTemplateDependencies;
use Weline\Theme\Service\StorefrontThemeCacheCoordinator;
use Weline\Theme\Service\ThemeContextService;

/**
 * Page-level HotCache prefetch for theme.path.resolve (nested literal fetches).
 *
 * Collects logical keys via getTemplateRealPath + literalFetches only —
 * never calls resolveThemeFile (would defeat prefetch).
 */
final class ThemePathResolvePagePrefetch
{
    public const LATCH_KEY = 'theme.path_resolve.page_prefetch.primed.v1';

    private const SEEN_REFS_KEY = 'theme.path_resolve.page_prefetch.seen_refs.v1';

    /**
     * @return int logical keys submitted to prefetchPolicy (0 = noop)
     */
    public function primeBeforeLayoutFetch(?Template $template = null, string ...$templateRefs): int
    {
        if (!RequestContext::isInitialized()) {
            return 0;
        }
        $themeId = $this->resolveFrontendThemeId();
        if ($themeId <= 0) {
            return 0;
        }

        $hotCache = ObjectManager::getInstance(StorefrontScopeHotCache::class);
        if (!$hotCache instanceof StorefrontScopeHotCache) {
            return 0;
        }

        $tpl = $template;
        if (!$tpl instanceof Template) {
            try {
                $tpl = ObjectManager::getInstance(Template::class);
            } catch (\Throwable) {
                return 0;
            }
        }
        if (!$tpl instanceof Template) {
            return 0;
        }

        $seen = RequestContext::get(self::SEEN_REFS_KEY);
        if (!\is_array($seen)) {
            $seen = [];
        }

        $modulePaths = [];
        $newRefs = [];
        foreach ($templateRefs as $ref) {
            $ref = \trim($ref);
            if ($ref === '' || isset($seen[$ref])) {
                continue;
            }
            $seen[$ref] = true;
            $newRefs[] = $ref;
        }
        RequestContext::set(self::SEEN_REFS_KEY, $seen);

        if ($newRefs === [] && RequestContext::get(self::LATCH_KEY) === true) {
            return 0;
        }

        foreach ($newRefs as $ref) {
            $this->collectFromLogicalRef($tpl, $ref, $modulePaths, 0);
        }

        if ($modulePaths === []) {
            RequestContext::set(self::LATCH_KEY, true);

            return 0;
        }

        $keys = [];
        foreach ($modulePaths as $path => $_) {
            $keys[] = (string)$themeId . '|' . \str_replace(['/', '\\'], DS, $path);
        }
        $keys = \array_values(\array_unique($keys));

        $t0 = \microtime(true);
        try {
            $primed = $hotCache->prefetchPolicy(
                StorefrontThemeCacheCoordinator::themePathResolvePolicy(),
                $keys,
            );
        } catch (\Throwable) {
            $primed = 0;
        }
        $prefetchMs = \round((\microtime(true) - $t0) * 1000, 2);

        // #region agent log
        try {
            $payload = [
                'sessionId' => '8f7f40',
                'runId' => 'cold-lag-pre',
                'hypothesisId' => 'A,E',
                'location' => 'ThemePathResolvePagePrefetch.php:primeBeforeLayoutFetch',
                'message' => 'path.resolve prefetch result',
                'data' => [
                    'theme_id' => $themeId,
                    'key_count' => \count($keys),
                    'primed' => (int)$primed,
                    'prefetch_ms' => $prefetchMs,
                    'request_id' => RequestContext::getId(),
                ],
                'timestamp' => (int)\round(\microtime(true) * 1000),
            ];
            @\file_put_contents(
                '/Users/weline/Project/Official/框架/.cursor/debug-8f7f40.log',
                \json_encode($payload, \JSON_UNESCAPED_UNICODE) . "\n",
                \FILE_APPEND | \LOCK_EX
            );
        } catch (\Throwable) {
        }
        // #endregion

        RequestContext::set(self::LATCH_KEY, true);

        return \max(0, (int)$primed);
    }

    /**
     * @param array<string, true> $modulePaths
     */
    private function collectFromLogicalRef(Template $template, string $logicalOrPath, array &$modulePaths, int $depth): void
    {
        if ($depth > 1) {
            return;
        }

        $absolute = $this->toModuleAbsolutePath($template, $logicalOrPath);
        if ($absolute === '') {
            return;
        }
        $modulePaths[$absolute] = true;

        if (!\is_file($absolute)) {
            return;
        }
        $source = @\file_get_contents($absolute);
        if (!\is_string($source) || $source === '') {
            return;
        }

        try {
            /** @var ThemeLayoutTemplateDependencies $deps */
            $deps = ObjectManager::getInstance(ThemeLayoutTemplateDependencies::class);
            $literals = $deps->literalFetches($source);
        } catch (\Throwable) {
            return;
        }

        foreach ($literals as $literal) {
            if (!\is_string($literal) || $literal === '') {
                continue;
            }
            // Only Module::….phtml style — same filter as ThemeLayoutTemplateDependencies::moduleSources.
            if (\preg_match('~^[A-Za-z0-9_]+::[^:]+\.phtml$~D', $literal) !== 1) {
                continue;
            }
            $nestedAbs = $this->toModuleAbsolutePath($template, $literal);
            if ($nestedAbs === '') {
                continue;
            }
            $modulePaths[$nestedAbs] = true;
            if ($depth < 1) {
                $this->collectFromLogicalRef($template, $literal, $modulePaths, $depth + 1);
            }
        }
    }

    private function toModuleAbsolutePath(Template $template, string $ref): string
    {
        $ref = \trim($ref);
        if ($ref === '') {
            return '';
        }
        // Already an absolute filesystem path (common after processFileSource).
        if (\str_starts_with($ref, DS) || (\strlen($ref) > 2 && $ref[1] === ':')) {
            return \str_replace(['/', '\\'], DS, $ref);
        }
        try {
            $path = $template->getTemplateRealPath($ref);
        } catch (\Throwable) {
            return '';
        }
        if (!\is_string($path) || $path === '') {
            return '';
        }

        return \str_replace(['/', '\\'], DS, $path);
    }

    private function resolveFrontendThemeId(): int
    {
        try {
            /** @var ThemeContextService $context */
            $context = ObjectManager::getInstance(ThemeContextService::class);
            $theme = $context->resolveTheme('frontend', null, false);
            if ($theme instanceof WelineTheme) {
                $id = (int)$theme->getId();
                if ($id > 0) {
                    return $id;
                }
            }
            $fallback = $context->resolveRegisteredDefaultTheme('frontend');
            if ($fallback instanceof WelineTheme) {
                return (int)$fallback->getId();
            }
        } catch (\Throwable) {
        }

        return 0;
    }
}
