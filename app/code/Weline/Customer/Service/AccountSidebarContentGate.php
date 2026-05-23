<?php

declare(strict_types=1);

namespace Weline\Customer\Service;

/**
 * 个人中心延迟分区：sidebar-content 按 section 参数只渲染目标 Hook。
 */
final class AccountSidebarContentGate
{
    public static function requestedSection(): string
    {
        return trim((string) ($GLOBALS['__weline_account_sidebar_content_section'] ?? ''));
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
