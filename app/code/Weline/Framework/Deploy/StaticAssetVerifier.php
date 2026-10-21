<?php

declare(strict_types=1);

namespace Weline\Framework\Deploy;

final class StaticAssetVerifier
{
    private const ASSET_EXTENSION_PATTERN = 'css|js|mjs|map|json|svg|png|jpe?g|gif|webp|avif|ico|woff2?|ttf|otf|eot';

    public static function extractAssetUrls(string $html): array
    {
        preg_match_all('/(?:src|href)="([^"]+)"/i', $html, $matches);
        $urls = [];
        foreach ($matches[1] ?? [] as $rawUrl) {
            $url = html_entity_decode((string)$rawUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!preg_match('/\.(?:' . self::ASSET_EXTENSION_PATTERN . ')(?:\?[^\s]*)?$/i', $url)) {
                continue;
            }
            if (!str_starts_with($url, '/static/')) {
                continue;
            }
            if (!in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    public static function verify(array $urls, callable $head): array
    {
        $failures = [];
        $unversioned = [];
        foreach ($urls as $url) {
            if (!StaticPublicSurface::isVersionedStaticUrl((string)$url)) {
                $unversioned[] = (string)$url;
            }
            $status = (int)$head((string)$url);
            if ($status !== 200) {
                $failures[] = ['url' => (string)$url, 'status' => $status, 'reason' => 'not_200'];
            }
        }

        return ['ok' => $failures === [] && $unversioned === [], 'failures' => $failures, 'unversioned' => $unversioned];
    }
}
