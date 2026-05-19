<?php

declare(strict_types=1);

namespace Weline\Frontend\Service\Head;

class TitleComposer
{
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
        $siteName = $this->normalizeText($context['site_name'] ?? 'Weline Framework');
        $pageTitle = $this->normalizeText($this->firstNonEmpty([
            $context['seo_title'] ?? null,
            $context['meta_title'] ?? null,
            $context['title'] ?? null,
            $context['page_title'] ?? null,
            $siteName,
        ]));

        if (!empty($context['is_homepage'])) {
            $homeTitle = $this->normalizeText($policy['home_title'] ?? '');
            $title = ($policy['home_title_mode'] ?? 'site_only') === 'custom' && $homeTitle !== ''
                ? $homeTitle
                : ($siteName !== '' ? $siteName : $pageTitle);
            return $this->limitTitle($title, (int)($policy['max_length'] ?? 0));
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
        if ($appendSiteName && $siteName !== '' && !$this->titleContainsSiteName($parts, $siteName, (bool)($policy['deduplicate_site_name'] ?? true))) {
            if (($policy['site_name_position'] ?? 'suffix') === 'prefix') {
                array_unshift($parts, $siteName);
            } else {
                $parts[] = $siteName;
            }
        }

        $parts = array_values(array_filter($parts, static fn($part) => trim((string)$part) !== ''));
        if ($parts === [] && $siteName !== '') {
            $parts[] = $siteName;
        }

        $title = implode((string)($policy['separator'] ?? ' | '), $parts);
        return $this->limitTitle($title, (int)($policy['max_length'] ?? 0));
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
            'pagination_label' => '第 %{page} 页',
            'max_length' => 0,
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
        return $label !== '' ? $label : '第 ' . $currentPage . ' 页';
    }

    private function limitTitle(string $title, int $maxLength): string
    {
        if ($maxLength <= 0 || mb_strlen($title) <= $maxLength) {
            return $title;
        }

        return rtrim(mb_substr($title, 0, max(0, $maxLength - 3))) . '...';
    }
}
