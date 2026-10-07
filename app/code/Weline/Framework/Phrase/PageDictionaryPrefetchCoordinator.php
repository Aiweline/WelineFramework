<?php

declare(strict_types=1);

namespace Weline\Framework\Phrase;

use Weline\Framework\Hook\Config\HookReader;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\View\Template;

/**
 * Page-level (request) dictionary module union: prefetch once before layout hooks,
 * so Template::getHook does not open N× view.hook.dictionary_prefetch / WLS MGET.
 *
 * Not a second render-context authority bag — only Phrase prefetch bookkeeping.
 */
final class PageDictionaryPrefetchCoordinator
{
    public const PRIMED_KEY = 'phrase.page_dictionary_union.primed.v1';
    public const MODULES_KEY = 'phrase.page_dictionary_modules.v1';

    /**
     * Storefront chrome hooks that almost always run on public layouts (homepage etc.).
     *
     * @var list<string>
     */
    private const STOREFRONT_BASE_HOOKS = [
        'Weline_Theme::frontend::layouts::base::head-before',
        'Weline_Theme::frontend::layouts::base::head-after',
        'Weline_Theme::frontend::layouts::base::body-start',
        'Weline_Theme::frontend::layouts::base::body-end',
        'seo::body',
        'seo::footer',
        'seo::head',
    ];

    public function isPrimed(): bool
    {
        return RequestContext::get(self::PRIMED_KEY) === true;
    }

    /**
     * @return list<string>
     */
    public function primedModules(): array
    {
        $modules = RequestContext::get(self::MODULES_KEY, []);

        return \is_array($modules) ? \array_values(\array_filter($modules, 'is_string')) : [];
    }

    /**
     * Collect hook names from compiled templates + storefront base set, resolve
     * source modules, prefetch once, latch RequestContext.
     *
     * @return int modules prefetched (0 if noop / fail-open)
     */
    public function primeBeforeLayoutFetch(Template $template, string ...$templateRefs): int
    {
        if (!RequestContext::isInitialized() || !\method_exists(Parser::class, 'prefetchGlobalDictionaryModules')) {
            return 0;
        }

        $hookNames = [];
        // Base chrome hooks only on the first prime; later layout fetches only scan new refs.
        if (!$this->isPrimed()) {
            foreach (self::STOREFRONT_BASE_HOOKS as $name) {
                $hookNames[] = $name;
            }
        }
        foreach ($this->scanHookNamesFromTemplateRefs($template, ...$templateRefs) as $name) {
            $hookNames[] = $name;
        }
        $hookNames = \array_values(\array_unique(\array_filter($hookNames, static fn(string $n): bool => $n !== '')));

        $modules = $this->resolveSourceModules($template, $hookNames);
        $already = $this->primedModules();
        $alreadySet = \array_fill_keys($already, true);
        $needed = \array_values(\array_filter(
            $modules,
            static fn(string $module): bool => !isset($alreadySet[$module]),
        ));
        if ($needed === []) {
            RequestContext::set(self::PRIMED_KEY, true);
            if ($already === [] && $modules !== []) {
                RequestContext::set(self::MODULES_KEY, $modules);
            } elseif ($already === []) {
                RequestContext::set(self::MODULES_KEY, []);
            }

            return 0;
        }

        RequestLifecycleTrace::measurePhase(
            'view.page.dictionary_prefetch',
            static fn() => Parser::prefetchGlobalDictionaryModules($needed),
            [
                'hooks' => \count($hookNames),
                'modules' => \count($needed),
                'module_set_hash' => \sha1(\implode('|', $needed)),
                'incremental' => $this->isPrimed(),
            ],
        );

        $merged = \array_values(\array_unique(\array_merge($already, $needed)));
        \sort($merged);
        RequestContext::set(self::PRIMED_KEY, true);
        RequestContext::set(self::MODULES_KEY, $merged);

        return \count($needed);
    }

    /**
     * Whether getHook may skip its own dictionary_prefetch phase for these modules.
     *
     * @param list<string> $modules
     */
    public static function coversModules(array $modules): bool
    {
        if (RequestContext::get(self::PRIMED_KEY) !== true) {
            return false;
        }
        if ($modules === []) {
            return true;
        }
        $primed = RequestContext::get(self::MODULES_KEY, []);
        if (!\is_array($primed) || $primed === []) {
            return false;
        }
        $set = [];
        foreach ($primed as $module) {
            if (\is_string($module) && $module !== '') {
                $set[$module] = true;
            }
        }
        foreach ($modules as $module) {
            if (!\is_string($module) || $module === '' || !isset($set[$module])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Record incremental modules discovered after page prime (nested hooks).
     *
     * @param list<string> $modules
     */
    public static function noteAdditionalModules(array $modules): void
    {
        if (RequestContext::get(self::PRIMED_KEY) !== true || $modules === []) {
            return;
        }
        $primed = RequestContext::get(self::MODULES_KEY, []);
        if (!\is_array($primed)) {
            $primed = [];
        }
        foreach ($modules as $module) {
            if (\is_string($module) && $module !== '') {
                $primed[] = $module;
            }
        }
        $primed = \array_values(\array_unique($primed));
        \sort($primed);
        RequestContext::set(self::MODULES_KEY, $primed);
    }

    /**
     * Chrome/base hook module union for deferred locale phrase prime (no Request latch).
     * Fail-open: returns fixed owners when HookReader/Template unavailable.
     *
     * @return list<string>
     */
    public static function resolveStorefrontChromeModules(): array
    {
        try {
            /** @var self $coordinator */
            $coordinator = ObjectManager::getInstance(self::class);
            /** @var Template $template */
            $template = ObjectManager::getInstance(Template::class);
            $modules = $coordinator->resolveSourceModules($template, self::STOREFRONT_BASE_HOOKS);
            if ($modules !== []) {
                return $modules;
            }
        } catch (\Throwable) {
        }

        return ['Weline_Seo', 'Weline_Theme'];
    }

    /**
     * @return list<string>
     */
    public static function collectHookNamesFromPhp(string $php): array
    {
        if ($php === '' || !\str_contains($php, 'getHook')) {
            return [];
        }
        $names = [];
        if (\preg_match_all('/->getHook\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $php, $m) > 0) {
            foreach ($m[1] as $name) {
                $name = \trim((string)$name);
                if ($name !== '') {
                    $names[$name] = $name;
                }
            }
        }

        return \array_values($names);
    }

    /**
     * @return list<string>
     */
    private function scanHookNamesFromTemplateRefs(Template $template, string ...$templateRefs): array
    {
        $names = [];
        foreach ($templateRefs as $ref) {
            $ref = \trim($ref);
            if ($ref === '') {
                continue;
            }
            try {
                $compiled = $template->getFetchFile($ref);
            } catch (\Throwable) {
                continue;
            }
            if (!\is_string($compiled) || $compiled === '' || !\is_file($compiled)) {
                continue;
            }
            $php = @\file_get_contents($compiled);
            if (!\is_string($php) || $php === '') {
                continue;
            }
            foreach (self::collectHookNamesFromPhp($php) as $name) {
                $names[$name] = $name;
            }
        }

        return \array_values($names);
    }

    /**
     * @param list<string> $hookNames
     * @return list<string>
     */
    private function resolveSourceModules(Template $template, array $hookNames): array
    {
        $modules = [];
        foreach ($hookNames as $hookName) {
            try {
                /** @var HookReader $hookReader */
                $hookReader = ObjectManager::make(HookReader::class);
                $hookReader->setPath($hookName);
                $hookFiles = $hookReader->getFileList();
            } catch (\Throwable) {
                continue;
            }
            if (!\is_array($hookFiles) || $hookFiles === []) {
                continue;
            }
            $soloHook = null;
            try {
                $withMeta = $hookReader->getFileListWithMeta();
                foreach ($withMeta as $module => $meta) {
                    if (!empty($meta['solo'])) {
                        $soloHook = $module;
                        break;
                    }
                }
            } catch (\Throwable) {
                $soloHook = null;
            }
            if ($soloHook !== null && isset($hookFiles[$soloHook])) {
                $hookFiles = [$soloHook => $hookFiles[$soloHook]];
            }
            foreach ($hookFiles as $hookFile) {
                try {
                    [, $sourceModule] = $template->processModuleSourceFilePath('hooks', (string)$hookFile);
                    if (\is_string($sourceModule) && $sourceModule !== '') {
                        $modules[$sourceModule] = $sourceModule;
                    }
                } catch (\Throwable) {
                    // Fail-open: page prime skips bad paths; getHook still prefetches.
                }
            }
        }
        $list = \array_values($modules);
        \sort($list);

        return $list;
    }
}
