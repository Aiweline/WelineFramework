<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Contract;

final class VisualAssetUsageValidator
{
    /**
     * @param array<string, mixed> $assetManifest
     * @return array{valid:bool, violations:list<string>}
     */
    public function validate(array $assetManifest, string $renderedHtml): array
    {
        $violations = [];

        $srcUsages = $this->extractImageSources($renderedHtml);

        foreach ($srcUsages as $src => $count) {
            $maxUsage = $this->resolveMaxUsage($assetManifest, $src);
            if ($count > $maxUsage) {
                $violations[] = "Image {$src} used {$count} times (max {$maxUsage})";
            }
        }

        return [
            'valid' => $violations === [],
            'violations' => $violations,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function extractImageSources(string $html): array
    {
        $sources = [];

        \preg_match_all('/<img[^>]+src\s*=\s*["\']([^"\']+)["\']/si', $html, $matches);
        foreach ($matches[1] ?? [] as $src) {
            $src = \trim((string)$src);
            if ($src !== '') {
                $sources[$src] = ($sources[$src] ?? 0) + 1;
            }
        }

        \preg_match_all('/url\(["\']?([^)"\']+)["\']?\)/si', $html, $matches);
        foreach ($matches[1] ?? [] as $url) {
            $url = \trim((string)$url);
            if ($url !== '' && !\str_starts_with($url, 'data:')) {
                $sources[$url] = ($sources[$url] ?? 0) + 1;
            }
        }

        return $sources;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function resolveMaxUsage(array $manifest, string $src): int
    {
        $slots = \is_array($manifest['slots'] ?? null) ? $manifest['slots'] : $manifest;
        foreach ($slots as $slot) {
            if (!\is_array($slot)) {
                continue;
            }
            $finalUrl = \trim((string)($slot['final_url'] ?? ''));
            if ($finalUrl !== '' && \str_contains($src, $finalUrl)) {
                if ($this->isReusableIdentityAssetSlot($slot)) {
                    return \max(2, (int)($slot['max_usage'] ?? 12));
                }
                return (int)($slot['max_usage'] ?? 1);
            }
        }

        return 1;
    }

    /**
     * Header and footer should normally reuse the same brand identity asset.
     * The single-use rule is intended for section photos/media, not logos/icons.
     *
     * @param array<string, mixed> $slot
     */
    private function isReusableIdentityAssetSlot(array $slot): bool
    {
        $slotId = \strtolower(\trim((string)($slot['slot_id'] ?? '')));
        $slotType = \strtolower(\trim((string)($slot['slot_type'] ?? '')));
        $kind = \strtolower(\trim((string)($slot['kind'] ?? '')));
        $field = \strtolower(\trim((string)($slot['field'] ?? '')));
        $sectionCode = \strtolower(\trim((string)($slot['section_code'] ?? '')));

        return \str_starts_with($slotId, 'identity:')
            || \in_array($slotType, ['logo_icon', 'brand_identity', 'identity'], true)
            || \in_array($kind, ['website_logo', 'brand_logo', 'site_title_icon', 'favicon'], true)
            || \in_array($field, ['logo', 'favicon', 'icon', 'site.icon', 'brand.logo'], true)
            || \in_array($sectionCode, ['identity', 'global'], true);
    }
}
