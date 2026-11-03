<?php

declare(strict_types=1);

namespace Weline\Theme\Region;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;

/**
 * Renders the fragments of one global region.
 *
 * Fragments are rendered at request time rather than baked into the artifact:
 * several of them legitimately depend on site configuration (store music track
 * lists, support copy, affiliate parameters). Baking them would turn every
 * config edit into a repackage plus a full HTML invalidation; rendering is cheap
 * because the output is memoised per request by {@see GlobalRegion}.
 *
 * Enabled state resolution is injected as a closure so this class stays free of
 * site-config plumbing: the caller decides how a switch is read, and the default
 * is the value derived from the path (theme on, module off).
 */
final class FragmentRegionProvider implements GlobalRegionProviderInterface
{
    /** @var array<string, list<GlobalRegionFragment>> */
    private static array $scanCache = [];

    /**
     * 本次请求里「布局实体渲染器」读到的部件配置（widget code => config）。
     *
     * 编辑器保存的配置存在布局实体里，只有布局渲染知道；全局区片段是异步水合的，
     * 必须在占位里带上这份配置的签名令牌，水合请求才能按同一份配置渲染。
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $pageConfigs = [];

    /**
     * @param list<array{source:string, path:string}> $roots
     * @param (callable(GlobalRegionFragment):bool)|null $isEnabled
     */
    public function __construct(
        private readonly string $region,
        private readonly array $roots,
        private readonly mixed $isEnabled = null,
    ) {
    }

    public static function forProject(string $projectRoot, string $region, mixed $isEnabled = null): self
    {
        return new self($region, GlobalRegionFragmentScanner::defaultRoots($projectRoot), $isEnabled);
    }

    public function region(): string
    {
        return $this->region;
    }

    /** @return list<GlobalRegionFragment> */
    public function fragments(): array
    {
        $cacheKey = $this->region . '|' . $this->signature();
        if (!isset(self::$scanCache[$cacheKey])) {
            $scanned = (new GlobalRegionFragmentScanner($this->roots))->scan();
            self::$scanCache[$cacheKey] = \array_values(\array_filter(
                $scanned['fragments'],
                fn(GlobalRegionFragment $f): bool => $f->region === $this->region,
            ));
        }

        return self::$scanCache[$cacheKey];
    }

    /**
     * Cheap change detector for the fragment tree.
     *
     * Without it the scan result lived for the whole process lifetime, so dropping a
     * new fragment in place required restarting every worker before it rendered — that
     * made "edit then verify" untestable. Directory mtimes only move when files are
     * added or removed, which is exactly what changes the file *list*; file contents
     * are read at render time, so content edits already take effect immediately.
     */
    private function signature(): string
    {
        $parts = [];
        foreach ($this->roots as $root) {
            $base = \rtrim((string)($root['path'] ?? ''), '/\\');
            if ($base === '' || !\is_dir($base)) {
                $parts[] = $base . ':0';

                continue;
            }
            $parts[] = $base . ':' . (int)@\filemtime($base);
            foreach (GlobalRegionSourceRewriter::REGIONS as $region) {
                $regionPath = $base . '/' . $region;
                if (!\is_dir($regionPath)) {
                    continue;
                }
                $parts[] = $regionPath . ':' . (int)@\filemtime($regionPath);
                foreach ((array)@\scandir($regionPath) as $group) {
                    if ($group === '.' || $group === '..') {
                        continue;
                    }
                    $groupPath = $regionPath . '/' . $group;
                    if (\is_dir($groupPath)) {
                        $parts[] = $groupPath . ':' . (int)@\filemtime($groupPath);
                    }
                }
            }
        }

        return \md5(\implode('|', $parts));
    }

    public function render(): string
    {
        $html = '';
        $needsHydrator = false;
        $floatSlots = [];
        foreach ($this->fragments() as $fragment) {
            if (!$this->enabled($fragment)) {
                continue;
            }
            // 全局区 base 组件默认异步：页面只留占位，标记由浏览器稍后取回，
            // 避免每页都为全局内容付出 HTML 体积。声明 eager 的（像素引导）仍内联。
            if (!$fragment->eager && $fragment->widgetCode !== '') {
                $placeholder = $this->asyncPlaceholder($fragment, '/theme/frontend/widget/shell');
                $needsHydrator = true;
                if ($fragment->floatSlot !== '') {
                    // 浮层必须落在默认主题的浮动层容器里，否则绝对定位会相对正文。
                    $floatSlots[$fragment->floatSlot][] = $placeholder;
                    continue;
                }
                $html .= $placeholder;
                continue;
            }
            $html .= $this->renderFragment($fragment);
        }
        // 浮层槽位不自己造容器：主题已经有 `#w-storefront-float-layer`，多造一个会重复 id
        // 并破坏定位。这里只出「带槽位标记的占位」，由水合器在浏览器里挂进主题容器。
        foreach ($floatSlots as $slot => $placeholders) {
            $dock = [
                'start' => 'left',
                'end' => 'right',
                'side' => 'right',
                'middle-start' => 'left',
                'top-start' => 'left',
                'top-center' => 'up',
                'top-end' => 'right',
                'bottom-center' => 'down',
            ][$slot] ?? 'left';
            $html .= '<div class="w-storefront-float-layer__slot w-storefront-float-layer__slot--' . $slot . '"'
                . ' data-global-region-float="' . $slot . '"'
                . ' data-float-slot="' . $slot . '"'
                . ' data-float-dock="' . $dock . '"'
                . ' data-edge-dismiss-label="' . \htmlspecialchars((string)__('Collapse float'), ENT_QUOTES, 'UTF-8') . '"'
                . ' data-edge-recall-label="' . \htmlspecialchars((string)__('Expand float'), ENT_QUOTES, 'UTF-8') . '"'
                . ' data-testid="storefront-float-' . $slot . '">'
                . \implode('', $placeholders)
                . '</div>';
        }

        // 水合器随「本区有异步占位」一起出现：不能靠跨调用的静态标记，
        // 否则同一请求里更早一次（可能被丢弃的）渲染会把脚本吃掉。
        return $needsHydrator ? $this->hydratorScript() . $html : $html;
    }

    private function renderFragment(GlobalRegionFragment $fragment): string
    {
        $template = ObjectManager::getInstance(Template::class);
        $source = @\file_get_contents($fragment->path);
        if (!\is_string($source) || $source === '') {
            return '';
        }
        try {
            $compiled = $template->getFetchFileFromSource(
                $fragment->path,
                $source,
                $fragment->path,
                'global-region:' . $this->region,
            );
            // getFetchFileFromSource only compiles; ob_file executes and returns the HTML.
            // Fragments that replace a widget receive that widget's config values, so a
            // panel edit (copy, colours, track list) lands exactly like a slot widget's.
            // 合并顺序：已发布快照（编辑器）< 本请求布局实体捕获 < 全局区面板（面板优先）。
            $params = $fragment->widgetCode === ''
                ? []
                : self::mergeConfig(
                    self::editableConfigFor($fragment),
                    GlobalRegionSwitch::fromEnv()->configFor($fragment),
                );

            $html = $params === []
                ? (string)$template->ob_file($compiled)
                : (string)$template->fetchHtml($compiled, $params);

            return $this->wrapWithWidgetIdentity($fragment, $html);
        } catch (\Throwable) {
            // Per-fragment isolation: one broken fragment must not take the region down.
            return '';
        }
    }

    /**
     * 给片段套上与槽位部件同构的外壳（`widget-wrapper` + 身份三属性）。
     *
     * 后台编辑器靠 `data-widget-module/type/code` 认部件；片段没有这层外壳时会被当成
     * 「缺少身份的部件」，导致面板绑不上真节点、保存/发布被整体拦下（实测）。
     */
    private function wrapWithWidgetIdentity(GlobalRegionFragment $fragment, string $html): string
    {
        if ($html === '' || $fragment->widgetCode === '') {
            return $html;
        }
        $attributes = ' class="widget-wrapper"'
            . ' data-widget-code="' . \htmlspecialchars($fragment->widgetCode, ENT_QUOTES, 'UTF-8') . '"'
            . ' data-widget-module="' . \htmlspecialchars($fragment->widgetModule, ENT_QUOTES, 'UTF-8') . '"'
            . ' data-widget-type="' . \htmlspecialchars($fragment->widgetType, ENT_QUOTES, 'UTF-8') . '"'
            . ' data-global-region-fragment="' . \htmlspecialchars($fragment->id, ENT_QUOTES, 'UTF-8') . '"';

        return '<div' . $attributes . '>' . $html . '</div>';
    }

    private function enabled(GlobalRegionFragment $fragment): bool
    {
        if ($this->isEnabled === null) {
            return $fragment->defaultEnabled;
        }

        return (bool)($this->isEnabled)($fragment);
    }

    /**
     * Placeholder for a fragment that must not occupy the page's HTML.
     *
     * Global contributions are mostly invisible until the visitor needs them, so the page
     * only carries an empty node plus the endpoint that can render the real markup later.
     * The hydrator script is inlined once per response (it is ~2 KB and has no dependencies).
     */
    public function asyncPlaceholder(GlobalRegionFragment $fragment, string $endpointBase): string
    {
        // 配置不随占位带走了：水合端会用同一个 PublishedWidgetConfigResolver 现算，
        // 而占位里的令牌会被外壳缓存「冻结」在生成那一刻（发布后仍按旧值渲染，实测）。
        // 验签通道保留给旧页面兼容，但不再生成。
        // 端点必须带上当前语言前缀：水合是独立请求，前缀丢了就按默认语言渲染
        // （实测：`/ar_SA/` 页面里的「下载 App」片段水合成中文）。
        // w_url 可能已经带上查询串（本地开发穿章），因此用正确的分隔符拼接。
        $endpoint = self::localizedEndpoint($endpointBase);
        $separator = \str_contains($endpoint, '?') ? '&' : '?';
        $url = $endpoint . $separator . 'fragment=' . \urlencode($fragment->id);

        return '<div data-global-region-async="' . \htmlspecialchars($fragment->id, ENT_QUOTES, 'UTF-8') . '"'
            . ' data-global-region-endpoint="' . \htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"'
            . ' hidden></div>';
    }

    /**
     * 片段水合端点：统一走框架的 URL 生成（`w_url`），不在业务代码里自己拼前缀。
     *
     * 这样货币/语言前缀、站点挂载、SEO 重写、本地开发穿章都由 `Url` 一处决定；
     * 之前这里写死 `/theme/frontend/widget/shell`，水合请求丢了语言，
     * `/ar_SA/` 页面上的「下载 App」会渲染成中文（实测）。
     */
    private static function localizedEndpoint(string $endpointBase): string
    {
        try {
            $url = (string)\w_url($endpointBase);
            if ($url === '') {
                return $endpointBase;
            }
            $path = (string)(\parse_url($url, PHP_URL_PATH) ?? '');
            if ($path === '' || !\str_starts_with($path, '/')) {
                return $url !== '' ? $url : $endpointBase;
            }
            $query = (string)(\parse_url($url, PHP_URL_QUERY) ?? '');

            return $query === '' ? $path : $path . '?' . $query;
        } catch (\Throwable) {
            return $endpointBase;
        }
    }

    /** Hydrator markup, emitted once per region render that actually produced async placeholders. */
    private function hydratorScript(): string
    {
        return self::hydratorMarkup();
    }

    /**
     * 水合器标记（供区域渲染与出口兜底共用）。
     *
     * 出口兜底补占位时也必须带上它，否则那一页只有占位、没有水合逻辑。
     */
    public static function hydratorMarkup(): string
    {
        static $script = null;
        if ($script === null) {
            $contents = @\file_get_contents(__DIR__ . '/../view/statics/js/global-region-async.js');
            $script = \is_string($contents) && $contents !== ''
                ? '<script data-weline-global-region-hydrator>' . $contents . '</script>'
                : '';
        }

        return $script;
    }

    /**
     * Renders exactly one fragment (used by the async hydration endpoint).
     *
     * @param array<string, mixed> $overrides 水合请求带回来的、已验签的部件配置
     */
    public function renderOne(string $fragmentId, array $overrides = []): string
    {
        foreach ($this->fragments() as $fragment) {
            if ($fragment->id === $fragmentId && $this->enabled($fragment)) {
                if ($overrides !== [] && $fragment->widgetCode !== '') {
                    self::rememberPageConfig($fragment->widgetCode, $overrides);
                }

                return \trim($this->renderFragmentWithCache($fragment, $overrides));
            }
        }

        return '';
    }

    /**
     * 与访客无关的片段可按「片段+语言+货币+配置指纹」缓存渲染结果（`@global-region.cache {N}`）。
     *
     * 只对显式声明 TTL 的片段生效；购物车/会话相关片段必须保持 0，避免把某个访客的
     * 状态缓存给另一个访客。
     *
     * @param array<string, mixed> $overrides
     */
    private function renderFragmentWithCache(GlobalRegionFragment $fragment, array $overrides = []): string
    {
        if ($fragment->cacheTtl <= 0) {
            return $this->renderFragment($fragment);
        }
        $key = $this->fragmentCacheKey($fragment, $overrides);
        try {
            $cached = \w_cache('theme')->get($key);
            if (\is_string($cached) && $cached !== '') {
                return $cached;
            }
        } catch (\Throwable) {
            // 缓存不可用就直接渲染，视频片段不能因此空白。
        }
        $html = $this->renderFragment($fragment);
        if ($html !== '') {
            try {
                \w_cache('theme')->set($key, $html, $fragment->cacheTtl);
            } catch (\Throwable) {
                // 写入失败忽略：下一次请求重新渲染即可。
            }
        }

        return $html;
    }

    /** @param array<string, mixed> $overrides */
    private function fragmentCacheKey(GlobalRegionFragment $fragment, array $overrides = []): string
    {
        $locale = (string)(\Weline\Framework\Runtime\RequestContext::locale() ?? '');
        $currency = (string)(\Weline\Framework\Runtime\RequestContext::currency() ?? '');
        $config = self::editableConfigFor($fragment);
        if ($fragment->widgetCode !== '') {
            $config = self::mergeConfig($config, GlobalRegionSwitch::fromEnv()->configFor($fragment));
        }
        if ($overrides !== []) {
            $config = self::mergeConfig($config, $overrides);
        }

        return 'global-region.render.' . \sha1(\implode('|', [
            $fragment->id,
            $fragment->path,
            // 模板文件改动（含 theme:update 重写）自动失效，不依赖发布钩子。
            (string)(@\filemtime($fragment->path) ?: 0),
            $locale,
            $currency,
            GlobalRegionSourceRewriter::VERSION,
            (string)\json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]));
    }

    /** Test seam: the per-process scan cache would otherwise leak between cases. */
    public static function flushScanCache(): void
    {
        self::$scanCache = [];
        self::$pageConfigs = [];
    }

    /**
     * 记住布局实体里该部件的配置（编辑器保存的那份）。
     *
     * @param array<string, mixed> $config
     */
    public static function rememberPageConfig(string $widgetCode, array $config): void
    {
        if ($widgetCode === '' || $config === []) {
            return;
        }
        self::$pageConfigs[$widgetCode] = $config;
    }

    /** @return array<string, mixed> */
    public static function pageConfigFor(string $widgetCode): array
    {
        return self::$pageConfigs[$widgetCode] ?? [];
    }

    /**
     * 片段在「编辑器」这一层能拿到的配置：已发布快照 < 本请求布局实体捕获。
     *
     * @return array<string, mixed>
     */
    public static function editableConfigFor(GlobalRegionFragment $fragment): array
    {
        if ($fragment->widgetCode === '') {
            return [];
        }

        // 已发布（编辑器）配置优先于本请求布局实体捕获的旧配置：
        // 布局产物里的部件配置是历史快照，运营在后台改过之后必须以发布为准。
        $config = self::mergeConfig(
            self::pageConfigFor($fragment->widgetCode),
            PublishedWidgetConfigResolver::forWidgetCode($fragment->widgetCode),
        );
        if ($config !== []) {
            // 标记来源：片段模板可以据此区分「运营显式配置」与「注解默认值」，
            // 例如进店音乐不能把显式的 enabled=false 当成脏默认值丢掉。
            $config['__weline_config_source'] = 'editor';
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function mergeConfig(array $base, array $overrides): array
    {
        if ($base === []) {
            return $overrides;
        }
        if ($overrides === []) {
            return $base;
        }

        return \array_replace($base, $overrides);
    }

    /**
     * 占位里携带的配置令牌：`c` = base64url(JSON)，`s` = 对 `c` 的 HMAC。
     *
     * 水合是独立请求，服务端不保留上一跳的请求上下文，所以配置必须随占位一起带过去；
     * 用 HMAC 保证只接受本站渲染出的配置，客户端改不动。
     *
     * @param array<string, mixed> $config
     * @return array{c:string,s:string}
     */
    public static function configToken(array $config): array
    {
        if ($config === []) {
            return ['c' => '', 's' => ''];
        }
        $json = (string)\json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $encoded = \rtrim(\strtr(\base64_encode($json), '+/', '-_'), '=');

        return ['c' => $encoded, 's' => self::signConfig($encoded)];
    }

    /**
     * @return array<string, mixed>|null null 表示令牌缺失或签名不对（调用方按未携带处理）
     */
    public static function verifyConfigToken(string $encoded, string $signature): ?array
    {
        if ($encoded === '' || $signature === '') {
            return null;
        }
        if (!\hash_equals(self::signConfig($encoded), $signature)) {
            return null;
        }
        $json = \base64_decode(\strtr($encoded, '-_', '+/'), true);
        if (!\is_string($json) || $json === '') {
            return null;
        }
        $decoded = \json_decode($json, true);

        return \is_array($decoded) ? $decoded : null;
    }

    private static function signConfig(string $encoded): string
    {
        return \hash_hmac('sha256', $encoded, self::configSecret());
    }

    private static function configSecret(): string
    {
        try {
            $secret = (string)\Weline\Framework\App\Env::getInstance()->getConfig('theme/global_region_config_secret', '');
            if ($secret !== '') {
                return $secret;
            }
        } catch (\Throwable) {
            // 退回派生密钥：没有配置时也要能签/验，只是密钥随安装固定。
        }
        $root = \defined('BP') ? \rtrim((string)\BP, '/\\') : \getcwd();
        $pierce = @\file_get_contents($root . '/generated/framework/fpc_pierce_secret');

        return \hash('sha256', 'global-region-config|' . (\is_string($pierce) ? \trim($pierce) : ''));
    }
}
