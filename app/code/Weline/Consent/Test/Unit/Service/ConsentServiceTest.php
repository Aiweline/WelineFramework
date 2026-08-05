<?php

declare(strict_types=1);

namespace Weline\Consent\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Consent\Service\ConsentService;
use Weline\Consent\Test\Unit\Double\InMemoryConsentRepository;
use Weline\Consent\Test\Unit\Double\MutableConsentRecordingPolicy;

/**
 * TEST-P1D-04：Website A/B 隔离；撤回后横幅重现。
 */
final class ConsentServiceTest extends TestCase
{
    private ConsentService $svc;
    private MutableConsentRecordingPolicy $policy;
    private string $visitor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new MutableConsentRecordingPolicy();
        $this->svc = new ConsentService(new InMemoryConsentRepository(), $this->policy);
        $this->visitor = 'v1_' . str_repeat('A', 43);
    }

    public function testWebsiteIsolation(): void
    {
        $this->svc->grant(1, $this->visitor, 'analytics');
        self::assertTrue($this->svc->isGranted(1, $this->visitor, 'analytics'));
        self::assertFalse($this->svc->isGranted(2, $this->visitor, 'analytics'));
        self::assertTrue($this->svc->shouldShowBanner(2, $this->visitor));
        self::assertTrue($this->svc->shouldShowBanner(1, $this->visitor));
        $this->svc->grant(1, $this->visitor, 'marketing');
        self::assertFalse($this->svc->shouldShowBanner(1, $this->visitor));
        self::assertTrue($this->svc->shouldShowBanner(2, $this->visitor));
    }

    public function testWithdrawShowsBannerAgain(): void
    {
        $this->svc->grant(0, $this->visitor, 'analytics');
        $this->svc->grant(0, $this->visitor, 'marketing');
        self::assertFalse($this->svc->shouldShowBanner(0, $this->visitor));
        $this->svc->withdraw(0, $this->visitor, 'analytics');
        self::assertTrue($this->svc->shouldShowBanner(0, $this->visitor));
        self::assertFalse($this->svc->isGranted(0, $this->visitor, 'analytics'));
        self::assertCount(3, $this->svc->auditForWebsite(0));
    }

    public function testDisableRecordingKeepsExistingAudit(): void
    {
        $this->svc->grant(1, $this->visitor, 'analytics');
        $this->policy->enabled = false;
        self::assertTrue($this->svc->isGranted(1, $this->visitor, 'analytics'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('consent_recording_disabled');
        $this->svc->grant(1, $this->visitor, 'marketing');
    }

    public function testZeroWebsiteIdIsValid(): void
    {
        $this->svc->grant(0, $this->visitor, 'analytics');
        self::assertTrue($this->svc->isGranted(0, $this->visitor, 'analytics'));
        self::assertCount(1, $this->svc->listForWebsite(0));
    }

    public function testRequiredCategoryCannotBeWithdrawn(): void
    {
        $this->svc->grant(0, $this->visitor, 'necessary');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('consent_required_cannot_withdraw');
        $this->svc->withdraw(0, $this->visitor, 'necessary');
    }
}
