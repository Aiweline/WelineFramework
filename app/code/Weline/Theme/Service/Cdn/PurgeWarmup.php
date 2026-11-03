<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Cdn;

/**
 * purge 之后的预热：按影响面预取关键页面，避免失效后第一个真实访客吃到冷渲染。
 *
 * 真正的「取」由调用方注入（curl、内部请求、CDN 预热接口都行），这样离线可测。
 */
final class PurgeWarmup
{
    /**
     * @param list<string> $hosts
     *
     * @return list<string> 需要预热的 URL
     */
    public function urlsFor(array $impact, array $hosts): array
    {
        $paths = [];
        if (($impact['shared'] ?? false) === true) {
            $paths[] = '/';
        }
        foreach (($impact['exact'] ?? []) as $pageType) {
            $pageType = \trim((string)$pageType);
            if ($pageType !== '') {
                $paths[] = '/' . \ltrim($pageType, '/');
            }
        }
        foreach (($impact['prefixes'] ?? []) as $prefix) {
            $prefix = \trim((string)$prefix);
            if ($prefix !== '') {
                $paths[] = $prefix;
            }
        }
        $paths = \array_values(\array_unique($paths));
        if ($paths === []) {
            return [];
        }

        $urls = [];
        foreach ($hosts as $host) {
            $host = \trim((string)$host);
            if ($host === '') {
                continue;
            }
            foreach ($paths as $path) {
                $urls[] = 'https://' . $host . $path;
            }
        }

        return $urls;
    }

    /**
     * @param list<string> $urls
     * @param callable(string):bool $fetcher 返回 true 表示预热成功
     *
     * @return array{ok:int,failed:list<string>}
     */
    public function warm(array $urls, callable $fetcher): array
    {
        $ok = 0;
        $failed = [];
        foreach ($urls as $url) {
            $result = false;
            try {
                $result = (bool)$fetcher($url);
            } catch (\Throwable) {
                $result = false;
            }
            if ($result) {
                $ok++;
                continue;
            }
            $failed[] = $url;
        }

        return ['ok' => $ok, 'failed' => $failed];
    }
}
