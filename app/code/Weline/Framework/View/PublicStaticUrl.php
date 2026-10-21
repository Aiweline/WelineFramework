<?php

declare(strict_types=1);

namespace Weline\Framework\View;

use Weline\Framework\Deploy\DeployPublicUrlMode;
use Weline\Framework\Deploy\StaticBuildId;

/**
 * Single authority for module static public URLs.
 *
 * DEV web path:  /Weline/{Module}/view/statics/{rest}
 * PROD (Upgrade flat publish): /static/Weline/{Module}/{rest}
 *
 * Aligns with {@see \Weline\Framework\Console\Console\Deploy\Upgrade} flat trees
 * and Frontend chrome boot paths. Gated by {@see DeployPublicUrlMode}.
 *
 * not_to_do: invent a third URL shape; hardcode /Weline/.../view/statics in runtime.
 */
final class PublicStaticUrl
{
    /**
     * @param string $devWebPath Root-relative DEV path (query string allowed)
     */
    public static function fromDevWebPath(string $devWebPath): string
    {
        $raw = str_replace('\\', '/', trim($devWebPath));
        if ($raw === '') {
            return '';
        }
        $query = '';
        if (str_contains($raw, '?')) {
            [$raw, $queryPart] = explode('?', $raw, 2);
            $query = '?' . $queryPart;
        }
        $path = '/' . ltrim($raw, '/');
        if (str_starts_with($path, '/static/')) {
            return StaticBuildId::versionedPath($path . $query);
        }
        if (!DeployPublicUrlMode::useProduction()) {
            return $path . $query;
        }
        if (preg_match(
            '#^/(?P<vendor>[A-Za-z0-9]+)/(?P<module>[A-Za-z0-9_]+)/view/statics/(?P<rest>.*)$#',
            $path,
            $m,
        ) === 1) {
            $rest = ltrim((string)$m['rest'], '/');

            return StaticBuildId::versionedPath('/static/' . $m['vendor'] . '/' . $m['module']
                . ($rest !== '' ? '/' . $rest : '')
                . $query);
        }

        // Non-module path: keep leading slash under /static as last-resort prefix.
        return StaticBuildId::versionedPath('/static' . $path . $query);
    }

    /**
     * Build from Vendor_Module::relative (relative to view/statics/).
     */
    public static function fromModuleSource(string $moduleSource): string
    {
        $source = trim($moduleSource);
        if ($source === '' || !str_contains($source, '::')) {
            return self::fromDevWebPath($source);
        }
        [$module, $rel] = array_pad(explode('::', $source, 2), 2, '');
        $module = trim($module);
        $rel = ltrim(str_replace('\\', '/', trim($rel)), '/');
        if ($module === '') {
            return '';
        }
        $web = '/' . str_replace('_', '/', $module) . '/view/statics/' . $rel;

        return self::fromDevWebPath($web);
    }
}
