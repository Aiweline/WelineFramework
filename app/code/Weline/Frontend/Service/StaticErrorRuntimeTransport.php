<?php
declare(strict_types=1);
namespace Weline\Frontend\Service;

/** Rebind the existing browser transport config when serving a static error page. */
final class StaticErrorRuntimeTransport
{
    public static function rebind(string $html, string $host, string $baseHost, string $apiHost, string $endpoint): string
    {
        return preg_replace_callback(
            '~(<script\b[^>]*\bid=(["\x27])weline-frontend-runtime-config\2[^>]*>)(.*?)(</script\s*>)~is',
            static function (array $match) use ($host, $baseHost, $apiHost, $endpoint): string {
                $config = json_decode($match[3], true, 512, JSON_THROW_ON_ERROR);
                $config['baseUrl'] = $baseHost;
                $config['url']['origin'] = $host;
                $config['url']['frontendHost'] = $host;
                $config['url']['apiHost'] = $apiHost;
                $config['site']['host'] = rtrim($host, '/') . '/';
                $config['site']['base_host'] = $baseHost;
                $config['site']['api_host'] = $apiHost;
                $config['api']['endpoint'] = $endpoint;
                $config['api']['queryBinUrl'] = $endpoint;
                return $match[1] . json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . $match[4];
            },
            $html,
        ) ?? $html;
    }
}
