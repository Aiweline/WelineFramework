<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

class AiSiteProfileGenerationService
{
    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function generate(array $scope): array
    {
        $existing = \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [];
        $siteTitle = $this->resolveSiteTitle($scope, $existing);
        $siteTagline = \trim((string)($existing['site_tagline'] ?? $scope['site_tagline'] ?? ''));
        $briefDescription = \trim((string)($existing['brief_description'] ?? $scope['brief_description'] ?? $scope['user_description'] ?? ''));
        $targetDomain = \strtolower(\trim((string)($existing['target_domain'] ?? $scope['target_domain'] ?? '')));
        $defaultLocale = \trim((string)($existing['default_locale'] ?? $scope['default_locale'] ?? $scope['default_language'] ?? 'en_US'));
        $locales = $this->normalizeLocales($existing['locales'] ?? $scope['locales'] ?? $scope['language_codes'] ?? [], $defaultLocale);
        $initials = $this->extractInitials($siteTitle, $targetDomain);

        $logo = \trim((string)($existing['logo'] ?? $scope['logo'] ?? ''));
        if ($logo === '') {
            $logo = $this->buildSvgDataUri($initials, '#0f172a', '#ffffff', 160, 48, 12);
        }

        $icon = \trim((string)($existing['icon'] ?? $existing['favicon'] ?? $scope['icon'] ?? $scope['favicon'] ?? ''));
        if ($icon === '') {
            $icon = $this->buildSvgDataUri($initials, '#2563eb', '#ffffff', 64, 64, 18);
        }

        $seo = \is_array($existing['seo'] ?? null) ? $existing['seo'] : [];
        $metaTitle = \trim((string)($seo['meta_title'] ?? ''));
        if ($metaTitle === '') {
            $metaTitle = $siteTagline !== '' ? ($siteTitle . ' | ' . $siteTagline) : $siteTitle;
        }

        $metaDescription = \trim((string)($seo['meta_description'] ?? ''));
        if ($metaDescription === '') {
            $metaDescription = $this->clipText(
                $briefDescription !== '' ? $briefDescription : ($siteTagline !== '' ? $siteTagline : $siteTitle),
                160
            );
        }

        $metaKeywords = \trim((string)($seo['meta_keywords'] ?? ''));
        if ($metaKeywords === '') {
            $metaKeywords = $this->buildKeywordString($siteTitle, $targetDomain);
        }

        return [
            'site_title' => $siteTitle,
            'site_tagline' => $siteTagline,
            'brief_description' => $briefDescription,
            'target_domain' => $targetDomain,
            'default_locale' => $defaultLocale,
            'locales' => $locales,
            'logo' => $logo,
            'favicon' => $icon,
            'icon' => $icon,
            'seo' => [
                'meta_title' => $metaTitle,
                'meta_description' => $metaDescription,
                'meta_keywords' => $metaKeywords,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $existing
     */
    private function resolveSiteTitle(array $scope, array $existing): string
    {
        $siteTitle = \trim((string)($existing['site_title'] ?? $scope['site_title'] ?? ''));
        if ($siteTitle !== '') {
            return $siteTitle;
        }

        $domain = \strtolower(\trim((string)($existing['target_domain'] ?? $scope['target_domain'] ?? '')));
        if ($domain !== '') {
            $host = \preg_replace('/^https?:\/\//', '', $domain);
            $host = \explode('/', (string)$host)[0] ?? '';
            $host = \explode('.', (string)$host)[0] ?? '';
            $host = \str_replace(['-', '_'], ' ', (string)$host);
            $siteTitle = \ucwords(\trim((string)$host));
        }

        return $siteTitle !== '' ? $siteTitle : 'AI Site';
    }

    /**
     * @return list<string>
     */
    private function normalizeLocales(mixed $raw, string $defaultLocale): array
    {
        if (\is_array($raw)) {
            $items = $raw;
        } elseif (\is_string($raw) && \trim($raw) !== '') {
            $decoded = \json_decode($raw, true);
            $items = \is_array($decoded) ? $decoded : (\preg_split('/[\s,]+/', $raw, -1, \PREG_SPLIT_NO_EMPTY) ?: []);
        } else {
            $items = [];
        }

        $locales = [];
        foreach ($items as $item) {
            if (!\is_scalar($item)) {
                continue;
            }
            $locale = \trim((string)$item);
            if ($locale === '' || \in_array($locale, $locales, true)) {
                continue;
            }
            $locales[] = $locale;
        }

        if ($defaultLocale !== '' && !\in_array($defaultLocale, $locales, true)) {
            \array_unshift($locales, $defaultLocale);
        }

        return $locales;
    }

    private function extractInitials(string $siteTitle, string $targetDomain): string
    {
        $chunks = \preg_split('/[^a-z0-9]+/i', $siteTitle, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $letters = '';
        foreach ($chunks as $chunk) {
            $letters .= \substr($chunk, 0, 1);
            if (\strlen($letters) >= 2) {
                break;
            }
        }

        if ($letters !== '') {
            return \strtoupper($letters);
        }

        $domain = \preg_replace('/^https?:\/\//', '', $targetDomain);
        $domain = \explode('/', (string)$domain)[0] ?? '';
        $domain = \explode('.', (string)$domain)[0] ?? '';
        $domain = \preg_replace('/[^a-z0-9]+/i', '', (string)$domain);
        $letters = \strtoupper(\substr((string)$domain, 0, 2));

        return $letters !== '' ? $letters : 'AI';
    }

    private function clipText(string $value, int $limit): string
    {
        $value = \trim($value);
        if ($value === '') {
            return '';
        }

        if (\function_exists('mb_strlen') && \function_exists('mb_substr')) {
            if (\mb_strlen($value) <= $limit) {
                return $value;
            }

            return \rtrim(\mb_substr($value, 0, $limit - 1)) . '…';
        }

        if (\strlen($value) <= $limit) {
            return $value;
        }

        return \rtrim(\substr($value, 0, $limit - 1)) . '…';
    }

    private function buildKeywordString(string $siteTitle, string $targetDomain): string
    {
        $keywords = [];
        foreach ([$siteTitle, $targetDomain] as $source) {
            $parts = \preg_split('/[^a-z0-9]+/i', \strtolower($source), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($parts as $part) {
                if (\strlen($part) < 2 || \in_array($part, $keywords, true)) {
                    continue;
                }
                $keywords[] = $part;
            }
        }

        return \implode(', ', $keywords);
    }

    private function buildSvgDataUri(
        string $text,
        string $background,
        string $foreground,
        int $width,
        int $height,
        int $fontSize
    ): string {
        $safeText = htmlspecialchars($text, \ENT_QUOTES, 'UTF-8');
        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$width}" height="{$height}" viewBox="0 0 {$width} {$height}">
  <rect width="100%" height="100%" rx="10" fill="{$background}"/>
  <text x="50%" y="50%" dominant-baseline="central" text-anchor="middle" font-family="Arial, sans-serif" font-size="{$fontSize}" font-weight="700" fill="{$foreground}">{$safeText}</text>
</svg>
SVG;

        return 'data:image/svg+xml;base64,' . \base64_encode($svg);
    }
}
