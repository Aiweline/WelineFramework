<?php

declare(strict_types=1);

namespace Weline\Maintenance\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Http\MaintenanceStaticPage;
use Weline\Framework\Manager\ObjectManager;

/**
 * Generates maintenance HTML/JSON snapshots under pub/errors/maintenance/.
 *
 * No template compilation, no database — safe to run before maintenance Worker takes over.
 */
final class MaintenanceStaticGenerator
{
    private const DEFAULT_LANG = MaintenanceStaticPage::DEFAULT_LANG;

    private static ?array $langMapping = null;

    public function publishAll(int $retryAfter = 60): array
    {
        $written = [];
        $locales = $this->discoverAvailableLocales();
        foreach ($locales as $lang) {
            $htmlFile = MaintenanceStaticPage::staticFilePath($lang, false);
            $jsonFile = MaintenanceStaticPage::staticFilePath($lang, true);
            $this->saveStaticFile($htmlFile, $this->buildHtml($lang));
            $this->saveStaticFile($jsonFile, $this->buildJson($lang, $retryAfter));
            $written[] = $lang;
        }

        $this->pruneObsoleteStaticFiles($locales);

        return $written;
    }

    public function renderHtml(string $lang): string
    {
        $staticFile = MaintenanceStaticPage::staticFilePath($lang, false);
        if ($this->isStaticFileFresh($staticFile, $lang, false)) {
            return (string)\file_get_contents($staticFile);
        }

        $html = $this->buildHtml($lang);
        $this->saveStaticFile($staticFile, $html);

        return $html;
    }

    public function renderDevPreviewHtml(string $lang): string
    {
        return $this->generateMaintenanceHtml($this->loadTranslations($lang), $lang, true);
    }

    public function renderJson(string $lang, int $retryAfter): string
    {
        $staticFile = MaintenanceStaticPage::staticFilePath($lang, true);
        if ($this->isStaticFileFresh($staticFile, $lang, true)) {
            return (string)\file_get_contents($staticFile);
        }

        $json = $this->buildJson($lang, $retryAfter);
        $this->saveStaticFile($staticFile, $json);

        return $json;
    }

    private function buildHtml(string $lang): string
    {
        return $this->generateMaintenanceHtml($this->loadTranslations($lang), $lang, false);
    }

    private function buildJson(string $lang, int $retryAfter): string
    {
        $translations = $this->loadTranslations($lang);
        $wave = (new UpgradeWaveService())->readWave();
        $waitGiftEnabled = (bool)($wave['wait_gift_enabled'] ?? false);

        $message = $waitGiftEnabled
            ? $this->translate('非常抱歉，网站正在升级维护。若您耐心等待升级完成，我们将发放补偿礼金。', $translations)
            : $this->translate('系统正在升级维护中，请稍后再试。', $translations);

        $payload = [
            'success' => false,
            'code' => 'maintenance',
            'message' => $message,
            'data' => [
                'retry_after' => \max(1, $retryAfter),
                'lang' => $lang,
                'wait_gift_enabled' => $waitGiftEnabled,
                'wave_id' => (string)($wave['wave_id'] ?? ''),
                'system_version' => (string)($wave['system_version_to'] ?? ''),
                'theme_version' => (string)($wave['theme_version_to'] ?? ''),
                'wait_gift_endpoints' => [
                    'issue' => '/maintenance/frontend/wait-gift/issue',
                    'heartbeat' => '/maintenance/frontend/wait-gift/heartbeat',
                    'abandon' => '/maintenance/frontend/wait-gift/abandon',
                    'redeem' => '/maintenance/frontend/wait-gift/redeem',
                    'wave' => '/maintenance/frontend/wait-gift/wave',
                ],
            ],
        ];

        return (string)\json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT);
    }

    /**
     * @return list<string>
     */
    private function discoverAvailableLocales(): array
    {
        try {
            $codes = ObjectManager::getInstance(\Weline\I18n\Service\ActiveLocaleCodeProvider::class)
                ->getInstalledActiveCodes();
            $normalized = $this->normalizeLocaleCodes($codes);
            if ($normalized !== []) {
                return $normalized;
            }
        } catch (\Throwable) {
        }

        $dir = BP . 'generated/language/';
        if (!\is_dir($dir)) {
            return [self::DEFAULT_LANG, 'en_US'];
        }

        $locales = [];
        foreach (\glob($dir . '*.php') ?: [] as $file) {
            $code = \basename((string)$file, '.php');
            if ($code === 'words' || \preg_match('/^[a-z]{2}_[A-Za-z]{2,}(?:_[A-Z]{2})?$/', $code) !== 1) {
                continue;
            }
            $locales[] = $code;
        }

        \sort($locales);

        return $locales !== [] ? $locales : [self::DEFAULT_LANG, 'en_US'];
    }

    /**
     * @param list<string> $codes
     * @return list<string>
     */
    private function normalizeLocaleCodes(array $codes): array
    {
        $locales = [];
        foreach ($codes as $code) {
            $code = \trim((string)$code);
            if ($code === '' || \preg_match('/^[a-z]{2}_[A-Za-z]{2,}(?:_[A-Z]{2})?$/', $code) !== 1) {
                continue;
            }
            $locales[] = $code;
        }

        \sort($locales);

        return $locales;
    }

    /**
     * @param list<string> $activeLocales
     */
    private function pruneObsoleteStaticFiles(array $activeLocales): void
    {
        $active = \array_fill_keys($activeLocales, true);
        $dir = (\defined('PUB') ? \rtrim((string)PUB, \DIRECTORY_SEPARATOR) : BP . 'pub')
            . \DIRECTORY_SEPARATOR . 'errors' . \DIRECTORY_SEPARATOR . 'maintenance';
        if (!\is_dir($dir)) {
            return;
        }

        foreach (\glob($dir . \DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            $basename = \basename((string)$file);
            if (\preg_match('/^(.+)\.(html|json)$/', $basename, $matches) !== 1) {
                continue;
            }
            if (!isset($active[$matches[1]])) {
                @\unlink($file);
            }
        }
    }

    private function isStaticFileFresh(string $staticFile, string $lang, bool $isApi): bool
    {
        if (!\is_file($staticFile)) {
            return false;
        }

        $staticMtime = (int)@\filemtime($staticFile);
        if ($staticMtime <= 0) {
            return false;
        }

        foreach ($this->getStaticDependencies($lang, $isApi) as $dependency) {
            if (\is_file($dependency) && (int)@\filemtime($dependency) > $staticMtime) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return string[]
     */
    private function getStaticDependencies(string $lang, bool $isApi = false): array
    {
        $dependencies = [
            __FILE__,
            BP . 'app/code/Weline/Maintenance/Service/MaintenanceContactEmailResolver.php',
            BP . 'generated/language/' . $lang . '.php',
            BP . 'app/code/Weline/Maintenance/i18n/' . $lang . '.csv',
            BP . 'app/code/Weline/Maintenance/i18n/' . self::DEFAULT_LANG . '.csv',
            BP . 'app/code/Weline/I18n/i18n/' . $lang . '.csv',
            BP . 'app/code/Weline/I18n/i18n/' . self::DEFAULT_LANG . '.csv',
        ];

        if ($isApi) {
            $dependencies[] = BP . 'app/code/Weline/Maintenance/view/templates/maintenance_api.json';
        } else {
            $dependencies[] = BP . 'app/code/Weline/Maintenance/view/templates/maintenance.phtml';
        }

        return $dependencies;
    }

    private function loadTranslations(string $lang): array
    {
        $moduleFallbackTranslations = $this->loadModuleTranslations($lang);

        $generatedFile = BP . 'generated/language/' . $lang . '.php';
        if (\is_file($generatedFile)) {
            $allTranslations = @include $generatedFile;
            if (\is_array($allTranslations)) {
                if (isset($allTranslations['Weline_Maintenance']) && \is_array($allTranslations['Weline_Maintenance'])) {
                    return \array_merge($allTranslations['Weline_Maintenance'], $moduleFallbackTranslations);
                }

                $merged = [];
                foreach ($allTranslations as $generatedModuleTranslations) {
                    if (\is_array($generatedModuleTranslations)) {
                        $merged = \array_merge($merged, $generatedModuleTranslations);
                    }
                }
                if ($merged !== []) {
                    return \array_merge($merged, $moduleFallbackTranslations);
                }
            }
        }

        return $moduleFallbackTranslations;
    }

    private function loadModuleTranslations(string $lang): array
    {
        $translations = [];
        $i18nFile = BP . 'app/code/Weline/Maintenance/i18n/' . $lang . '.csv';

        if (!\is_file($i18nFile)) {
            $i18nFile = BP . 'app/code/Weline/Maintenance/i18n/' . self::DEFAULT_LANG . '.csv';
        }
        if (!\is_file($i18nFile)) {
            $i18nFile = BP . 'app/code/Weline/Maintenance/i18n/en_US.csv';
        }
        if (!\is_file($i18nFile)) {
            return $translations;
        }

        $handle = @\fopen($i18nFile, 'r');
        if ($handle === false) {
            return $translations;
        }

        while (($data = \fgetcsv($handle, 100000, ',', '"', '\\')) !== false) {
            if (isset($data[0], $data[1]) && !empty(\trim($data[0]))) {
                $translations[\trim($data[0])] = \trim($data[1]);
            }
        }

        \fclose($handle);

        return $translations;
    }

    private function translate(string $text, array $translations): string
    {
        return $translations[$text] ?? $text;
    }

    /**
     * @param list<string> $locales
     * @return array<string, array{code: string, name: string, tag_label: string, flag: string, country_code: string}>
     */
    private function buildLanguageCatalog(array $locales, string $displayLocale): array
    {
        $catalog = [];

        try {
            $languages = \Weline\I18n\Taglib\LanguageSwitcher::buildLanguagesFromCodes($locales, $displayLocale);
            foreach ($locales as $code) {
                if (!isset($languages[$code]) || !\is_array($languages[$code])) {
                    continue;
                }
                $label = (string)($languages[$code]['tag_label'] ?? ($languages[$code]['name'] ?? ''));
                if ($label === '') {
                    continue;
                }
                $countryCode = $this->normalizeCountryCode(
                    (string)($languages[$code]['country_code'] ?? ''),
                    (string)$code,
                );
                // Storefront LanguageSelect SSR leaves flag empty (binquery hydrate).
                // Standalone maintenance HTML cannot load Weline i18n JS / query-bin,
                // so materialize country flags under /pub/errors/maintenance/flags/.
                $flag = $this->materializeMaintenanceFlagMarkup($countryCode);
                $catalog[$code] = [
                    'code' => $code,
                    'name' => (string)($languages[$code]['name'] ?? $label),
                    'tag_label' => $label,
                    'flag' => $flag,
                    'country_code' => $countryCode,
                ];
            }
        } catch (\Throwable) {
        }

        foreach ($locales as $code) {
            if (isset($catalog[$code])) {
                continue;
            }
            $fallback = $this->getLocaleFallbackLabel($code);
            $countryCode = $this->normalizeCountryCode('', (string)$code);
            $catalog[$code] = [
                'code' => $code,
                'name' => $fallback,
                'tag_label' => $fallback,
                'flag' => $this->materializeMaintenanceFlagMarkup($countryCode),
                'country_code' => $countryCode,
            ];
        }

        return $catalog;
    }

    private function normalizeCountryCode(string $countryCode, string $localeCode): string
    {
        $countryCode = \strtoupper(\trim($countryCode));
        if (\preg_match('/^[A-Z]{2}$/', $countryCode) === 1) {
            return $countryCode;
        }
        $parts = \explode('_', \str_replace('-', '_', \trim($localeCode)));
        $last = \end($parts);

        return \is_string($last) && \preg_match('/^[A-Za-z]{2}$/', $last) === 1
            ? \strtoupper($last)
            : '';
    }

    /**
     * Copy lipis flag SVG into pub/errors (maintenance whitelist) and return &lt;img&gt; markup.
     */
    private function materializeMaintenanceFlagMarkup(string $countryCode): string
    {
        if (!\class_exists(\Weline\I18n\Helper\CountryFlagMarkup::class)) {
            return '';
        }
        $code = \Weline\I18n\Helper\CountryFlagMarkup::normalizeCountryCode($countryCode);
        if ($code === '') {
            return '';
        }
        $url = $this->materializeMaintenanceFlagUrl($code);
        if ($url === '') {
            return '';
        }
        $safeUrl = \htmlspecialchars($url, \ENT_QUOTES, 'UTF-8');
        $safeAlt = \htmlspecialchars(\strtoupper($code), \ENT_QUOTES, 'UTF-8');

        return '<img class="language-flag-img" src="' . $safeUrl . '" alt="' . $safeAlt . '"'
            . ' width="22" height="16" decoding="async" loading="lazy" />';
    }

    private function materializeMaintenanceFlagUrl(string $countryCode): string
    {
        $code = \Weline\I18n\Helper\CountryFlagMarkup::normalizeCountryCode($countryCode);
        if ($code === '') {
            return '';
        }
        $src = \Weline\I18n\Helper\CountryFlagMarkup::staticFilePath($code);
        if ($src === '' || !\is_file($src)) {
            return '';
        }
        $destDir = BP . 'pub/errors/maintenance/flags';
        $dest = $destDir . '/' . $code . '.svg';
        if (!\is_file($dest)) {
            if (!\is_dir($destDir) && !@\mkdir($destDir, 0755, true) && !\is_dir($destDir)) {
                return '';
            }
            if (!@\copy($src, $dest) || !\is_file($dest)) {
                return '';
            }
        }

        return '/pub/errors/maintenance/flags/' . $code . '.svg';
    }

    private function getLocaleFallbackLabel(string $code): string
    {
        static $labels = [
            'zh_Hans_CN' => '简体中文',
            'zh_Hant_TW' => '繁體中文',
            'en_US' => 'English',
            'ja_JP' => '日本語',
            'ko_KR' => '한국어',
            'fr_FR' => 'Français',
            'de_DE' => 'Deutsch',
            'es_ES' => 'Español',
            'pt_BR' => 'Português',
            'ru_RU' => 'Русский',
            'ar_SA' => 'العربية',
            'th_TH' => 'ไทย',
            'vi_VN' => 'Tiếng Việt',
        ];

        return $labels[$code] ?? \str_replace('_', ' ', $code);
    }

    private function generateMaintenanceHtml(array $translations, string $lang, bool $devPreview = false): string
    {
        $wave = (new UpgradeWaveService())->readWave();
        $waitGiftEnabled = (bool)($wave['wait_gift_enabled'] ?? false);
        $waveSystemVersion = (string)($wave['system_version_to'] ?? '');
        $waveThemeVersion = (string)($wave['theme_version_to'] ?? '');
        $waveIdShort = $waveSystemVersion !== '' || $waveThemeVersion !== ''
            ? 'SYS ' . ($waveSystemVersion !== '' ? $waveSystemVersion : '-') . ' · Theme ' . ($waveThemeVersion !== '' ? $waveThemeVersion : '-')
            : '';

        $title = $this->translate('网站维护', $translations);
        $heading = $this->translate('系统升级维护中', $translations);
        if ($waitGiftEnabled) {
            $message1 = $this->translate('非常抱歉，网站正在升级维护，给您带来不便。', $translations);
            $message2 = $this->translate('不过也要恭喜您——若您愿意耐心等到升级完成，我们将发放一份补偿礼金。请先不要关闭本页，恢复后 10 分钟内会自动领取。', $translations);
            $recoveryNotice = $this->translate('正在升级 · 请勿关闭本页。恢复后将自动为您领取补偿礼金。', $translations);
        } else {
            $message1 = $this->translate('网站正在维护中...', $translations);
            $message2 = $this->translate('请稍等片刻', $translations);
            $recoveryNotice = $this->translate('正在尝试现场抢修，恢复后会自动进入，请耐心等待', $translations);
        }
        $whyTitle = $this->translate('为什么网站会处于维护模式？', $translations);
        $whyContent = $this->translate('网站可能发生系统升级事件，或者大多数的内容发生变化，程序员们正在维护或者升级数据以提供更加优质的服务。', $translations);
        $howLongTitle = $this->translate('多久能够恢复？', $translations);
        $howLongContent = $this->translate('大部分的网站升级活动都只有几分钟甚至几秒钟的时间。', $translations);
        $helpTitle = $this->translate('需要帮助吗？', $translations);
        $helpContent = $this->translate('如果你长期看到这个页面，对网站存在疑惑，请联系：', $translations);
        $backHome = $this->translate('返回首页', $translations);
        $refreshCurrentPage = $this->translate('刷新当前页面', $translations);
        $pageTools = $this->translate('页面工具', $translations);
        $switchLanguage = $this->translate('切换语言', $translations);
        $systemStatus = $this->translate('系统状态', $translations);
        $recoveryChecking = $this->translate('恢复检测中', $translations);
        $maintenanceInstructions = $this->translate('维护说明', $translations);
        $devPreviewBanner = $this->translate('开发预览：此地址仅在 DEV 环境可用，上线后不可访问。', $translations);
        $languageCatalog = $this->buildLanguageCatalog($this->discoverAvailableLocales(), $lang);
        $devPreviewMode = $devPreview;
        $defaultLang = MaintenanceStaticPage::DEFAULT_LANG;

        $contactEmail = ObjectManager::getInstance(MaintenanceContactEmailResolver::class)->resolve();

        $brandUrls = $this->resolveMaintenanceBrandUrls();
        $maintenance_logo_url = $brandUrls['logo'];
        $maintenance_favicon_url = $brandUrls['favicon'];
        $maintenance_apple_touch_icon_url = $brandUrls['apple_touch_icon'];

        $htmlLang = \str_replace('_', '-', $lang);
        if (\str_starts_with($htmlLang, 'zh-Hans')) {
            $htmlLang = 'zh-CN';
        } elseif (\str_starts_with($htmlLang, 'zh-Hant')) {
            $htmlLang = 'zh-TW';
        }

        $templateFile = BP . 'app/code/Weline/Maintenance/view/templates/maintenance.phtml';
        if (\is_file($templateFile)) {
            $template = null;
            try {
                $template = \Weline\Framework\View\Template::getInstance();
            } catch (\Throwable) {
                $template = null;
            }

            \ob_start();
            include $templateFile;

            return (string)\ob_get_clean();
        }

        return $this->getFallbackHtml($htmlLang, $title, $heading, $message1, $message2, $recoveryNotice, $backHome);
    }

    private function getFallbackHtml(
        string $htmlLang,
        string $title,
        string $heading,
        string $message1,
        string $message2,
        string $recoveryNotice,
        string $backHome,
    ): string {
        $escape = static fn(string $value): string => \htmlspecialchars($value, \ENT_QUOTES, 'UTF-8');

        $defaultIcon = \Weline\Theme\Helper\SiteBrand::DEFAULT_ICON_PUBLIC_PATH;
        return <<<HTML
<!DOCTYPE html>
<html lang="{$escape($htmlLang)}" data-w-area="frontend" data-theme-preference="system" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$escape($title)}</title>
    <link rel="icon" type="image/png" sizes="128x128" href="{$escape($defaultIcon)}">
    <link rel="shortcut icon" href="{$escape($defaultIcon)}">
</head>
<body class="w-maintenance-page">
    <main><h1>{$escape($heading)}</h1><p>{$escape($message1)}<br>{$escape($message2)}</p><p>{$escape($recoveryNotice)}</p><a href="/">{$escape($backHome)}</a></main>
</body>
</html>
HTML;
    }

    /**
     * Resolve logo/favicon for the standalone maintenance document.
     * Same cascade as storefront header SiteBrand, but static publish / CLI /
     * maintenance Worker often lack a storefront ScopeIdentity (global → empty
     * appearance). Always try website-scoped ThemeBrandResolver first so the
     * page follows the published website brand (e.g. default.__website__.default).
     *
     * @return array{logo: string, favicon: string, apple_touch_icon: string}
     */
    private function resolveMaintenanceBrandUrls(): array
    {
        $defaultLogo = '/Weline/Theme/view/theme/frontend/assets/images/theme/logo.png';
        $favicon = \Weline\Theme\Helper\SiteBrand::DEFAULT_ICON_PUBLIC_PATH;
        $apple = \Weline\Theme\Helper\SiteBrand::DEFAULT_APPLE_TOUCH_ICON_PUBLIC_PATH;
        $logo = $defaultLogo;
        try {
            $siteBrand = ObjectManager::getInstance(\Weline\Theme\Helper\SiteBrand::class);

            // Website-scoped published brand (matches storefront header when
            // RequestContext is missing or still global during static generation).
            $this->applyPublishedBrandUrls($this->resolveWebsiteScopedPublishedBrand(), $logo, $favicon, $apple, $defaultLogo);

            try {
                $template = \Weline\Framework\View\Template::getInstance();
            } catch (\Throwable) {
                $template = null;
            }

            if ($template !== null) {
                if ($logo === $defaultLogo) {
                    $resolvedLogo = \trim((string)$siteBrand->resolveFrontendLogoUrl($template));
                    if ($resolvedLogo !== '') {
                        $logo = $resolvedLogo;
                    }
                }
                if ($favicon === \Weline\Theme\Helper\SiteBrand::DEFAULT_ICON_PUBLIC_PATH) {
                    $resolvedIcon = \trim((string)$siteBrand->resolveIconUrl($template));
                    if ($resolvedIcon !== '') {
                        $favicon = $resolvedIcon;
                    }
                }
                if ($apple === \Weline\Theme\Helper\SiteBrand::DEFAULT_APPLE_TOUCH_ICON_PUBLIC_PATH) {
                    $resolvedApple = \trim((string)$siteBrand->resolveAppleTouchIconUrl($template));
                    if ($resolvedApple !== '') {
                        $apple = $resolvedApple;
                    }
                }
            }

            if ($logo !== $defaultLogo
                && $favicon !== \Weline\Theme\Helper\SiteBrand::DEFAULT_ICON_PUBLIC_PATH
                && $apple !== \Weline\Theme\Helper\SiteBrand::DEFAULT_APPLE_TOUCH_ICON_PUBLIC_PATH
            ) {
                return [
                    'logo' => $logo,
                    'favicon' => $favicon,
                    'apple_touch_icon' => $apple,
                ];
            }

            $backendConfig = ObjectManager::getInstance(\Weline\Backend\Api\Config\BackendConfigStore::class);

            if ($favicon === \Weline\Theme\Helper\SiteBrand::DEFAULT_ICON_PUBLIC_PATH
                || $apple === \Weline\Theme\Helper\SiteBrand::DEFAULT_APPLE_TOUCH_ICON_PUBLIC_PATH) {
                $siteIcon = \trim((string)($backendConfig->getConfig('site_icon', 'Weline_Backend') ?? ''));
                if ($siteIcon !== '' && !$siteBrand->isLegacyPlaceholder($siteIcon)) {
                    $resolved = $this->toPublicMediaOrStaticUrl($siteIcon);
                    if ($resolved !== '') {
                        if ($favicon === \Weline\Theme\Helper\SiteBrand::DEFAULT_ICON_PUBLIC_PATH) {
                            $favicon = $resolved;
                        }
                        if ($apple === \Weline\Theme\Helper\SiteBrand::DEFAULT_APPLE_TOUCH_ICON_PUBLIC_PATH) {
                            $apple = $resolved;
                        }
                    }
                }
            }

            if ($logo === $defaultLogo) {
                $logoLight = \trim((string)($backendConfig->getConfig('logo_light', 'Weline_Backend') ?? ''));
                if ($logoLight === '') {
                    $logoLight = \trim((string)($backendConfig->getConfig('logo_dark', 'Weline_Backend') ?? ''));
                }
                if ($logoLight !== '' && !$siteBrand->isLegacyPlaceholder($logoLight)) {
                    $resolvedLogo = $this->toPublicMediaOrStaticUrl($logoLight);
                    if ($resolvedLogo !== '') {
                        $logo = $resolvedLogo;
                    }
                }
            }
        } catch (\Throwable) {
        }

        return [
            'logo' => $logo,
            'favicon' => $favicon,
            'apple_touch_icon' => $apple,
        ];
    }

    /**
     * @return array{favicon: string, apple_touch_icon: string, logo_light: string, logo_dark: string, source_scope: ?string}
     */
    private function resolveWebsiteScopedPublishedBrand(): array
    {
        $empty = [
            'favicon' => '',
            'apple_touch_icon' => '',
            'logo_light' => '',
            'logo_dark' => '',
            'source_scope' => null,
        ];
        try {
            $websiteId = 0;
            $websiteCode = 'default';
            if (\class_exists(\Weline\Websites\Data\WebsiteData::class)) {
                $id = \Weline\Websites\Data\WebsiteData::getWebsiteId();
                if ($id !== null) {
                    $websiteId = (int)$id;
                }
                $code = \trim((string)(\Weline\Websites\Data\WebsiteData::getCode() ?? ''));
                if ($code !== '') {
                    $websiteCode = $code;
                }
            }
            $identity = \Weline\Framework\Runtime\ScopeIdentity::website($websiteId, $websiteCode);
            try {
                $catalog = ObjectManager::getInstance(
                    \Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface::class
                );
                $identity = $catalog->authoritativeIdentity($identity);
            } catch (\Throwable) {
            }
            $scopes = ObjectManager::getInstance(\Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface::class);
            $scope = $scopes->contextFromIdentity($identity);
            $brand = ObjectManager::getInstance(\Weline\Theme\Service\ThemeBrandResolver::class)
                ->resolvePublishedBrand('frontend', null, $scope, false);

            return \is_array($brand) ? $brand + $empty : $empty;
        } catch (\Throwable) {
            return $empty;
        }
    }

    /**
     * @param array{favicon?: string, apple_touch_icon?: string, logo_light?: string, logo_dark?: string} $brand
     */
    private function applyPublishedBrandUrls(
        array $brand,
        string &$logo,
        string &$favicon,
        string &$apple,
        string $defaultLogo,
    ): void {
        $themeFavicon = $this->toPublicMediaOrStaticUrl((string)($brand['favicon'] ?? ''));
        if ($themeFavicon !== '' && $favicon === \Weline\Theme\Helper\SiteBrand::DEFAULT_ICON_PUBLIC_PATH) {
            $favicon = $themeFavicon;
        }
        $themeApple = $this->toPublicMediaOrStaticUrl((string)($brand['apple_touch_icon'] ?? ''));
        if ($themeApple !== '' && $apple === \Weline\Theme\Helper\SiteBrand::DEFAULT_APPLE_TOUCH_ICON_PUBLIC_PATH) {
            $apple = $themeApple;
        } elseif ($themeFavicon !== '' && $apple === \Weline\Theme\Helper\SiteBrand::DEFAULT_APPLE_TOUCH_ICON_PUBLIC_PATH) {
            $apple = $themeFavicon;
        }
        if ($logo === $defaultLogo) {
            foreach (['logo_light', 'logo_dark'] as $logoKey) {
                $themeLogo = $this->toPublicMediaOrStaticUrl((string)($brand[$logoKey] ?? ''));
                if ($themeLogo !== '') {
                    $logo = $themeLogo;
                    break;
                }
            }
        }
    }

    private function toPublicMediaOrStaticUrl(string $path): string
    {
        $path = \trim($path);
        if ($path === '') {
            return '';
        }
        if (\str_starts_with($path, 'http') || \str_starts_with($path, '//')) {
            return $path;
        }
        if (\str_starts_with($path, '/Weline/') || \str_starts_with($path, '/static/') || \str_starts_with($path, '/pub/static/')) {
            return $path;
        }
        if (\str_starts_with($path, '/pub/media/')) {
            return $path;
        }
        foreach (['pub/media/', '/media/', 'media/'] as $prefix) {
            if (\str_starts_with($path, $prefix)) {
                $path = \ltrim(\substr($path, \strlen($prefix)), '/');
                break;
            }
        }

        return '/pub/media/' . \ltrim($path, '/');
    }

    private function saveStaticFile(string $filePath, string $content): void
    {
        $dir = \dirname($filePath);
        if (!\is_dir($dir)) {
            if (!@\mkdir($dir, 0755, true) && !\is_dir($dir)) {
                return;
            }
        }
        @\file_put_contents($filePath, $content);
    }
}
