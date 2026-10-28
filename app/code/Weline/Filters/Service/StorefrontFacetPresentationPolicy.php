<?php

declare(strict_types=1);

namespace Weline\Filters\Service;

/**
 * Keeps storefront facets focused on purchase decisions.
 *
 * Product EAV also contains sourcing, quality-control and export attributes.
 * Those fields remain available to catalog operations, but must not turn the
 * customer filter rail into a raw supplier-data dump. Semantic aliases are
 * grouped so only the first populated (or currently selected) source is shown.
 */
final class StorefrontFacetPresentationPolicy
{
    /**
     * Ordered by the way a Hanfu shopper narrows a collection.
     *
     * @var list<list<string>>
     */
    private const FACET_FAMILIES = [
        ['hanfu_chao_dai'],
        ['hanfu_xing_zhi', 'hanfu_han_fu_zhi_shi', 'style_type'],
        ['hanfu_shi_yong_xing_bie'],
        ['color', 'available_colors'],
        ['size', 'available_sizes'],
        ['hanfu_zhi_wu', 'material', 'hanfu_zhi_wu_ming_cheng', 'hanfu_zhu_zhi_wu_cheng_fen'],
        ['hanfu_shi_yong_chang_he'],
        ['hanfu_shi_yong_ji_jie', 'hanfu_shi_he_ji_jie', 'hanfu_shang_shi_nian_fen_ji_jie'],
        ['hanfu_feng_ge', 'hanfu_zao_xing_feng_ge'],
        ['hanfu_gong_yi', 'hanfu_zhi_wu_gong_yi'],
        ['hanfu_tu_an'],
        ['brand', 'hanfu_pin_pai'],
    ];

    /**
     * Shopper rail keeps only reachable facets. Unselected count=1 values are
     * dead ends and dominate the shared SSR leaf — one measured PLP snapshot
     * had 1041 of 1192 options at count=1.
     */
    public const MIN_UNSELECTED_OPTION_COUNT = 2;

    /** @var list<string> */
    private const COLOR_FAMILY_CODES = ['color', 'available_colors'];

    /**
     * Apparel letter sizes (and size-like pure numerics) that leak into color EAV.
     * Matched case-insensitively against the option value token.
     */
    private const COLOR_SIZE_TOKEN_PATTERN = '/^(?:XXS|XS|S|M|L|XL|XXL|XXXL|\d{1,2})$/i';

    /**
     * Flat list of shopper-facing facet attribute codes (for bounded EAV loads).
     *
     * @return list<string>
     */
    public function allCandidateCodes(): array
    {
        $codes = [];
        foreach (self::FACET_FAMILIES as $family) {
            foreach ($family as $code) {
                $code = strtolower(trim((string)$code));
                if ($code !== '') {
                    $codes[$code] = $code;
                }
            }
        }

        return array_values($codes);
    }

    /**
     * Restrict offer projection counting to customer-facing candidates.
     *
     * @param array<string, string> $codeNames
     * @return array<string, string>
     */
    public function candidateCodeNames(array $codeNames): array
    {
        $normalized = [];
        foreach ($codeNames as $code => $name) {
            $code = strtolower(trim((string)$code));
            if ($code === '') {
                continue;
            }
            $normalized[$code] = trim((string)$name) !== '' ? trim((string)$name) : $code;
        }

        $candidates = [];
        foreach (self::FACET_FAMILIES as $family) {
            foreach ($family as $code) {
                if (isset($normalized[$code])) {
                    $candidates[$code] = $normalized[$code];
                }
            }
        }

        return $candidates;
    }

    /**
     * @param array<string, array<string, int>> $countsByCode
     * @param array<string, string> $codeNames
     * @param array<string, string> $selectedAttributes
     * @return array<string, array{name:string,counts:array<string,int>}>
     */
    public function curateFacetCounts(
        array $countsByCode,
        array $codeNames,
        array $selectedAttributes = [],
    ): array {
        $normalizedCounts = [];
        foreach ($countsByCode as $code => $counts) {
            $code = strtolower(trim((string)$code));
            if ($code === '' || !is_array($counts)) {
                continue;
            }
            $selectedValue = '';
            if (isset($selectedAttributes[$code])) {
                $selectedValue = trim((string)$selectedAttributes[$code]);
            }
            $stripColorSizeJunk = $this->isColorFamilyCode($code);
            $nonEmpty = [];
            foreach ($counts as $value => $count) {
                $value = trim((string)$value);
                $count = (int)$count;
                $isSelected = $selectedValue !== ''
                    && strtolower($selectedValue) === strtolower($value);
                // Color family: drop size-token junk (S/M/L/XL…) unless currently selected.
                if ($stripColorSizeJunk && !$isSelected && $this->isColorSizeToken($value)) {
                    continue;
                }
                // R3: drop unselected zeros; R1/R2: keep selected even when count=0.
                if ($value !== '' && ($count > 0 || $isSelected)) {
                    $nonEmpty[$value] = $count;
                }
            }
            if ($selectedValue !== '' && !isset($nonEmpty[$selectedValue])) {
                // Preserve exact selected label key when counts used a different casing.
                $matched = false;
                foreach ($nonEmpty as $existing => $_) {
                    if (strtolower((string)$existing) === strtolower($selectedValue)) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    $nonEmpty[$selectedValue] = 0;
                }
            }
            if ($nonEmpty !== []) {
                $normalizedCounts[$code] = $nonEmpty;
            }
        }

        $normalizedNames = [];
        foreach ($codeNames as $code => $name) {
            $code = strtolower(trim((string)$code));
            if ($code !== '') {
                $normalizedNames[$code] = trim((string)$name) !== '' ? trim((string)$name) : $code;
            }
        }
        $selectedCodes = array_fill_keys(array_map(
            static fn(string $code): string => strtolower(trim($code)),
            array_keys($selectedAttributes),
        ), true);

        // R4: selected attribute with only count=0 must still produce a curated group.
        foreach ($selectedAttributes as $code => $value) {
            $code = strtolower(trim((string)$code));
            $value = trim((string)$value);
            if ($code === '' || $value === '' || isset($normalizedCounts[$code])) {
                continue;
            }
            $normalizedCounts[$code] = [$value => 0];
            if (!isset($normalizedNames[$code])) {
                $normalizedNames[$code] = $code;
            }
        }

        $curated = [];
        foreach (self::FACET_FAMILIES as $family) {
            $chosen = '';
            foreach ($family as $code) {
                if (isset($selectedCodes[$code], $normalizedCounts[$code])) {
                    $chosen = $code;
                    break;
                }
            }
            if ($chosen === '') {
                foreach ($family as $code) {
                    if (isset($normalizedCounts[$code])) {
                        $chosen = $code;
                        break;
                    }
                }
            }
            if ($chosen === '') {
                continue;
            }

            $curated[$chosen] = [
                'name' => $normalizedNames[$chosen] ?? $chosen,
                'counts' => $normalizedCounts[$chosen],
            ];
        }

        return $curated;
    }

    /**
     * Shopper rail sort: selected first, then count DESC, then label.
     *
     * @param array{label?:string,value?:string,count?:int,selected?:bool} $left
     * @param array{label?:string,value?:string,count?:int,selected?:bool} $right
     */
    public function compareFacetOptions(array $left, array $right): int
    {
        $selectionOrder = (int)!empty($right['selected']) <=> (int)!empty($left['selected']);
        if ($selectionOrder !== 0) {
            return $selectionOrder;
        }
        $countOrder = (int)($right['count'] ?? 0) <=> (int)($left['count'] ?? 0);
        if ($countOrder !== 0) {
            return $countOrder;
        }
        $leftLabel = (string)($left['label'] ?? $left['value'] ?? '');
        $rightLabel = (string)($right['label'] ?? $right['value'] ?? '');

        return strcasecmp($leftLabel, $rightLabel);
    }

    public function isUnselectedOptionVisible(int $count): bool
    {
        return $count >= self::MIN_UNSELECTED_OPTION_COUNT;
    }

    public function isColorFamilyCode(string $code): bool
    {
        return in_array(strtolower(trim($code)), self::COLOR_FAMILY_CODES, true);
    }

    public function isColorSizeToken(string $value): bool
    {
        $value = trim($value);

        return $value !== '' && preg_match(self::COLOR_SIZE_TOKEN_PATTERN, $value) === 1;
    }
}
