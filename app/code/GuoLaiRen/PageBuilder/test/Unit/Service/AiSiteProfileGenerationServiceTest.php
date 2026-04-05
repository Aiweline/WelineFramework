<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteProfileGenerationService;
use PHPUnit\Framework\TestCase;

class AiSiteProfileGenerationServiceTest extends TestCase
{
    public function testGeneratePrefersTopLevelScopeValuesOverStaleWebsiteProfileSnapshot(): void
    {
        $service = new AiSiteProfileGenerationService();

        $profile = $service->generate([
            'site_title' => 'Canonical Virtual Theme Flow',
            'site_tagline' => 'Fresh tagline',
            'brief_description' => 'Fresh brief',
            'target_domain' => 'canonical-flow.local.test',
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
}
