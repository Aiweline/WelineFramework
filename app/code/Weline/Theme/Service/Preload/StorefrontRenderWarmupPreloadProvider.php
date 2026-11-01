<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Preload;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Preload\AbstractWorkerPreloadProvider;
use Weline\Framework\Runtime\Preload\WorkerPreloadContext;
use Weline\Framework\Runtime\Preload\WorkerPreloadResult;
use Weline\Framework\Runtime\RuntimeInterface;
use Weline\Framework\View\Template;
use Weline\Theme\Block\Partials;
use Weline\Theme\Service\SharedChromeService;

/**
 * Publish the storefront render into process memory before the Worker is READY.
 *
 * Root cause this removes: the chrome partials (`theme.partials.fetch.head`,
 * `theme.partials.fetch.header`) carry a request-path-keyed render cache, so a
 * Worker that has served nothing yet has to build them inside the first request
 * that arrives. Measured on `/best-sellers` with FPC pierced (so the request
 * always reaches the Worker):
 *
 *     steady-state render      ~132 ms   (partial render cache warm)
 *     first-request render     568-936 ms
 *       theme.partials.fetch.head   113-306 ms   <-- the gap is here
 *       theme.partials.fetch.header  33-40 ms
 *
 * The first-request cost is a build, not a per-request cost: the same partial
 * served out of the process cache costs ~1.2 ms. Per the project rule that global
 * data must be in process memory *before* READY — never lazily on the first
 * request — the build belongs in Worker boot.
 *
 * Module-owned: the Theme module declares what to warm and its dependency order;
 * the framework runtime only schedules. Invalidation stays in this module
 * (`theme_catalogue` / `theme_layout` / `widget_registry`), which is why those
 * keys are declared here rather than managed by the runtime.
 */
final class StorefrontRenderWarmupPreloadProvider extends AbstractWorkerPreloadProvider
{
    /** Storefront page used as the warm-up document. */
    public const WARMUP_PATH = '/best-sellers';

    /**
     * Templates the storefront page document renders through. Compiling these
     * once at boot removes the compiler from the first request.
     */
    private const COMPILED_TEMPLATES = [
        'Weline_Product::templates/frontend/best-sellers/index.phtml',
        'Weline_Theme::theme/frontend/partials/head/default.phtml',
        'Weline_Theme::theme/frontend/partials/header/default.phtml',
        'Weline_Theme::theme/frontend/partials/footer/default.phtml',
        'Weline_Theme::theme/frontend/partials/breadcrumb/default.phtml',
    ];

    /**
     * Hooks the storefront header renders as fallbacks.
     *
     * Each hook carries its OWN first-render cost — measured cold, all six together
     * were 183.54 ms, while a second pass over the same six was 4.60 ms:
     *
     *     header-language-switcher   116.19 ms  -> 2.33 ms
     *     header-wishlist-icon        23.83 ms  -> 0.26 ms
     *     header-account-links        21.94 ms  -> 1.63 ms
     *     header-currency-switcher    19.40 ms  -> 0.31 ms
     *     header-hamburger-menu        1.31 ms  -> 0.04 ms
     *     header-user-info             0.87 ms  -> 0.03 ms
     *
     * Warming one representative hook therefore does NOT cover the others, which is
     * why every one the header actually renders is listed here.
     */
    private const WARM_HOOKS = [
        'header-language-switcher',
        'header-currency-switcher',
        'header-account-links',
        'header-wishlist-icon',
        'header-hamburger-menu',
        'header-user-info',
    ];

    /**
     * Time box for the warm-up render.
     *
     * The warm-up runs before READY, so it must never be able to stall boot. On
     * this machine a cold storefront render is ~600-900 ms; the cap allows that
     * plus head-room and gives up otherwise (fail-open: a slow warm-up must not
     * take the Worker out of service).
     */
    private const BUDGET_SECONDS = 20.0;

    public function code(): string
    {
        return 'storefront_render';
    }

    /**
     * After the catalogue and the site/theme application context: the render
     * resolves placeable widgets and the active theme version, both of which
     * those providers publish.
     */
    public function priority(): int
    {
        return 68;
    }

    public function dependsOn(): array
    {
        return ['theme_catalogue', 'websites_theme_application', 'website_directory'];
    }

    public function isEnabled(WorkerPreloadContext $context): bool
    {
        return $context->runtimeMode() === RuntimeInterface::MODE_WLS;
    }

    /**
     * A published theme layout / catalogue change must drop these entries, and
     * the entries are keyed by the source fingerprint, so dropping them on
     * theme refresh is exactly what makes the next Worker rebuild instead of
     * serving the previous theme's chrome.
     */
    public function invalidationKeys(): array
    {
        return ['theme_catalogue', 'theme_layout', 'widget_registry'];
    }

    public function preload(WorkerPreloadContext $context): WorkerPreloadResult
    {
        unset($context);
        $start = \microtime(true);
        $memoryStart = \memory_get_usage(true);

        $deadline = $start + self::BUDGET_SECONDS;
        $items = 0;
        $failures = [];

        /** @var Partials $partials */
        $partials = ObjectManager::getInstance(Partials::class);

        // Storefront chrome. The frontend head partial is page-scoped (its cache
        // key carries the request path), so it is warmed for the warm-up document
        // itself; that is the document the READY gate and the first real request
        // both render.
        $options = (string)Env::get('wls.worker.storefront_render_warmup_option', 'default');

        foreach ([
            'header' => $options,
            'footer' => $options,
            'head' => $options,
        ] as $type => $option) {
            if (\microtime(true) >= $deadline) {
                $failures['budget'] = 'deadline exceeded before ' . $type;
                break;
            }
            $result = $this->warmFrontendPartial($partials, $type, $option);
            if (isset($result['error'])) {
                $failures[$type] = $result['error'];
                continue;
            }
            if ($result['warmed'] === true) {
                ++$items;
            }
        }

        // Compiled artifacts of the storefront page template.
        //
        // Separately from the output caches above, the *compiler* still had to
        // run on the first request: resolving `best-sellers/index.phtml` to its
        // compiled artifact measured 100.85 ms cold in a fresh process and
        // `partials/head/default.phtml` 37.28 ms, while every later resolution in
        // that process costs 0.3-0.5 ms. That is the compile, not the parse of a
        // 449 KB document — which the micro-benchmark put at 12 ms. Compiling the
        // page's own templates once here leaves the request path with "artifact
        // exists -> include it", never "compile it".
        if (\microtime(true) < $deadline) {
            $compiled = $this->warmCompiledArtifacts();
            $items += $compiled['items'];
            foreach ($compiled['failures'] as $key => $message) {
                $failures[$key] = $message;
            }
        }

        // Execution-time warm-up.
        //
        // Compiling an artifact into opcache does NOT remove the real cost: measured
        // first `opcache_compile_file` of the 60 KB header artifact is 0.88 ms while
        // executing it costs 456 ms. The expensive part is running the template's PHP,
        // and the dominant slice of that is one-time service/URL initialisation that
        // every Worker pays on its first chrome render:
        //
        //     Template::getInstance()                    21.16 ms
        //     emitPartialsHeaderShellLayoutCss 1st call   7.22 ms   (2nd: 0.16 ms)
        //
        // Both are pure process-local initialisation, so they belong before READY.
        if (\microtime(true) < $deadline) {
            $exec = $this->warmExecutionPath();
            $items += $exec['items'];
            foreach ($exec['failures'] as $key => $message) {
                $failures[$key] = $message;
            }
        }

        $durationMs = \round((\microtime(true) - $start) * 1000, 2);
        $memoryDelta = \memory_get_usage(true) - $memoryStart;

        if ($items === 0) {
            $reason = $failures === []
                ? 'no storefront chrome partial produced output'
                : \implode('; ', \array_slice(\array_map(
                    static fn(string $key, string $value): string => $key . ': ' . $value,
                    \array_keys($failures),
                    $failures,
                ), 0, 3));

            return WorkerPreloadResult::failed(
                $this->code(),
                $this->phase(),
                $reason,
                $durationMs,
                $memoryDelta,
            );
        }

        return WorkerPreloadResult::warmed($this->code(), $this->phase(), $items, $durationMs, $memoryDelta);
    }

    /**
     * Render one frontend partial so its output cache entry exists before the
     * first request. Returns whether non-empty output was produced; failures are
     * reported instead of thrown so one missing partial cannot fail Worker boot.
     *
     * @return array{warmed?: bool, error?: string}
     */
    private function warmFrontendPartial(Partials $partials, string $type, string $option): array
    {
        try {
            $html = $partials->renderPartials('frontend', $type, [], $option);
        } catch (\Throwable $exception) {
            return ['error' => $exception->getMessage()];
        }

        return ['warmed' => \is_string($html) && \trim($html) !== ''];
    }

    /**
     * Compile the storefront page template chain so the request path only reads
     * the compiled artifact. Resolution failures are reported, never thrown: a
     * renamed template must not fail Worker boot.
     *
     * @return array{items: int, failures: array<string, string>}
     */
    private function warmCompiledArtifacts(): array
    {
        $items = 0;
        $failures = [];
        $template = Template::getInstance();

        // Warm under the SAME compile context the storefront request uses.
        //
        // Compiled chrome artifacts are partitioned by (lang, currency) — the path
        // carries a `<lang>/<currency>/` segment. A warm-up that resolves them under
        // the CLI defaults writes opcache entries for `<zh_Hans_CN>/<CNY>/…`, while
        // the live storefront reads `<zh_Hans_CN>/<USD>/…`: different files, so the
        // first request still paid a full first-include compile (measured ~98 ms per
        // chrome partial). Resolve the site's real defaults before warming.
        $previousLang = \Weline\Framework\Env\WelineEnv::getLang();
        $previousCurrency = \Weline\Framework\Env\WelineEnv::getCurrency();
        try {
            [$lang, $currency] = $this->resolveStorefrontCompileContext();
            if ($lang !== '') {
                \Weline\Framework\Env\WelineEnv::setLang($lang);
            }
            if ($currency !== '') {
                \Weline\Framework\Env\WelineEnv::setCurrency($currency);
            }

            foreach (self::COMPILED_TEMPLATES as $index => $fileName) {
                try {
                    $compiled = (string)$template->getFetchFile($fileName);
                } catch (\Throwable $exception) {
                    $failures['tpl' . $index] = $fileName . ': ' . $exception->getMessage();
                    continue;
                }
                if ($compiled === '' || !\is_file($compiled)) {
                    $failures['tpl' . $index] = $fileName . ': no compiled artifact';
                    continue;
                }
                if (\function_exists('opcache_compile_file')) {
                    @\opcache_compile_file($compiled);
                }
                ++$items;
            }
        } finally {
            if ($previousLang !== '') {
                \Weline\Framework\Env\WelineEnv::setLang($previousLang);
            }
            if ($previousCurrency !== '') {
                \Weline\Framework\Env\WelineEnv::setCurrency($previousCurrency);
            }
        }

        return ['items' => $items, 'failures' => $failures];
    }

    /**
     * Storefront default (lang, currency) used to partition compiled chrome artifacts.
     *
     * Fail-open: an unresolvable site keeps the process defaults.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveStorefrontCompileContext(): array
    {
        $lang = '';
        $currency = '';
        try {
            /** @var \Weline\Websites\Model\Website $website */
            $website = ObjectManager::getInstance(\Weline\Websites\Model\Website::class);
            $website = (clone $website)->clearData()->clearQuery()->load(
                \Weline\Websites\Model\Website::ID_DEFAULT,
                null,
                true,
            );
            if ((int)$website->getId() > 0) {
                $lang = \trim((string)$website->getDefaultLanguage());
                $currency = \trim((string)$website->getDefaultCurrency());
            }
        } catch (\Throwable) {
        }

        return [$lang, $currency];
    }

    /**
     * Run the storefront chrome's process-local initialisation before READY.
     *
     * Everything here is pure in-process warm-up with no output side effects:
     * instantiate the shared Template singleton and pre-resolve the header shell
     * CSS URLs so the first real chrome render does not pay for them.
     *
     * @return array{items: int, failures: array<string, string>}
     */
    private function warmExecutionPath(): array
    {
        $items = 0;
        $failures = [];

        try {
            Template::getInstance();
            ++$items;
        } catch (\Throwable $exception) {
            $failures['template'] = $exception->getMessage();
        }

        // Header shell CSS: the FIRST emitModuleLayoutCssHtml call pays the whole
        // static-URL resolver bootstrap (measured 26.85 ms for the first entry,
        // 0.24 ms for the remaining six combined).
        try {
            $css = SharedChromeService::emitPartialsHeaderShellLayoutCssHtml();
            if (\is_string($css) && \trim($css) !== '') {
                ++$items;
            } else {
                $failures['header_shell_css'] = 'empty output';
            }
        } catch (\Throwable $exception) {
            $failures['header_shell_css'] = $exception->getMessage();
        }

        // Hook render chain.
        //
        // Every hook the header renders carries its own first-render cost. Measured
        // cold, all six together were 183.54 ms (see WARM_HOOKS for the breakdown),
        // against 4.60 ms on a second pass. Warming one representative hook does not
        // cover the rest, so each one is warmed here.
        try {
            $template = Template::getInstance();
            foreach (self::WARM_HOOKS as $hook) {
                $template->getHookResult($hook, true, true);
                ++$items;
            }
        } catch (\Throwable $exception) {
            $failures['hook_chain'] = $exception->getMessage();
        }

        return ['items' => $items, 'failures' => $failures];
    }
}
