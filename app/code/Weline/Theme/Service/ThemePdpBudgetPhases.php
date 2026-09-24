<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

/**
 * UC-pdp-cold phase names for wls_tpl_perf / RequestLifecycleTrace.
 *
 * Tags only — does not remove chrome or required recommendation slots.
 * See meetings/主题-design.md WS4 / contracts WS4.
 */
final class ThemePdpBudgetPhases
{
    public const MAIN = 'pdp.main';
    public const RELATED_STACK = 'pdp.related_stack';
    public const PERSONALIZATION = 'pdp.personalization';

    /** @var array<string, string> */
    private const SLOT_MAP = [
        'product-main' => self::MAIN,
        'product-info' => self::MAIN,
        'product-gallery' => self::MAIN,
        'product-related-products' => self::RELATED_STACK,
        'product-bestsellers' => self::RELATED_STACK,
        'product-cross-sell' => self::RELATED_STACK,
        'product-you-may-like' => self::PERSONALIZATION,
        'product-recently-viewed' => self::PERSONALIZATION,
    ];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::MAIN,
            self::RELATED_STACK,
            self::PERSONALIZATION,
        ];
    }

    public static function forSlot(string $slotId): ?string
    {
        $slotId = trim($slotId);
        if ($slotId === '') {
            return null;
        }

        return self::SLOT_MAP[$slotId] ?? null;
    }
}
