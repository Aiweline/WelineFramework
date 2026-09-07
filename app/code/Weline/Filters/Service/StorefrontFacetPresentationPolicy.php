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
            $nonEmpty = [];
            foreach ($counts as $value => $count) {
                $value = trim((string)$value);
                $count = (int)$count;
                if ($value !== '' && $count > 0) {
                    $nonEmpty[$value] = $count;
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
}
