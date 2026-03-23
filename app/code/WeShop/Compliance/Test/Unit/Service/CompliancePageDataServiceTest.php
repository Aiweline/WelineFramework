<?php

declare(strict_types=1);

namespace WeShop\Compliance\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Compliance\Service\CompliancePageDataService;
use WeShop\Compliance\Service\ConsentService;
use Weline\Framework\Http\Url;

class CompliancePageDataServiceTest extends TestCase
{
    public function testBuildConsentPageMapsConsentStatusesAndUrls(): void
    {
        $consentService = $this->createMock(ConsentService::class);
        $consentService->expects($this->once())
            ->method('getConsentStatuses')
            ->with(9)
            ->willReturn([
                'cookie' => true,
                'privacy' => true,
                'marketing' => false,
                'terms' => true,
            ]);

        $url = $this->createMock(Url::class);
        $url->method('getUrl')->willReturnMap([
            ['compliance/consent/save', null, '/compliance/consent/save'],
            ['compliance/privacy', null, '/compliance/privacy'],
            ['compliance/consent', null, '/compliance/consent'],
            ['compliance', null, '/compliance'],
        ]);

        $service = new CompliancePageDataService($consentService, $url);
        $result = $service->buildConsentPage(9);

        $this->assertTrue($result['can_manage']);
        $this->assertSame('/compliance/consent/save', $result['save_url']);
        $this->assertSame('/compliance/privacy', $result['privacy_url']);
        $this->assertCount(4, $result['consent_items']);
        $this->assertSame('cookie', $result['consent_items'][0]['code']);
        $this->assertTrue($result['consent_items'][0]['accepted']);
    }

    public function testBuildPrivacyPageProvidesSections(): void
    {
        $consentService = $this->createMock(ConsentService::class);
        $url = $this->createMock(Url::class);
        $url->method('getUrl')->willReturnMap([
            ['compliance/consent', null, '/compliance/consent'],
            ['compliance', null, '/compliance'],
        ]);

        $service = new CompliancePageDataService($consentService, $url);
        $result = $service->buildPrivacyPage();

        $this->assertSame('/compliance/consent', $result['consent_url']);
        $this->assertSame('/compliance', $result['compliance_url']);
        $this->assertNotEmpty($result['sections']);
    }
}

