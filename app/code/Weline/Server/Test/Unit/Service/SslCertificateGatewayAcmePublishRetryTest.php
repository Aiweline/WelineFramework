<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\SslCertificateService;

require_once \dirname(__DIR__, 7) . '/app/bootstrap_phpunit.php';

final class SslCertificateGatewayAcmePublishRetryTest extends TestCase
{
    private const DESIRED = [
        'generation' => 1,
        'digest' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        'challenges' => [],
    ];

    public function testTransientGatewayRegistrationRaceRetriesBeforeCaValidation(): void
    {
        $service = new SslCertificateGatewayAcmePublishRetryProbe(
            [false, false, true],
            1.0,
        );

        self::assertTrue($service->publishBeforeValidation(self::DESIRED, 'shop.example.com'));
        self::assertSame(3, $service->publishCalls);
        self::assertSame(2, $service->waitCalls);
        self::assertSame('', $service->lastError());
    }

    public function testPermanentGatewayPublicationFailureStopsAtBoundedDeadline(): void
    {
        $service = new SslCertificateGatewayAcmePublishRetryProbe(
            [false, false, false],
            16.0,
        );

        self::assertFalse($service->publishBeforeValidation(self::DESIRED, 'shop.example.com'));
        self::assertSame(1, $service->publishCalls);
        self::assertSame(1, $service->waitCalls);
        self::assertSame(15.0, $service->elapsed());
        self::assertStringContainsString('15', $service->lastError());
    }

    public function testAlreadyPublishedChallengeDoesNotSleep(): void
    {
        $service = new SslCertificateGatewayAcmePublishRetryProbe([true], 1.0);

        self::assertTrue($service->publishBeforeValidation(self::DESIRED, 'shop.example.com'));
        self::assertSame(1, $service->publishCalls);
        self::assertSame(0, $service->waitCalls);
    }

    public function testRetriesPropagateOneAbsolutePublicationDeadline(): void
    {
        $service = new SslCertificateGatewayAcmePublishRetryProbe(
            [false, false, true],
            1.0,
        );

        self::assertTrue($service->publishBeforeValidation(
            self::DESIRED,
            'shop.example.com',
        ));
        self::assertSame([15.0, 15.0, 15.0], $service->publicationDeadlines);
    }
}

final class SslCertificateGatewayAcmePublishRetryProbe extends SslCertificateService
{
    public int $publishCalls = 0;
    public int $waitCalls = 0;
    /** @var list<float|null> */
    public array $publicationDeadlines = [];
    private float $now = 0.0;

    /** @param list<bool> $publishResults */
    public function __construct(
        private array $publishResults,
        private readonly float $waitAdvance,
    ) {
    }

    /** @param array{generation:int,digest:string,challenges:list<array<string,mixed>>} $desired */
    public function publishBeforeValidation(array $desired, string $domain): bool
    {
        return $this->publishGatewayAcmeDesiredBeforeValidation($desired, $domain);
    }

    public function lastError(): string
    {
        return $this->lastAcmeError;
    }

    public function elapsed(): float
    {
        return $this->now;
    }

    protected function publishGatewayAcmeDesired(
        array $desired,
        ?string $requiredDomain = null,
        ?float $deadlineMonotonic = null,
    ): bool {
        unset($desired, $requiredDomain);
        $this->publishCalls++;
        $this->publicationDeadlines[] = $deadlineMonotonic;
        return \array_shift($this->publishResults) ?? false;
    }

    protected function gatewayAcmePublishMonotonicNow(): float
    {
        return $this->now;
    }

    protected function waitForGatewayAcmePublishRetry(
        int $attempt,
        float $remainingSeconds,
    ): void
    {
        unset($attempt);
        $this->waitCalls++;
        $this->now += \min($this->waitAdvance, $remainingSeconds);
    }
}
