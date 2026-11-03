<?php

declare(strict_types=1);

namespace Weline\Theme\Region;

/**
 * Runtime entry point for the three global regions.
 *
 * Page layouts carry `<?= GlobalRegion::render('head') ?>` (and the body_open /
 * body_end twins) because the compiler injects them. This class answers each call
 * by asking every registered provider of that region for markup.
 *
 * Two hard properties:
 *
 *   - **Per-request idempotence.** A layout may be composed of several nested
 *     templates, so the same region can be reached more than once in one request.
 *     Only the first call produces output; the rest return '' — otherwise every
 *     fragment would be emitted twice.
 *   - **Never fatal.** A misbehaving provider must not take the page down: its
 *     exception is swallowed (and reported by the provider itself) so the page
 *     still renders with the remaining regions.
 */
final class GlobalRegion
{
    /** @var list<GlobalRegionProviderInterface> */
    private static array $providers = [];

    /** @var array<string,bool> regions already rendered in this request */
    private static array $rendered = [];

    private static bool $defaultsRegistered = false;

    private static ?string $projectRoot = null;

    private static ?GlobalRegionSwitch $switch = null;

    public static function register(GlobalRegionProviderInterface $provider): void
    {
        self::$providers[] = $provider;
    }

    public static function render(string $region): string
    {
        $region = \trim($region);
        if ($region === '' || !\in_array($region, GlobalRegionSourceRewriter::REGIONS, true)) {
            return '';
        }
        if (isset(self::$rendered[$region])) {
            return '';
        }
        self::$rendered[$region] = true;

        self::registerDefaults();

        $html = '';
        foreach (self::$providers as $provider) {
            if (\trim($provider->region()) !== $region) {
                continue;
            }
            try {
                $html .= $provider->render();
            } catch (\Throwable $error) {
                if (\function_exists('w_log_warning')) {
                    \w_log_warning('[GlobalRegion] provider failed: ' . $error->getMessage(), [
                        'region' => $region,
                        'provider' => $error::class,
                    ]);
                }
            }
        }

        return $html;
    }

    /** Called at request teardown / by tests. */
    public static function reset(): void
    {
        self::$rendered = [];
        self::$providers = [];
        self::$defaultsRegistered = false;
        self::$switch = null;
        self::$replacedWidgets = [];
        // 片段清单按目录 mtime 缓存；长期运行的 worker 在「加/删片段后 mtime 精度不足」或
        // 缓存被上一版代码写入时会拿到缺项的清单，请求边界清一次最稳。
        FragmentRegionProvider::flushScanCache();
        // 已发布部件配置的进程级 memo 同理：发布后必须按新发布重算，
        // 否则常驻 worker 会把上一版配置一直发下去（实测：改回来了前端还关着）。
        PublishedWidgetConfigResolver::reset();
    }

    /** @var array<string, bool> */
    private static array $replacedWidgets = [];

    /**
     * 已启用、但当前 HTML 里没有出现的异步片段占位。
     *
     * 布局锚点是最先落地的路径，但它在个别页面上会被后续 HTML 处理吞掉其中某个节点；
     * 出口注入据此补齐缺失项，保证「启用的片段一定在页面上」（没有它，被吞的那一项
     * 在浏览器里永远水合不出来，且面板开关看起来失效）。
     */
    public static function missingAsyncPlaceholders(string $html): string
    {
        self::registerDefaults();
        $switch = self::$switch ?? GlobalRegionSwitch::fromEnv();
        $append = '';
        foreach (self::$providers as $provider) {
            if (!$provider instanceof FragmentRegionProvider) {
                continue;
            }
            foreach ($provider->fragments() as $fragment) {
                if ($fragment->eager || $fragment->widgetCode === '' || !$switch->allows($fragment)) {
                    continue;
                }
                if (\str_contains($html, 'data-global-region-async="' . $fragment->id . '"')) {
                    continue;
                }
                $append .= $provider->asyncPlaceholder($fragment, '/theme/frontend/widget/shell');
            }
        }

        return $append;
    }

    /**
     * Render one fragment by id — the async hydration endpoint's entry point.
     *
     * Only enabled fragments render, so the endpoint cannot be used to pull in markup the
     * site switch has turned off.
     */
    public static function renderFragment(string $fragmentId, array $overrides = []): string
    {
        $fragmentId = \trim($fragmentId);
        if ($fragmentId === '') {
            return '';
        }
        self::registerDefaults();
        foreach (self::$providers as $provider) {
            if (!$provider instanceof FragmentRegionProvider) {
                continue;
            }
            $html = $provider->renderOne($fragmentId, $overrides);
            if ($html !== '') {
                return $html;
            }
        }

        return '';
    }

    /**
     * True when an enabled global-region fragment takes over this widget code.
     *
     * Base contributions moved here keep their widget identity (`@widget.code`), so
     * the legacy widget must stop rendering into its theme slot — otherwise every
     * page carries two copies and the panel switch appears to do nothing.
     */
    public static function replacesWidget(string $widgetCode): bool
    {
        $widgetCode = \trim($widgetCode);
        if ($widgetCode === '') {
            return false;
        }
        if (\array_key_exists($widgetCode, self::$replacedWidgets)) {
            return self::$replacedWidgets[$widgetCode];
        }

        self::registerDefaults();
        $switch = self::$switch ?? GlobalRegionSwitch::fromEnv();
        $replaced = false;
        foreach (self::$providers as $provider) {
            if (!$provider instanceof FragmentRegionProvider) {
                continue;
            }
            foreach ($provider->fragments() as $fragment) {
                if ($fragment->widgetCode === $widgetCode && $switch->allows($fragment)) {
                    $replaced = true;
                    break 2;
                }
            }
        }

        return self::$replacedWidgets[$widgetCode] = $replaced;
    }

    /**
     * Self-bootstrapping registration: nothing else has to wire the fragment
     * providers, which keeps the contract usable from any area (frontend, backend)
     * without touching per-area bootstrap files.
     */
    private static function registerDefaults(): void
    {
        if (self::$defaultsRegistered) {
            return;
        }
        self::$defaultsRegistered = true;

        $projectRoot = self::$projectRoot ?? (\defined('BP') ? \rtrim((string)\BP, '/\\') : '');
        if ($projectRoot === '' || !\is_dir($projectRoot . '/app')) {
            return;
        }
        self::$projectRoot = $projectRoot;

        $switch = self::$switch ?? GlobalRegionSwitch::fromEnv();
        $isEnabled = static fn(GlobalRegionFragment $fragment): bool => $switch->allows($fragment);

        foreach (GlobalRegionSourceRewriter::REGIONS as $region) {
            self::$providers[] = FragmentRegionProvider::forProject($projectRoot, $region, $isEnabled);
        }
    }

    /** Test seam: point the defaults at a fixture project tree. */
    public static function useProjectRoot(string $projectRoot): void
    {
        self::$projectRoot = $projectRoot;
        self::$defaultsRegistered = false;
        self::$providers = [];
        self::$rendered = [];
    }

    /** Test seam: override the switch tree the defaults consult. */
    public static function useSwitch(?GlobalRegionSwitch $switch): void
    {
        self::$switch = $switch;
        self::$defaultsRegistered = false;
        self::$providers = [];
        self::$rendered = [];
    }
}
