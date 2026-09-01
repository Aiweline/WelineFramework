<?php

declare(strict_types=1);

namespace Weline\Websites\Observer;

use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Runtime\RequestContext;

/**
 * Maps the active Website (id + mount path) onto Framework CookieScope fields.
 *
 * Framework event payload stays website-agnostic (`name_suffix`, `mount_path`).
 * Website model language stays inside Weline_Websites.
 */
final class CookieScopeResolve implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $revision = $this->revision();
        if ($revision === '') {
            return;
        }

        $websiteId = $this->websiteId();
        if ($websiteId < 0) {
            return;
        }

        // Frontend and backend both qualify by website_id (+ port via Session
        // name resolver) so storefront Expire of unscoped WELINE_SESSID_{port}
        // cannot wipe the admin jar. Backend keeps Path=/ because admin routes
        // are not under storefront mount paths.
        $backend = $this->isBackendArea();
        $event->setData([
            'active' => true,
            'name_suffix' => '_w' . $websiteId,
            'name_suffix_pattern' => '/_w\d+$/',
            'mount_path' => $backend ? '/' : $this->mountPath(),
            'expire_unscoped_aliases' => true,
            'revision' => ($backend ? 'backend|' : '') . $revision,
        ]);
    }

    private function revision(): string
    {
        $websiteUrl = $this->websiteUrl();
        if ($websiteUrl === '') {
            return '';
        }

        $websiteId = $this->websiteId();
        if ($websiteId < 0) {
            return '';
        }

        return $websiteId . '|' . $websiteUrl;
    }

    /**
     * Prefer the installed Website URL; when the parser left it empty (common for
     * root mounts), synthesize from the request authority so CookieScope stays
     * active for Session and website cookies in the same request.
     */
    private function websiteUrl(): string
    {
        try {
            $websiteUrl = \trim((string)RequestContext::getWelineWebsiteUrl());
        } catch (\Throwable) {
            $websiteUrl = '';
        }
        if ($websiteUrl !== '') {
            return $websiteUrl;
        }

        $context = Context::getCurrent();
        if ($context === null) {
            return '';
        }

        $host = \trim((string)(
            $context->get('input.server.HTTP_HOST', '')
            ?: $context->get('input.host', '')
            ?: ''
        ));
        if ($host === '') {
            return '';
        }

        $scheme = \strtolower(\trim((string)(
            $context->get('input.scheme', '')
            ?: $context->get('input.server.REQUEST_SCHEME', '')
            ?: ''
        )));
        if ($scheme !== 'http' && $scheme !== 'https') {
            $https = \strtolower(\trim((string)$context->get('input.server.HTTPS', '')));
            $scheme = ($https !== '' && $https !== 'off') ? 'https' : 'http';
        }

        return $scheme . '://' . $host . '/';
    }

    private function isBackendArea(): bool
    {
        $area = '';
        try {
            $area = (string)RequestContext::getWelineArea();
        } catch (\Throwable) {
            $area = '';
        }
        if (\in_array($area, [RequestContext::AREA_BACKEND, RequestContext::AREA_REST_BACKEND], true)) {
            return true;
        }

        try {
            if ((bool)WelineEnv::get('is_backend', false)) {
                return true;
            }
            $envArea = (string)WelineEnv::get('area', '');
            return \in_array($envArea, [RequestContext::AREA_BACKEND, RequestContext::AREA_REST_BACKEND], true);
        } catch (\Throwable) {
            return false;
        }
    }

    private function websiteId(): int
    {
        try {
            if (RequestContext::getId() !== null && RequestContext::getId() !== '') {
                return (int)RequestContext::getWelineWebsiteId();
            }
        } catch (\Throwable) {
        }

        try {
            $envId = WelineEnv::get('website_id', null);
            if ($envId !== null && $envId !== '') {
                return (int)$envId;
            }
        } catch (\Throwable) {
        }

        $context = Context::getCurrent();
        if ($context !== null) {
            foreach (['input.get.website_id', 'input.post.website_id', 'route.website_id'] as $key) {
                $raw = $context->get($key, null);
                if ($raw === null || $raw === '') {
                    continue;
                }
                if (\is_int($raw) || (\is_string($raw) && \ctype_digit($raw))) {
                    return (int)$raw;
                }
            }

            $cookies = $context->get('input.cookie', []);
            if (\is_array($cookies)) {
                $direct = $cookies['WELINE_WEBSITE_ID'] ?? null;
                if ($direct !== null && $direct !== '' && (\is_int($direct) || (\is_string($direct) && \ctype_digit($direct)))) {
                    return (int)$direct;
                }
                foreach ($cookies as $name => $value) {
                    if (!\is_string($name) || \preg_match('/^WELINE_WEBSITE_ID_w(\d+)$/D', $name, $m) !== 1) {
                        continue;
                    }
                    if ($value !== null && $value !== '' && (\is_int($value) || (\is_string($value) && \ctype_digit((string)$value)))) {
                        return (int)$value;
                    }

                    return (int)$m[1];
                }
            }
        }

        // Admin realm defaults to website 0 when the request has not frozen a site yet.
        if ($this->isBackendArea()) {
            return 0;
        }

        return -1;
    }

    private function mountPath(): string
    {
        $websiteUrl = $this->websiteUrl();
        if ($websiteUrl === '') {
            return '/';
        }

        $path = (string)(\parse_url($websiteUrl, \PHP_URL_PATH) ?: '/');
        $path = '/' . \trim($path, '/');

        return $path === '/' ? '/' : \rtrim($path, '/');
    }
}
