<?php

declare(strict_types=1);

namespace Weline\Customer\Service;

use Weline\Framework\Runtime\RequestContext;

/**
 * 个人中心延迟分区：sidebar-content 按 section 参数只渲染目标 Hook。
 */
final class AccountSidebarContentGate
{
    public const REQUEST_CONTEXT_KEY = 'weline_customer.account_sidebar_content.section';
    public const REQUEST_PARAMS_CONTEXT_KEY = 'weline_customer.account_sidebar_content.params';

    public static function setRequestedSection(?string $section): void
    {
        $section = trim((string) $section);
        if ($section === '') {
            RequestContext::remove(self::REQUEST_CONTEXT_KEY);
            return;
        }

        RequestContext::set(self::REQUEST_CONTEXT_KEY, $section);
    }

    public static function requestedSection(): string
    {
        $requested = RequestContext::get(self::REQUEST_CONTEXT_KEY);
        if (is_string($requested) && trim($requested) !== '') {
            return trim($requested);
        }

        return '';
    }

    /** @param array<string, mixed>|null $params */
    public static function setRequestParams(?array $params): void
    {
        if ($params === null || $params === []) {
            RequestContext::remove(self::REQUEST_PARAMS_CONTEXT_KEY);
            return;
        }

        RequestContext::set(self::REQUEST_PARAMS_CONTEXT_KEY, $params);
    }

    /** @return array<string, mixed> */
    public static function requestParams(): array
    {
        $params = RequestContext::get(self::REQUEST_PARAMS_CONTEXT_KEY);
        return is_array($params) ? $params : [];
    }

    public static function requestParam(string $key, mixed $default = null): mixed
    {
        $params = self::requestParams();
        return array_key_exists($key, $params) ? $params[$key] : $default;
    }

    public static function accepts(string ...$sections): bool
    {
        $requested = self::requestedSection();
        if ($requested === '') {
            return false;
        }

        return in_array($requested, $sections, true);
    }
}
