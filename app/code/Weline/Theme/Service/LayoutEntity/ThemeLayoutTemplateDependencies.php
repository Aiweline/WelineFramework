<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\View\Template;
use Weline\Theme\Helper\ThemePathResolver;
use Weline\Theme\Model\WelineTheme;

/** Static source dependencies only; calls and their PHP branches remain in the original template. */
final class ThemeLayoutTemplateDependencies
{
    /** @return list<string> */
    public function literalFetches(string $source): array
    {
        // 只复用同一请求内相同源字节的语法事实，路径解析仍使用当前主题上下文。
        return ObjectManager::getInstance(StorefrontScopeHotCache::class)->rememberForRequest(
            'theme.template.literal_fetches', hash('sha256', $source), fn(): array => $this->parseLiteralFetches($source));
    }

    /** @return list<string> */
    private function parseLiteralFetches(string $source): array
    {
        $tree = (new \PhpParser\ParserFactory())->createForNewestSupportedVersion()->parse($source) ?? [];
        $calls = (new \PhpParser\NodeFinder())->findInstanceOf($tree, \PhpParser\Node\Expr\MethodCall::class);
        $literals = [];
        foreach ($calls as $call) {
            if (!$call->var instanceof \PhpParser\Node\Expr\Variable || $call->var->name !== 'this'
                || !$call->name instanceof \PhpParser\Node\Identifier || !in_array($call->name->name, ['fetch', 'fetchHtml'], true)
                || !($call->args[0]->value ?? null) instanceof \PhpParser\Node\Scalar\String_) { continue; }
            $literals[] = $call->args[0]->value->value;
        }
        return array_values(array_unique($literals));
    }

    /** @return array<string,array{origin:string,logical_path:string}> */
    public function moduleSources(string $source, ?WelineTheme $theme, string $area, ?string $sourceOrigin = null): array
    {
        $dependencies = [];
        foreach ($this->literalFetches($source) as $logical) {
            if (!preg_match('~^[A-Za-z0-9_]+::[^:]+\.phtml$~D', $logical)) {
                // 相对引用按所属源模板目录解析，不依赖调用方请求模块。
                if ($sourceOrigin === null || str_contains($logical, '://') || !str_ends_with($logical, '.phtml')) { continue; }
                $origin = realpath(str_starts_with($logical, '/') ? $logical : dirname($sourceOrigin) . '/' . $logical);
                if ($origin !== false && is_file($origin)) {
                    $dependencies[$origin] = ['origin' => $origin, 'logical_path' => $logical];
                }
                continue;
            }
            [, $relative] = explode('::', $logical, 2);
            // Public partials are generated once with the version's chrome nodes
            // and parameters; page nodes must not replace those shared sources.
            if (str_starts_with($relative, 'theme/' . $area . '/partials/')) { continue; }
            $origin = ObjectManager::getInstance(Template::class)->getTemplateRealPath($logical);
            if ($theme !== null && $origin !== '') {
                $origin = ObjectManager::getInstance(ThemePathResolver::class)->resolveThemeFile($origin, $theme);
            }
            // Optional calls may live in branches that do not execute. Preserve
            // their normal fetch behavior instead of failing generation early.
            if ($origin === '' || !is_file($origin)) { continue; }
            $dependencies[$logical] = ['origin' => $origin, 'logical_path' => $logical];
        }
        return $dependencies;
    }

    /** Resolve literal public partial references against the selected source catalog. */
    public function partialSources(string $source, string $origin, array $resources, string $area): array
    {
        $dependencies = [];
        foreach ($this->literalFetches($source) as $literal) {
            $logical = preg_replace('/^Weline_Theme::/', '', $literal);
            $prefix = 'theme/' . $area . '/partials/';
            if (str_starts_with($logical, $prefix) && str_ends_with($logical, '.phtml')) {
                $key = 'partials/' . substr($logical, strlen($prefix), -6);
                if (isset($resources[$key])) { $dependencies[$key] = $resources[$key]; }
                continue;
            }
            $absolute = str_starts_with($literal, '/') ? realpath($literal) : realpath(dirname($origin) . '/' . $literal);
            if ($absolute === false) { continue; }
            foreach ($resources as $key => $resource) {
                if (realpath((string)($resource['file_path'] ?? '')) === $absolute) { $dependencies[$key] = $resource; break; }
            }
        }
        return $dependencies;
    }
}
