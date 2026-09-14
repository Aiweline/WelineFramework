<?php

declare(strict_types=1);

namespace Weline\Smtp\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Asset\MediaUrl;
use Weline\I18n\Api\Translation\TranslationResolverInterface;
use Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\SystemConfig\Service\SystemConfigScopeResolver;
use Weline\Theme\Helper\SiteBrand;
use Weline\Theme\Helper\SiteContactInfo;
use Weline\Theme\Service\ThemeBrandResolver;
use Weline\Websites\Model\SalesChannel;
use Weline\Websites\Model\Store;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteDomain;

/**
 * 按站店渠 scope 解析邮件品牌/信任上下文（logo、站名、店铺名、联系方式）。
 * 仅读取公开 Helper/实体，不写 Theme / SystemConfig。
 * 传入邮件 locale 时，可译字段与预览样本经 TranslationResolver 对齐该语种（避免后台 UI 语种串台）。
 */
class MailBrandContextService
{
    /** @return list<string> */
    public static function variableCodes(): array
    {
        return [
            'site_name',
            'store_name',
            'channel_name',
            'brand_display_name',
            'site_url',
            'site_logo_url',
            'site_logo_img',
            'site_description',
            'contact_email',
            'contact_phone',
            'contact_address',
            'service_hours',
            // 主题色盘 → 邮件安全 hex（页头/页尾品牌调性）
            'brand_primary',
            'brand_primary_dark',
            'brand_on_primary',
            'brand_header_bg',
            'brand_header_text',
            'brand_header_muted',
            'brand_accent',
            'brand_canvas',
            'brand_surface',
            'brand_border',
            'brand_text',
            'brand_muted',
            'brand_link',
            'brand_footer_heading',
            'brand_footer_bg',
        ];
    }

    /**
     * 后台「插入变量」列表（与发信注入同一套 code）。
     *
     * @return list<array{code:string,label:string,sample:string}>
     */
    public static function variableDefinitions(): array
    {
        return [
            ['code' => 'site_name', 'label' => (string)__('网站名称'), 'sample' => '示例商城'],
            ['code' => 'store_name', 'label' => (string)__('店铺名称'), 'sample' => '默认店铺'],
            ['code' => 'channel_name', 'label' => (string)__('销售渠道名称'), 'sample' => 'Web'],
            ['code' => 'brand_display_name', 'label' => (string)__('展示品牌名（店名优先）'), 'sample' => '示例商城'],
            ['code' => 'site_url', 'label' => (string)__('网站地址'), 'sample' => 'https://example.com'],
            ['code' => 'site_logo_url', 'label' => (string)__('Logo 绝对 URL'), 'sample' => 'https://example.com/logo.png'],
            ['code' => 'site_logo_img', 'label' => (string)__('Logo 图片 HTML（|raw）'), 'sample' => '<img …>'],
            ['code' => 'site_description', 'label' => (string)__('站点简介'), 'sample' => '官方商城'],
            ['code' => 'contact_email', 'label' => (string)__('联系邮箱'), 'sample' => 'support@example.com'],
            ['code' => 'contact_phone', 'label' => (string)__('联系电话'), 'sample' => '+86…'],
            ['code' => 'contact_address', 'label' => (string)__('联系地址'), 'sample' => '…'],
            ['code' => 'service_hours', 'label' => (string)__('服务时间'), 'sample' => '周一至周五 9:00-18:00'],
            ['code' => 'brand_primary', 'label' => (string)__('品牌主色'), 'sample' => '#b84a3c'],
            ['code' => 'brand_primary_dark', 'label' => (string)__('品牌主色深'), 'sample' => '#963b30'],
            ['code' => 'brand_on_primary', 'label' => (string)__('主色上文字色'), 'sample' => '#f7f4ef'],
            ['code' => 'brand_header_bg', 'label' => (string)__('邮件页头背景'), 'sample' => '#16181a'],
            ['code' => 'brand_header_text', 'label' => (string)__('邮件页头文字'), 'sample' => '#f7f4ef'],
            ['code' => 'brand_header_muted', 'label' => (string)__('邮件页头次要文字'), 'sample' => '#d4cfc5'],
            ['code' => 'brand_accent', 'label' => (string)__('邮件强调条'), 'sample' => '#b84a3c'],
            ['code' => 'brand_canvas', 'label' => (string)__('邮件画布背景'), 'sample' => '#f7f4ef'],
            ['code' => 'brand_surface', 'label' => (string)__('邮件卡片背景'), 'sample' => '#fffefa'],
            ['code' => 'brand_border', 'label' => (string)__('邮件边框色'), 'sample' => '#d4cfc5'],
            ['code' => 'brand_text', 'label' => (string)__('邮件正文色'), 'sample' => '#1c1c1c'],
            ['code' => 'brand_muted', 'label' => (string)__('邮件次要文字色'), 'sample' => '#5c5a56'],
            ['code' => 'brand_link', 'label' => (string)__('邮件链接色'), 'sample' => '#b84a3c'],
            ['code' => 'brand_footer_heading', 'label' => (string)__('页尾标题色'), 'sample' => '#7a3028'],
            ['code' => 'brand_footer_bg', 'label' => (string)__('页尾浅底'), 'sample' => '#f8ede9'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function resolve(string $storageScope, string $locale = ''): array
    {
        $contact = $this->resolveContact();
        $names = $this->resolveScopeNames($storageScope);
        $siteUrl = $names['site_url'];
        $logoUrl = $this->resolveLogoAbsoluteUrl($storageScope, $siteUrl);

        $siteName = $names['site_name'];
        if ($siteName === '' || preg_match('/^默认网站$|^Default(\s+Website)?$/iu', $siteName) === 1) {
            if (trim((string)$contact['site_name']) !== '') {
                $siteName = trim((string)$contact['site_name']);
            }
        }
        $storeName = $names['store_name'];
        $channelName = $names['channel_name'];
        $brandDisplay = $storeName !== '' ? $storeName : $siteName;
        if ($brandDisplay === '' || preg_match('/默认网站|默认店铺/u', $brandDisplay) === 1) {
            if (trim((string)$contact['site_name']) !== '') {
                $brandDisplay = trim((string)$contact['site_name']);
            }
        }
        $description = trim((string)$contact['site_description']);
        if ($description === '') {
            $description = '官方商城客户服务';
        }

        $logoImg = '';
        if ($logoUrl !== '') {
            $alt = htmlspecialchars($brandDisplay !== '' ? $brandDisplay : $siteName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $src = htmlspecialchars($logoUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $logoImg = '<img src="' . $src . '" alt="' . $alt . '" width="160" border="0" '
                . 'style="display:block;border:0;outline:none;text-decoration:none;height:auto;max-width:160px;">';
        }

        $palette = $this->resolvePalette($storageScope);
        $contactEmail = $this->normalizeContactEmail((string)$contact['contact_email'], $siteUrl);

        $brand = array_merge([
            'site_name' => $siteName,
            'store_name' => $storeName,
            'channel_name' => $channelName,
            'brand_display_name' => $brandDisplay,
            'site_url' => $siteUrl,
            'site_logo_url' => $logoUrl,
            'site_logo_img' => $logoImg,
            'site_description' => $description,
            'contact_email' => $contactEmail,
            'contact_phone' => (string)$contact['contact_phone'],
            'contact_address' => (string)$contact['contact_address'],
            'service_hours' => (string)$contact['service_hours'],
        ], $palette);

        return $this->localizeBrandStrings($brand, $locale);
    }

    /**
     * 从 Theme 前台色盘解析邮件安全 hex（禁止 CSS var()）。
     *
     * @return array<string, string>
     */
    public function resolvePalette(string $storageScope): array
    {
        $tokens = $this->loadThemeColorHexMap($storageScope);
        $pick = static function (array $tokens, array $keys, string $fallback) : string {
            foreach ($keys as $key) {
                $v = trim((string)($tokens[$key] ?? ''));
                if ($v !== '' && preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $v)) {
                    return strtolower(strlen($v) === 4
                        ? sprintf('#%s%s%s%s%s%s', $v[1], $v[1], $v[2], $v[2], $v[3], $v[3])
                        : $v);
                }
            }

            return $fallback;
        };

        // 兜底对齐 Theme frontend ink / variables/_colors（朱砂·宣纸），勿回 Ink Harbor
        $primary = $pick($tokens, ['--color-primary', '--weline-theme-primary'], '#b84a3c');
        $primaryDark = $pick($tokens, ['--color-primary-dark', '--color-primary-hover'], '#963b30');
        $onPrimary = $pick($tokens, ['--color-on-primary', '--color-text-inverse'], '#f7f4ef');
        $headerBg = $pick($tokens, ['--color-bg-dark-secondary', '--weline-chrome-bg-dark', '--color-bg-dark'], '#16181a');
        $headerText = $pick($tokens, ['--color-text-inverse', '--color-text-on-dark', '--color-on-primary'], '#f7f4ef');
        $headerMuted = $pick($tokens, ['--color-text-on-dark-muted', '--color-text-light-secondary'], '#d4cfc5');
        $canvas = $pick($tokens, ['--color-bg-canvas', '--color-bg-secondary'], '#f7f4ef');
        $surface = $pick($tokens, ['--color-bg-primary', '--color-surface'], '#fffefa');
        $border = $pick($tokens, ['--color-border-default', '--color-border-subtle'], '#d4cfc5');
        $text = $pick($tokens, ['--color-text', '--color-text-primary'], '#1c1c1c');
        $muted = $pick($tokens, ['--color-text-muted', '--color-text-secondary'], '#5c5a56');
        $link = $pick($tokens, ['--color-link', '--color-primary'], $primary);
        $footerHeading = $pick($tokens, ['--color-primary-text-emphasis', '--color-primary-dark'], '#7a3028');
        $footerBg = $pick($tokens, ['--color-primary-bg-subtle', '--color-bg-secondary'], '#f8ede9');

        return [
            'brand_primary' => $primary,
            'brand_primary_dark' => $primaryDark,
            'brand_on_primary' => $onPrimary,
            'brand_header_bg' => $headerBg,
            'brand_header_text' => $headerText,
            'brand_header_muted' => $headerMuted,
            'brand_accent' => $primary,
            'brand_canvas' => $canvas,
            'brand_surface' => $surface,
            'brand_border' => $border,
            'brand_text' => $text,
            'brand_muted' => $muted,
            'brand_link' => $link,
            'brand_footer_heading' => $footerHeading,
            'brand_footer_bg' => $footerBg,
        ];
    }

    /**
     * @return array<string, string> CSS 变量名 => 字面 hex
     */
    private function loadThemeColorHexMap(string $storageScope): array
    {
        $map = [];
        $themeRoot = $this->themeFrontendRoot();
        if ($themeRoot === '') {
            return $map;
        }

        $baseCss = $themeRoot . '/variables/_colors.css';
        $map = $this->parseLiteralHexTokens($baseCss);

        $disk = $this->resolveActiveColorDisk($storageScope);
        $candidates = array_values(array_unique(array_filter([
            $disk,
            'ink',
            'default',
        ])));
        foreach ($candidates as $name) {
            $path = $themeRoot . '/colors/_' . $name . '.css';
            if (!is_file($path)) {
                continue;
            }
            $map = array_merge($map, $this->parseLiteralHexTokens($path));
            break;
        }

        return $map;
    }

    private function themeFrontendRoot(): string
    {
        try {
            $ref = new \ReflectionClass(ThemeBrandResolver::class);
            $themeModule = dirname($ref->getFileName() ?: '', 2);
            $root = $themeModule . '/view/theme/frontend';
            if (is_dir($root)) {
                return $root;
            }
        } catch (\Throwable) {
        }

        return '';
    }

    private function resolveActiveColorDisk(string $storageScope): string
    {
        try {
            if (class_exists(\Weline\Theme\Helper\ThemeData::class)) {
                $cfg = \Weline\Theme\Helper\ThemeData::getColorConfig('frontend', 'default');
                $disk = trim((string)($cfg ?? ''));
                $disk = preg_replace('/[^a-zA-Z0-9_-]/', '', $disk) ?? '';
                if ($disk !== '') {
                    return $disk;
                }
            }
        } catch (\Throwable) {
        }

        // 前台品牌叶默认 ink（朱砂/宣纸），与 ThemeColorMode 契约一致
        return 'ink';
    }

    /**
     * @return array<string, string>
     */
    private function parseLiteralHexTokens(string $cssPath): array
    {
        if (!is_file($cssPath)) {
            return [];
        }
        $css = (string)file_get_contents($cssPath);
        $out = [];
        if (preg_match_all('/(--[\w-]+)\s*:\s*(#[0-9a-fA-F]{3,8})\s*;/', $css, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $out[$row[1]] = $row[2];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $vars
     * @return array<string, mixed>
     */
    public function mergeInto(array $vars, string $storageScope, string $locale = ''): array
    {
        $brand = $this->resolve($storageScope, $locale);
        // 调用方显式传入的同名变量优先（调用方亦可自带已按 locale 译好的文案）
        return array_merge($brand, $vars);
    }

    /**
     * 后台编辑页实时预览样本：站店渠已解析值优先；渠道 sample 中的占位主机（example.com）改写为当前范围 site_url。
     * 传入邮件 locale 时，渠道 sample 文案与可译品牌字段按该语种译写。
     *
     * @param list<array{code?:string,sample?:string}> $variables
     * @return array<string, string>
     */
    public function buildPreviewSamples(array $variables, string $storageScope, string $locale = ''): array
    {
        $brand = $this->resolve($storageScope, $locale);
        $siteUrl = rtrim((string)($brand['site_url'] ?? ''), '/');
        $samples = [];
        foreach ($variables as $var) {
            if (!is_array($var)) {
                continue;
            }
            $code = trim((string)($var['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            if (array_key_exists($code, $brand) && trim((string)$brand[$code]) !== '') {
                $samples[$code] = (string)$brand[$code];
                continue;
            }
            $sample = (string)($var['sample'] ?? '');
            $samples[$code] = $this->localizePreviewText(
                $this->scopeLockPreviewSample($sample, $siteUrl, $brand),
                $locale
            );
        }

        return $samples;
    }

    /**
     * @param array<string, string> $brand
     * @return array<string, string>
     */
    private function localizeBrandStrings(array $brand, string $locale): array
    {
        $locale = trim($locale);
        if ($locale === '') {
            return $brand;
        }
        foreach (['site_name', 'store_name', 'channel_name', 'site_description', 'service_hours', 'contact_address'] as $key) {
            if (!isset($brand[$key]) || !is_string($brand[$key])) {
                continue;
            }
            $brand[$key] = $this->localizePreviewText((string)$brand[$key], $locale);
        }

        return $brand;
    }

    private function localizePreviewText(string $text, string $locale): string
    {
        $text = trim($text);
        $locale = trim($locale);
        if ($text === '' || $locale === '') {
            return $text;
        }
        // URL / 邮箱 / 色值 / 纯代码不走词典
        if (preg_match('#^(https?://|mailto:|/)#i', $text) === 1
            || str_contains($text, '@')
            || preg_match('/^#[0-9a-fA-F]{3,8}$/', $text) === 1
            || preg_match('/^[a-z][a-z0-9_]{2,64}$/i', $text) === 1
        ) {
            return $text;
        }
        // 复合站店名里常夹着「默认网站」「默认店铺」
        foreach (['默认网站', '默认店铺', '默认渠道'] as $phrase) {
            if (!str_contains($text, $phrase)) {
                continue;
            }
            $part = $this->translateMailPhrase($phrase, $locale);
            if ($part !== '' && $part !== $phrase) {
                $text = str_replace($phrase, $part, $text);
            }
        }

        return $this->translateMailPhrase($text, $locale);
    }

    private function translateMailPhrase(string $text, string $locale): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        try {
            /** @var TranslationResolverInterface $resolver */
            $resolver = ObjectManager::getInstance(TranslationResolverInterface::class);
            $translated = trim($resolver->translate(
                $text,
                $locale,
                ['Weline_Smtp', 'Weline_Backend', 'Weline_Websites', 'Weline_Framework', 'Weline_Theme']
            ));
            if ($translated !== '') {
                return $translated;
            }
        } catch (\Throwable) {
            // CLI/单测无容器时保留源文
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $brand
     */
    private function scopeLockPreviewSample(string $sample, string $siteUrl, array $brand): string
    {
        $sample = trim($sample);
        if ($sample === '') {
            return '';
        }

        // 邮箱样例：优先用范围联系邮箱（仍带示例本地名时保留路径语义）
        if (str_contains($sample, '@') && !preg_match('#^https?://#i', $sample)) {
            $contact = trim((string)($brand['contact_email'] ?? ''));
            if ($contact !== '' && preg_match('/@example\\.com$/i', $sample) === 1) {
                return $contact;
            }

            return $sample;
        }

        if ($siteUrl === '') {
            return $sample;
        }

        if (str_starts_with($sample, '/')) {
            $path = $this->sanitizePreviewPlaceholder($sample);

            return $siteUrl . $path;
        }

        if (preg_match('#^https?://#i', $sample) !== 1) {
            return $sample;
        }

        // 避免 parse_url 弄坏 query 中的 UTF-8（如 …）；分隔符不用 #，以免与 fragment 字符类冲突
        if (preg_match('~^https?://([^/?#]+)([/?#].*)?$~i', $sample, $m) === 1) {
            $hostPort = strtolower((string)$m[1]);
            $hostOnly = explode(':', $hostPort, 2)[0];
            if ($hostOnly === 'example.com' || str_ends_with($hostOnly, '.example.com')) {
                $rest = (string)($m[2] ?? '');

                return $this->sanitizePreviewPlaceholder($siteUrl . $rest);
            }
        }

        return $sample;
    }

    private function sanitizePreviewPlaceholder(string $value): string
    {
        $out = preg_replace('/(?:\x{2026}|\x{22EF}|\.{3}|…)/u', 'preview', $value);
        $value = is_string($out) ? $out : $value;
        // token= 预览占位统一成 preview，避免 UTF-8 损坏显示为 �
        $tok = preg_replace('/([?&]token=)[^\s<&"\']*/i', '$1preview', $value);
        $value = is_string($tok) ? $tok : $value;
        if (!mb_check_encoding($value, 'UTF-8')) {
            $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            $value = is_string($clean) ? $clean : '';
        }

        return $value;
    }

    /**
     * @return array{site_name:string,site_description:string,contact_email:string,contact_phone:string,service_hours:string,contact_address:string}
     */
    private function resolveContact(): array
    {
        try {
            /** @var SiteContactInfo $info */
            $info = ObjectManager::getInstance(SiteContactInfo::class);

            return $info->resolve();
        } catch (\Throwable) {
            return [
                'site_name' => 'Weline',
                'site_description' => (string)__('官方商城客户服务'),
                'contact_email' => 'support@example.com',
                'contact_phone' => '',
                'service_hours' => (string)__('周一至周五 9:00 - 18:00'),
                'contact_address' => '',
            ];
        }
    }

    /**
     * @return array{site_name:string,store_name:string,channel_name:string,site_url:string}
     */
    private function resolveScopeNames(string $storageScope): array
    {
        $out = [
            'site_name' => '',
            'store_name' => '',
            'channel_name' => '',
            'site_url' => '',
        ];
        $scope = trim($storageScope) !== '' ? trim($storageScope) : SystemConfig::SCOPE_GLOBAL;
        $parts = explode('.', $scope);
        $websiteCode = trim((string)($parts[0] ?? ''));
        $storeCode = trim((string)($parts[1] ?? ''));
        $channelCode = trim((string)($parts[2] ?? ''));

        if ($websiteCode === '' || $websiteCode === 'default' || str_starts_with($websiteCode, '__')) {
            // Global：仍尝试 default 站实体
            $websiteCode = 'default';
        }

        try {
            /** @var Website $website */
            $website = ObjectManager::getInstance(Website::class);
            $row = $website->clear()->where(Website::schema_fields_CODE, $websiteCode)->find()->fetch();
            if ($row && $row->getId() !== null && $row->getId() !== '') {
                $out['site_name'] = trim((string)$row->getData(Website::schema_fields_NAME));
                $websiteId = (int)$row->getData(Website::schema_fields_ID);
                $fallbackUrl = $this->normalizeAbsoluteUrl((string)$row->getData(Website::schema_fields_URL));
                $out['site_url'] = $this->resolvePublicSiteUrl($websiteId, $fallbackUrl);

                if ($storeCode !== '' && $storeCode !== 'default' && !str_starts_with($storeCode, '__')) {
                    /** @var Store $store */
                    $store = ObjectManager::getInstance(Store::class);
                    $s = $store->clear()
                        ->where(Store::schema_fields_WEBSITE_ID, $websiteId)
                        ->where(Store::schema_fields_CODE, $storeCode)
                        ->find()
                        ->fetch();
                    if ($s && $s->getId() !== null && $s->getId() !== '') {
                        $out['store_name'] = trim((string)$s->getData(Store::schema_fields_NAME));
                        $storeId = (int)$s->getData(Store::schema_fields_ID);
                        if ($channelCode !== '' && $channelCode !== 'default' && !str_starts_with($channelCode, '__')) {
                            /** @var SalesChannel $channel */
                            $channel = ObjectManager::getInstance(SalesChannel::class);
                            $c = $channel->clear()
                                ->where(SalesChannel::schema_fields_STORE_ID, $storeId)
                                ->where(SalesChannel::schema_fields_CODE, $channelCode)
                                ->find()
                                ->fetch();
                            if ($c && $c->getId() !== null && $c->getId() !== '') {
                                $out['channel_name'] = trim((string)$c->getData(SalesChannel::schema_fields_NAME));
                            }
                        }
                    }
                } elseif ($storeCode === 'default' || $storeCode === '' || str_starts_with($storeCode, '__')) {
                    /** @var Store $store */
                    $store = ObjectManager::getInstance(Store::class);
                    $s = $store->clear()
                        ->where(Store::schema_fields_WEBSITE_ID, $websiteId)
                        ->where(Store::schema_fields_CODE, Store::CODE_DEFAULT)
                        ->find()
                        ->fetch();
                    if ($s && $s->getId() !== null && $s->getId() !== '') {
                        $out['store_name'] = trim((string)$s->getData(Store::schema_fields_NAME));
                    }
                }
            }
        } catch (\Throwable) {
        }

        return $out;
    }

    private function resolveLogoAbsoluteUrl(string $storageScope, string $siteUrl): string
    {
        // 对齐前台：Theme appearance brand 按 Scope 上溯（website→store→channel），
        // 再 Backend SiteBrand media；禁止回退 Theme 包默认 logo.svg/png（非商家前台标）。
        $path = $this->resolvePublishedBrandLogoPath($storageScope);
        if ($path === '') {
            $path = $this->resolveBackendConfiguredLogoPath();
        }
        if ($path === '' || $this->isThemePackageDefaultLogo($path)) {
            return '';
        }

        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        // 与前台 header 一致：已是 /pub/media/... 则直接拼站址，勿经 MediaUrl 改写为 /media/image/
        if (str_starts_with($path, '/pub/media/') || str_starts_with($path, 'pub/media/')) {
            $rel = str_starts_with($path, '/') ? $path : '/' . $path;
            if (is_file(BP . $rel) || is_file(BP . '/' . ltrim($path, '/'))) {
                return $this->joinBaseUrl($siteUrl, $rel);
            }

            return $this->joinBaseUrl($siteUrl, $rel);
        }

        // 优先可静态访问的主题资源（仅非包默认商家自定义静态路径）
        if (str_contains($path, '/Theme/view/theme/') && !$this->isThemePackageDefaultLogo($path)) {
            $staticRel = '/pub/static' . (str_starts_with($path, '/') ? $path : '/' . $path);
            if (is_file(BP . $staticRel) || is_file(BP . '/app/code' . (str_starts_with($path, '/') ? $path : '/' . $path))) {
                return $this->joinBaseUrl($siteUrl, $staticRel);
            }
        }

        try {
            $media = MediaUrl::fromPath($path);
            if (is_string($media) && $media !== '' && preg_match('#^https?://#i', $media)) {
                return $media;
            }
            if (is_string($media) && $media !== '') {
                return $this->joinBaseUrl($siteUrl, $media);
            }
        } catch (\Throwable) {
        }

        return $this->joinBaseUrl($siteUrl, $path);
    }

    /**
     * Theme appearance brand 按 Scope 回退链上溯（近→远），取首个非空 logo。
     * channel 空 brand 不得遮蔽 website 已发布商家 logo。
     */
    private function resolvePublishedBrandLogoPath(string $storageScope): string
    {
        try {
            /** @var SystemConfigScopeResolver $scopes */
            $scopes = ObjectManager::getInstance(SystemConfigScopeResolver::class);
            /** @var ThemeBrandResolver $resolver */
            $resolver = ObjectManager::getInstance(ThemeBrandResolver::class);

            $identity = $scopes->fromStorageScope($storageScope !== '' ? $storageScope : SystemConfig::SCOPE_GLOBAL);
            try {
                /** @var ScopeIdentityCatalogInterface $catalog */
                $catalog = ObjectManager::getInstance(ScopeIdentityCatalogInterface::class);
                $identity = $catalog->authoritativeIdentity($identity);
            } catch (\Throwable) {
            }
            $scopeCtx = $scopes->contextFromIdentity($identity);
            $candidates = $scopeCtx->fallbackStorageScopes;
            if ($candidates === []) {
                $candidates = [$storageScope !== '' ? $storageScope : SystemConfig::SCOPE_GLOBAL];
            }
            // Global 哨兵 default.default.default 无 website 父级；前台 brand 常发布在
            // {website}.__website__.default，需显式并入候选（与店面 header/JSON-LD 一致）。
            $websiteCode = trim((string)($identity->websiteCode ?? ''));
            if ($websiteCode === '' && preg_match('/^([a-z0-9_-]+)\./i', $storageScope, $m) === 1) {
                $websiteCode = strtolower((string)$m[1]);
            }
            if ($websiteCode === '') {
                $websiteCode = 'default';
            }
            $websiteBrandScope = strtolower($websiteCode) . '.' . SystemConfigScopeResolver::WEBSITE_DEFAULT_SENTINEL . '.default';
            if (!in_array($websiteBrandScope, $candidates, true)) {
                $candidates[] = $websiteBrandScope;
            }

            foreach ($candidates as $scopeKey) {
                $scopeKey = trim((string)$scopeKey);
                if ($scopeKey === '') {
                    continue;
                }
                try {
                    $id = $scopes->fromStorageScope($scopeKey);
                    try {
                        /** @var ScopeIdentityCatalogInterface $catalog */
                        $catalog = ObjectManager::getInstance(ScopeIdentityCatalogInterface::class);
                        $id = $catalog->authoritativeIdentity($id);
                    } catch (\Throwable) {
                    }
                    $ctx = $scopes->contextFromIdentity($id);
                    $brand = $resolver->resolvePublishedBrand('frontend', null, $ctx, false);
                    $path = trim((string)($brand['logo_light'] ?? ''));
                    if ($path === '') {
                        $path = trim((string)($brand['logo_dark'] ?? ''));
                    }
                    if ($path !== '' && !$this->isThemePackageDefaultLogo($path)) {
                        return $path;
                    }
                } catch (\Throwable) {
                    continue;
                }
            }
        } catch (\Throwable) {
        }

        return '';
    }

    private function resolveBackendConfiguredLogoPath(): string
    {
        try {
            /** @var SiteBrand $siteBrand */
            $siteBrand = ObjectManager::getInstance(SiteBrand::class);
            foreach (['logo_light', 'logo_dark'] as $key) {
                $raw = trim($siteBrand->getRawConfig($key));
                if ($raw !== '' && !$this->isThemePackageDefaultLogo($raw)) {
                    return $raw;
                }
                $media = trim($siteBrand->resolveMediaUrl($key, 240, 80));
                if ($media !== '' && !$this->isThemePackageDefaultLogo($media)) {
                    return $media;
                }
            }
        } catch (\Throwable) {
        }

        return '';
    }

    private function isThemePackageDefaultLogo(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);

        return (bool)preg_match(
            '#(?:^|/)Weline/Theme/view/theme/frontend/assets/images/theme/logo\.(?:svg|png)(?:\?|$)#i',
            $normalized
        ) || (bool)preg_match(
            '#/pub/static/Weline/Theme/view/theme/frontend/assets/images/theme/logo\.(?:svg|png)(?:\?|$)#i',
            $normalized
        );
    }

    /**
     * 从 WebsiteDomain 选真实可访问公网站址（偏好 *.test.weline.com；贬低 localhost）。
     */
    private function resolvePublicSiteUrl(int $websiteId, string $fallbackUrl): string
    {
        $requestOrigin = $this->resolveMatchingRequestOrigin($websiteId);
        if ($requestOrigin !== '') {
            return rtrim($requestOrigin, '/');
        }

        $best = '';
        $bestScore = PHP_INT_MIN;
        try {
            /** @var WebsiteDomain $domainModel */
            $domainModel = ObjectManager::getInstance(WebsiteDomain::class);
            $rows = $domainModel->getWebsiteDomains($websiteId);
            if (!is_array($rows)) {
                $rows = [];
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $status = strtolower(trim((string)($row[WebsiteDomain::schema_fields_STATUS] ?? 'active')));
                if ($status !== '' && $status !== 'active') {
                    continue;
                }
                $host = strtolower(trim((string)($row[WebsiteDomain::schema_fields_DOMAIN] ?? '')));
                if ($host === '') {
                    continue;
                }
                $sub = trim((string)($row[WebsiteDomain::schema_fields_SUB_PATH] ?? ''));
                if ($sub !== '' && !str_starts_with($sub, '/')) {
                    $sub = '/' . $sub;
                }
                $https = !empty($row[WebsiteDomain::schema_fields_HTTPS_ENABLED]);
                $primary = !empty($row[WebsiteDomain::schema_fields_IS_PRIMARY]);
                $score = $this->scorePublicHost($host, $primary, $https);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $scheme = $https ? 'https' : 'http';
                    $best = $scheme . '://' . $host . $sub;
                }
            }
        } catch (\Throwable) {
        }

        if ($best !== '' && $bestScore >= 0) {
            return $this->normalizeAbsoluteUrl($this->appendDevPortIfNeeded($best));
        }

        return $fallbackUrl;
    }

    private function scorePublicHost(string $host, bool $primary, bool $https): int
    {
        $score = 0;
        if ($host === 'localhost' || $host === '127.0.0.1' || str_ends_with($host, '.local')) {
            return -100;
        }
        if (str_ends_with($host, '.test.weline.com')) {
            $score += 100;
        } elseif (str_ends_with($host, '.weline.test')) {
            $score += 40;
        } else {
            $score += 20;
        }
        if ($primary) {
            $score += 15;
        }
        if ($https) {
            $score += 10;
        }

        return $score;
    }

    /**
     * 后台预览时若当前请求 Host 属于该站域名，优先用请求 Origin（含端口）。
     */
    private function resolveMatchingRequestOrigin(int $websiteId): string
    {
        $httpHost = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($httpHost === '') {
            return '';
        }
        $reqHost = strtolower(explode(':', $httpHost, 2)[0]);
        if ($reqHost === '') {
            return '';
        }
        try {
            /** @var WebsiteDomain $domainModel */
            $domainModel = ObjectManager::getInstance(WebsiteDomain::class);
            $rows = $domainModel->getWebsiteDomains($websiteId);
            if (!is_array($rows)) {
                return '';
            }
            $matched = false;
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $host = strtolower(trim((string)($row[WebsiteDomain::schema_fields_DOMAIN] ?? '')));
                if ($host !== '' && $host === $reqHost) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return '';
            }
        } catch (\Throwable) {
            return '';
        }

        $https = false;
        $fwd = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($fwd === 'https' || str_starts_with($fwd, 'https,')) {
            $https = true;
        } elseif (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            $https = true;
        } elseif ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') {
            $https = true;
        }

        return ($https ? 'https://' : 'http://') . $httpHost;
    }

    private function appendDevPortIfNeeded(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !empty($parts['port'])) {
            return $url;
        }
        $host = strtolower((string)($parts['host'] ?? ''));
        if ($host === '' || (!str_ends_with($host, '.test.weline.com') && !str_ends_with($host, '.weline.test'))) {
            return $url;
        }
        $reqHost = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($reqHost !== '' && str_contains($reqHost, ':')) {
            $port = (int)explode(':', $reqHost, 2)[1];
            if ($port > 0 && $port !== 80 && $port !== 443) {
                $scheme = (string)($parts['scheme'] ?? 'https');
                $path = (string)($parts['path'] ?? '');

                return $scheme . '://' . $host . ':' . $port . $path;
            }
        }

        return $url;
    }

    private function normalizeContactEmail(string $email, string $siteUrl): string
    {
        $email = trim($email);
        if ($email === '' || preg_match('/@example\\.com$/i', $email) !== 1) {
            return $email;
        }
        $host = strtolower((string)(parse_url($siteUrl, PHP_URL_HOST) ?? ''));
        if ($host === '' || $host === 'localhost' || $host === '127.0.0.1') {
            return '';
        }
        // 占位邮箱 → 用真实站域生成可识别预览联系址
        return 'support@' . $host;
    }

    private function normalizeAbsoluteUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        return rtrim($url, '/');
    }

    private function joinBaseUrl(string $base, string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        if ($base === '') {
            return $path;
        }

        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}
