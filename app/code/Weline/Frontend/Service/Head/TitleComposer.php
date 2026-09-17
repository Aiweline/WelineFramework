<?php

declare(strict_types=1);

namespace Weline\Frontend\Service\Head;

class TitleComposer
{
    public const SOFT_SERP_TITLE_MAX = 65;

    public function __construct(
        private readonly ?HeadProviderRegistry $providerRegistry = null
    ) {
    }

    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     */
    public function compose($template, array $context): string
    {
        $policy = $this->applyPolicyProviders($template, $context, $this->defaultPolicy());
        $siteName = $this->normalizeText($context['site_name'] ?? '');
        $pageTitle = $this->normalizeText($this->firstNonEmpty([
            $context['seo_title'] ?? null,
            $context['meta_title'] ?? null,
            $context['title'] ?? null,
            $context['page_title'] ?? null,
            $siteName,
        ]));
        $maxLength = (int)($policy['max_length'] ?? self::SOFT_SERP_TITLE_MAX);
        $separator = (string)($policy['separator'] ?? ' | ');

        if (!empty($context['is_homepage'])) {
            $homeTitle = $this->normalizeText($policy['home_title'] ?? '');
            $title = ($policy['home_title_mode'] ?? 'site_only') === 'custom' && $homeTitle !== ''
                ? $homeTitle
                : ($siteName !== '' ? $siteName : $pageTitle);
            return $this->fitSingleTitle($title, $maxLength);
        }

        $parts = [];
        if ($pageTitle !== '') {
            $parts[] = $pageTitle;
        }

        $currentPage = (int)($context['current_page'] ?? 1);
        if ($currentPage > 1) {
            $parts[] = $this->paginationLabel($currentPage, (string)($policy['pagination_label'] ?? 'Page %{page}'));
        }

        $appendSiteName = (bool)($policy['append_site_name'] ?? true);
        $siteNamePosition = (string)($policy['site_name_position'] ?? 'suffix');
        $siteAttached = false;
        if ($appendSiteName && $siteName !== '' && !$this->titleContainsSiteName($parts, $siteName, (bool)($policy['deduplicate_site_name'] ?? true))) {
            if ($siteNamePosition === 'prefix') {
                array_unshift($parts, $siteName);
            } else {
                $parts[] = $siteName;
            }
            $siteAttached = true;
        }

        $parts = array_values(array_filter($parts, static fn($part) => trim((string)$part) !== ''));
        if ($parts === [] && $siteName !== '') {
            $parts[] = $siteName;
            $siteAttached = true;
        }

        return $this->fitComposedParts(
            $parts,
            $separator,
            $siteAttached ? $siteName : '',
            $siteNamePosition,
            $maxLength
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultPolicy(): array
    {
        return [
            'separator' => ' | ',
            'append_site_name' => true,
            'site_name_position' => 'suffix',
            'deduplicate_site_name' => true,
            'home_title_mode' => 'site_only',
            'home_title' => '',
            'pagination_label' => 'Page %{page}',
            // Soft SERP budget aligned with Seo inspector / crawler (30-65).
            'max_length' => self::SOFT_SERP_TITLE_MAX,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $policy
     * @return array<string, mixed>
     */
    private function applyPolicyProviders($template, array $context, array $policy): array
    {
        if (!$this->providerRegistry) {
            return $policy;
        }

        foreach ($this->providerRegistry->getPolicyProviders() as $provider) {
            try {
                $provided = $provider->provide($template, $policy, $context);
                if ($provided !== []) {
                    $policy = array_replace($policy, $provided);
                }
            } catch (\Throwable) {
            }
        }

        return $policy;
    }

    /**
     * @param mixed[] $values
     */
    private function firstNonEmpty(array $values): mixed
    {
        foreach ($values as $value) {
            if (!is_array($value) && $value !== null && trim((string)$value) !== '') {
                return $value;
            }
        }
        return '';
    }

    private function normalizeText(mixed $value): string
    {
        $text = trim(html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return preg_replace('/\s+/u', ' ', $text) ?: $text;
    }

    /**
     * @param string[] $parts
     */
    private function titleContainsSiteName(array $parts, string $siteName, bool $deduplicate): bool
    {
        if (!$deduplicate) {
            return false;
        }
        $needle = mb_strtolower($siteName);
        foreach ($parts as $part) {
            if (mb_strpos(mb_strtolower((string)$part), $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private function paginationLabel(int $currentPage, string $format): string
    {
        $label = str_replace(['%{page}', '%{1}'], (string)$currentPage, $format);
        return $label !== '' ? $label : 'Page ' . $currentPage;
    }

    /**
     * Prefer shortening the brand suffix (segment before ·/-) before trimming the page leaf.
     *
     * @param list<string> $parts
     */
    private function fitComposedParts(
        array $parts,
        string $separator,
        string $siteName,
        string $siteNamePosition,
        int $maxLength
    ): string {
        $title = implode($separator, $parts);
        if ($maxLength <= 0 || mb_strlen($title) <= $maxLength) {
            return $title;
        }

        if ($siteName !== '') {
            $shortSite = $this->shortSiteName($siteName);
            if ($shortSite !== '' && $shortSite !== $siteName) {
                $parts = $this->replaceSitePart($parts, $siteName, $shortSite, $siteNamePosition);
                $title = implode($separator, $parts);
                if (mb_strlen($title) <= $maxLength) {
                    return $title;
                }
                $siteName = $shortSite;
            }
        }

        if ($siteName !== '' && count($parts) >= 2) {
            $siteBudget = mb_strlen($siteName) + mb_strlen($separator);
            $leafBudget = max(12, $maxLength - $siteBudget);
            $leafIndex = $siteNamePosition === 'prefix' ? count($parts) - 1 : 0;
            if (isset($parts[$leafIndex]) && $parts[$leafIndex] !== $siteName) {
                $parts[$leafIndex] = $this->truncateAtWord((string)$parts[$leafIndex], $leafBudget);
                $title = implode($separator, array_values(array_filter($parts, static fn($p) => trim((string)$p) !== '')));
                if (mb_strlen($title) <= $maxLength) {
                    return $title;
                }
            }
        }

        return $this->fitSingleTitle($title, $maxLength);
    }

    /**
     * @param list<string> $parts
     * @return list<string>
     */
    private function replaceSitePart(array $parts, string $siteName, string $shortSite, string $siteNamePosition): array
    {
        if ($siteNamePosition === 'prefix' && isset($parts[0]) && $parts[0] === $siteName) {
            $parts[0] = $shortSite;
            return $parts;
        }
        $last = count($parts) - 1;
        if ($last >= 0 && $parts[$last] === $siteName) {
            $parts[$last] = $shortSite;
        }
        return $parts;
    }

    private function shortSiteName(string $siteName): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $siteName) ?? $siteName);
        foreach ([' · ', ' • ', ' - ', ' – ', ' — ', ' | '] as $delimiter) {
            $pos = mb_strpos($normalized, $delimiter);
            if ($pos !== false && $pos >= 2) {
                $short = trim(mb_substr($normalized, 0, $pos));
                if ($short !== '') {
                    return $short;
                }
            }
        }

        return $normalized;
    }

    private function fitSingleTitle(string $title, int $maxLength): string
    {
        if ($maxLength <= 0 || mb_strlen($title) <= $maxLength) {
            return $title;
        }

        return $this->truncateAtWord($title, $maxLength);
    }

    private function truncateAtWord(string $text, int $maxLength): string
    {
        if ($maxLength <= 0 || mb_strlen($text) <= $maxLength) {
            return $text;
        }

        $cut = mb_substr($text, 0, $maxLength);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space >= (int)floor($maxLength * 0.55)) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, " \t-,:;|/·");
    }
}
