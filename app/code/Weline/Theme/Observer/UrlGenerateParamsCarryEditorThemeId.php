<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Url;
use Weline\Theme\Service\PreviewContextService;

/**
 * 可视化编辑画布（态 1）：挂 url_generate_params（始终派发），只追加 query，不碰 Seo path 重写。
 * 仅注入店面/前台同站 URL；后台 chrome（如主题编辑器「返回」）保持干净。
 */
class UrlGenerateParamsCarryEditorThemeId implements ObserverInterface
{
    public function __construct(
        private readonly PreviewContextService $previewContextService,
        private readonly Url $url,
    ) {
    }

    public function execute(Event &$event): void
    {
        if (!$this->previewContextService->shouldCarryEditorIdentityOnGeneratedUrls()) {
            return;
        }

        $url = (string)$event->getData('data');
        if ($url === '' || $this->isExternalAbsoluteUrl($url) || $this->isBackendGeneratedUrl($url)) {
            return;
        }

        $carry = $this->previewContextService->getEditorIdentityCarryQueryParams();
        if ($carry === []) {
            return;
        }

        $event->setData('data', $this->mergeQueryParams($url, $carry));
    }

    private function isExternalAbsoluteUrl(string $url): bool
    {
        if (!$this->url->isLink($url)) {
            return false;
        }

        $parts = \parse_url($url);
        if (!\is_array($parts)) {
            return true;
        }

        $host = \strtolower((string)($parts['host'] ?? ''));
        if ($host === '') {
            return false;
        }

        $current = \strtolower((string)(\Weline\Framework\Env\WelineEnv::server('HTTP_HOST', '') ?: ''));
        if ($current === '') {
            $websiteUrl = (string)(\Weline\Framework\Env\WelineEnv::get('website_url', '') ?: '');
            $current = \strtolower((string)(\parse_url($websiteUrl, \PHP_URL_HOST) ?: ''));
        }

        if ($current === '') {
            return false;
        }

        $currentHost = \strtolower((string)(\explode(':', $current, 2)[0] ?? $current));

        return $host !== $currentHost;
    }

    /**
     * 后台 / rest-backend 生成链不得携带画布身份（否则「返回主题列表」等 chrome 被污染）。
     */
    private function isBackendGeneratedUrl(string $url): bool
    {
        $path = (string)(\parse_url($url, \PHP_URL_PATH) ?: '');
        if ($path === '') {
            $path = \str_starts_with($url, '/') ? \explode('?', $url, 2)[0] : '';
        }
        if ($path === '') {
            return false;
        }

        $normalized = '/' . \strtolower(\trim($path, '/'));
        if (\str_contains($normalized, '/theme/backend/')
            || \str_ends_with($normalized, '/theme/backend')) {
            return true;
        }

        $segments = \array_values(\array_filter(\explode('/', \trim($normalized, '/')), static fn(string $s): bool => $s !== ''));
        if ($segments === []) {
            return false;
        }

        $prefixes = [];
        foreach (['backend', 'rest_backend'] as $area) {
            $prefix = \strtolower(\trim((string)(Env::getAreaRoutePrefix($area) ?? ''), '/'));
            if ($prefix !== '') {
                $prefixes[$prefix] = true;
            }
        }

        foreach ($segments as $segment) {
            if (isset($prefixes[$segment])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, scalar> $carry
     */
    private function mergeQueryParams(string $absoluteUrl, array $carry): string
    {
        $parts = \parse_url($absoluteUrl);
        if (!\is_array($parts)) {
            return $absoluteUrl;
        }

        $existing = [];
        if (!empty($parts['query'])) {
            \parse_str((string)$parts['query'], $existing);
        }

        foreach ($carry as $key => $value) {
            $key = (string)$key;
            if ($key === '') {
                continue;
            }
            if (isset($existing[$key]) && (string)$existing[$key] !== '' && (string)$existing[$key] !== '0') {
                continue;
            }
            $existing[$key] = $value;
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $host = (string)($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $user = (string)($parts['user'] ?? '');
        $pass = isset($parts['pass']) ? ':' . $parts['pass'] : '';
        $auth = $user !== '' ? $user . $pass . '@' : '';
        $path = (string)($parts['path'] ?? '/');
        $query = $existing !== [] ? '?' . \http_build_query($existing) : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        if ($host === '') {
            return $path . $query . $fragment;
        }

        return $scheme . $auth . $host . $port . $path . $query . $fragment;
    }
}
