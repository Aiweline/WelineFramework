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
            public function resolve(string $storageScope): array
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
            public function resolve(string $storageScope): array
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

    public function testPaletteResolvesThemeHexTokens(): void
    {
        $svc = new MailBrandContextService();
        $palette = $svc->resolvePalette('default.default.default');
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $palette['brand_primary']);
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $palette['brand_header_bg']);
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $palette['brand_canvas']);
        // 对齐 Theme ink/朱砂，而非历史 Ink Harbor 蓝绿琥珀
        self::assertNotSame('#16333f', $palette['brand_header_bg']);
        self::assertNotSame('#e8a14a', $palette['brand_accent']);
        self::assertSame('#b84a3c', $palette['brand_primary']);
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

        $resolved = $svc->resolve('default.default.default');
        self::assertNotSame('http://localhost', $resolved['site_url']);
        self::assertStringNotContainsString('example.com', (string)$resolved['contact_email']);
        self::assertStringNotContainsString('://localhost/', (string)$resolved['site_logo_url']);
        self::assertMatchesRegularExpression('#^https?://#i', (string)$resolved['site_logo_url']);
        self::assertStringNotContainsString('/theme/frontend/assets/images/theme/logo.', (string)$resolved['site_logo_url']);
        self::assertStringContainsString('/pub/media/', (string)$resolved['site_logo_url']);
        if (str_contains((string)$resolved['site_url'], 'test.weline.com') || str_contains((string)$resolved['site_url'], 'weline.test')) {
            self::assertStringContainsString('p05113ef3', (string)$resolved['site_url']);
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

    public function testSendPathMergesBrandCodes(): void
    {
        $provider = (string)file_get_contents(dirname(__DIR__, 2) . '/extends/module/Weline_Framework/Query/SmtpQueryProvider.php');
        self::assertStringContainsString('MailBrandContextService', $provider);
        self::assertStringContainsString('mergeInto', $provider);
        self::assertStringContainsString('variableCodes', $provider);

        $controller = (string)file_get_contents(dirname(__DIR__, 2) . '/Controller/Backend/Template.php');
        self::assertStringContainsString('variableDefinitions', $controller);
    }
}
