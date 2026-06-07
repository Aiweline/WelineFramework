<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use Weline\Framework\Manager\ObjectManager;

class AiSiteProfileGenerationService
{
    private const PROFILE_VERSION = 7;

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
        $internalVisibleCopyTerms = $this->buildInternalVisibleCopyTerms($scope);

        $defaultLocale = $this->pickString(
            $scope['default_locale'] ?? null,
            $scope['default_language'] ?? null,
            $existing['default_locale'] ?? null,
            'en_US'
        );
        $locales = $this->resolveLocales($scope, $existing, $defaultLocale);
        $sourceBrief = $this->resolveSourceBrief($scope, $existing, $manualFlags, $internalVisibleCopyTerms);
        $contentLocale = $this->resolveContentLocale($scope, $existing, $sourceBrief);
        if ($contentLocale !== '') {
            $locales = $this->prependLocaleToList($locales, $contentLocale);
        }
        $targetDomain = $this->resolveTargetDomain($scope, $existing, $manualFlags);

        $titleLocked = $this->hasManualOverride($scope, 'site_title', $manualFlags);
        $taglineLocked = $this->hasManualOverride($scope, 'site_tagline', $manualFlags);
        $briefLocked = $this->hasManualOverride($scope, 'brief_description', $manualFlags);
        $planJsonTitle = $this->resolvePlanJsonSiteTitle($scope, $internalVisibleCopyTerms);
        $planJsonTagline = $this->resolvePlanJsonSiteTagline($scope, $internalVisibleCopyTerms);

        $scopeTitle = $this->normalizeMeaningfulTitle($scope['site_title'] ?? null, $internalVisibleCopyTerms, false);
        $scopeTitleCanLock = !\array_key_exists('site_title', $manualFlags);
        $lockedTitle = $titleLocked
            ? $this->normalizeMeaningfulTitle($scope['site_title'] ?? null, $internalVisibleCopyTerms, false)
            : ($scopeTitleCanLock && $scopeTitle !== ''
                ? $scopeTitle
                : $this->resolveStoredLockedTitle($existing, $hasManualMap, $internalVisibleCopyTerms));
        $lockedTagline = $taglineLocked
            ? $this->normalizeProfileTagline($this->readScopeString($scope, 'site_tagline'), $lockedTitle, $internalVisibleCopyTerms)
            : $this->resolveStoredLockedTagline($existing, $hasManualMap, $internalVisibleCopyTerms);
        $lockedBrief = $briefLocked ? $this->normalizeProfileBriefDescription($this->readScopeString($scope, 'brief_description'), $internalVisibleCopyTerms) : '';

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
            $lockedIcon,
            $internalVisibleCopyTerms
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
                $internalVisibleCopyTerms,
                $allowAi
            );

        $siteTitle = ($titleLocked || $lockedTitle !== '')
            ? $lockedTitle
            : $this->pickString(
                $planJsonTitle,
                $generated['site_title'] ?? null,
                $this->deriveSiteTitleFromBrief($sourceBrief, $targetDomain, $internalVisibleCopyTerms)
            );

        $siteTitle = $this->normalizeMeaningfulTitle(
            $siteTitle,
            $internalVisibleCopyTerms,
            !($titleLocked || $lockedTitle !== '')
        )
            ?: $this->deriveSiteTitleFromBrief($sourceBrief, $targetDomain, $internalVisibleCopyTerms);

        $siteTagline = ($taglineLocked || $lockedTagline !== '')
            ? $lockedTagline
            : $this->pickString(
                $planJsonTagline,
                $generated['site_tagline'] ?? null,
                $this->deriveSiteTaglineFromBrief($sourceBrief, $siteTitle, $internalVisibleCopyTerms)
            );
        $siteTagline = $this->normalizeProfileTagline($siteTagline, $siteTitle, $internalVisibleCopyTerms);

        $briefDescription = $briefLocked
            ? $lockedBrief
            : $this->pickString(
                $generated['brief_description'] ?? null,
                $sourceBrief
            );
        $briefDescription = $this->normalizeProfileBriefDescription($briefDescription, $internalVisibleCopyTerms);

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
        $metaTitle = $this->stripInternalProfileTokens($metaTitle, $internalVisibleCopyTerms);
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
        $metaDescription = $this->clipText($this->normalizeProfileBriefDescription($metaDescription, $internalVisibleCopyTerms), 160);

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
     * @param list<string> $internalVisibleCopyTerms
     */
    private function resolveSourceBrief(array $scope, array $existing, array $manualFlags, array $internalVisibleCopyTerms = []): string
    {
        if ($this->hasManualOverride($scope, 'brief_description', $manualFlags)) {
            return $this->normalizeProfileBriefDescription($this->readScopeString($scope, 'brief_description'), $internalVisibleCopyTerms);
        }

        $existingMeta = \is_array($existing['_ai_profile'] ?? null) ? $existing['_ai_profile'] : [];

        return $this->normalizeProfileBriefDescription($this->pickString(
            $this->resolvePlanJsonSourceBrief($scope),
            $scope['brief_description'] ?? null,
            $scope['user_description'] ?? null,
            $existingMeta['source_brief'] ?? null,
            $existing['brief_description'] ?? null
        ), $internalVisibleCopyTerms);
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

        $explicit = $this->pickString($scope['target_domain'] ?? null, $scope['selected_domain'] ?? null);
        if ($explicit !== '') {
            return \strtolower($explicit);
        }

        $localHost = $this->resolveLocalPreviewHost($scope);
        if ($localHost !== '') {
            return $localHost;
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
     * @param list<string> $internalVisibleCopyTerms
     */
    private function resolveStoredLockedTitle(array $existing, bool $hasManualMap, array $internalVisibleCopyTerms = []): string
    {
        if ($hasManualMap || $this->hasManagedProfileMeta($existing)) {
            return '';
        }

        return $this->normalizeMeaningfulTitle($existing['site_title'] ?? null, $internalVisibleCopyTerms);
    }

    /**
     * @param array<string, mixed> $existing
     * @param list<string> $internalVisibleCopyTerms
     */
    private function resolveStoredLockedTagline(array $existing, bool $hasManualMap, array $internalVisibleCopyTerms = []): string
    {
        if ($hasManualMap || $this->hasManagedProfileMeta($existing)) {
            return '';
        }

        return $this->normalizeProfileTagline(
            $this->pickString($existing['site_tagline'] ?? null),
            $this->pickString($existing['site_title'] ?? null),
            $internalVisibleCopyTerms
        );
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
        string $lockedIcon,
        array $internalVisibleCopyTerms = []
    ): string {
        $signatureSource = [
            'version' => self::PROFILE_VERSION,
            'source_brief' => $sourceBrief,
            'target_domain' => $targetDomain,
            'default_locale' => $defaultLocale,
            'internal_visible_copy_terms_hash' => $internalVisibleCopyTerms !== []
                ? \sha1(\implode("\n", $internalVisibleCopyTerms))
                : '',
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
        array $internalVisibleCopyTerms,
        bool $allowAi
    ): array {
        $fallbackTitle = $lockedTitle !== '' ? $lockedTitle : $this->deriveSiteTitleFromBrief($sourceBrief, $targetDomain, $internalVisibleCopyTerms);
        $fallbackTagline = $lockedTagline !== '' ? $lockedTagline : $this->deriveSiteTaglineFromBrief($sourceBrief, $fallbackTitle, $internalVisibleCopyTerms);
        $fallbackBrief = $lockedBrief !== '' ? $lockedBrief : $this->normalizeProfileBriefDescription($sourceBrief, $internalVisibleCopyTerms);

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
                    'forbidden_visible_terms' => $internalVisibleCopyTerms,
                ]);
            } catch (\Throwable) {
                $generated = [];
            }
        }

        $siteTitle = $this->normalizeMeaningfulTitle(
            $this->pickString($generated['site_title'] ?? null, $fallbackTitle),
            $internalVisibleCopyTerms
        ) ?: $fallbackTitle;
        $siteTagline = $this->normalizeProfileTagline(
            $this->pickString($generated['site_tagline'] ?? null, $fallbackTagline),
            $siteTitle,
            $internalVisibleCopyTerms
        ) ?: $fallbackTagline;
        $briefDescription = $this->normalizeProfileBriefDescription(
            $this->pickString($generated['brief_description'] ?? null, $fallbackBrief),
            $internalVisibleCopyTerms
        ) ?: $fallbackBrief;
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
            'meta_title' => $this->stripInternalProfileTokens(
                $this->pickString($generated['meta_title'] ?? null, $siteTagline !== '' ? ($siteTitle . ' | ' . $siteTagline) : $siteTitle),
                $internalVisibleCopyTerms
            ),
            'meta_description' => $this->clipText(
                $this->normalizeProfileBriefDescription(
                    $this->pickString($generated['meta_description'] ?? null, $this->clipText($briefDescription, 160)),
                    $internalVisibleCopyTerms
                ),
                160
            ),
            'meta_keywords' => $this->stripInternalProfileTokens(
                $this->pickString($generated['meta_keywords'] ?? null, $this->buildKeywordString($siteTitle, $targetDomain, $briefDescription)),
                $internalVisibleCopyTerms
            ),
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
    private function resolvePlanJsonSourceBrief(array $scope): string
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $source = \is_array($planJson['source_of_truth'] ?? null) ? $planJson['source_of_truth'] : [];
        $requirements = \is_array($source['user_requirements'] ?? null) ? $source['user_requirements'] : [];
        $siteBrief = \is_array($planJson['site_brief'] ?? null) ? $planJson['site_brief'] : [];
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

    /**
     * @param array<string, mixed> $scope
     * @param list<string> $internalVisibleCopyTerms
     */
    private function resolvePlanJsonSiteTitle(array $scope, array $internalVisibleCopyTerms = []): string
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $siteBrief = \is_array($planJson['site_brief'] ?? null) ? $planJson['site_brief'] : [];
        $sharedPrompt = \is_array($planJson['shared_prompt_context'] ?? null) ? $planJson['shared_prompt_context'] : [];
        $themeContext = \is_array($planJson['theme_context_snapshot'] ?? null) ? $planJson['theme_context_snapshot'] : [];
        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        $home = \is_array($pages['home_page'] ?? null) ? $pages['home_page'] : [];

        return $this->normalizeMeaningfulTitle($this->pickString(
            $planJson['site_title'] ?? null,
            $planJson['site_name'] ?? null,
            $sharedPrompt['site_display_name'] ?? null,
            $sharedPrompt['site_title'] ?? null,
            $themeContext['site_display_name'] ?? null,
            $themeContext['site_title'] ?? null,
            $siteBrief['site_title'] ?? null,
            $siteBrief['site_name'] ?? null,
            $siteBrief['title'] ?? null,
            $siteBrief['name'] ?? null,
            $home['meta_title'] ?? null,
            $home['page_title'] ?? null,
            $home['title'] ?? null,
            $home['name'] ?? null
        ), $internalVisibleCopyTerms);
    }

    /**
     * @param array<string, mixed> $scope
     * @param list<string> $internalVisibleCopyTerms
     */
    private function resolvePlanJsonSiteTagline(array $scope, array $internalVisibleCopyTerms = []): string
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $siteBrief = \is_array($planJson['site_brief'] ?? null) ? $planJson['site_brief'] : [];
        $sharedPrompt = \is_array($planJson['shared_prompt_context'] ?? null) ? $planJson['shared_prompt_context'] : [];
        $themeContext = \is_array($planJson['theme_context_snapshot'] ?? null) ? $planJson['theme_context_snapshot'] : [];
        $requirementExpansion = \is_array($planJson['requirement_expansion'] ?? null) ? $planJson['requirement_expansion'] : [];

        return $this->normalizeProfileTagline($this->pickString(
            $planJson['site_tagline'] ?? null,
            $planJson['tagline'] ?? null,
            $sharedPrompt['site_tagline'] ?? null,
            $sharedPrompt['tagline'] ?? null,
            $themeContext['site_tagline'] ?? null,
            $themeContext['tagline'] ?? null,
            $siteBrief['site_tagline'] ?? null,
            $siteBrief['tagline'] ?? null,
            $siteBrief['summary'] ?? null,
            $requirementExpansion['site_goal'] ?? null
        ), '', $internalVisibleCopyTerms);
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<string>
     */
    private function buildInternalVisibleCopyTerms(array $scope): array
    {
        $terms = [];

        foreach ([
            $scope['_plan_sse_request']['selected_skill_codes'] ?? null,
            $scope['selected_skill_codes'] ?? null,
            $scope['plan_json']['contract_context']['selected_skill_codes'] ?? null,
            $scope['contract_context']['selected_skill_codes'] ?? null,
        ] as $candidate) {
            $this->collectInternalVisibleCopyTerms($candidate, $terms);
        }

        foreach ([
            $scope['skill_snapshots'] ?? null,
            $scope['plan_json']['contract_context']['skill_snapshots'] ?? null,
            $scope['contract_context']['skill_snapshots'] ?? null,
        ] as $candidate) {
            $this->collectInternalVisibleCopyTerms($candidate, $terms);
        }

        foreach ([
            $scope['design_direction_code'] ?? null,
            $scope['design_direction_hash'] ?? null,
            $scope['design_direction_match_reason'] ?? null,
            $scope['design_direction'] ?? null,
            $scope['design_direction_snapshot'] ?? null,
            $scope['plan_json']['contract_context']['design_direction_code'] ?? null,
            $scope['plan_json']['contract_context']['design_direction_snapshot'] ?? null,
            $scope['contract_context']['design_direction_code'] ?? null,
            $scope['contract_context']['design_direction_snapshot'] ?? null,
        ] as $candidate) {
            $this->collectInternalVisibleCopyTerms($candidate, $terms);
        }

        return \array_values($terms);
    }

    /**
     * @param array<string, string> $terms
     */
    private function collectInternalVisibleCopyTerms(mixed $raw, array &$terms): void
    {
        if (\is_array($raw)) {
            foreach ($raw as $key => $value) {
                if (\is_array($value)) {
                    foreach (['code', 'name', 'title', 'label'] as $field) {
                        $this->addInternalVisibleCopyTerm($value[$field] ?? null, $terms);
                    }
                    continue;
                }
                if (\is_scalar($key) && \in_array((string)$key, ['code', 'name', 'title', 'label'], true)) {
                    $this->addInternalVisibleCopyTerm($value, $terms);
                    continue;
                }
                if (\is_int($key)) {
                    $this->addInternalVisibleCopyTerm($value, $terms);
                }
            }
            return;
        }

        if (!\is_string($raw)) {
            $this->addInternalVisibleCopyTerm($raw, $terms);
            return;
        }

        $text = \trim($raw);
        if ($text === '') {
            return;
        }

        $decoded = \json_decode($text, true);
        if (\is_array($decoded)) {
            $this->collectInternalVisibleCopyTerms($decoded, $terms);
            return;
        }

        $items = \preg_split('/[,;]+/u', $text, -1, \PREG_SPLIT_NO_EMPTY) ?: [$text];
        foreach ($items as $item) {
            $this->addInternalVisibleCopyTerm($item, $terms);
        }
    }

    /**
     * @param array<string, string> $terms
     */
    private function addInternalVisibleCopyTerm(mixed $value, array &$terms): void
    {
        if (!\is_scalar($value)) {
            return;
        }

        $term = $this->normalizeInternalVisibleCopyTerm((string)$value);
        if ($term === '') {
            return;
        }

        foreach (\array_values(\array_unique([
            $term,
            \str_replace(['-', '_'], ' ', $term),
            \ucwords(\str_replace(['-', '_'], ' ', $term)),
        ])) as $variant) {
            $variant = $this->normalizeInternalVisibleCopyTerm($variant);
            if ($variant === '') {
                continue;
            }
            $key = \function_exists('mb_strtolower') ? \mb_strtolower($variant) : \strtolower($variant);
            $terms[$key] = $variant;
        }
    }

    private function normalizeInternalVisibleCopyTerm(string $value): string
    {
        $value = $this->normalizeWhitespace(\trim($value, " \t\n\r\0\x0B\"'`"));
        if ($value === '' || $this->isUnsafeInternalCopyTerm($value)) {
            return '';
        }

        return $value;
    }

    private function isUnsafeInternalCopyTerm(string $value): bool
    {
        $length = \function_exists('mb_strlen') ? \mb_strlen($value) : \strlen($value);
        if ($length < 3 || $length > 80) {
            return true;
        }
        if (\preg_match('/^\d+$/u', $value) === 1) {
            return true;
        }
        if (\str_contains($value, '://') || \str_contains($value, '{') || \str_contains($value, '}')) {
            return true;
        }

        $normalized = \strtolower($value);

        return \in_array($normalized, [
            'ai',
            'css',
            'html',
            'json',
            'site',
            'skill',
            'style',
            'design',
            'prompt',
            'profile',
            'website',
            'pagebuilder',
        ], true);
    }

    /**
     * @param list<string> $internalVisibleCopyTerms
     */
    private function normalizeProfileTagline(string $value, string $siteTitle, array $internalVisibleCopyTerms = []): string
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
        $value = $this->stripInternalProfileTokens($value, $internalVisibleCopyTerms);

        return $this->clipText($value, 96);
    }

    /**
     * @param list<string> $internalVisibleCopyTerms
     */
    private function normalizeProfileBriefDescription(string $value, array $internalVisibleCopyTerms = []): string
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
        $value = $this->stripInternalProfileTokens($value, $internalVisibleCopyTerms);

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

    /**
     * @param list<string> $internalVisibleCopyTerms
     */
    private function normalizeMeaningfulTitle(mixed $value, array $internalVisibleCopyTerms = [], bool $clip = true): string
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
        $title = $this->stripInternalProfileTokens($title, $internalVisibleCopyTerms);
        $title = $this->stripGeneratedRunSuffixFromTitle($title);
        if ($title === '' || $this->isPlaceholderSiteTitle($title) || $this->isGenericPageTitle($title)) {
            return '';
        }

        return $clip ? $this->clipSiteTitle($title) : $title;
    }

    /**
     * @param list<string> $internalVisibleCopyTerms
     */
    private function stripInternalProfileTokens(string $value, array $internalVisibleCopyTerms = []): string
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
        foreach ($internalVisibleCopyTerms as $term) {
            $term = $this->normalizeWhitespace($term);
            if ($term === '' || $this->isUnsafeInternalCopyTerm($term)) {
                continue;
            }
            $termPattern = \preg_quote($term, '/');
            if (\preg_match('/^' . $termPattern . '$/iu', $value) === 1) {
                return '';
            }
            $value = (string)\preg_replace(
                '/(?<![\p{L}\p{N}])' . $termPattern . '(?![\p{L}\p{N}])/iu',
                ' ',
                $value
            );
        }
        $value = (string)\preg_replace('/\s*[-:|]\s*$/u', '', $value);
        if (\preg_match('/^(?:built|created|generated|designed|powered)\s+(?:with|by)$/iu', $value) === 1) {
            return '';
        }

        return $this->normalizeWhitespace($value);
    }

    private function stripGeneratedRunSuffixFromTitle(string $title): string
    {
        $title = $this->normalizeWhitespace($title);
        if ($title === '') {
            return '';
        }
        if (\preg_match('/^(.*\D)\s+(\d{4,})$/u', $title, $matches) !== 1) {
            return $title;
        }

        $suffix = (int)($matches[2] ?? 0);
        $currentYear = (int)\date('Y');
        if ($suffix < 1900 || $suffix > ($currentYear + 5) || \strlen((string)($matches[2] ?? '')) >= 5) {
            return $this->normalizeWhitespace((string)($matches[1] ?? $title));
        }

        return $title;
    }

    /**
     * @param list<string> $internalVisibleCopyTerms
     */
    private function deriveSiteTitleFromBrief(string $briefDescription, string $targetDomain, array $internalVisibleCopyTerms = []): string
    {
        $explicitTitle = $this->extractExplicitSiteTitleFromBrief($briefDescription);
        $explicitTitle = $this->normalizeMeaningfulTitle($explicitTitle, $internalVisibleCopyTerms);
        if ($explicitTitle !== '' && !$this->isPlaceholderSiteTitle($explicitTitle)) {
            return $this->clipSiteTitle($explicitTitle);
        }

        $candidates = $this->buildDescriptionSegments($briefDescription);
        foreach ($candidates as $candidate) {
            $candidate = $this->stripTitleLeadIn($candidate);
            $candidate = $this->stripDescriptiveTitleTail($candidate);
            $candidate = $this->normalizeMeaningfulTitle($candidate, $internalVisibleCopyTerms);
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
        if (\preg_match('/^\s*([A-Z][A-Za-z0-9 .&\'-]{1,42})\s*(?:,|\|)/u', $briefDescription, $matches) === 1) {
            $title = \trim((string)($matches[1] ?? ''), " \t\n\r\0\x0B-_:");
            if ($title !== '' && !$this->looksLikeSentenceTitle($title)) {
                return $title;
            }
        }
        if (\preg_match('/\b(?:website|site|homepage|landing\s+page)\s+(?:for|about)\s+([A-Za-z][A-Za-z0-9 .&\'-]{2,80}?)(?:\.|,|;|$)/iu', $briefDescription, $matches) === 1) {
            $title = $this->stripDescriptiveTitleTail((string)($matches[1] ?? ''));
            if ($title !== '' && !$this->looksLikeSentenceTitle($title)) {
                return $title;
            }
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

    /**
     * @param list<string> $internalVisibleCopyTerms
     */
    private function deriveSiteTaglineFromBrief(string $briefDescription, string $siteTitle, array $internalVisibleCopyTerms = []): string
    {
        $explicit = $this->extractLabeledProfileSegment($briefDescription, $this->profileTaglineLabelPatterns());
        if ($explicit !== '') {
            return $this->normalizeProfileTagline($explicit, $siteTitle, $internalVisibleCopyTerms);
        }

        $segments = $this->buildDescriptionSegments($briefDescription);
        foreach ($segments as $segment) {
            $candidate = $this->stripRepeatedTitlePrefix($segment, $siteTitle);
            $candidate = $this->stripInternalProfileTokens($candidate, $internalVisibleCopyTerms);
            if ($candidate !== '' && $candidate !== $siteTitle) {
                return $this->clipText($candidate, 72);
            }
        }

        $candidate = $this->stripRepeatedTitlePrefix($this->normalizeWhitespace($briefDescription), $siteTitle);
        $candidate = $this->stripInternalProfileTokens($candidate, $internalVisibleCopyTerms);
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

        $sentences = \preg_split('/[\r\n]+|(?<=[A-Za-z0-9])\.(?=\s|$)|[\x{3002}\x{FF01}\x{FF1F}!?;]+/u', $briefDescription, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
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
            '/^(?:please\s+)?(?:create|build|generate|make|design|develop|produce)\s+(?:a|an|the)?\s*(?:(?:polished|premium|english|official|mobile-first|responsive|seo-friendly|recommendation)\s+)*(?:website|site|homepage|landing\s+page)\s+(?:for|about)\s*/iu',
            '/^(?:a|an|the)\s+(?:(?:polished|premium|english|official|mobile-first|responsive|seo-friendly|recommendation)\s+)*(?:website|site|homepage|landing\s+page)\s+(?:for|about)\s*/iu',
            '/^(?:please\s+)?(?:create|build|generate|make|design|develop|produce)\s+(?:a|an|the)?\s*/iu',
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

    private function stripDescriptiveTitleTail(string $title): string
    {
        $title = $this->normalizeWhitespace($title);
        $title = (string)\preg_replace('/\b(?:downloads?|download\s+hub|comparison|comparisons|recommendations?)$/iu', '', $title);
        if (\preg_match('/APK$/iu', $title) === 1) {
            $title .= ' Guide';
        }

        return $this->normalizeWhitespace($title);
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

    private function isGenericPageTitle(string $value): bool
    {
        $normalized = \strtolower($this->normalizeWhitespace($value));

        return \in_array($normalized, ['home', 'home page', 'homepage', 'index', 'landing page'], true);
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
        $glyph = $this->buildFallbackIdentityGlyphMarkup($siteTitle . ' ' . $siteTagline . ' ' . $briefDescription, $primary, $secondary, $accent, 80, 24, 1.0);

        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="160" height="48" viewBox="0 0 160 48">
  <defs>
    <linearGradient id="brandLogoGradient" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="{$primary}"/>
      <stop offset="100%" stop-color="{$secondary}"/>
    </linearGradient>
  </defs>
{$glyph}
</svg>
SVG;

        return 'data:image/svg+xml;base64,' . \base64_encode($svg);
    }

    private function normalizeGeneratedSvgAsset(string $value, bool $allowText = false): string
    {
        $value = \trim($value);
        if ($value === '') {
            return '';
        }

        if (\str_starts_with($value, 'data:image/svg+xml;base64,')) {
            $decoded = \base64_decode(\substr($value, 26), true);
            return \is_string($decoded) && $this->isSafeGeneratedSvgMarkup($decoded, $allowText) ? $value : '';
        }

        if ($this->isSafeGeneratedSvgMarkup($value, $allowText)) {
            return 'data:image/svg+xml;base64,' . \base64_encode($value);
        }

        return '';
    }

    private function isSafeGeneratedSvgMarkup(string $value, bool $allowText = false): bool
    {
        if (!\str_contains($value, '<svg') || !\str_contains($value, '</svg>')) {
            return false;
        }

        $normalized = \strtolower($value);
        if (!$allowText && (\str_contains($normalized, '<text') || \str_contains($normalized, '<tspan'))) {
            return false;
        }
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
            return $this->normalizeGeneratedSvgAsset($value, true);
        }

        return $value;
    }

    private function buildFallbackIconDataUri(string $siteTitle, string $siteTagline, string $briefDescription): string
    {
        [$primary, $secondary, $accent] = $this->deriveBrandPalette($siteTitle, $briefDescription);
        $glyph = $this->buildFallbackIdentityGlyphMarkup($siteTitle . ' ' . $siteTagline . ' ' . $briefDescription, $primary, $secondary, $accent, 32, 32, 1.05);

        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">
  <defs>
    <linearGradient id="brandIconGradient" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="{$primary}"/>
      <stop offset="100%" stop-color="{$secondary}"/>
    </linearGradient>
  </defs>
{$glyph}
</svg>
SVG;

        return 'data:image/svg+xml;base64,' . \base64_encode($svg);
    }

    private function buildFallbackIdentityGlyphMarkup(
        string $themeText,
        string $primary,
        string $secondary,
        string $accent,
        int $centerX,
        int $centerY,
        float $scale
    ): string {
        $themeText = \strtolower($themeText);
        $scaleText = \rtrim(\rtrim(\sprintf('%.2F', $scale), '0'), '.');
        if ($scaleText === '') {
            $scaleText = '1';
        }

        if (\preg_match('/card|poker|rummy|teen|patti|casino|mahjong|chip|game|apk|棋牌|扑克|拉米|麻将|游戏/u', $themeText) === 1) {
            return <<<SVG
  <g transform="translate({$centerX} {$centerY}) scale({$scaleText})">
    <circle cx="0" cy="0" r="21" fill="{$secondary}" opacity="0.16"/>
    <circle cx="0" cy="0" r="17" fill="none" stroke="{$primary}" stroke-width="4"/>
    <path d="M0 -13 L11 0 L0 13 L-11 0 Z" fill="{$accent}"/>
    <path d="M-18 0 H18 M0 -18 V18" stroke="{$secondary}" stroke-width="2" stroke-linecap="round" opacity="0.48"/>
  </g>
SVG;
        }

        if (\preg_match('/eco|green|organic|nature|garden|plant|leaf|环保|绿色|自然|花园|植物/u', $themeText) === 1) {
            return <<<SVG
  <g transform="translate({$centerX} {$centerY}) scale({$scaleText})">
    <circle cx="0" cy="0" r="22" fill="{$secondary}" opacity="0.14"/>
    <path d="M-18 7 C-14 -15 8 -18 18 -3 C8 -1 2 7 -2 18 C-7 11 -12 8 -18 7 Z" fill="{$primary}"/>
    <path d="M-11 8 C-2 4 7 -4 15 -12" stroke="{$accent}" stroke-width="3" stroke-linecap="round" fill="none"/>
  </g>
SVG;
        }

        if (\preg_match('/tech|software|data|ai|cloud|security|digital|科技|软件|数据|智能|云|安全/u', $themeText) === 1) {
            return <<<SVG
  <g transform="translate({$centerX} {$centerY}) scale({$scaleText})">
    <circle cx="0" cy="0" r="21" fill="{$secondary}" opacity="0.13"/>
    <path d="M-15 7 L0 -12 L15 7 L0 16 Z" fill="none" stroke="{$primary}" stroke-width="4" stroke-linejoin="round"/>
    <circle cx="0" cy="-12" r="4" fill="{$accent}"/>
    <circle cx="-15" cy="7" r="4" fill="{$secondary}"/>
    <circle cx="15" cy="7" r="4" fill="{$secondary}"/>
  </g>
SVG;
        }

        return <<<SVG
  <g transform="translate({$centerX} {$centerY}) scale({$scaleText})">
    <circle cx="0" cy="0" r="22" fill="{$secondary}" opacity="0.12"/>
    <path d="M0 -20 L18 -6 L11 17 L-11 17 L-18 -6 Z" fill="{$primary}" opacity="0.92"/>
    <path d="M0 -10 L10 0 L0 10 L-10 0 Z" fill="{$accent}"/>
    <path d="M-21 0 C-10 -14 10 -14 21 0 C10 14 -10 14 -21 0 Z" fill="none" stroke="{$secondary}" stroke-width="2" opacity="0.62"/>
  </g>
SVG;
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
