<?php

declare(strict_types=1);

namespace Weline\Blog\Service;

use Weline\Framework\App\State;
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
        // Prefer getLangLocal(): I18n maps path casing (en_us) to installed code (en_US).
        $locale = trim(State::getLangLocal());
        if ($locale === '') {
            $locale = trim(State::getLang());
        }
        if ($locale === '') {
            return 'zh_Hans_CN';
        }

        return str_replace('-', '_', $locale);
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
