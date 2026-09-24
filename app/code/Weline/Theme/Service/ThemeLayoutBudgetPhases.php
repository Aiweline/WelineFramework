<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

/**
 * UC-layout-budget L0–L4 phase names for wls_tpl_perf / RequestLifecycleTrace.
 *
 * Tags only — does not change chrome structure. Existing sub-phases remain;
 * these names are the acceptance bucket aliases from meetings/主题-design.md WS2.
 */
final class ThemeLayoutBudgetPhases
{
    public const L0_CONTEXT = 'theme.layout.L0-context';
    public const L1_CHROME = 'theme.layout.L1-chrome';
    public const L2_HEADER = 'theme.layout.L2-header';
    public const L3_SLOTS = 'theme.layout.L3-slots';
    public const L4_PAGE_BODY = 'theme.layout.L4-page-body';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::L0_CONTEXT,
            self::L1_CHROME,
            self::L2_HEADER,
            self::L3_SLOTS,
            self::L4_PAGE_BODY,
        ];
    }
}
