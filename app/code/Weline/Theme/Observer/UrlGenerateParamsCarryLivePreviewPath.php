<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Url;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\PreviewTokenService;
use Weline\Theme\Service\ThemeLivePreviewPathMount;

/**
 * Live preview (shell=preview / path mount): keep generated storefront URLs under /~preview/{token}/.
 */
class UrlGenerateParamsCarryLivePreviewPath implements ObserverInterface
{
    public function __construct(
        private readonly PreviewContextService $previewContextService,
        private readonly Url $url,
    ) {
    }

    public function execute(Event &$event): void
    {
        if ($this->previewContextService->shouldCarryEditorIdentityOnGeneratedUrls()) {
            return;
        }

        $token = $this->resolveLivePreviewToken();
        if ($token === null || $token === '') {
            return;
        }

        $url = (string)$event->getData('data');
        if ($url === '' || $this->isExternalAbsoluteUrl($url) || $this->isBackendGeneratedUrl($url)) {
            return;
        }

        $event->setData('data', ThemeLivePreviewPathMount::prefixStorefrontUrl($url, $token));
    }

    private function resolveLivePreviewToken(): ?string
    {
        // Path-mount only — never prefix formal storefront links from a leftover Cookie.
        try {
            $fromContext = RequestContext::get(ThemeLivePreviewPathMount::REQUEST_CONTEXT_TOKEN_KEY);
            if (\is_string($fromContext) && ThemeLivePreviewPathMount::isPreviewToken($fromContext)) {
                return \trim($fromContext);
            }
        } catch (\Throwable) {
        }

        try {
            $fromEnv = (string)WelineEnv::get(ThemeLivePreviewPathMount::ENV_TOKEN_KEY, '');
            if (ThemeLivePreviewPathMount::isPreviewToken($fromEnv)) {
                return \trim($fromEnv);
            }
        } catch (\Throwable) {
        }

        try {
            $origin = (string)(WelineEnv::server('WELINE_ORIGIN_REQUEST_URI', '')
                ?: WelineEnv::server('REQUEST_URI', '')
                ?: '');
            $parsed = ThemeLivePreviewPathMount::parseFromUri($origin);
            if ($parsed !== null) {
                return $parsed['token'];
            }
        } catch (\Throwable) {
        }

        return null;
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

        $current = \strtolower((string)(WelineEnv::server('HTTP_HOST', '') ?: ''));
        if ($current === '') {
            return false;
        }
        $currentHost = \strtolower((string)(\explode(':', $current, 2)[0] ?? $current));

        return $host !== $currentHost;
    }

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

        return \str_contains($normalized, '/theme/backend/')
            || \str_contains($normalized, '/weline_admin/')
            || \str_contains($normalized, '/rest_backend/');
    }
}
