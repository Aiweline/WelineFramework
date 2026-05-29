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

    public static function accepts(string ...$sections): bool
    {
        $requested = self::requestedSection();
        if ($requested === '') {
            return false;
        }

        return in_array($requested, $sections, true);
    }
}
