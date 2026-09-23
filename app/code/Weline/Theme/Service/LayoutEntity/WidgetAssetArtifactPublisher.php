<?php
declare(strict_types=1);
namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\CachePolicy;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Compilation\AtomicCompiledFilePublisher;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Minify\StaticAssetMinifier;
use Weline\Theme\Service\StorefrontThemeCacheCoordinator;
use Weline\Theme\Service\ThemeResourceGateway;

/** Local module sources only; unresolved external resources keep their original link. */
class WidgetAssetArtifactPublisher
{
    public function canMerge(array $asset): bool
    {
        $source = $this->source($asset);
        return $source !== null && $source['relocatable'] && $source['mergeable']
            && !preg_match('/\b(?:media|disabled)\s*(?:=|>)/i', $asset['tag'])
            && (!str_starts_with(strtolower($asset['tag']), '<script') || preg_match('/\sdefer(?:\s|=|>)/i', $asset['tag']));
    }

    public function publish(array $assets, bool $minify): ?string
    {
        try {
            $sources = [];
            foreach ($assets as $asset) {
                $source = $this->source($asset);
                if ($source === null || !$source['relocatable']) { return null; }
                $sources[] = $source;
            }
            $type = $assets[0]['type'];
            $key = hash('sha256', json_encode([$this->implementationVersion(), $type, $minify, array_column($sources, 'hash'), array_column($assets, 'url')], JSON_THROW_ON_ERROR));
            $gateway = ObjectManager::getInstance(ThemeResourceGateway::class);
            $target = $gateway->buildWidgetAssetArtifact($key, $type, $assets[0]['area'] ?? 'frontend');
            if ($target === null) { return null; }
            if (!is_file($target['path'])) {
                $contents = [];
                foreach ($sources as $index => $source) {
                    $content = $source['content'];
                    if ($type === 'css') { $content = $this->rewriteCssUrls($content, $assets[$index]['url']); }
                    if ($minify) { $content = ObjectManager::getInstance(StaticAssetMinifier::class)->minifyFileContent($content, $type); }
                    $contents[] = $content;
                }
                $content = implode($type === 'js' ? "\n;\n" : "\n", $contents);
                if ($content === '') { return null; }
                ObjectManager::getInstance(AtomicCompiledFilePublisher::class)->publish($target['path'], $content);
            }
            return $target['url'];
        } catch (\Throwable $e) {
            if (function_exists('w_log_warning')) { w_log_warning('Widget resource publication failed: ' . $e->getMessage()); }
            return null;
        }
    }

    private function implementationVersion(): string
    {
        $files = [__FILE__, (new \ReflectionClass(StaticAssetMinifier::class))->getFileName(),
            (new \ReflectionClass(\Weline\Theme\Minify\Css\CssMin::class))->getFileName(),
            (new \ReflectionClass(\Weline\Theme\Minify\Js\JsMin::class))->getFileName()];
        $stamp = implode('|', array_map(static fn(string $file): string => $file . ':' . filemtime($file) . ':' . filesize($file), $files));
        return ObjectManager::getInstance(StorefrontScopeHotCache::class)->rememberPolicy(new CachePolicy(
            resource: 'theme.widget_asset_transform_version', pool: StorefrontThemeCacheCoordinator::LAYOUT_ENTITY_PUBLISHED_PROJECTION_POOL,
            scope: 'global', dependencies: ['global/storefront/theme'], freshTtlSeconds: 3600, staleTtlSeconds: 3600,
        ), hash('sha256', $stamp), static fn(): string => hash('sha256', implode('|', array_map('hash_file', array_fill(0, count($files), 'sha256'), $files))));
    }

    private function source(array $asset): ?array
    {
        $moduleSource = (string)($asset['module_source'] ?? '');
        if (!preg_match('/^([A-Za-z][A-Za-z0-9]*_[A-Za-z][A-Za-z0-9]*)::(.+)$/', $moduleSource, $match)) { return null; }
        $relative = str_replace('\\', '/', $match[2]);
        if (in_array('..', explode('/', $relative), true)) { return null; }
        $module = Env::getInstance()->getModuleList()[$match[1]] ?? null;
        if (!is_array($module)) { return null; }
        $path = rtrim($module['base_path'], '/\\') . '/view/statics/' . ltrim(preg_replace('~^statics/~', '', $relative), '/');
        if (!is_file($path)) { return null; }
        $type = $asset['type'] ?? (str_starts_with(strtolower($asset['tag']), '<link') ? 'css' : 'js');
        $tag = $asset['tag'];
        if (preg_match('/\b(?:integrity|async|nomodule)\b|\btype=["\']module["\']/i', $tag)) { return null; }
        // Source metadata is cheap on warm requests; content/minification is behind the framework hot cache.
        $key = 'widget-source-v3|' . $path . '|' . filemtime($path) . '|' . filesize($path);
        $builder = static function () use ($path, $type): ?array {
            $content = file_get_contents($path);
            if (!is_string($content)) { return null; }
            $relocatable = $type === 'css'
                ? !preg_match('/@(?:import|namespace)\b/i', $content)
                : !preg_match('/\b(?:import\s*(?:\(|\.|[\w{*])|export\s|document\s*\.\s*currentScript\b)/', $content);
            // A script-level strict directive must not change the mode of a neighbouring file.
            $leading = preg_replace('~\A(?:\s+|/\*.*?\*/|//[^\n]*\n)+~s', '', $content) ?? $content;
            $mergeable = $type !== 'js' || self::isIsolatedJavaScript($leading);
            return ['content' => $content, 'hash' => hash('sha256', $content), 'relocatable' => $relocatable, 'mergeable' => $mergeable];
        };
        return ObjectManager::getInstance(StorefrontScopeHotCache::class)->rememberPolicy(new CachePolicy(
            resource: 'theme.widget_asset_source', pool: StorefrontThemeCacheCoordinator::LAYOUT_ENTITY_PUBLISHED_PROJECTION_POOL,
            scope: 'global', dependencies: ['global/storefront/theme'], freshTtlSeconds: 3600, staleTtlSeconds: 3600,
        ), $key, $builder);
    }

    /** Recognize complete generated callback/IIFE statements; uncertain syntax remains a separate file. */
    public static function isIsolatedJavaScript(string $source): bool
    {
        if (!preg_match('/^(?:\(\s*function\b|window\.WelineWidgetAssets\.register\s*\()/s', $source)) { return false; }
        $start = strpos($source, '(');
        $stack = [];
        $length = strlen($source);
        for ($i = $start; $i < $length; ++$i) {
            $char = $source[$i];
            if ($char === '"' || $char === "'") {
                $quote = $char;
                for (++$i; $i < $length; ++$i) {
                    if ($source[$i] === '\\') { ++$i; continue; }
                    if ($source[$i] === $quote) { break; }
                }
                if ($i >= $length) { return false; }
                continue;
            }
            if ($char === '`') { return false; }
            if (substr($source, $i, 2) === '//') {
                $end = strpos($source, "\n", $i + 2);
                if ($end === false) { return false; }
                $i = $end;
                continue;
            }
            if (substr($source, $i, 2) === '/*') {
                $end = strpos($source, '*/', $i + 2);
                if ($end === false) { return false; }
                $i = $end + 1;
                continue;
            }
            // Regex literals need a full JS lexer; retaining the external file preserves their semantics.
            if ($char === '/') { return false; }
            if (str_contains('({[', $char)) { $stack[] = $char; }
            elseif (str_contains(')}]', $char)) {
                $open = array_pop($stack);
                if ($open === null || strpos('({[', $open) !== strpos(')}]', $char)) { return false; }
                if ($stack === []) {
                    $tail = substr($source, $i + 1);
                    return (bool)preg_match('/^\s*(?:\(\s*(?:[\w$]+(?:\s*,\s*[\w$]+)*)?\s*\))?\s*;?\s*$/', $tail);
                }
            }
        }
        return false;
    }

    public function rewriteCssUrls(string $content, string $sourceUrl): string
    {
        $output = '';
        $length = strlen($content);
        for ($i = 0; $i < $length;) {
            // Strings and comments are opaque; text such as content:"url(x)" is not a resource.
            if (substr($content, $i, 2) === '/*') {
                $end = strpos($content, '*/', $i + 2);
                $end = $end === false ? $length : $end + 2;
                $output .= substr($content, $i, $end - $i);
                $i = $end;
                continue;
            }
            if ($content[$i] === '"' || $content[$i] === "'") {
                $end = $this->cssStringEnd($content, $i);
                $output .= substr($content, $i, $end - $i);
                $i = $end;
                continue;
            }
            if ($content[$i] === '\\') { throw new \RuntimeException('Escaped CSS identifier retains its original resource'); }
            $isBoundary = $i === 0 || !preg_match('/[a-z0-9_\\-]/i', $content[$i - 1]);
            if (!$isBoundary || !preg_match('/\Gurl\s*\(/i', $content, $function, 0, $i)) {
                $output .= $content[$i++];
                continue;
            }
            $start = $i;
            $cursor = $i + strlen($function[0]);
            while ($cursor < $length && ctype_space($content[$cursor])) { ++$cursor; }
            if ($cursor < $length && ($content[$cursor] === '"' || $content[$cursor] === "'")) {
                $end = $this->cssStringEnd($content, $cursor);
                $value = substr($content, $cursor + 1, $end - $cursor - 2);
                $cursor = $end;
                while ($cursor < $length && ctype_space($content[$cursor])) { ++$cursor; }
                if ($cursor >= $length || $content[$cursor] !== ')') { throw new \RuntimeException('Unsupported CSS url token'); }
            } else {
                $end = strpos($content, ')', $cursor);
                if ($end === false) { throw new \RuntimeException('Unterminated CSS url token'); }
                $value = trim(substr($content, $cursor, $end - $cursor));
                $cursor = $end;
            }
            $i = $cursor + 1;
            // Escaped URL values need CSS escape decoding; retain the original external file instead.
            if (str_contains($value, '\\')) { throw new \RuntimeException('Escaped CSS URL retains its original resource'); }
            if ($value === '' || preg_match('~^(?:[a-z][a-z0-9+.-]*:|/|#)~i', $value)) {
                $output .= substr($content, $start, $i - $start);
                continue;
            }
            $parts = parse_url($sourceUrl);
            $base = dirname($parts['path'] ?? '/') . '/' . $value;
            $segments = [];
            foreach (explode('/', $base) as $segment) {
                if ($segment === '..') { array_pop($segments); }
                elseif ($segment !== '.' && $segment !== '') { $segments[] = $segment; }
            }
            $origin = isset($parts['host']) ? (($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')) : '';
            $output .= 'url("' . $origin . '/' . implode('/', $segments) . '")';
        }
        return $output;
    }

    /** Offset immediately after a CSS quoted string, respecting escaped quotes. */
    private function cssStringEnd(string $css, int $start): int
    {
        $quote = $css[$start];
        $length = strlen($css);
        for ($i = $start + 1; $i < $length; ++$i) {
            if ($css[$i] === '\\') { ++$i; continue; }
            if ($css[$i] === $quote) { return $i + 1; }
        }
        throw new \RuntimeException('Unterminated CSS string retains its original resource');
    }
}
