<?php

declare(strict_types=1);

namespace Weline\Consent\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\Consent\Extends\Module\Weline_Framework\Query\ConsentQueryProvider;
use Weline\Consent\Service\ConsentService;
use Weline\Consent\Test\Unit\Double\FixedConsentVisitorIdentity;
use Weline\Consent\Test\Unit\Double\InMemoryConsentRepository;
use Weline\Consent\Test\Unit\Double\MutableConsentRecordingPolicy;
use Weline\Framework\Runtime\RequestContext;

final class ConsentQueryProviderTest extends TestCase
{
    private ConsentService $service;
    private ConsentQueryProvider $provider;
    private string $visitor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->visitor = 'v1_' . str_repeat('B', 43);
        $this->service = new ConsentService(
            new InMemoryConsentRepository(),
            new MutableConsentRecordingPolicy(),
        );
        $this->provider = new ConsentQueryProvider(
            $this->service,
            new FixedConsentVisitorIdentity($this->visitor),
        );
        RequestContext::setWelineWebsiteId(0);
        unset($_COOKIE['weline_consent_vid']);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['weline_consent_vid']);
        parent::tearDown();
    }

    public function testAcceptUsesServerIdentityWithoutReturningVisitorKey(): void
    {
        $result = $this->provider->execute('accept', [
            'categories' => ['analytics', 'marketing'],
        ]);

        self::assertTrue($result['success']);
        self::assertArrayNotHasKey('visitor_key', $result);
        self::assertFalse($result['show_banner']);
        self::assertTrue($this->service->isGranted(0, $this->visitor, 'analytics'));
        self::assertTrue($this->service->isGranted(0, $this->visitor, 'marketing'));
        self::assertTrue($this->service->isGranted(0, $this->visitor, 'necessary'));
    }

    public function testStatusUsesServerIdentityConsistentlyWithAccept(): void
    {
        $this->provider->execute('accept', [
            'categories' => ['analytics', 'marketing'],
        ]);

        $status = $this->provider->execute('status');
        self::assertFalse($status['show_banner']);
        self::assertTrue($status['granted']['analytics']);
        self::assertTrue($status['granted']['marketing']);
    }

    public function testWithdrawReopensBanner(): void
    {
        $this->provider->execute('accept', [
            'categories' => ['analytics', 'marketing'],
        ]);
        $withdraw = $this->provider->execute('withdraw', [
            'category' => 'analytics',
        ]);

        self::assertTrue($withdraw['success']);
        self::assertTrue($withdraw['show_banner']);
    }

    public function testDescriptorPublishesFrontendAcceptStatusWithdraw(): void
    {
        $descriptor = $this->provider->getDescriptor();
        $ops = array_column($descriptor['operations'], 'name');
        self::assertSame(['accept', 'status', 'withdraw'], $ops);
        foreach ($descriptor['operations'] as $operation) {
            self::assertTrue($operation['frontend']);
        }
    }

    public function testCallerProvidedVisitorKeyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('consent_visitor_key_forbidden');
        $this->provider->execute('accept', [
            'visitor_key' => 'v1_' . str_repeat('C', 43),
            'categories' => ['analytics'],
        ]);
    }
}
