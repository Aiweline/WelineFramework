<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteProfileAiGenerationService;
use GuoLaiRen\PageBuilder\Service\AiSiteProfileGenerationService;
use PHPUnit\Framework\TestCase;

class AiSiteProfileGenerationServiceTest extends TestCase
{
    private const LEGACY_PLACEHOLDER_SVG = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxNjAiIGhlaWdodD0iNDgiIHZpZXdCb3g9IjAgMCAxNjAgNDgiPgogIDxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIHJ4PSIxMCIgZmlsbD0iIzBmMTcyYSIvPgogIDx0ZXh0IHg9IjUwJSIgeT0iNTAlIiBkb21pbmFudC1iYXNlbGluZT0iY2VudHJhbCIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZm9udC1mYW1pbHk9IkFyaWFsLCBzYW5zLXNlcmlmIiBmb250LXNpemU9IjEyIiBmb250LXdlaWdodD0iNzAwIiBmaWxsPSIjZmZmZmZmIj5BUzwvdGV4dD4KPC9zdmc+';

    public function testGeneratePrefersTopLevelScopeValuesOverStaleWebsiteProfileSnapshot(): void
    {
        $service = new AiSiteProfileGenerationService();

        $profile = $service->generate([
            'site_title' => 'Canonical Virtual Theme Flow',
            'site_tagline' => 'Fresh tagline',
            'brief_description' => 'Fresh brief',
            'target_domain' => 'canonical-flow.local.test',
            'site_profile_manual' => [
                'site_title' => true,
                'site_tagline' => true,
                'brief_description' => true,
                'target_domain' => true,
            ],
            'website_profile' => [
                'site_title' => 'AI Site',
                'site_tagline' => '',
                'brief_description' => '',
                'target_domain' => '',
            ],
        ]);

        self::assertSame('Canonical Virtual Theme Flow', $profile['site_title']);
        self::assertSame('Fresh tagline', $profile['site_tagline']);
        self::assertSame('Fresh brief', $profile['brief_description']);
        self::assertSame('canonical-flow.local.test', $profile['target_domain']);
    }

    public function testGenerateFallsBackToExistingWebsiteProfileWhenScopeOmitsValues(): void
    {
        $service = new AiSiteProfileGenerationService();

        $profile = $service->generate([
            'website_profile' => [
                'site_title' => 'Existing Title',
                'site_tagline' => 'Existing Tagline',
                'brief_description' => 'Existing brief',
                'target_domain' => 'existing.local.test',
                'default_locale' => 'zh_Hans_CN',
                'locales' => ['zh_Hans_CN', 'en_US'],
            ],
        ]);

        self::assertSame('Existing Title', $profile['site_title']);
        self::assertSame('Existing Tagline', $profile['site_tagline']);
        self::assertSame('Existing brief', $profile['brief_description']);
        self::assertSame('existing.local.test', $profile['target_domain']);
        self::assertSame('zh_Hans_CN', $profile['default_locale']);
        self::assertSame(['zh_Hans_CN', 'en_US'], $profile['locales']);
    }

    public function testGeneratePrefersExplicitTargetDomainOverPreviewHost(): void
    {
        $service = new AiSiteProfileGenerationService();

        $profile = $service->generate([
            'target_domain' => 'apk-cookie-56a3cf.weline.test',
            'preview_full_url' => 'https://p11005ce4.weline.test:9502/pagebuilder/backend/ai-site-agent/workspace-preview',
            'website_profile' => [
                'target_domain' => 'p11005ce4.weline.test',
            ],
        ]);

        self::assertSame('apk-cookie-56a3cf.weline.test', $profile['target_domain']);
    }

    public function testGeneratePrefersSelectedLocaleOverStaleContentLocale(): void
    {
        $service = new AiSiteProfileGenerationService();

        $profile = $service->generate([
            'site_title' => 'Teenipiya',
            'default_locale' => 'pt_BR',
            'content_locale' => 'zh_Hans_CN',
            'website_profile' => [
                'default_locale' => 'pt_BR',
                'content_locale' => 'zh_Hans_CN',
            ],
        ]);

        self::assertSame('pt_BR', $profile['default_locale']);
        self::assertSame('pt_BR', $profile['content_locale']);
        self::assertSame('pt_BR', $profile['locales'][0] ?? null);
    }

    public function testGenerateDerivesCustomerFacingTitleAndTaglineAndProducesSvgAssets(): void
    {
        $service = new AiSiteProfileGenerationService();

        $profile = $service->generate([
            'brief_description' => '面向欧美市场的宠物用品独立站，突出天然成分、安心配方和快速发货服务。',
            'website_profile' => [
                'site_title' => 'AI Site',
                'site_tagline' => '',
            ],
        ]);

        self::assertSame('面向欧美市场的宠物用品独立站', $profile['site_title']);
        self::assertSame('突出天然成分、安心配方和快速发货服务', $profile['site_tagline']);
        self::assertStringStartsWith('data:image/svg+xml;base64,', (string)$profile['logo']);
        self::assertStringStartsWith('data:image/svg+xml;base64,', (string)$profile['icon']);
        self::assertSame((string)$profile['icon'], (string)$profile['favicon']);
    }

    public function testGenerateRespectsManualSiteFieldsEvenWhenBlankedByUser(): void
    {
        $service = new AiSiteProfileGenerationService();

        $profile = $service->generate([
            'site_title' => 'Northwind Pet Care',
            'site_tagline' => '',
            'brief_description' => '面向欧美市场的宠物用品独立站，突出天然成分、安心配方和快速发货服务。',
            'site_profile_manual' => [
                'site_title' => true,
                'site_tagline' => true,
            ],
        ]);

        self::assertSame('Northwind Pet Care', $profile['site_title']);
        self::assertSame('', $profile['site_tagline']);
    }

    public function testGenerateReplacesLegacyPlaceholderAssetsWithFreshSvgBrandAssets(): void
    {
        $service = new AiSiteProfileGenerationService();

        $profile = $service->generate([
            'brief_description' => '高客单家居品牌官网，强调定制设计、案例展示和预约咨询转化。',
            'website_profile' => [
                'site_title' => 'AI Site',
                'logo' => self::LEGACY_PLACEHOLDER_SVG,
                'icon' => self::LEGACY_PLACEHOLDER_SVG,
                'favicon' => self::LEGACY_PLACEHOLDER_SVG,
            ],
        ]);

        self::assertSame('高客单家居品牌官网', $profile['site_title']);
        self::assertNotSame(self::LEGACY_PLACEHOLDER_SVG, $profile['logo']);
        self::assertNotSame(self::LEGACY_PLACEHOLDER_SVG, $profile['favicon']);
        self::assertNotSame(self::LEGACY_PLACEHOLDER_SVG, $profile['icon']);
        self::assertStringStartsWith('data:image/svg+xml;base64,', (string)$profile['logo']);
        self::assertStringStartsWith('data:image/svg+xml;base64,', (string)$profile['favicon']);
        self::assertStringStartsWith('data:image/svg+xml;base64,', (string)$profile['icon']);
    }

    public function testGenerateUsesAiGeneratedProfilePayloadWhenAvailable(): void
    {
        $generator = new class extends AiSiteProfileAiGenerationService {
            public int $calls = 0;

            public function generateProfile(array $context): array
            {
                $this->calls++;

                return [
                    'site_title' => 'Pawly',
                    'site_tagline' => 'Natural pet care delivered with confidence',
                    'brief_description' => 'A polished AI summary for the storefront.',
                    'meta_title' => 'Pawly | Natural pet care',
                    'meta_description' => 'Shop natural pet care essentials with fast delivery and trust-focused positioning.',
                    'meta_keywords' => 'pet care, natural, delivery',
                    'logo_svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="160" height="48" viewBox="0 0 160 48"><rect width="160" height="48" rx="12" fill="#0f766e"/><text x="80" y="28" text-anchor="middle" font-family="Arial, sans-serif" font-size="16" font-weight="700" fill="#ffffff">Pawly</text></svg>',
                    'icon_svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64"><rect width="64" height="64" rx="18" fill="#0f766e"/><text x="32" y="38" text-anchor="middle" font-family="Arial, sans-serif" font-size="22" font-weight="700" fill="#ffffff">P</text></svg>',
                ];
            }
        };
        $service = new AiSiteProfileGenerationService($generator);

        $profile = $service->generate([
            'brief_description' => '面向欧美市场的宠物用品独立站，突出天然成分、安心配方和快速发货服务。',
            'website_profile' => [
                'site_title' => 'AI Site',
            ],
        ]);

        self::assertSame('Pawly', $profile['site_title']);
        self::assertSame('Natural pet care delivered with confidence', $profile['site_tagline']);
        self::assertSame('A polished AI summary for the storefront.', $profile['brief_description']);
        self::assertSame('Pawly | Natural pet care', $profile['seo']['meta_title']);
        self::assertStringStartsWith('data:image/svg+xml;base64,', (string)$profile['logo']);
        self::assertStringStartsWith('data:image/svg+xml;base64,', (string)$profile['icon']);
        self::assertSame(1, $generator->calls);
    }

    public function testGenerateReusesCachedManagedProfileWhenSignatureMatches(): void
    {
        $generator = new class extends AiSiteProfileAiGenerationService {
            public int $calls = 0;

            public function generateProfile(array $context): array
            {
                $this->calls++;

                return [
                    'site_title' => 'Orbit Studio',
                    'site_tagline' => 'Design systems for high-conversion launches',
                    'brief_description' => 'Orbit Studio helps brands launch polished digital storefronts.',
                    'logo_svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="160" height="48" viewBox="0 0 160 48"><rect width="160" height="48" rx="12" fill="#1d4ed8"/><text x="80" y="28" text-anchor="middle" font-family="Arial, sans-serif" font-size="16" font-weight="700" fill="#ffffff">Orbit Studio</text></svg>',
                    'icon_svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64"><rect width="64" height="64" rx="18" fill="#1d4ed8"/><text x="32" y="38" text-anchor="middle" font-family="Arial, sans-serif" font-size="22" font-weight="700" fill="#ffffff">O</text></svg>',
                ];
            }
        };
        $service = new AiSiteProfileGenerationService($generator);

        $first = $service->generate([
            'brief_description' => '高端设计工作室官网，突出案例展示、服务能力与预约咨询。',
            'website_profile' => [
                'site_title' => 'AI Site',
            ],
        ]);

        $second = $service->generate([
            'brief_description' => '高端设计工作室官网，突出案例展示、服务能力与预约咨询。',
            'website_profile' => $first,
        ]);

        self::assertSame('Orbit Studio', $first['site_title']);
        self::assertSame('Orbit Studio', $second['site_title']);
        self::assertSame(1, $generator->calls);
    }

    public function testGenerateRejectsMalformedAiSvgAndFallsBackToDeterministicAssets(): void
    {
        $generator = new class extends AiSiteProfileAiGenerationService {
            public function generateProfile(array $context): array
            {
                return [
                    'site_title' => 'Broken Brand',
                    'site_tagline' => 'Malformed SVG should not leak into preview HTML',
                    'brief_description' => 'Reject invalid SVG payloads before they reach the browser.',
                    'logo_svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="160" height="48" viewBox="0 0 160 48"><path d="M10 10 L40 40</div></svg>',
                    'icon_svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64"><path d="M8 8 L32 32</div></svg>',
                ];
            }
        };
        $service = new AiSiteProfileGenerationService($generator);

        $profile = $service->generate([
            'brief_description' => 'Reject invalid SVG payloads before they reach the browser.',
            'website_profile' => [
                'site_title' => 'AI Site',
            ],
        ]);

        self::assertSame('Broken Brand', $profile['site_title']);
        self::assertStringStartsWith('data:image/svg+xml;base64,', (string)$profile['logo']);
        self::assertStringStartsWith('data:image/svg+xml;base64,', (string)$profile['icon']);

        $logoSvg = \base64_decode((string)\substr((string)$profile['logo'], 26), true);
        $iconSvg = \base64_decode((string)\substr((string)$profile['icon'], 26), true);

        self::assertIsString($logoSvg);
        self::assertIsString($iconSvg);
        self::assertStringNotContainsString('</div>', $logoSvg);
        self::assertStringNotContainsString('</div>', $iconSvg);
        self::assertStringContainsString('brandLogoGradient', $logoSvg);
        self::assertStringContainsString('brandIconGradient', $iconSvg);
    }

    public function testGenerateSanitizesPersistedMalformedSvgAssetsFromExistingProfile(): void
    {
        $service = new AiSiteProfileGenerationService();

        $profile = $service->generate([
            'brief_description' => 'Reject persisted malformed SVG assets before rendering preview pages.',
            'website_profile' => [
                'site_title' => 'Unsafe Cache',
                'logo' => 'data:image/svg+xml;base64,' . \base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="160" height="48" viewBox="0 0 160 48"><path d="M10 10 L40 40</div></svg>'),
                'icon' => 'data:image/svg+xml;base64,' . \base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64"><path d="M8 8 L32 32</div></svg>'),
                'favicon' => 'data:image/svg+xml;base64,' . \base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64"><path d="M8 8 L32 32</div></svg>'),
            ],
        ]);

        $logoSvg = \base64_decode((string)\substr((string)$profile['logo'], 26), true);
        $iconSvg = \base64_decode((string)\substr((string)$profile['icon'], 26), true);

        self::assertIsString($logoSvg);
        self::assertIsString($iconSvg);
        self::assertStringNotContainsString('</div>', $logoSvg);
        self::assertStringNotContainsString('</div>', $iconSvg);
        self::assertStringContainsString('brandLogoGradient', $logoSvg);
        self::assertStringContainsString('brandIconGradient', $iconSvg);
        self::assertSame((string)$profile['icon'], (string)$profile['favicon']);
    }
}
