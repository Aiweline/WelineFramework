<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use Weline\Framework\Manager\ObjectManager;

class AiSiteProfileGenerationService
{
    private const PROFILE_VERSION = 6;

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
        $contentLocale = $this->resolveContentLocale($scope, $existing, $sourceBrief);
        if ($contentLocale !== '') {
            $locales = $this->prependLocaleToList($locales, $contentLocale);
        }
        $targetDomain = $this->resolveTargetDomain($scope, $existing, $manualFlags);

        $titleLocked = $this->hasManualOverride($scope, 'site_title', $manualFlags);
        $taglineLocked = $this->hasManualOverride($scope, 'site_tagline', $manualFlags);
        $briefLocked = $this->hasManualOverride($scope, 'brief_description', $manualFlags);

        $scopeTitle = $this->normalizeMeaningfulTitle($scope['site_title'] ?? null);
        $lockedTitle = $titleLocked
            ? $this->normalizeMeaningfulTitle($scope['site_title'] ?? null)
            : ($scopeTitle !== '' ? $scopeTitle : $this->resolveLegacyLockedTitle($existing, $hasManualMap));
        $lockedTagline = $taglineLocked
            ? $this->normalizeProfileTagline($this->readScopeString($scope, 'site_tagline'), $lockedTitle)
            : $this->resolveLegacyLockedTagline($existing, $hasManualMap);
        $lockedBrief = $briefLocked ? $this->normalizeProfileBriefDescription($this->readScopeString($scope, 'brief_description')) : '';

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

        $siteTitle = $this->normalizeMeaningfulTitle($siteTitle) ?: $this->deriveSiteTitleFromBrief($sourceBrief, $targetDomain);

        $siteTagline = ($taglineLocked || $lockedTagline !== '')
            ? $lockedTagline
            : $this->pickString(
                $generated['site_tagline'] ?? null,
                $this->deriveSiteTaglineFromBrief($sourceBrief, $siteTitle)
            );
        $siteTagline = $this->normalizeProfileTagline($siteTagline, $siteTitle);

        $briefDescription = $briefLocked
            ? $lockedBrief
            : $this->pickString(
                $generated['brief_description'] ?? null,
                $sourceBrief
            );
        $briefDescription = $this->normalizeProfileBriefDescription($briefDescription);

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

        $logo = $this->normalizeProfileAsset($logo);
        if ($logo === '') {
            $logo = $this->buildFallbackLogoDataUri($siteTitle, $siteTagline, $sourceBrief);
        }
        $icon = $this->normalizeProfileAsset($icon);
        if ($icon === '') {
            $icon = $this->buildFallbackIconDataUri($siteTitle, $siteTagline, $sourceBrief);
        }

        $seoInput = $this->resolveSeoInput($scope, $existing);
        $metaTitle = \trim((string)($seoInput['meta_title'] ?? ''));
        if ($metaTitle === '') {
            $metaTitle = $this->pickString(
                $generated['meta_title'] ?? null,
                $siteTagline !== '' && $siteTitle !== '' ? ($siteTitle . ' | ' . $siteTagline) : $siteTitle
            );
        }
        $metaTitle = $this->normalizeWhitespace($this->stripProfileFieldLabelPrefix($metaTitle, \array_merge(
            $this->profileTitleLabelPatterns(),
            $this->profileTaglineLabelPatterns()
        )));
        $metaTitle = $this->stripInternalProfileTokens($metaTitle);
        if ($metaTitle === '') {
            $metaTitle = $siteTitle;
        }

        $metaDescription = \trim((string)($seoInput['meta_description'] ?? ''));
        if ($metaDescription === '') {
            $metaDescription = $this->pickString(
                $generated['meta_description'] ?? null,
                $this->clipText($briefDescription !== '' ? $briefDescription : ($siteTagline !== '' ? $siteTagline : $siteTitle), 160)
            );
        }
        $metaDescription = $this->clipText($this->normalizeProfileBriefDescription($metaDescription), 160);

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
            'content_locale' => $contentLocale,
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
            return $this->normalizeProfileBriefDescription($this->readScopeString($scope, 'brief_description'));
        }

        $existingMeta = \is_array($existing['_ai_profile'] ?? null) ? $existing['_ai_profile'] : [];

        return $this->normalizeProfileBriefDescription($this->pickString(
            $this->resolveBuildPlanSourceBrief($scope),
            $scope['brief_description'] ?? null,
            $scope['user_description'] ?? null,
            $existingMeta['source_brief'] ?? null,
            $existing['brief_description'] ?? null
        ));
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

        $localHost = $this->resolveLocalPreviewHost($scope);
        if ($localHost !== '') {
            return $localHost;
        }

        $explicit = $this->pickString($scope['target_domain'] ?? null, $scope['selected_domain'] ?? null);
        if ($explicit !== '') {
            return \strtolower($explicit);
        }

        return \strtolower($this->pickString($existing['target_domain'] ?? null));
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function resolveLocalPreviewHost(array $scope): string
    {
        foreach (['preview_full_url', 'visual_preview_url', 'visual_edit_url', 'preview_url'] as $key) {
            $url = \trim((string)($scope[$key] ?? ''));
            if ($url === '') {
                continue;
            }
            $host = \parse_url($url, \PHP_URL_HOST);
            $host = \is_string($host) ? \strtolower(\trim($host)) : '';
            if ($this->isLocalPreviewHost($host)) {
                return $host;
            }
        }

        return '';
    }

    private function isLocalPreviewHost(string $host): bool
    {
        return $host !== ''
            && (\str_ends_with($host, '.weline.test') || \str_ends_with($host, '.local.test'));
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
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $existing
     */
    private function resolveContentLocale(array $scope, array $existing, string $sourceBrief): string
    {
        $explicit = $this->pickString(
            $scope['ai_content_locale'] ?? null,
            $scope['default_locale'] ?? null,
            $existing['default_locale'] ?? null,
            $scope['default_language'] ?? null,
            $existing['default_language'] ?? null,
            $scope['content_locale'] ?? null,
            $existing['content_locale'] ?? null
        );
        if ($explicit !== '') {
            return $explicit;
        }

        return $this->inferContentLocaleFromText($sourceBrief . "\n" . $this->pickString(
            $scope['user_description'] ?? null,
            $scope['site_description'] ?? null,
            $scope['requirements']['expanded_brief'] ?? null
        ));
    }

    private function inferContentLocaleFromText(string $text): string
    {
        $text = \trim($text);
        if ($text === '') {
            return '';
        }

        if (\preg_match('/\bhi(?:[_-]IN)?\b|Hindi|Hindustani|Devanagari|印地语|印地文|印度语|हिन्दी|हिंदी/iu', $text) === 1) {
            return 'hi_IN';
        }
        if (\preg_match('/\bth(?:[_-]TH)?\b|Thai|泰语|泰文|ภาษาไทย/iu', $text) === 1) {
            return 'th_TH';
        }
        if (\preg_match('/\bzh(?:[_-](?:Hans|CN|SG))?\b|简体中文|中文|Chinese/iu', $text) === 1) {
            return 'zh_Hans_CN';
        }
        if (\preg_match('/\bru(?:[_-]RU)?\b|Russian|俄语|русский/iu', $text) === 1) {
            return 'ru_RU';
        }

        return '';
    }

    /**
     * @param list<string> $locales
     * @return list<string>
     */
    private function prependLocaleToList(array $locales, string $locale): array
    {
        $locale = \trim($locale);
        $result = [];
        if ($locale !== '') {
            $result[] = $locale;
        }
        foreach ($locales as $item) {
            $candidate = \trim((string)$item);
            if ($candidate === '' || \in_array($candidate, $result, true)) {
                continue;
            }
            $result[] = $candidate;
        }

        return $result;
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

        return $this->normalizeProfileTagline($this->pickString($existing['site_tagline'] ?? null), $this->pickString($existing['site_title'] ?? null));
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $existing
     */
    private function resolveLockedAsset(array $scope, array $existing, string $key, bool $hasManualMap): string
    {
        $scopeValue = $this->normalizeProfileAsset($this->pickString($scope[$key] ?? null));
        if ($scopeValue !== '' && !$this->isGeneratedPlaceholderImage($scopeValue)) {
            return $scopeValue;
        }

        if ($this->hasManagedProfileMeta($existing)) {
            return '';
        }

        if ($hasManualMap) {
            return '';
        }

        $existingValue = $this->normalizeProfileAsset($this->pickString($existing[$key] ?? null));
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
            $scopeValue = $this->normalizeProfileAsset($this->pickString($scope[$key] ?? null));
            if ($scopeValue !== '' && !$this->isGeneratedPlaceholderImage($scopeValue)) {
                return $scopeValue;
            }
        }

        if ($this->hasManagedProfileMeta($existing) || $hasManualMap) {
            return '';
        }

        foreach (['icon', 'favicon'] as $key) {
            $existingValue = $this->normalizeProfileAsset($this->pickString($existing[$key] ?? null));
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
        if ($this->hasManagedProfileMeta($existing)) {
            return [];
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
        return !empty($manualFlags[$key])
            && \array_key_exists($key, $scope);
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

    /**
     * @param array<string, mixed> $scope
     */
    private function resolveBuildPlanSourceBrief(array $scope): string
    {
        $contract = \is_array($scope['build_plan_v2'] ?? null) ? $scope['build_plan_v2'] : [];
        $source = \is_array($contract['source_of_truth'] ?? null) ? $contract['source_of_truth'] : [];
        $requirements = \is_array($source['user_requirements'] ?? null) ? $source['user_requirements'] : [];
        $siteBrief = \is_array($contract['site_brief'] ?? null) ? $contract['site_brief'] : [];
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $requirementExpansion = \is_array($planJson['requirement_expansion'] ?? null) ? $planJson['requirement_expansion'] : [];
        $siteStrategy = \is_array($planJson['site_strategy'] ?? null) ? $planJson['site_strategy'] : [];

        return $this->pickString(
            $requirements['expanded_brief'] ?? null,
            $requirements['planning_summary'] ?? null,
            $requirements['site_goal'] ?? null,
            $requirements['content_direction'] ?? null,
            $siteBrief['summary'] ?? null,
            $requirementExpansion['expanded_brief'] ?? null,
            $requirementExpansion['planning_summary'] ?? null,
            $requirementExpansion['site_goal'] ?? null,
            $siteStrategy['core_goal'] ?? null,
            $siteStrategy['content_strategy'] ?? null
        );
    }

    private function normalizeProfileTagline(string $value, string $siteTitle): string
    {
        $value = $this->normalizeWhitespace($value);
        if ($value === '') {
            return '';
        }
        $extracted = $this->extractLabeledProfileSegment($value, $this->profileTaglineLabelPatterns());
        if ($extracted !== '') {
            $value = $extracted;
        } else {
            $value = $this->stripProfileFieldLabelPrefix($value, $this->profileTaglineLabelPatterns());
        }
        $value = $this->stripRepeatedTitlePrefix($value, $siteTitle);

        return $this->clipText($value, 96);
    }

    private function normalizeProfileBriefDescription(string $value): string
    {
        $value = $this->normalizeWhitespace($value);
        if ($value === '') {
            return '';
        }
        $value = (string)\preg_replace(
            '/(?:^|[\s\x{3002};\x{FF1B}])(?:\x{9875}\x{9762}\x{4EE3}\x{7801}|page\s*type\s*codes?)\s*[:\x{FF1A}][^\x{3002};\x{FF1B}]+/iu',
            ' ',
            $value
        );
        $value = (string)\preg_replace(
            '/(?:\x{7AD9}\x{70B9}\x{540D}\x{79F0}|\x{7F51}\x{7AD9}\x{540D}\x{79F0}|\x{54C1}\x{724C}\x{540D}|site\s*(?:title|name)|brand\s*name|\x{4E00}\x{53E5}\x{8BDD}\x{5B9A}\x{4F4D}|positioning|\x{89C6}\x{89C9}\x{5951}\x{7EA6}|visual\s*contract|\x{8F6C}\x{5316}\x{76EE}\x{6807}|conversion\s*goal|\x{5185}\x{5BB9}\x{8BED}\x{8A00}|content\s*language)\s*[:\x{FF1A}]\s*/iu',
            '',
            $value
        );

        return $this->normalizeWhitespace($value);
    }

    /**
     * @param list<string> $labelPatterns
     */
    private function extractLabeledProfileSegment(string $value, array $labelPatterns): string
    {
        $value = $this->normalizeWhitespace($value);
        if ($value === '') {
            return '';
        }
        $label = '(?:' . \implode('|', $labelPatterns) . ')';
        $pattern = '/(?:^|[\s,;|\x{3002}\x{FF1B}\x{FF0C}])' . $label . '\s*[:\x{FF1A}]\s*([^,.;|\x{3002}\x{FF1B}\x{FF0C}\n\r]{1,120})/iu';
        if (\preg_match($pattern, $value, $matches) !== 1) {
            return '';
        }

        return $this->normalizeWhitespace(\trim((string)($matches[1] ?? ''), " \t\n\r\0\x0B-_:|"));
    }

    /**
     * @param list<string> $labelPatterns
     */
    private function stripProfileFieldLabelPrefix(string $value, array $labelPatterns): string
    {
        $value = $this->normalizeWhitespace($value);
        if ($value === '') {
            return '';
        }
        $label = '(?:' . \implode('|', $labelPatterns) . ')';
        $value = (string)\preg_replace('/^\s*' . $label . '\s*[:\x{FF1A}]\s*/iu', '', $value);

        return $this->normalizeWhitespace($value);
    }

    /**
     * @return list<string>
     */
    private function profileTitleLabelPatterns(): array
    {
        return [
            '\x{7AD9}\x{70B9}\x{540D}\x{79F0}',
            '\x{7F51}\x{7AD9}\x{540D}\x{79F0}',
            '\x{7AD9}\x{70B9}\x{6807}\x{9898}',
            '\x{7F51}\x{7AD9}\x{6807}\x{9898}',
            '\x{54C1}\x{724C}\x{540D}',
            'site\s*(?:title|name)',
            'brand\s*name',
        ];
    }

    /**
     * @return list<string>
     */
    private function profileTaglineLabelPatterns(): array
    {
        return [
            '\x{4E00}\x{53E5}\x{8BDD}\x{5B9A}\x{4F4D}',
            '\x{7AD9}\x{70B9}\x{5B9A}\x{4F4D}',
            '\x{54C1}\x{724C}\x{5B9A}\x{4F4D}',
            'positioning',
            'tagline',
            'slogan',
        ];
    }

    private function normalizeMeaningfulTitle(mixed $value): string
    {
        if (!\is_scalar($value)) {
            return '';
        }

        $title = $this->normalizeWhitespace((string)$value);
        $extracted = $this->extractLabeledProfileSegment($title, $this->profileTitleLabelPatterns());
        if ($extracted !== '') {
            $title = $extracted;
        } else {
            $title = $this->stripProfileFieldLabelPrefix($title, $this->profileTitleLabelPatterns());
        }
        $title = $this->stripInternalProfileTokens($title);
        if ($title === '' || $this->isPlaceholderSiteTitle($title)) {
            return '';
        }

        return $this->clipSiteTitle($title);
    }

    private function stripInternalProfileTokens(string $value): string
    {
        $value = $this->normalizeWhitespace($value);
        if ($value === '') {
            return '';
        }
        $value = (string)\preg_replace(
            '/\b(?:website\s*profile|websiteprofile|site\s*profile|target[_\s-]*domain|scope[_\s-]*json|profile[_\s-]*json)\b/iu',
            ' ',
            $value
        );
        $value = (string)\preg_replace('/\s*[-:|]\s*$/u', '', $value);

        return $this->normalizeWhitespace($value);
    }

    private function deriveSiteTitleFromBrief(string $briefDescription, string $targetDomain): string
    {
        $explicitTitle = $this->extractExplicitSiteTitleFromBrief($briefDescription);
        if ($explicitTitle !== '' && !$this->isPlaceholderSiteTitle($explicitTitle)) {
            return $this->clipSiteTitle($explicitTitle);
        }

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

    private function extractExplicitSiteTitleFromBrief(string $briefDescription): string
    {
        $briefDescription = $this->normalizeWhitespace($briefDescription);
        if ($briefDescription === '') {
            return '';
        }
        $explicit = $this->extractLabeledProfileSegment($briefDescription, $this->profileTitleLabelPatterns());
        if ($explicit !== '' && !$this->looksLikeSentenceTitle($explicit)) {
            return $explicit;
        }

        $patterns = [
            '/(?:网站标题|站点标题|品牌名|站点名|网站名|标题)\s*(?:是|为|叫|[:：])\s*([A-Za-z][A-Za-z0-9 .&\'-]{1,42})/u',
            '/^\s*([A-Za-z][A-Za-z0-9 .&\'-]{1,42})\s*(?:是|为|面向|，|,|\|)/u',
        ];
        foreach ($patterns as $pattern) {
            if (\preg_match($pattern, $briefDescription, $matches) !== 1) {
                continue;
            }
            $title = \trim((string)($matches[1] ?? ''), " \t\n\r\0\x0B-_:：|,，.");
            if ($title !== '' && !$this->looksLikeSentenceTitle($title)) {
                return $title;
            }
        }

        return '';
    }

    private function looksLikeSentenceTitle(string $title): bool
    {
        if ($this->containsCjk($title)) {
            return true;
        }

        $words = \preg_split('/\s+/u', \trim($title), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        return \count($words) > 4;
    }

    private function deriveSiteTaglineFromBrief(string $briefDescription, string $siteTitle): string
    {
        $explicit = $this->extractLabeledProfileSegment($briefDescription, $this->profileTaglineLabelPatterns());
        if ($explicit !== '') {
            return $this->normalizeProfileTagline($explicit, $siteTitle);
        }

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

        if (
            \str_contains($normalized, '<rect width="100%" height="100%" rx="10"')
            && \str_contains($normalized, 'dominant-baseline="central"')
            && \str_contains($normalized, 'font-family="arial, sans-serif"')
            && (
                \str_contains($normalized, 'fill="#0f172a"')
                || \str_contains($normalized, 'fill="#2563eb"')
            )
        ) {
            return true;
        }

        return \str_contains($normalized, 'id="brandlogogradient"')
            || \str_contains($normalized, 'id="brandicongradient"');
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
            $decoded = \base64_decode(\substr($value, 26), true);
            return \is_string($decoded) && $this->isSafeGeneratedSvgMarkup($decoded) ? $value : '';
        }

        if ($this->isSafeGeneratedSvgMarkup($value)) {
            return 'data:image/svg+xml;base64,' . \base64_encode($value);
        }

        return '';
    }

    private function isSafeGeneratedSvgMarkup(string $value): bool
    {
        if (!\str_contains($value, '<svg') || !\str_contains($value, '</svg>')) {
            return false;
        }

        $normalized = \strtolower($value);
        foreach ([
            '<script',
            '<foreignobject',
            '<iframe',
            '<object',
            '<embed',
            '<!doctype',
            '<!entity',
            'javascript:',
            'onload=',
            'onclick=',
            '<image',
            'xlink:href=',
            'href="http',
            "href='http",
            '<animate',
            '<set ',
        ] as $pattern) {
            if (\str_contains($normalized, $pattern)) {
                return false;
            }
        }

        if (\strlen($value) > 12000) {
            return false;
        }

        if (!\class_exists(\DOMDocument::class)) {
            return !$this->svgContainsGeneratedIdentityBackground($value, 0, 0);
        }

        $previousUseInternalErrors = \libxml_use_internal_errors(true);
        $document = new \DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadXML($value, \LIBXML_NONET | \LIBXML_NOWARNING | \LIBXML_NOERROR);
        $errors = \libxml_get_errors();
        \libxml_clear_errors();
        \libxml_use_internal_errors($previousUseInternalErrors);

        if (!$loaded || $errors !== []) {
            return false;
        }

        $root = $document->documentElement;
        if (!$root instanceof \DOMElement) {
            return false;
        }

        if (\strtolower($root->localName) !== 'svg') {
            return false;
        }

        $namespace = \trim((string)$root->namespaceURI);
        if ($namespace !== '' && $namespace !== 'http://www.w3.org/2000/svg') {
            return false;
        }

        return !$this->svgContainsGeneratedIdentityBackground($value, 0, 0);
    }

    private function normalizeProfileAsset(string $value): string
    {
        $value = \trim($value);
        if ($value === '') {
            return '';
        }

        if (
            \str_starts_with($value, 'data:image/svg+xml;base64,')
            || \str_contains($value, '<svg')
            || \str_contains($value, '</svg>')
        ) {
            return $this->normalizeGeneratedSvgAsset($value);
        }

        return $value;
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
  <circle cx="32" cy="32" r="24" fill="url(#brandIconGradient)" opacity="0.18"/>
  <path d="M18 46 L46 18" stroke="{$accent}" stroke-width="5" stroke-linecap="round" opacity="0.72"/>
  <circle cx="32" cy="32" r="19" fill="none" stroke="url(#brandIconGradient)" stroke-width="4"/>
  <text x="32" y="36" dominant-baseline="middle" text-anchor="middle" font-family="Arial, sans-serif" font-size="24" font-weight="800" fill="{$primary}">{$safeMark}</text>
</svg>
SVG;

        return 'data:image/svg+xml;base64,' . \base64_encode($svg);
    }

    private function svgContainsGeneratedIdentityBackground(string $value, int $expectedWidth, int $expectedHeight): bool
    {
        if (\preg_match('/viewBox\s*=\s*["\']\s*0\s+0\s+([0-9.]+)\s+([0-9.]+)\s*["\']/i', $value, $viewBoxMatches) === 1) {
            $expectedWidth = $expectedWidth > 0 ? $expectedWidth : (int)\round((float)$viewBoxMatches[1]);
            $expectedHeight = $expectedHeight > 0 ? $expectedHeight : (int)\round((float)$viewBoxMatches[2]);
        }

        if ($expectedWidth <= 0 || $expectedHeight <= 0) {
            return false;
        }

        if (\preg_match_all('/<rect\b([^>]*)>/i', $value, $rectMatches) !== false) {
            foreach ($rectMatches[1] as $rawAttrs) {
                $attrs = $this->parseSvgAttributes((string)$rawAttrs);
                $width = $this->resolveSvgLength($attrs['width'] ?? '', $expectedWidth);
                $height = $this->resolveSvgLength($attrs['height'] ?? '', $expectedHeight);
                $x = $this->resolveSvgLength($attrs['x'] ?? '0', $expectedWidth);
                $y = $this->resolveSvgLength($attrs['y'] ?? '0', $expectedHeight);
                if ($width <= 0.0 || $height <= 0.0) {
                    continue;
                }

                $coversCanvas = $x <= ($expectedWidth * 0.05)
                    && $y <= ($expectedHeight * 0.05)
                    && ($x + $width) >= ($expectedWidth * 0.95)
                    && ($y + $height) >= ($expectedHeight * 0.95);
                if ($coversCanvas) {
                    return true;
                }

                $isIconTile = $expectedWidth <= 80
                    && $expectedHeight <= 80
                    && $x <= 8.0
                    && $y <= 8.0
                    && $width >= ($expectedWidth * 0.72)
                    && $height >= ($expectedHeight * 0.72);
                if ($isIconTile) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string,string>
     */
    private function parseSvgAttributes(string $rawAttrs): array
    {
        $attrs = [];
        if (\preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*(["\'])(.*?)\2/s', $rawAttrs, $matches, \PREG_SET_ORDER) === false) {
            return $attrs;
        }

        foreach ($matches as $match) {
            $attrs[\strtolower((string)$match[1])] = (string)$match[3];
        }

        return $attrs;
    }

    private function resolveSvgLength(string $value, int $basis): float
    {
        $value = \trim($value);
        if ($value === '') {
            return 0.0;
        }

        if (\str_ends_with($value, '%')) {
            return ((float)\rtrim($value, '%')) * $basis / 100;
        }

        if (\preg_match('/^-?[0-9]+(?:\.[0-9]+)?/', $value, $matches) !== 1) {
            return 0.0;
        }

        return (float)$matches[0];
    }

    /**
     * @return array{string,string,string}
     */
    private function deriveBrandPalette(string $siteTitle, string $briefDescription): array
    {
        $themeText = \strtolower($siteTitle . ' ' . $briefDescription);
        if (\preg_match('/gold|golden|luxury|royal|palace|jewel|casino|card|poker|rummy|金色|黄金|奢华|高端|宫殿|宝石|棋牌|扑克|拉米|印度|香料/u', $themeText) === 1) {
            return ['#8f3d00', '#f59e0b', '#facc15'];
        }
        if (\preg_match('/eco|green|organic|nature|garden|环保|绿色|自然|花园|植物/u', $themeText) === 1) {
            return ['#166534', '#22c55e', '#facc15'];
        }
        if (\preg_match('/tech|software|data|ai|cloud|security|科技|软件|数据|智能|云|安全/u', $themeText) === 1) {
            return ['#1d4ed8', '#38bdf8', '#f97316'];
        }

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
