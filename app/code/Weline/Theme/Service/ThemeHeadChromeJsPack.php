<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Compilation\AtomicCompiledFilePublisher;
use Weline\Framework\Runtime\ThemeApplicationContext;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Minify\StaticAssetMinifier;
use Weline\Theme\Model\WelineTheme;

/**
 * Head chrome classic JS packs (prepaint / css-ready slots).
 *
 * Gated by resource_files/theme_js_merge. type=module scripts (weline-ui.js) are never packed.
 * work_mode=theme_module_runtime
 */
final class ThemeHeadChromeJsPack
{
    public const PACK_EARLY = 'early';
    public const PACK_AFTER_CSS = 'after-css';

    public function __construct(
        private readonly ThemeResourceConfig $resourceConfig,
        private readonly ThemeContextService $themeContext,
        private readonly ThemeResourceGateway $gateway,
        private readonly StaticAssetMinifier $minifier,
        private readonly AtomicCompiledFilePublisher $atomicPublisher,
    ) {
    }

    public function isEnabled(string $area = 'frontend'): bool
    {
        $area = $this->themeContext->normalizeArea($area);
        try {
            return !empty($this->resourceConfig->resolve(null, $area)['theme_js_merge']);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Publish area JS packs so Formal/com warm path does not cold-miss on first request.
     */
    public function warmAreaPacks(string $area = 'frontend'): void
    {
        $area = $this->themeContext->normalizeArea($area);
        if (!$this->isEnabled($area)) {
            return;
        }
        foreach ([self::PACK_EARLY, self::PACK_AFTER_CSS] as $pack) {
            try {
                $this->publishPack($area, $pack);
            } catch (\Throwable $e) {
                if (function_exists('w_log_warning')) {
                    w_log_warning('Theme head chrome JS warm failed: ' . $e->getMessage());
                }
            }
        }
    }

    /**
     * Emit a packed &lt;script&gt;, or null when packing is off / publish failed.
     */
    public function tryScriptHtml(string $area, string $pack): ?string
    {
        $area = $this->themeContext->normalizeArea($area);
        if (!$this->isEnabled($area)) {
            return null;
        }
        try {
            $url = $this->publishPack($area, $pack);
        } catch (\Throwable $e) {
            if (function_exists('w_log_warning')) {
                w_log_warning('Theme head chrome JS pack failed: ' . $e->getMessage());
            }

            return null;
        }
        if ($url === '') {
            return null;
        }
        $safe = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<script src="' . $safe . '" data-weline-theme-head-pack="'
            . htmlspecialchars($pack, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"></script>';
    }

    private function publishPack(string $area, string $pack): string
    {
        $theme = $this->resolveTheme($area);
        $sources = $this->collectSources($area, $pack);
        if ($sources === []) {
            return '';
        }
        $minify = !empty($this->resourceConfig->resolve($theme, $area)['js_minify']);
        $scopeFp = $this->resolvePackScopeFingerprint($area);
        $key = hash('sha256', json_encode([
            'theme-head-js-v2',
            $area,
            $pack,
            $minify,
            (int)($theme?->getId() ?? 0),
            $scopeFp,
            array_column($sources, 'hash'),
        ], JSON_THROW_ON_ERROR));
        // Pin artifact namespace to the same theme used for source collection.
        $target = $this->gateway->buildHeadChromeArtifact($key, $area, 'js', $theme);
        if ($target === null) {
            return '';
        }
        if (!is_file($target['path'])) {
            $chunks = [];
            foreach ($sources as $source) {
                $content = $source['content'];
                if ($minify) {
                    $content = $this->minifier->minifyFileContent($content, 'js');
                }
                $chunks[] = '/* ' . $source['label'] . ' */' . "\n" . $content;
            }
            $body = implode("\n;\n", $chunks);
            if ($body === '') {
                return '';
            }
            $this->atomicPublisher->publish($target['path'], $body);
        }

        return $target['url'];
    }

    /**
     * @return list<array{label:string,path:string,content:string,hash:string}>
     */
    private function collectSources(string $area, string $pack): array
    {
        $leaves = match ($pack) {
            self::PACK_EARLY => ['ui/weline-theme-prepaint.js'],
            self::PACK_AFTER_CSS => ['ui/weline-css-ready.js'],
            default => [],
        };
        // Backend head also uses prepaint + css-ready; minimal frontend has early only.
        if ($area === 'backend' && $pack === self::PACK_AFTER_CSS) {
            $leaves = ['ui/weline-css-ready.js'];
        }
        $sources = [];
        foreach ($leaves as $leaf) {
            $resolved = $this->resolveStaticsLeaf($leaf);
            if ($resolved !== null) {
                $sources[] = $resolved;
            }
        }

        return $sources;
    }

    /**
     * @return array{label:string,path:string,content:string,hash:string}|null
     */
    private function resolveStaticsLeaf(string $relativeUnderStatics): ?array
    {
        $relativeUnderStatics = ltrim(str_replace('\\', '/', $relativeUnderStatics), '/');
        $module = Env::getInstance()->getModuleList()['Weline_Theme'] ?? null;
        if (!is_array($module)) {
            return null;
        }
        $absolute = rtrim((string)$module['base_path'], '/\\') . '/view/statics/' . $relativeUnderStatics;
        if (!is_file($absolute)) {
            return null;
        }
        $content = file_get_contents($absolute);
        if (!is_string($content) || $content === '') {
            return null;
        }

        return [
            'label' => $relativeUnderStatics,
            'path' => $absolute,
            'content' => $content,
            'hash' => hash('sha256', $absolute . '|' . filemtime($absolute) . '|' . $content),
        ];
    }

    private function resolveTheme(string $area): ?WelineTheme
    {
        $current = ThemeData::getCurrentTheme();
        if ($current instanceof WelineTheme && (int)$current->getId() > 0) {
            return $current;
        }
        try {
            $resolved = $this->themeContext->resolveTheme($area)
                ?? $this->themeContext->resolveRegisteredDefaultTheme($area);

            return $resolved instanceof WelineTheme && (int)$resolved->getId() > 0 ? $resolved : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, scalar>
     */
    private function resolvePackScopeFingerprint(string $area): array
    {
        try {
            $app = ThemeApplicationContext::current($area);
            if ($app instanceof ThemeApplicationContext) {
                return [
                    'scope_key' => $app->scopeKey,
                    'store_mode' => $app->storeMode,
                    'theme_id' => $app->themeId,
                    'provider' => $app->provider,
                    'version_owner_scope' => $app->versionOwnerScope,
                    'theme_version_id' => $app->themeVersionId,
                    'content_revision' => $app->contentRevision,
                ];
            }
        } catch (\Throwable) {
        }

        return [
            'scope_key' => 'unbound',
            'store_mode' => '',
            'theme_id' => 0,
            'provider' => '',
            'version_owner_scope' => '',
            'theme_version_id' => 0,
            'content_revision' => 0,
        ];
    }
}
