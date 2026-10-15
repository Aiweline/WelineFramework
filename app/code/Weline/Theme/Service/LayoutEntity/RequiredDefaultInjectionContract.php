<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

/**
 * Storefront contract for default_injections planning helpers.
 *
 * Design (2026-09-21 XOR + 2026-09-26 hard-fail 纠偏):
 * - Identity XOR (SAME module): the *same* widget must not be both layout-embedded
 *   and listed in `default_injections` JSON / DefaultLayoutSeeder / entity nodes
 *   (layout OR injection — not both; delete JSON/Seeder; mark placement=layout).
 * - CROSS module: FORBID layout/partial mutual widget calls; foreign widgets enter
 *   ONLY via owning-module JSON `default_injections` + empty slot.
 * - Duplicate same-code in a slot MUST hard-fail (`required_default_injection_duplicate`).
 * - Overlay unfilled (declared but missing) remains soft-skip — missing fill must not 500.
 *   Uninstall (`user_deleted@{versionId}`) still omits a planned injection.
 * - Solidified shells: bake owns required; runtime Overlay is not the primary path.
 *
 * Runtime presence: prefer {@see slotHasWidgetPresent} for required fill XOR
 * (same widget_code may legally occupy multiple declared slots, e.g. mini-cart
 * footer-extras + checkout-summary-discount). {@see pageHasWidgetPresent} /
 * countWidgetPresent remain for dual-path / diagnostics — not for per-slot heal.
 */
final class RequiredDefaultInjectionContract
{
    public static function userDeletedSource(int $versionId): string
    {
        return $versionId > 0 ? 'user_deleted@' . $versionId : 'user_deleted';
    }

    public static function isUninstallSource(string $source): bool
    {
        return $source === 'user_deleted' || str_starts_with($source, 'user_deleted@');
    }

    public static function matchesVersionUninstall(string $source, int $versionId): bool
    {
        return $versionId > 0 && $source === self::userDeletedSource($versionId);
    }

    /**
     * Required injection targets for a page type (no merge / no node payload).
     *
     * @param list<array<string, mixed>> $declarations
     * @return list<array{slot_id:string,widget_module:string,widget_code:string}>
     */
    public static function requiredTargets(array $declarations, string $pageType): array
    {
        $targets = [];
        foreach (self::requiredForPage($declarations, $pageType) as $item) {
            $targets[] = [
                'slot_id' => $item['slot_id'],
                'widget_module' => $item['widget_module'],
                'widget_code' => $item['widget_code'],
            ];
        }

        return $targets;
    }

    /**
     * Detect whether slot inner HTML already contains the widget markers.
     * Zero-tolerance: only data-widget-code / data-testid count — loose data-action /
     * class markers must not suppress required injection.
     */
    public static function slotInnerHasWidgetCode(string $innerHtml, string $module, string $code): bool
    {
        unset($module);
        $code = strtolower(trim($code));
        if ($code === '') {
            return false;
        }
        $innerNorm = strtolower($innerHtml);
        $testid = str_replace('_', '-', $code);

        return str_contains($innerNorm, 'data-widget-code="' . $code . '"')
            || str_contains($innerNorm, "data-widget-code='" . $code . "'")
            || str_contains($innerNorm, 'data-testid="' . $code . '"')
            || str_contains($innerNorm, "data-testid='" . $code . "'")
            || str_contains($innerNorm, 'data-testid="' . $testid . '"')
            || str_contains($innerNorm, "data-testid='" . $testid . "'");
    }

    /**
     * Page-level presence (整页扫描): diagnostics / dual-path detection only.
     * Do NOT use for required safety-net fill — that must be {@see slotHasWidgetPresent}.
     */
    public static function pageHasWidgetPresent(string $pageHtml, string $module, string $code): bool
    {
        $pageHtml = self::stripIgnoredPresenceRegions($pageHtml);
        if (self::slotInnerHasWidgetCode($pageHtml, $module, $code)) {
            return true;
        }
        $code = strtolower(trim($code));
        if ($code === '' || $pageHtml === '') {
            return false;
        }
        $norm = strtolower($pageHtml);

        return str_contains($norm, 'data-w-component="' . $code . '"')
            || str_contains($norm, "data-w-component='" . $code . "'")
            || (bool)preg_match(
                '/\bdata-w-component=(["\'])([^"\']*\s)?' . preg_quote($code, '/') . '(\s[^"\']*)?\1/',
                $norm,
            );
    }

    /**
     * Slot-scoped presence: required injection XOR is per declared slot, not whole page.
     * Mini-cart already having checkout-coupon must not suppress checkout-summary-discount.
     *
     * Also treats a widget-wrapper that stamps the same data-slot-id + data-widget-code
     * as present — bake / strip nesting can leave the wrapper as a sibling of the host
     * (outside host inner). Inner-only checks would miss it and the runtime safety net
     * would inject a second copy; DEV dup markers already key on wrapper data-slot-id.
     */
    public static function slotHasWidgetPresent(
        string $pageHtml,
        string $slotId,
        string $module,
        string $code,
    ): bool {
        $slotId = trim($slotId);
        if ($slotId === '' || $pageHtml === '') {
            return false;
        }
        $inner = self::extractSlotInnerHtml($pageHtml, $slotId);
        if ($inner !== null && self::slotInnerHasWidgetCode($inner, $module, $code)) {
            return true;
        }

        return self::slotClaimedByWidgetWrapper($pageHtml, $slotId, $module, $code);
    }

    /**
     * True when any single open tag claims both this slot and widget code
     * (typical .widget-wrapper from ThemeLayoutEntityWidgetRenderer).
     */
    public static function slotClaimedByWidgetWrapper(
        string $pageHtml,
        string $slotId,
        string $module,
        string $code,
    ): bool {
        unset($module);
        $slotId = strtolower(trim($slotId));
        $code = strtolower(trim($code));
        if ($slotId === '' || $code === '' || $pageHtml === '') {
            return false;
        }
        $plain = strtolower(self::stripIgnoredPresenceRegions($pageHtml));
        $slotQ = preg_quote($slotId, '/');
        $codeQ = preg_quote($code, '/');

        return (bool)preg_match(
            '/<[^>]*\bdata-slot-id\s*=\s*(["\'])' . $slotQ . '\1[^>]*\bdata-widget-code\s*=\s*(["\'])' . $codeQ . '\2[^>]*>'
            . '|<[^>]*\bdata-widget-code\s*=\s*(["\'])' . $codeQ . '\3[^>]*\bdata-slot-id\s*=\s*(["\'])' . $slotQ . '\4[^>]*>/',
            $plain,
        );
    }

    /**
     * Inner HTML of the first matching slot host (data-slot-id / data-wslot / id).
     */
    public static function extractSlotInnerHtml(string $pageHtml, string $slotId): ?string
    {
        $slotId = trim($slotId);
        if ($slotId === '' || $pageHtml === '') {
            return null;
        }
        $quoted = preg_quote($slotId, '/');
        $patterns = [
            '/<div\b[^>]*\bdata-slot-id\s*=\s*(["\'])' . $quoted . '\1[^>]*>/i',
            '/<div\b[^>]*\bdata-wslot\s*=\s*(["\'])' . $quoted . '\1[^>]*>/i',
            '/<div\b[^>]*\bid\s*=\s*(["\'])' . $quoted . '\1[^>]*>/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $pageHtml, $m, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }
            $open = $m[0][0];
            $start = (int)$m[0][1] + strlen($open);
            $inner = self::sliceBalancedDivInner($pageHtml, $start);
            if ($inner !== null) {
                return $inner;
            }
        }

        return null;
    }

    /**
     * Walk HTML from just after an opening &lt;div&gt; until its matching close.
     */
    private static function sliceBalancedDivInner(string $html, int $start): ?string
    {
        $len = strlen($html);
        if ($start < 0 || $start > $len) {
            return null;
        }
        $depth = 1;
        $offset = $start;
        while ($offset < $len && $depth > 0) {
            if (!preg_match('/<\/?div\b[^>]*>/i', $html, $tm, PREG_OFFSET_CAPTURE, $offset)) {
                return null;
            }
            $tag = $tm[0][0];
            $at = (int)$tm[0][1];
            if ($tag[1] === '/') {
                $depth--;
                if ($depth === 0) {
                    return substr($html, $start, $at - $start);
                }
            } else {
                $depth++;
            }
            $offset = $at + strlen($tag);
        }

        return null;
    }

    /**
     * Count explicit presence markers (style/script + ignore regions stripped).
     * Counts one marker family only (widget-code → testid → component).
     */
    public static function countWidgetPresent(string $pageHtml, string $module, string $code): int
    {
        unset($module);
        $code = strtolower(trim($code));
        if ($code === '' || $pageHtml === '') {
            return 0;
        }
        $plain = self::stripIgnoredPresenceRegions($pageHtml);
        $plain = preg_replace('~<(style|script)\b[^>]*>.*?</\1>~is', '', $plain) ?? $plain;
        $plain = strtolower($plain);
        $testid = str_replace('_', '-', $code);

        $byWidgetCode = substr_count($plain, 'data-widget-code="' . $code . '"')
            + substr_count($plain, "data-widget-code='" . $code . "'");
        if ($byWidgetCode > 0) {
            return $byWidgetCode;
        }

        // hyphenated $code === $testid must not double-count one data-testid.
        $testIdNeedles = array_values(array_unique([
            'data-testid="' . $code . '"',
            "data-testid='" . $code . "'",
            'data-testid="' . $testid . '"',
            "data-testid='" . $testid . "'",
        ]));
        $byTestId = 0;
        foreach ($testIdNeedles as $needle) {
            $byTestId += substr_count($plain, $needle);
        }
        if ($byTestId > 0) {
            return $byTestId;
        }

        return substr_count($plain, 'data-w-component="' . $code . '"')
            + substr_count($plain, "data-w-component='" . $code . "'");
    }

    private static function stripIgnoredPresenceRegions(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $html = preg_replace(
            '/<([a-z0-9]+)([^>]*\bdata-purchase-failsafe\b[^>]*)>.*?<\/\1>/is',
            '',
            $html,
        ) ?? $html;
        $html = preg_replace(
            '/<([a-z0-9]+)([^>]*\bdata-required-injection-ignore\b[^>]*)>.*?<\/\1>/is',
            '',
            $html,
        ) ?? $html;

        return $html;
    }

    /**
     * Full required injection items (including render node) for a page type.
     *
     * @param list<array<string, mixed>> $declarations
     * @return list<array{slot_id:string,widget_module:string,widget_code:string,node:array<string,mixed>}>
     */
    public static function requiredInjections(array $declarations, string $pageType): array
    {
        return self::requiredForPage($declarations, $pageType);
    }

    /**
     * @param list<array<string, mixed>> $omissions
     */
    public static function isUninstalled(array $omissions, string $slotId, string $module, string $code): bool
    {
        return self::isOmitted($omissions, $slotId, $module, $code);
    }

    /**
     * Bake-time required merge (per declared slot).
     *
     * Same module+code may occupy multiple *declared* required slots on one page
     * (mini-cart footer-extras + checkout-summary-discount). Hits only in undeclared
     * / drifted slots (e.g. parent `content`) still relocate into the target slot
     * and are stripped from those undeclared hosts — never stack a second copy
     * inside the same slot.
     *
     * @param array<string, list<array<string, mixed>>> $slots
     * @param list<array<string, mixed>> $declarations
     * @param list<array<string, mixed>> $omissions
     * @return array<string, list<array<string, mixed>>>
     */
    public static function merge(array $slots, string $pageType, array $declarations, array $omissions): array
    {
        $pageType = trim($pageType);
        if ($pageType === '') {
            return $slots;
        }

        $required = self::requiredForPage($declarations, $pageType);
        $declaredSlotsByIdentity = [];
        foreach ($required as $planItem) {
            $ik = strtolower(trim((string)($planItem['widget_module'] ?? '')))
                . '|'
                . strtolower(trim((string)($planItem['widget_code'] ?? '')));
            $declaredSlotsByIdentity[$ik][trim((string)($planItem['slot_id'] ?? ''))] = true;
        }

        foreach ($required as $item) {
            $slotId = (string)$item['slot_id'];
            $module = (string)$item['widget_module'];
            $code = (string)$item['widget_code'];
            if (self::isOmitted($omissions, $slotId, $module, $code)) {
                continue;
            }
            $identityKey = strtolower(trim($module)) . '|' . strtolower(trim($code));
            $declaredSlots = array_keys($declaredSlotsByIdentity[$identityKey] ?? [$slotId => true]);
            $declaredSlots = array_values(array_filter(
                $declaredSlots,
                static fn(string $s): bool => $s !== '',
            ));

            if (self::slotListHasWidgetIdentity($slots[$slotId] ?? null, $module, $code)) {
                // Already in target slot: still strip undeclared drift copies.
                $slots = self::stripIdentityOutsideSlots($slots, $module, $code, $declaredSlots);
                continue;
            }

            $hits = self::findPageWidgetHits($slots, $module, $code);
            $undeclaredHits = [];
            foreach ($hits as $hit) {
                $hitSlot = trim((string)($hit['slot'] ?? ''));
                if ($hitSlot === '' || in_array($hitSlot, $declaredSlots, true)) {
                    continue;
                }
                $undeclaredHits[] = $hit;
            }

            if ($undeclaredHits !== []) {
                // Relocate drift → this declared slot; keep other declared slots intact.
                $slots = self::collapseHitsToDeclaredSlot(
                    $slots,
                    $undeclaredHits,
                    $slotId,
                    \is_array($item['node'] ?? null) ? $item['node'] : [],
                    $declaredSlots,
                );
                continue;
            }

            $slots[$slotId][] = $item['node'];
            self::sortSlotWidgets($slots[$slotId]);
        }

        return $slots;
    }

    /**
     * @param list<array<string, mixed>>|null $widgets
     */
    private static function slotListHasWidgetIdentity(?array $widgets, string $module, string $code): bool
    {
        if ($widgets === null) {
            return false;
        }
        foreach ($widgets as $widget) {
            if (\is_array($widget) && self::widgetIdentityMatches($widget, $module, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $slots
     * @param list<string> $keepSlots
     * @return array<string, list<array<string, mixed>>>
     */
    private static function stripIdentityOutsideSlots(
        array $slots,
        string $module,
        string $code,
        array $keepSlots,
    ): array {
        $keep = [];
        foreach ($keepSlots as $slot) {
            $keep[trim((string)$slot)] = true;
        }
        foreach ($slots as $slotId => $widgets) {
            if (!\is_array($widgets) || isset($keep[(string)$slotId])) {
                continue;
            }
            $kept = [];
            foreach ($widgets as $widget) {
                if (!\is_array($widget)) {
                    continue;
                }
                if (self::widgetIdentityMatches($widget, $module, $code)) {
                    continue;
                }
                $kept[] = $widget;
            }
            if ($kept === []) {
                unset($slots[$slotId]);
            } else {
                $slots[$slotId] = $kept;
            }
        }

        return $slots;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $slots
     * @return list<array{slot:string,widget:array<string,mixed>}>
     */
    public static function findPageWidgetHits(array $slots, string $module, string $code): array
    {
        $module = trim($module);
        $code = trim($code);
        if ($code === '') {
            return [];
        }
        $hits = [];
        foreach ($slots as $slotId => $widgets) {
            if (!\is_array($widgets)) {
                continue;
            }
            foreach ($widgets as $widget) {
                if (!\is_array($widget)) {
                    continue;
                }
                if (!self::widgetIdentityMatches($widget, $module, $code)) {
                    continue;
                }
                $hits[] = [
                    'slot' => (string)$slotId,
                    'widget' => $widget,
                ];
            }
        }

        return $hits;
    }

    /**
     * Relocate undeclared / drifted hits into $declaredSlot.
     * Slots listed in $preserveDeclaredSlots keep their identity copies.
     *
     * @param array<string, list<array<string, mixed>>> $slots
     * @param list<array{slot:string,widget:array<string,mixed>}> $hits
     * @param array<string, mixed> $templateNode
     * @param list<string> $preserveDeclaredSlots
     * @return array<string, list<array<string, mixed>>>
     */
    private static function collapseHitsToDeclaredSlot(
        array $slots,
        array $hits,
        string $declaredSlot,
        array $templateNode,
        array $preserveDeclaredSlots = [],
    ): array {
        $declaredSlot = trim($declaredSlot);
        if ($declaredSlot === '' || $hits === []) {
            return $slots;
        }
        $module = trim((string)($templateNode['widget_module'] ?? $hits[0]['widget']['widget_module'] ?? ''));
        $code = trim((string)($templateNode['widget_code'] ?? $hits[0]['widget']['widget_code'] ?? ''));

        $preserve = [$declaredSlot => true];
        foreach ($preserveDeclaredSlots as $keepSlot) {
            $keepSlot = trim((string)$keepSlot);
            if ($keepSlot !== '') {
                $preserve[$keepSlot] = true;
            }
        }

        $canonical = null;
        foreach ($hits as $hit) {
            if ((string)($hit['slot'] ?? '') === $declaredSlot) {
                $canonical = $hit['widget'];
                break;
            }
        }
        if (!\is_array($canonical)) {
            $canonical = $hits[0]['widget'];
        }

        foreach ($slots as $slotId => $widgets) {
            if (!\is_array($widgets)) {
                continue;
            }
            if (isset($preserve[(string)$slotId]) && (string)$slotId !== $declaredSlot) {
                continue;
            }
            $kept = [];
            foreach ($widgets as $widget) {
                if (!\is_array($widget)) {
                    continue;
                }
                if (self::widgetIdentityMatches($widget, $module, $code)) {
                    continue;
                }
                $kept[] = $widget;
            }
            if ($kept === []) {
                unset($slots[$slotId]);
            } else {
                $slots[$slotId] = $kept;
            }
        }

        $canonical['slot_id'] = $declaredSlot;
        if (trim((string)($canonical['widget_module'] ?? '')) === '' && $module !== '') {
            $canonical['widget_module'] = $module;
        }
        if (trim((string)($canonical['widget_code'] ?? '')) === '' && $code !== '') {
            $canonical['widget_code'] = $code;
        }
        if (trim((string)($canonical['area'] ?? '')) === '') {
            $area = trim((string)($templateNode['area'] ?? ''));
            if ($area !== '') {
                $canonical['area'] = $area;
            }
        }
        if (trim((string)($canonical['node_uid'] ?? '')) === '') {
            $uid = trim((string)($templateNode['node_uid'] ?? ''));
            if ($uid === '') {
                $uid = \substr(\hash('sha256', $declaredSlot . '|' . $module . '|' . $code), 0, 32);
            }
            $canonical['node_uid'] = \strtolower($uid);
        }
        if (!isset($slots[$declaredSlot]) || !\is_array($slots[$declaredSlot])) {
            $slots[$declaredSlot] = [];
        }
        // Avoid double-append if preserve left an existing copy in declaredSlot.
        if (!self::slotListHasWidgetIdentity($slots[$declaredSlot], $module, $code)) {
            $slots[$declaredSlot][] = $canonical;
        }
        self::sortSlotWidgets($slots[$declaredSlot]);

        return $slots;
    }

    /**
     * @param array<string, mixed> $widget
     */
    private static function widgetIdentityMatches(array $widget, string $module, string $code): bool
    {
        if (trim((string)($widget['widget_code'] ?? '')) !== $code) {
            return false;
        }
        $existingModule = trim((string)($widget['widget_module'] ?? ''));
        if ($existingModule === $module) {
            return true;
        }

        return $existingModule === '' && $module === '';
    }

    /**
     * @param list<array<string, mixed>> $widgets
     */
    private static function sortSlotWidgets(array &$widgets): void
    {
        usort(
            $widgets,
            static fn(array $a, array $b): int => ((int)($a['sort_order'] ?? 0)) <=> ((int)($b['sort_order'] ?? 0)),
        );
    }

    /**
     * Nested chrome destinations owned by the homepage chrome carrier.
     * Non-homepage pages inherit these via required overlay (非首页继承合并).
     */
    public static function isInheritedChromeCarrierSlot(string $slotId): bool
    {
        $slotId = strtolower(trim($slotId));
        if ($slotId === '') {
            return false;
        }
        // Root chrome shells are spliced as wholes; only nested extension slots inherit.
        if (in_array($slotId, ['header', 'footer', 'delivery'], true)) {
            return false;
        }
        // Global float destinations live under footer chrome but omit the footer- prefix.
        if (str_starts_with($slotId, 'storefront-float-') || str_starts_with($slotId, 'storefront_float_')) {
            return true;
        }
        foreach (['header', 'footer'] as $root) {
            if (str_starts_with($slotId, $root . '-') || str_starts_with($slotId, $root . '_')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $declarations
     * @return list<array{slot_id:string,widget_module:string,widget_code:string,node:array<string,mixed>}>
     */
    private static function requiredForPage(array $declarations, string $pageType): array
    {
        $pageType = trim($pageType);
        $items = self::collectRequiredForExactPage($declarations, $pageType);
        // Chrome carrier (homepage) nests footer-*-links / header-nav-extensions etc.
        // Non-homepage pages splice chrome roots but keep empty/missing-config nested
        // slots unless we also plan those homepage required injections here.
        if ($pageType !== '' && $pageType !== 'homepage') {
            $seen = [];
            foreach ($items as $item) {
                $seen[self::injectionIdentityKey($item)] = true;
            }
            foreach (self::collectRequiredForExactPage($declarations, 'homepage') as $carrierItem) {
                $slotId = trim((string)($carrierItem['slot_id'] ?? ''));
                if (!self::isInheritedChromeCarrierSlot($slotId)) {
                    continue;
                }
                $key = self::injectionIdentityKey($carrierItem);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $items[] = $carrierItem;
            }
        }

        return $items;
    }

    /**
     * @param array{slot_id?:string,widget_module?:string,widget_code?:string} $item
     */
    private static function injectionIdentityKey(array $item): string
    {
        return strtolower(trim((string)($item['slot_id'] ?? '')))
            . '|'
            . strtolower(trim((string)($item['widget_module'] ?? '')))
            . '|'
            . strtolower(trim((string)($item['widget_code'] ?? '')));
    }

    /**
     * @param list<array<string, mixed>> $declarations
     * @return list<array{slot_id:string,widget_module:string,widget_code:string,node:array<string,mixed>}>
     */
    private static function collectRequiredForExactPage(array $declarations, string $pageType): array
    {
        $items = [];
        foreach ($declarations as $declaration) {
            if (!is_array($declaration)) {
                continue;
            }
            $module = trim((string)($declaration['module'] ?? ''));
            $type = trim((string)($declaration['type'] ?? ''));
            $code = trim((string)($declaration['code'] ?? ''));
            if ($module === '' || $type === '' || $code === '') {
                continue;
            }
            $injections = $declaration['default_injections'] ?? [];
            if (!is_array($injections)) {
                continue;
            }
            $isList = $injections === [] || array_keys($injections) === range(0, count($injections) - 1);
            foreach ($isList ? $injections : [$injections] as $injection) {
                if (!is_array($injection) || !self::isRequired($injection['required'] ?? false)) {
                    continue;
                }
                $layoutType = trim((string)($injection['layout_type'] ?? ''));
                if ($layoutType !== '' && $layoutType !== '*' && $layoutType !== $pageType) {
                    continue;
                }
                $option = trim((string)($injection['layout_option'] ?? 'default'));
                if ($option !== '' && $option !== 'default' && $option !== '*') {
                    continue;
                }
                $slotId = trim((string)($injection['slot'] ?? ''));
                if ($slotId === '') {
                    continue;
                }
                $area = trim((string)($injection['area'] ?? ''));
                if ($area === '') {
                    $area = 'content';
                }
                $sort = (int)($injection['sort_order'] ?? 0);
                $config = is_array($injection['config'] ?? null) ? $injection['config'] : [];
                $items[] = [
                    'slot_id' => $slotId,
                    'widget_module' => $module,
                    'widget_code' => $code,
                    'node' => [
                        'node_uid' => substr(hash('sha256', $pageType . '|' . $slotId . '|' . $module . '|' . $code), 0, 32),
                        'widget_module' => $module,
                        'widget_code' => $code,
                        'widget_type' => $type,
                        'sort_order' => $sort,
                        'is_active' => true,
                        'area' => $area,
                        'slot_id' => $slotId,
                        'config' => $config,
                        // Provenance for data-widget-source / DEV dup markers / XOR.
                        'source' => 'default_injection',
                    ],
                ];
            }
        }

        return $items;
    }

    private static function isRequired(mixed $required): bool
    {
        return $required === true || $required === 1 || $required === '1' || $required === 'true';
    }

    /**
     * @param array<string, list<array<string, mixed>>> $slots
     */
    private static function slotHasWidget(array $slots, string $slotId, string $module, string $code): bool
    {
        foreach ($slots[$slotId] ?? [] as $widget) {
            if (!is_array($widget)) {
                continue;
            }
            if (trim((string)($widget['widget_code'] ?? '')) !== $code) {
                continue;
            }
            $existingModule = trim((string)($widget['widget_module'] ?? ''));
            // Empty module on a seeded ghost must not suppress a required module.
            if ($existingModule === $module) {
                return true;
            }
            if ($existingModule === '' && $module === '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $omissions
     */
    private static function isOmitted(array $omissions, string $slotId, string $module, string $code): bool
    {
        foreach ($omissions as $omission) {
            if (!is_array($omission)) {
                continue;
            }
            $omittedSlot = trim((string)($omission['slot_id'] ?? ''));
            if ($omittedSlot !== '' && $omittedSlot !== $slotId) {
                continue;
            }
            if (trim((string)($omission['widget_code'] ?? '')) !== $code) {
                continue;
            }
            $omittedModule = trim((string)($omission['widget_module'] ?? ''));
            if ($omittedModule === '' || $omittedModule === $module) {
                return true;
            }
        }

        return false;
    }
}
