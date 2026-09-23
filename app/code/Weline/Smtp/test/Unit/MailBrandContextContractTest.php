<?php

declare(strict_types=1);

namespace Weline\Smtp\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Smtp\Service\MailBrandContextService;
use Weline\Smtp\Service\MailTemplateRenderer;

/**
 * 邮件品牌上下文 + 协议安全契约。
 */
final class MailBrandContextContractTest extends TestCase
{
    public function testVariableCatalogAndMergePreferCaller(): void
    {
        $codes = MailBrandContextService::variableCodes();
        self::assertContains('site_name', $codes);
        self::assertContains('site_logo_img', $codes);
        self::assertContains('contact_email', $codes);
        self::assertContains('brand_primary', $codes);
        self::assertContains('brand_header_bg', $codes);
        self::assertContains('brand_canvas', $codes);

        $defs = MailBrandContextService::variableDefinitions();
        self::assertNotEmpty($defs);
        self::assertSame('site_name', $defs[0]['code']);

        $svc = new class extends MailBrandContextService {
            public function resolve(string $storageScope, string $locale = ''): array
            {
                return [
                    'site_name' => 'FromBrand',
                    'store_name' => 'StoreA',
                    'channel_name' => '',
                    'brand_display_name' => 'StoreA',
                    'site_url' => 'https://example.com',
                    'site_logo_url' => 'https://example.com/logo.png',
                    'site_logo_img' => '<img src="https://example.com/logo.png" alt="StoreA">',
                    'site_description' => 'desc',
                    'contact_email' => 'a@example.com',
                    'contact_phone' => '',
                    'contact_address' => '',
                    'service_hours' => '9-18',
                ];
            }
        };
        $merged = $svc->mergeInto(['site_name' => 'CallerWins', 'reset_url' => 'https://x'], 'shop.default.default');
        self::assertSame('CallerWins', $merged['site_name']);
        self::assertSame('StoreA', $merged['store_name']);
        self::assertSame('https://x', $merged['reset_url']);
    }

    public function testBuildPreviewSamplesLocksUrlsToScopeSite(): void
    {
        $svc = new class extends MailBrandContextService {
            public function resolve(string $storageScope, string $locale = ''): array
            {
                return [
                    'site_name' => '默认网站',
                    'store_name' => '默认店铺',
                    'channel_name' => '',
                    'brand_display_name' => '默认店铺',
                    'site_url' => 'https://p05113ef3.test.weline.com:9555',
                    'site_logo_url' => 'https://p05113ef3.test.weline.com:9555/logo.png',
                    'site_logo_img' => '<img src="https://p05113ef3.test.weline.com:9555/logo.png" alt="x">',
                    'site_description' => 'desc',
                    'contact_email' => 'support@shop.local',
                    'contact_phone' => '',
                    'contact_address' => '',
                    'service_hours' => '9-18',
                ];
            }
        };

        $samples = $svc->buildPreviewSamples([
            ['code' => 'reset_url', 'sample' => 'https://example.com/reset?token=...'],
            ['code' => 'customer_email', 'sample' => 'user@example.com'],
            ['code' => 'site_url', 'sample' => 'https://example.com'],
            ['code' => 'verification_url', 'sample' => 'https://example.com/bind/verify?token=...'],
            ['code' => 'relative_path', 'sample' => '/customer/account/forgot-password'],
        ], 'default.default.default');

        self::assertSame(
            'https://p05113ef3.test.weline.com:9555/reset?token=preview',
            $samples['reset_url']
        );
        self::assertSame('support@shop.local', $samples['customer_email']);
        self::assertSame('https://p05113ef3.test.weline.com:9555', $samples['site_url']);
        self::assertSame(
            'https://p05113ef3.test.weline.com:9555/bind/verify?token=preview',
            $samples['verification_url']
        );
        self::assertSame(
            'https://p05113ef3.test.weline.com:9555/customer/account/forgot-password',
            $samples['relative_path']
        );
        self::assertStringNotContainsString('example.com', $samples['reset_url']);
    }

    public function testBuildPreviewSamplesLocalizesChannelCopyByMailLocale(): void
    {
        $svc = new class extends MailBrandContextService {
            public function resolve(string $storageScope, string $locale = ''): array
            {
                return [
                    'site_name' => 'Store',
                    'store_name' => 'Store',
                    'channel_name' => '',
                    'brand_display_name' => 'Store',
                    'site_url' => 'https://p05113ef3.test.weline.com:9555',
                    'site_logo_url' => '',
                    'site_logo_img' => '',
                    'site_description' => '官方商城客户服务',
                    'contact_email' => 'support@shop.local',
                    'contact_phone' => '',
                    'contact_address' => '',
                    'service_hours' => '周一至周五 9:00 - 18:00',
                ];
            }
        };

        $samples = $svc->buildPreviewSamples([
            ['code' => 'title', 'sample' => '系统通知'],
            ['code' => 'type_label', 'sample' => '信息'],
            ['code' => 'content', 'sample' => '通知内容'],
            ['code' => 'topic_code', 'sample' => 'system_info'],
        ], 'default.default.default', 'en_US');

        self::assertSame('System notification', $samples['title']);
        self::assertSame('Information', $samples['type_label']);
        self::assertSame('Notification content', $samples['content']);
        self::assertSame('system_info', $samples['topic_code']);

        $brandSrc = (string)file_get_contents(dirname(__DIR__, 2) . '/Service/MailBrandContextService.php');
        self::assertStringContainsString('localizePreviewText', $brandSrc);
        self::assertStringContainsString('buildPreviewSamples(array $variables, string $storageScope, string $locale', $brandSrc);
        $controllerSrc = (string)file_get_contents(dirname(__DIR__, 2) . '/Controller/Backend/Template.php');
        self::assertStringContainsString('buildPreviewSamples($mergedVars, $editScope, $editLocale)', $controllerSrc);
    }

    public function testPaletteResolvesThemeHexTokens(): void
    {
        $svc = new MailBrandContextService();
        $palette = $svc->resolvePalette('default.default.default');
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $palette['brand_primary']);
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $palette['brand_header_bg']);
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $palette['brand_canvas']);
        // 对齐 Theme ink/朱砂或激活 design（hanfu-paper），而非历史 Ink Harbor 蓝绿琥珀
        self::assertNotSame('#16333f', $palette['brand_header_bg']);
        self::assertNotSame('#e8a14a', $palette['brand_accent']);
        self::assertContains($palette['brand_primary'], ['#b84a3c', '#a44535']);
    }

    public function testThemeFrontendRootPrefersActiveDesignTheme(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/Service/MailBrandContextService.php');
        self::assertStringContainsString("app/design/", $src);
        self::assertStringContainsString('--mail-header-text', $src);
        self::assertStringContainsString('hanfu-paper', $src);

        $svc = new MailBrandContextService();
        $palette = $svc->resolvePalette('default.default.default');
        // 本机激活 hanfu 时页头字取 --mail-header-text 朱砂橙
        if (is_dir(dirname(__DIR__, 5) . '/design/Weline/hanfu/frontend')) {
            $themePath = '';
            try {
                $theme = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Theme\Model\WelineTheme::class);
                $theme->clearData()->clearQuery()->getActiveTheme('frontend');
                $themePath = (string)$theme->getData('path');
            } catch (\Throwable) {
            }
            if (str_contains(strtolower($themePath), 'hanfu')) {
                self::assertSame('#ff5d05', $palette['brand_header_text']);
                self::assertSame('#e91b0c', $palette['brand_header_muted']);
                self::assertSame('#16181a', $palette['brand_header_bg']);
            }
        }
    }

    public function testRendererStripsScriptAndEvents(): void
    {
        $r = new MailTemplateRenderer();
        $out = $r->stripScripts('<p onclick="alert(1)">a</p><script>evil()</script><a href="javascript:alert(1)">x</a>');
        self::assertStringNotContainsString('<script', $out);
        self::assertStringNotContainsString('onclick', $out);
        self::assertStringNotContainsString('javascript:', strtolower($out));
    }

    public function testResolvePrefersPublicWebsiteDomain(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/Service/MailBrandContextService.php');
        self::assertStringContainsString('function resolvePublicSiteUrl', $src);
        self::assertStringContainsString('test.weline.com', $src);
        self::assertStringContainsString('WebsiteDomain', $src);
        self::assertStringContainsString('function normalizeContactEmail', $src);

        $svc = new MailBrandContextService();
        $ref = new \ReflectionClass($svc);
        $score = $ref->getMethod('scorePublicHost');
        $score->setAccessible(true);
        self::assertGreaterThan(
            (int)$score->invoke($svc, 'p05113ef3.weline.test', true, true),
            (int)$score->invoke($svc, 'p05113ef3.test.weline.com', false, true)
        );
        self::assertLessThan(0, (int)$score->invoke($svc, 'localhost', true, false));
        self::assertLessThan(0, (int)$score->invoke($svc, 'e2e-test-1783791034.weline.test', true, true));
        self::assertStringContainsString('isUndeliverableMailPublicHost', $src);
        self::assertStringContainsString('resolveManagedLocalPublicSiteUrl', $src);

        $resolved = $svc->resolve('default.default.default');
        self::assertNotSame('http://localhost', $resolved['site_url']);
        self::assertStringNotContainsString('example.com', (string)$resolved['contact_email']);
        self::assertStringNotContainsString('://localhost/', (string)$resolved['site_logo_url']);
        self::assertMatchesRegularExpression('#^https?://#i', (string)$resolved['site_logo_url']);
        self::assertStringNotContainsString('/theme/frontend/assets/images/theme/logo.', (string)$resolved['site_logo_url']);
        self::assertStringContainsString('/pub/media/', (string)$resolved['site_logo_url']);
        if (str_contains((string)$resolved['site_url'], 'test.weline.com') || str_contains((string)$resolved['site_url'], 'weline.test')) {
            self::assertStringContainsString('p05113ef3', (string)$resolved['site_url']);
            // CLI/Cron 无 HTTP_HOST 时也必须带本机 HTTPS 端口，否则邮件里 logo 裂图
            self::assertMatchesRegularExpression('#:(?:[1-9][0-9]{2,4})(/|$)#', (string)$resolved['site_url']);
            self::assertMatchesRegularExpression('#:(?:[1-9][0-9]{2,4})/#', (string)$resolved['site_logo_url']);
        }
    }

    public function testResolveLogoWalksWebsiteBrandNotThemeDefault(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/Service/MailBrandContextService.php');
        self::assertStringContainsString('function resolvePublishedBrandLogoPath', $src);
        self::assertStringContainsString('fallbackStorageScopes', $src);
        self::assertStringContainsString('isThemePackageDefaultLogo', $src);
        self::assertStringContainsString('WEBSITE_DEFAULT_SENTINEL', $src);

        $resolved = (new MailBrandContextService())->resolve('default.default.default');
        self::assertStringContainsString('/pub/media/websites/', (string)$resolved['site_logo_url']);
        self::assertStringNotContainsString('theme/logo.svg', (string)$resolved['site_logo_url']);
        self::assertStringContainsString('<img', (string)$resolved['site_logo_img']);
    }

    public function testResolveInjectsMailShellBackgroundAbsoluteUrls(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/Service/MailBrandContextService.php');
        self::assertStringContainsString('resolveMailShellBackgrounds', $src);
        self::assertStringContainsString('brand_header_bg_image', $src);
        self::assertStringContainsString('getMailShellBackgrounds', $src);

        $resolved = (new MailBrandContextService())->resolve('default.default.default');
        self::assertArrayHasKey('brand_header_bg_image', $resolved);
        self::assertArrayHasKey('brand_header_bg_css', $resolved);
        self::assertArrayHasKey('brand_body_bg_image', $resolved);
        self::assertArrayHasKey('brand_footer_bg_image', $resolved);
        self::assertStringContainsString('background-color:', (string)$resolved['brand_header_bg_css']);
        // 已配置壳背景时须注入绝对 /pub/media URL（邮件客户端直链原图）
        if (trim((string)$resolved['brand_header_bg_image']) !== '') {
            self::assertStringContainsString('/pub/media/', (string)$resolved['brand_header_bg_image']);
            self::assertMatchesRegularExpression('#^https?://#i', (string)$resolved['brand_header_bg_image']);
            self::assertStringContainsString('background-image:url(', (string)$resolved['brand_header_bg_css']);
        }
    }

    public function testSendPathMergesBrandCodes(): void
    {
        $provider = (string)file_get_contents(dirname(__DIR__, 2) . '/extends/module/Weline_Framework/Query/SmtpQueryProvider.php');
        self::assertStringContainsString('MailBrandContextService', $provider);
        self::assertStringContainsString('mergeInto', $provider);
        self::assertStringContainsString('variableCodes', $provider);
        self::assertStringContainsString('resolveFromDisplayName', $provider);
        self::assertStringContainsString('ensureBrandedSubject', $provider);

        $brandSrc = (string)file_get_contents(dirname(__DIR__, 2) . '/Service/MailBrandContextService.php');
        self::assertStringContainsString('brand_display_name', $brandSrc);
        self::assertStringContainsString('function ensureBrandedSubject', $brandSrc);
        self::assertStringContainsString('function resolveFromDisplayName', $brandSrc);

        $controller = (string)file_get_contents(dirname(__DIR__, 2) . '/Controller/Backend/Template.php');
        self::assertStringContainsString('variableDefinitions', $controller);
    }

    public function testResolveUsesWebsiteLocalDescriptionForMailLocale(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/Service/MailBrandContextService.php');
        self::assertStringContainsString('resolveWebsiteLocalFields', $src);
        self::assertStringContainsString('WebsiteLocalDescription', $src);
        self::assertStringContainsString('isChineseLocale', $src);

        $dataSrc = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Websites/Data/WebsiteData.php'
        );
        self::assertStringContainsString('function getDescriptionForLocale', $dataSrc);

        $seedSrc = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Websites/Service/WebsiteBrandIdentitySeedService.php'
        );
        self::assertStringContainsString('ensureDefaultWebsiteLocalBrandCopy', $seedSrc);
        self::assertFileExists(
            dirname(__DIR__, 3) . '/Websites/Service/data/website-brand-local-copy.v1.php'
        );
    }

    public function testArchitectureBrandFromAndSubjectPolicy(): void
    {
        $svc = new class extends MailBrandContextService {
            public function resolve(string $storageScope, string $locale = ''): array
            {
                return [
                    'site_name' => '长安汉服',
                    'store_name' => '长安汉服 · Hanfu Atelier',
                    'channel_name' => '',
                    'brand_display_name' => '长安汉服 · Hanfu Atelier',
                    'site_url' => 'https://example.com',
                    'site_logo_url' => '',
                    'site_logo_img' => '',
                    'site_description' => '',
                    'contact_email' => '',
                    'contact_phone' => '',
                    'contact_address' => '',
                    'service_hours' => '',
                ];
            }
        };

        self::assertSame(
            '长安汉服 · Hanfu Atelier',
            $svc->resolveFromDisplayName('default', 'default', 'aiweline@qq.com', 'default.default.default')
        );
        self::assertSame(
            '自定义店',
            $svc->resolveFromDisplayName('自定义店', 'default', 'aiweline@qq.com', 'default.default.default')
        );

        $brand = ['brand_display_name' => '长安汉服 · Hanfu Atelier'];
        self::assertSame(
            '长安汉服 · Hanfu Atelier · 订单已支付',
            $svc->ensureBrandedSubject('订单已支付', $brand)
        );
        self::assertSame(
            '长安汉服 · Hanfu Atelier · 订阅礼遇',
            $svc->ensureBrandedSubject('长安汉服 · Hanfu Atelier · 订阅礼遇', $brand)
        );
        self::assertSame('', $svc->ensureBrandedSubject('', $brand));
    }
}
