<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Runtime\RequestLifecycleTrace;

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

    /** @var array<string, string> widget code/name → phase (layout inline w:widget path). */
    private const WIDGET_CODE_MAP = [
        'product-info' => self::MAIN,
        'product-gallery' => self::MAIN,
        'product-detail' => self::MAIN,
        'related-products' => self::RELATED_STACK,
        'bestsellers' => self::RELATED_STACK,
        'cross-sell' => self::RELATED_STACK,
        'you-may-like' => self::PERSONALIZATION,
        'recently-viewed' => self::PERSONALIZATION,
    ];

    /** @var array<string, string> CSS class → phase (design themes may omit data-pdp-budget-phase). */
    private const SECTION_CLASS_MAP = [
        'product-detail-layout__main' => self::MAIN,
        'product-detail-layout__related' => self::RELATED_STACK,
        'product-detail-layout__personalization' => self::PERSONALIZATION,
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

    /**
     * Reserve L0–L4 + pdp.* summary slots early so armed samples stay observable
     * after product.catalog.* fills the default 48-phase cap.
     */
    public static function reserveTraceBuckets(): void
    {
        if (!RequestLifecycleTrace::isEnabled()) {
            return;
        }
        RequestLifecycleTrace::reserveSummaryPhases([
            ...ThemeLayoutBudgetPhases::all(),
            ...self::all(),
            'theme.layout_slot.zero_runtime_fill',
            'theme.layout_slot.safety_net_heal',
            'theme.layout_slot.narrow_filter_heal',
        ]);
    }

    public static function forSlot(string $slotId): ?string
    {
        $slotId = trim($slotId);
        if ($slotId === '') {
            return null;
        }

        return self::SLOT_MAP[$slotId] ?? null;
    }

    public static function forWidgetCode(string $widgetCode): ?string
    {
        $widgetCode = \strtolower(\trim($widgetCode));
        if ($widgetCode === '') {
            return null;
        }

        return self::WIDGET_CODE_MAP[$widgetCode] ?? null;
    }

    /**
     * Stamp data-pdp-budget-phase onto known PDP section classes when design themes
     * omit the attribute (hanfu/daocharms overrides). Does not change chrome structure.
     */
    public static function stampSectionAttributes(string $html): string
    {
        if ($html === '' || !\str_contains($html, 'product-detail-layout')) {
            return $html;
        }

        foreach (self::SECTION_CLASS_MAP as $class => $phase) {
            if (\str_contains($html, 'data-pdp-budget-phase="' . $phase . '"')) {
                continue;
            }
            $pattern = '/(<([a-z][a-z0-9:-]*)\b[^>]*\bclass=(["\'])([^"\']*\b'
                . \preg_quote($class, '/')
                . '\b[^"\']*)\3)(?![^>]*\bdata-pdp-budget-phase=)([^>]*>)/i';
            $replaced = \preg_replace_callback(
                $pattern,
                static function (array $m) use ($phase): string {
                    return $m[1] . ' data-pdp-budget-phase="' . $phase . '"' . $m[5];
                },
                $html,
                1,
            );
            if (\is_string($replaced) && $replaced !== '') {
                $html = $replaced;
            }
        }

        return $html;
    }

    /**
     * Record wall-clock for a PDP budget phase (safety-net / overlay paths that
     * never enter SlotRenderer::renderSlotWidgets).
     *
     * @template T
     * @param callable(): T $operation
     * @param array<string, mixed> $meta
     * @return T
     */
    public static function measure(string $phase, callable $operation, array $meta = []): mixed
    {
        $phase = \trim($phase);
        if ($phase === '' || !\in_array($phase, self::all(), true)) {
            return $operation();
        }

        return RequestLifecycleTrace::measurePhase($phase, $operation, $meta);
    }
}
