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
                return (int)($slot['max_usage'] ?? 1);
            }
        }

        return 1;
    }
}
