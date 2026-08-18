<?php

declare(strict_types=1);

namespace Weline\Websites\Observer;

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
        // Backend/rest_backend is a host-wide admin realm. Applying storefront
        // website suffixes here makes login and the next admin navigation emit
        // different Session cookie names, then expire the one that still holds
        // WF_BACKEND_USER_ID.
        if ($this->isBackendArea()) {
            return;
        }

        $revision = $this->revision();
        if ($revision === '') {
            return;
        }

        $websiteId = $this->websiteId();
        if ($websiteId < 0) {
            return;
        }

        $event->setData([
            'active' => true,
            'name_suffix' => '_w' . $websiteId,
            'name_suffix_pattern' => '/_w\d+$/',
            'mount_path' => $this->mountPath(),
            'expire_unscoped_aliases' => true,
            'revision' => $revision,
        ]);
    }

    private function revision(): string
    {
        if (RequestContext::getId() === null || RequestContext::getId() === '') {
            return '';
        }

        $websiteUrl = '';
        try {
            $websiteUrl = (string)RequestContext::getWelineWebsiteUrl();
        } catch (\Throwable) {
            $websiteUrl = '';
        }
        if ($websiteUrl === '') {
            return '';
        }

        $websiteId = $this->websiteId();
        if ($websiteId < 0) {
            return '';
        }

        return $websiteId . '|' . $websiteUrl;
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
        if (RequestContext::getId() === null || RequestContext::getId() === '') {
            return -1;
        }

        try {
            return (int)RequestContext::getWelineWebsiteId();
        } catch (\Throwable) {
            return -1;
        }
    }

    private function mountPath(): string
    {
        $websiteUrl = '';
        try {
            $websiteUrl = (string)RequestContext::getWelineWebsiteUrl();
        } catch (\Throwable) {
            $websiteUrl = '';
        }
        if ($websiteUrl === '') {
            return '/';
        }

        $path = (string)(\parse_url($websiteUrl, \PHP_URL_PATH) ?: '/');
        $path = '/' . \trim($path, '/');

        return $path === '/' ? '/' : \rtrim($path, '/');
    }
}
