<?php

declare(strict_types=1);

namespace Weline\Blog\Service;

use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;

final class BlogScopeResolver
{
    public function websiteId(): int
    {
        $scope = RequestContext::scopeIdentity();
        if ($scope instanceof ScopeIdentity) {
            return max(0, (int)($scope->websiteId ?? 0));
        }

        return 0;
    }

    public function locale(): string
    {
        $scope = RequestContext::scopeIdentity();
        if ($scope instanceof ScopeIdentity) {
            $locale = trim((string)($scope->localeCode ?? $scope->locale ?? ''));
            if ($locale !== '') {
                return $locale;
            }
        }

        return (string)(\w_env('lang', '') ?: 'zh_Hans_CN');
    }

    public function baseUrl(): string
    {
        $scope = RequestContext::scopeIdentity();
        if ($scope instanceof ScopeIdentity) {
            $url = trim((string)($scope->websiteUrl ?? $scope->baseUrl ?? ''));
            if ($url !== '') {
                return rtrim($url, '/');
            }
        }

        return rtrim(trim((string)(\w_env('website.url', '') ?: '')), '/');
    }
}
