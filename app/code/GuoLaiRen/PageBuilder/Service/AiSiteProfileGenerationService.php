<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use Weline\Framework\Manager\ObjectManager;

class AiSiteProfileGenerationService
{
    private const PROFILE_VERSION = 4;

    public function __construct(
        private readonly ?AiSiteProfileAiGenerationService $aiProfileGenerator = null,
    ) {
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function generate(array $scope, bool $allowAi = true): array
    {
        $existing = \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [];
        $manualFlags = $this->normalizeManualFlags($scope['site_profile_manual'] ?? null);
        $hasManualMap = \is_array($scope['site_profile_manual'] ?? null);

        $defaultLocale = $this->pickString(
            $scope['default_locale'] ?? null,
            $scope['default_language'] ?? null,
            $existing['default_locale'] ?? null,
            'en_US'
        );
        $locales = $this->resolveLocales($scope, $existing, $defaultLocale);
        $sourceBrief = $this->resolveSourceBrief($scope, $existing, $manualFlags);
        $targetDomain = $this->resolveTargetDomain($scope, $existing, $manualFlags);

        $titleLocked = $this->hasManualOverride($scope, 'site_title', $manualFlags);
        $taglineLocked = $this->hasManualOverride($scope, 'site_tagline', $manualFlags);
        $briefLocked = $this->hasManualOverride($scope, 'brief_description', $manualFlags);

        $lockedTitle = $titleLocked
            ? $this->readScopeString($scope, 'site_title')
            : $this->resolveLegacyLockedTitle($existing, $hasManualMap);
        $lockedTagline = $taglineLocked
            ? $this->readScopeString($scope, 'site_tagline')
            : $this->resolveLegacyLockedTagline($existing, $hasManualMap);
        $lockedBrief = $briefLocked ? $this->readScopeString($scope, 'brief_description') : '';

        $lockedLogo = $this->resolveLockedAsset($scope, $existing, 'logo', $hasManualMap);
        $lockedIcon = $this->resolveLockedIcon($scope, $existing, $hasManualMap);
        $logoLocked = $lockedLogo !== '';
        $iconLocked = $lockedIcon !== '';

        $signature = $this->buildGenerationSignature(
            $sourceBrief,
            $targetDomain,
            $defaultLocale,
            $titleLocked || $lockedTitle !== '',
            $lockedTitle,
            $taglineLocked || $lockedTagline !== '',
            $lockedTagline,
            $briefLocked,
            $lockedBrief,
            $logoLocked,
            $lockedLogo,
            $iconLocked,
            $lockedIcon
        );

        $generated = $this->canReuseGeneratedProfile($existing, $signature)
            ? $existing
            : $this->generateManagedProfile(
                $sourceBrief,
                $targetDomain,
                $defaultLocale,
                $signature,
                $lockedTitle,
                $lockedTagline,
                $lockedBrief,
                $lockedLogo,
                $lockedIcon,
                $allowAi
            );

        $siteTitle = ($titleLocked || $lockedTitle !== '')
            ? $lockedTitle
            : $this->pickString(
                $generated['site_title'] ?? null,
                $this->deriveSiteTitleFromBrief($sourceBrief, $targetDomain)
            );

        $siteTagline = ($taglineLocked || $lockedTagline !== '')
            ? $lockedTagline
            : $this->pickString(
                $generated['site_tagline'] ?? null,
                $this->deriveSiteTaglineFromBrief($sourceBrief, $siteTitle)
            );

        $briefDescription = $briefLocked
            ? $lockedBrief
            : $this->pickString(
                $generated['brief_description'] ?? null,
                $sourceBrief
            );

        $logo = $logoLocked
            ? $lockedLogo
            : $this->pickString(
                $generated['logo'] ?? null,
                $this->buildFallbackLogoDataUri($siteTitle, $siteTagline, $sourceBrief)
            );

        $icon = $iconLocked
            ? $lockedIcon
            : $this->pickString(
                $generated['icon'] ?? null,
                $this->buildFallbackIconDataUri($siteTitle, $siteTagline, $sourceBrief)
            );

        $seoInput = $this->resolveSeoInput($scope, $existing);
        $metaTitle = \trim((string)($seoInput['meta_title'] ?? ''));
        if ($metaTitle === '') {
            $metaTitle = $this->pickString(
                $generated['meta_title'] ?? null,
                $siteTagline !== '' && $siteTitle !== '' ? ($siteTitle . ' | ' . $siteTagline) : $siteTitle
            );
        }

        $metaDescription = \trim((string)($seoInput['meta_description'] ?? ''));
        if ($metaDescription === '') {
            $metaDescription = $this->pickString(
                $generated['meta_description'] ?? null,
                $this->clipText($briefDescription !== '' ? $briefDescription : ($siteTagline !== '' ? $siteTagline : $siteTitle), 160)
            );
        }

        $metaKeywords = \trim((string)($seoInput['meta_keywords'] ?? ''));
        if ($metaKeywords === '') {
            $metaKeywords = $this->pickString(
                $generated['meta_keywords'] ?? null,
                $this->buildKeywordString($siteTitle, $targetDomain, $briefDescription)
            );
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
            '_ai_profile' => [
                'version' => self::PROFILE_VERSION,
                'signature' => $signature,
                'source_brief' => $sourceBrief,
                'managed_fields' => [
                    'site_title' => !($titleLocked || $lockedTitle !== ''),
                    'site_tagline' => !($taglineLocked || $lockedTagline !== ''),
                    'brief_description' => !$briefLocked,
                    'logo' => !$logoLocked,
                    'icon' => !$iconLocked,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $existing
     * @param array<string, bool> $manualFlags
     */
    private function resolveSourceBrief(array $scope, array $existing, array $manualFlags): string
    {
        if ($this->hasManualOverride($scope, 'brief_description', $manualFlags)) {
            return $this->readScopeString($scope, 'brief_description');
        }

        $existingMeta = \is_array($existing['_ai_profile'] ?? null) ? $existing['_ai_profile'] : [];

        return $this->pickString(
            $scope['brief_description'] ?? null,
            $scope['user_description'] ?? null,
            $existingMeta['source_brief'] ?? null,
            $existing['brief_description'] ?? null
        );
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $existing
     * @param array<string, bool> $manualFlags
     */
    private function resolveTargetDomain(array $scope, array $existing, array $manualFlags): string
    {
        if ($this->hasManualOverride($scope, 'target_domain', $manualFlags)) {
            return \strtolower($this->readScopeString($scope, 'target_domain'));
        }

        return \strtolower($this->pickString($scope['target_domain'] ?? null, $existing['target_domain'] ?? null));
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $existing
     * @return list<string>
     */
    private function resolveLocales(array $scope, array $existing, string $defaultLocale): array
    {
        $localesSource = $scope['locales'] ?? $scope['language_codes'] ?? null;
        if (!\is_array($localesSource) || $localesSource === []) {
            $localesSource = $existing['locales'] ?? [];
        }

        return $this->normalizeLocales($localesSource, $defaultLocale);
    }

    /**
     * @param array<string, mixed> $existing
     */
    private function resolveLegacyLockedTitle(array $existing, bool $hasManualMap): string
    {
        if ($hasManualMap || $this->hasManagedProfileMeta($existing)) {
            return '';
        }

        return $this->normalizeMeaningfulTitle($existing['site_title'] ?? null);
    }

    /**
     * @param array<string, mixed> $existing
     */
    private function resolveLegacyLockedTagline(array $existing, bool $hasManualMap): string
    {
        if ($hasManualMap || $this->hasManagedProfileMeta($existing)) {
            return '';
        }

        return $this->pickString($existing['site_tagline'] ?? null);
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $existing
     */
    private function resolveLockedAsset(array $scope, array $existing, string $key, bool $hasManualMap): string
    {
        $scopeValue = $this->pickString($scope[$key] ?? null);
        if ($scopeValue !== '' && !$this->isGeneratedPlaceholderImage($scopeValue)) {
            return $scopeValue;
        }

        if ($this->hasManagedProfileMeta($existing)) {
            return '';
        }

        if ($hasManualMap) {
            return '';
        }

        $existingValue = $this->pickString($existing[$key] ?? null);
        if ($existingValue === '' || $this->isGeneratedPlaceholderImage($existingValue)) {
            return '';
        }

        return $existingValue;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $existing
     */
    private function resolveLockedIcon(array $scope, array $existing, bool $hasManualMap): string
    {
        foreach (['icon', 'favicon'] as $key) {
            $scopeValue = $this->pickString($scope[$key] ?? null);
            if ($scopeValue !== '' && !$this->isGeneratedPlaceholderImage($scopeValue)) {
                return $scopeValue;
            }
        }

        if ($this->hasManagedProfileMeta($existing) || $hasManualMap) {
            return '';
        }

        foreach (['icon', 'favicon'] as $key) {
            $existingValue = $this->pickString($existing[$key] ?? null);
            if ($existingValue !== '' && !$this->isGeneratedPlaceholderImage($existingValue)) {
                return $existingValue;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $existing
     */
    private function hasManagedProfileMeta(array $existing): bool
    {
        return \is_array($existing['_ai_profile'] ?? null)
            && (int)($existing['_ai_profile']['version'] ?? 0) > 0;
    }

    private function buildGenerationSignature(
        string $sourceBrief,
        string $targetDomain,
        string $defaultLocale,
        bool $titleLocked,
        string $lockedTitle,
        bool $taglineLocked,
        string $lockedTagline,
        bool $briefLocked,
        string $lockedBrief,
        bool $logoLocked,
        string $lockedLogo,
        bool $iconLocked,
        string $lockedIcon
    ): string {
        $signatureSource = [
            'version' => self::PROFILE_VERSION,
            'source_brief' => $sourceBrief,
            'target_domain' => $targetDomain,
            'default_locale' => $defaultLocale,
            'locks' => [
                'site_title' => ['enabled' => $titleLocked, 'value' => $lockedTitle],
                'site_tagline' => ['enabled' => $taglineLocked, 'value' => $lockedTagline],
                'brief_description' => ['enabled' => $briefLocked, 'value' => $lockedBrief],
                'logo' => ['enabled' => $logoLocked, 'hash' => $lockedLogo !== '' ? \sha1($lockedLogo) : ''],
                'icon' => ['enabled' => $iconLocked, 'hash' => $lockedIcon !== '' ? \sha1($lockedIcon) : ''],
            ],
        ];

        return \sha1((string)\json_encode($signatureSource, \JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param array<string, mixed> $existing
     */
    private function canReuseGeneratedProfile(array $existing, string $signature): bool
    {
        $meta = \is_array($existing['_ai_profile'] ?? null) ? $existing['_ai_profile'] : [];
        if ((int)($meta['version'] ?? 0) !== self::PROFILE_VERSION) {
            return false;
        }

        return (string)($meta['signature'] ?? '') === $signature;
    }

    /**
     * @return array<string, mixed>
     */
    private function generateManagedProfile(
        string $sourceBrief,
        string $targetDomain,
        string $defaultLocale,
        string $signature,
        string $lockedTitle,
        string $lockedTagline,
        string $lockedBrief,
        string $lockedLogo,
        string $lockedIcon,
        bool $allowAi
    ): array {
        $fallbackTitle = $lockedTitle !== '' ? $lockedTitle : $this->deriveSiteTitleFromBrief($sourceBrief, $targetDomain);
        $fallbackTagline = $lockedTagline !== '' ? $lockedTagline : $this->deriveSiteTaglineFromBrief($sourceBrief, $fallbackTitle);
        $fallbackBrief = $lockedBrief !== '' ? $lockedBrief : $sourceBrief;

        $generated = [];
        if ($allowAi) {
            try {
                $generated = $this->getAiProfileGenerator()->generateProfile([
                    'brief_description' => $sourceBrief,
                    'target_domain' => $targetDomain,
                    'default_locale' => $defaultLocale,
                    'locked_site_title' => $lockedTitle,
                    'locked_site_tagline' => $lockedTagline,
                    'locked_brief_description' => $lockedBrief,
                    'locked_logo' => $lockedLogo,
                    'locked_icon' => $lockedIcon,
                ]);
            } catch (\Throwable) {
                $generated = [];
            }
        }

        $siteTitle = $this->pickString($generated['site_title'] ?? null, $fallbackTitle);
        $siteTagline = $this->pickString($generated['site_tagline'] ?? null, $fallbackTagline);
        $briefDescription = $this->pickString($generated['brief_description'] ?? null, $fallbackBrief);
        $logo = $this->normalizeGeneratedSvgAsset($this->pickString($generated['logo_svg'] ?? null, $generated['logo'] ?? null));
        $icon = $this->normalizeGeneratedSvgAsset($this->pickString($generated['icon_svg'] ?? null, $generated['icon'] ?? null));

        if ($logo === '') {
            $logo = $this->buildFallbackLogoDataUri($siteTitle, $siteTagline, $sourceBrief);
        }
        if ($icon === '') {
            $icon = $this->buildFallbackIconDataUri($siteTitle, $siteTagline, $sourceBrief);
        }

        return [
            'site_title' => $siteTitle,
            'site_tagline' => $siteTagline,
            'brief_description' => $briefDescription,
            'logo' => $logo,
            'icon' => $icon,
            'meta_title' => $this->pickString($generated['meta_title'] ?? null, $siteTagline !== '' ? ($siteTitle . ' | ' . $siteTagline) : $siteTitle),
            'meta_description' => $this->pickString($generated['meta_description'] ?? null, $this->clipText($briefDescription, 160)),
            'meta_keywords' => $this->pickString($generated['meta_keywords'] ?? null, $this->buildKeywordString($siteTitle, $targetDomain, $briefDescription)),
            '_ai_profile' => [
                'version' => self::PROFILE_VERSION,
                'signature' => $signature,
                'source_brief' => $sourceBrief,
            ],
        ];
    }

    private function getAiProfileGenerator(): AiSiteProfileAiGenerationService
    {
        return $this->aiProfileGenerator ?? ObjectManager::getInstance(AiSiteProfileAiGenerationService::class);
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    private function resolveSeoInput(array $scope, array $existing): array
    {
        if (\is_array($scope['seo'] ?? null)) {
            return $scope['seo'];
        }

        return \is_array($existing['seo'] ?? null) ? $existing['seo'] : [];
    }

    /**
     * @return array<string, bool>
     */
    private function normalizeManualFlags(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $flags = [];
        foreach ($raw as $key => $value) {
            if (!\is_scalar($key)) {
                continue;
            }
            $flags[(string)$key] = \in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true);
        }

        return $flags;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, bool> $manualFlags
     */
    private function hasManualOverride(array $scope, string $key, array $manualFlags): bool
    {
        return !empty($manualFlags[$key]) && \array_key_exists($key, $scope);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function readScopeString(array $scope, string $key): string
    {
        if (!\array_key_exists($key, $scope) || !\is_scalar($scope[$key])) {
            return '';
        }

        return \trim((string)$scope[$key]);
    }

    private function normalizeMeaningfulTitle(mixed $value): string
    {
        if (!\is_scalar($value)) {
            return '';
        }

        $title = $this->normalizeWhitespace((string)$value);
        if ($title === '' || $this->isPlaceholderSiteTitle($title)) {
            return '';
        }

        return $this->clipSiteTitle($title);
    }

    private function deriveSiteTitleFromBrief(string $briefDescription, string $targetDomain): string
    {
        $candidates = $this->buildDescriptionSegments($briefDescription);
        foreach ($candidates as $candidate) {
            $candidate = $this->stripTitleLeadIn($candidate);
            if ($candidate === '' || $this->isPlaceholderSiteTitle($candidate)) {
                continue;
            }

            return $this->clipSiteTitle($candidate);
        }

        return $this->deriveTitleFromDomain($targetDomain);
    }

    private function deriveSiteTaglineFromBrief(string $briefDescription, string $siteTitle): string
    {
        $segments = $this->buildDescriptionSegments($briefDescription);
        foreach ($segments as $segment) {
            $candidate = $this->stripRepeatedTitlePrefix($segment, $siteTitle);
            if ($candidate !== '' && $candidate !== $siteTitle) {
                return $this->clipText($candidate, 72);
            }
        }

        $candidate = $this->stripRepeatedTitlePrefix($this->normalizeWhitespace($briefDescription), $siteTitle);
        if ($candidate === '' || $candidate === $siteTitle) {
            return '';
        }

        return $this->clipText($candidate, 72);
    }

    /**
     * @return list<string>
     */
    private function buildDescriptionSegments(string $briefDescription): array
    {
        $briefDescription = $this->normalizeWhitespace($briefDescription);
        if ($briefDescription === '') {
            return [];
        }

        $sentences = \preg_split('/[\r\n]+|[\x{3002}\x{FF01}\x{FF1F}!?;]+/u', $briefDescription, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $segments = [];
        foreach ($sentences as $sentence) {
            $sentence = $this->normalizeWhitespace($sentence);
            if ($sentence === '') {
                continue;
            }
            $parts = \preg_split('/[\x{FF0C},]+/u', $sentence, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($parts as $part) {
                $part = $this->normalizeWhitespace($part);
                if ($part === '') {
                    continue;
                }
                $segments[] = $part;
            }
            $segments[] = $sentence;
        }

        return \array_values(\array_unique($segments));
    }

    private function stripTitleLeadIn(string $candidate): string
    {
        $candidate = $this->normalizeWhitespace($candidate);
        if ($candidate === '') {
            return '';
        }

        $patterns = [
            '/^(?:请帮我|帮我|帮忙|我要|我想|我需要|需要|想做|想要|希望|打算|准备|计划)\s*/u',
            '/^(?:做|做个|做一个|做一套|搭建|创建|生成|制作|开发|设计|建立)\s*/u',
            '/^(?:一个|个|一套|一款)\s*/u',
        ];

        foreach ($patterns as $pattern) {
            $candidate = (string)\preg_replace($pattern, '', $candidate);
            $candidate = \trim($candidate, " \t\n\r\0\x0B-_:：|");
        }

        return $this->normalizeWhitespace($candidate);
    }

    private function stripRepeatedTitlePrefix(string $candidate, string $siteTitle): string
    {
        $candidate = $this->normalizeWhitespace($candidate);
        $siteTitle = $this->normalizeWhitespace($siteTitle);
        if ($candidate === '' || $siteTitle === '') {
            return $candidate;
        }

        $pattern = '/^' . \preg_quote($siteTitle, '/') . '[\s\-_:：|,，]*/u';
        $candidate = (string)\preg_replace($pattern, '', $candidate);

        return $this->normalizeWhitespace($candidate);
    }

    private function deriveTitleFromDomain(string $targetDomain): string
    {
        $domain = \preg_replace('/^https?:\/\//i', '', \trim($targetDomain));
        $domain = \explode('/', (string)$domain)[0] ?? '';
        $domain = \explode('.', (string)$domain)[0] ?? '';
        $domain = \str_replace(['-', '_'], ' ', (string)$domain);
        $domain = $this->normalizeWhitespace($domain);

        return $domain !== '' ? $this->clipSiteTitle(\ucwords($domain)) : '';
    }

    private function clipSiteTitle(string $value): string
    {
        return $this->clipText($value, $this->containsCjk($value) ? 24 : 42);
    }

    private function containsCjk(string $value): bool
    {
        return \preg_match('/[\x{4E00}-\x{9FFF}]/u', $value) === 1;
    }

    private function isPlaceholderSiteTitle(string $value): bool
    {
        $normalized = \strtolower($this->normalizeWhitespace($value));

        return \in_array($normalized, ['ai site', 'pagebuilder ai draft', 'page builder ai draft'], true);
    }

    private function normalizeWhitespace(string $value): string
    {
        $value = (string)\preg_replace('/\s+/u', ' ', \trim($value));

        return \trim($value);
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

            return \rtrim(\mb_substr($value, 0, $limit - 3)) . '...';
        }

        if (\strlen($value) <= $limit) {
            return $value;
        }

        return \rtrim(\substr($value, 0, $limit - 3)) . '...';
    }

    private function pickString(mixed ...$values): string
    {
        foreach ($values as $value) {
            if (!\is_scalar($value)) {
                continue;
            }
            $candidate = \trim((string)$value);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
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

    private function buildKeywordString(string $siteTitle, string $targetDomain, string $briefDescription): string
    {
        $keywords = [];
        foreach ([$siteTitle, $targetDomain, $briefDescription] as $source) {
            $parts = \preg_split('/[\s,，。；;|]+/u', \strtolower($source), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($parts as $part) {
                $part = \trim($part);
                if ($part === '' || \in_array($part, $keywords, true)) {
                    continue;
                }
                $keywords[] = $part;
                if (\count($keywords) >= 8) {
                    break 2;
                }
            }
        }

        return \implode(', ', $keywords);
    }

    private function isGeneratedPlaceholderImage(string $value): bool
    {
        $prefix = 'data:image/svg+xml;base64,';
        if (!\str_starts_with($value, $prefix)) {
            return false;
        }

        $decoded = \base64_decode(\substr($value, \strlen($prefix)), true);
        if (!\is_string($decoded) || $decoded === '') {
            return false;
        }

        $normalized = \strtolower((string)\preg_replace('/\s+/', ' ', $decoded));

        return \str_contains($normalized, '<rect width="100%" height="100%" rx="10"')
            && \str_contains($normalized, 'dominant-baseline="central"')
            && \str_contains($normalized, 'font-family="arial, sans-serif"')
            && (
                \str_contains($normalized, 'fill="#0f172a"')
                || \str_contains($normalized, 'fill="#2563eb"')
            );
    }

    private function buildFallbackLogoDataUri(string $siteTitle, string $siteTagline, string $briefDescription): string
    {
        [$primary, $secondary, $accent] = $this->deriveBrandPalette($siteTitle, $briefDescription);
        $title = $this->pickString($siteTitle, $this->deriveSiteTitleFromBrief($briefDescription, ''));
        $title = $this->clipText($title, 18);
        $tagline = $this->clipText($this->pickString($siteTagline, $briefDescription), 28);
        $mark = $this->resolveBrandMark($title, $briefDescription);
        $safeTitle = \htmlspecialchars($title, \ENT_QUOTES, 'UTF-8');
        $safeTagline = \htmlspecialchars($tagline, \ENT_QUOTES, 'UTF-8');
        $safeMark = \htmlspecialchars($mark, \ENT_QUOTES, 'UTF-8');

        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="160" height="48" viewBox="0 0 160 48">
  <defs>
    <linearGradient id="brandLogoGradient" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="{$primary}"/>
      <stop offset="100%" stop-color="{$secondary}"/>
    </linearGradient>
  </defs>
  <rect x="0" y="0" width="160" height="48" rx="14" fill="#ffffff"/>
  <rect x="6" y="6" width="36" height="36" rx="12" fill="url(#brandLogoGradient)"/>
  <circle cx="24" cy="24" r="10" fill="rgba(255,255,255,0.18)"/>
  <text x="24" y="26" dominant-baseline="middle" text-anchor="middle" font-family="Arial, sans-serif" font-size="14" font-weight="700" fill="#ffffff">{$safeMark}</text>
  <text x="52" y="20" font-family="Arial, sans-serif" font-size="13" font-weight="700" fill="#0f172a">{$safeTitle}</text>
  <text x="52" y="34" font-family="Arial, sans-serif" font-size="9" fill="#64748b">{$safeTagline}</text>
  <rect x="52" y="24" width="18" height="2" rx="1" fill="{$accent}"/>
</svg>
SVG;

        return 'data:image/svg+xml;base64,' . \base64_encode($svg);
    }

    private function normalizeGeneratedSvgAsset(string $value): string
    {
        $value = \trim($value);
        if ($value === '') {
            return '';
        }

        if (\str_starts_with($value, 'data:image/svg+xml;base64,')) {
            return $value;
        }

        if (\str_contains($value, '<svg') && \str_contains($value, '</svg>')) {
            return 'data:image/svg+xml;base64,' . \base64_encode($value);
        }

        return '';
    }

    private function buildFallbackIconDataUri(string $siteTitle, string $siteTagline, string $briefDescription): string
    {
        [$primary, $secondary, $accent] = $this->deriveBrandPalette($siteTitle, $briefDescription);
        $mark = $this->resolveBrandMark($siteTitle, $briefDescription);
        $safeMark = \htmlspecialchars($mark, \ENT_QUOTES, 'UTF-8');

        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">
  <defs>
    <linearGradient id="brandIconGradient" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="{$primary}"/>
      <stop offset="100%" stop-color="{$secondary}"/>
    </linearGradient>
  </defs>
  <rect x="4" y="4" width="56" height="56" rx="18" fill="url(#brandIconGradient)"/>
  <circle cx="32" cy="32" r="16" fill="rgba(255,255,255,0.14)"/>
  <path d="M18 46 L46 18" stroke="{$accent}" stroke-width="3" stroke-linecap="round" opacity="0.55"/>
  <text x="32" y="35" dominant-baseline="middle" text-anchor="middle" font-family="Arial, sans-serif" font-size="20" font-weight="700" fill="#ffffff">{$safeMark}</text>
</svg>
SVG;

        return 'data:image/svg+xml;base64,' . \base64_encode($svg);
    }

    /**
     * @return array{string,string,string}
     */
    private function deriveBrandPalette(string $siteTitle, string $briefDescription): array
    {
        $hash = \md5(\strtolower($siteTitle . '|' . $briefDescription));
        $palettes = [
            ['#0f766e', '#14b8a6', '#f59e0b'],
            ['#1d4ed8', '#60a5fa', '#f97316'],
            ['#374151', '#111827', '#22c55e'],
            ['#7c3aed', '#c084fc', '#f43f5e'],
            ['#be123c', '#fb7185', '#facc15'],
            ['#0f172a', '#334155', '#38bdf8'],
        ];
        $index = \hexdec(\substr($hash, 0, 2)) % \count($palettes);

        return $palettes[$index];
    }

    private function resolveBrandMark(string $siteTitle, string $briefDescription): string
    {
        $letters = '';
        $source = $this->pickString($siteTitle, $briefDescription);
        $parts = \preg_split('/[^a-z0-9\x{4E00}-\x{9FFF}]+/iu', $source, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($parts as $part) {
            $letters .= $this->firstCharacter($part);
            if ($this->textLength($letters) >= 2) {
                break;
            }
        }

        if ($letters !== '') {
            return $this->textLength($letters) > 2 ? $this->textSubstr($letters, 0, 2) : $letters;
        }

        return 'A';
    }

    private function firstCharacter(string $value): string
    {
        $value = \trim($value);
        if ($value === '') {
            return '';
        }

        return $this->textSubstr($value, 0, 1);
    }

    private function textLength(string $value): int
    {
        if (\function_exists('mb_strlen')) {
            return \mb_strlen($value);
        }

        return \strlen($value);
    }

    private function textSubstr(string $value, int $start, int $length): string
    {
        if (\function_exists('mb_substr')) {
            return (string)\mb_substr($value, $start, $length);
        }

        return \substr($value, $start, $length);
    }
}
