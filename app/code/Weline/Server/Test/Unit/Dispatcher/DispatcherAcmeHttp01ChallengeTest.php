<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Dispatcher;

use PHPUnit\Framework\TestCase;
use Weline\Server\Dispatcher\Dispatcher;
use Weline\Server\Service\Edge\Gateway\ProjectAcmeHttp01ChallengeStore;
use Weline\Server\Service\SslCertificateService;

final class DispatcherAcmeHttp01ChallengeTest extends TestCase
{
    private array $cleanupFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupFiles as $file) {
            if (\is_file($file)) {
                @\unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testParseAcmeHttp01RequestFromRelativePath(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $method = new \ReflectionMethod(Dispatcher::class, 'parseAcmeHttp01Request');
        $method->setAccessible(true);

        $result = $method->invoke(
            $dispatcher,
            "GET /.well-known/acme-challenge/TOKEN_123-abc?x=1 HTTP/1.1\r\nHost: test.aiweline.com\r\n\r\n"
        );

        self::assertSame('GET', $result['method']);
        self::assertSame('/.well-known/acme-challenge/TOKEN_123-abc', $result['path']);
        self::assertSame('TOKEN_123-abc', $result['token']);
    }

    public function testResolveChallengeByHostAndToken(): void
    {
        $token = 'Gy7HnlSGiN_Dchg8qWH9woawHDoRrwISnua6u8Lr_68';
        $keyAuth = $token . '.' . \str_repeat('A', 43);
        $this->writeAcmeChallenge('test.aiweline.com', $token, $keyAuth);

        $dispatcher = $this->newDispatcherWithoutConstructor();
        $method = new \ReflectionMethod(Dispatcher::class, 'resolveAcmeHttp01ChallengeBody');
        $method->setAccessible(true);

        self::assertSame($keyAuth, $method->invoke($dispatcher, 'test.aiweline.com', $token));
        self::assertNull($method->invoke($dispatcher, 'test.aiweline.com', 'wrong-token'));
    }

    public function testExpiredChallengeIsRejected(): void
    {
        $token = 'expired_token';
        $keyAuth = $token . '.' . \str_repeat('B', 43);
        $this->writeAcmeChallenge('expired.aiweline.com', $token, $keyAuth, \time() - 1);

        $dispatcher = $this->newDispatcherWithoutConstructor();
        $method = new \ReflectionMethod(Dispatcher::class, 'resolveAcmeHttp01ChallengeBody');
        $method->setAccessible(true);

        self::assertNull($method->invoke($dispatcher, 'expired.aiweline.com', $token));
    }

    public function testResolveChallengeRejectsMissingHostInsteadOfScanningByToken(): void
    {
        $token = 'fallback_token';
        $keyAuth = $token . '.' . \str_repeat('C', 43);
        $this->writeAcmeChallenge('fallback.aiweline.com', $token, $keyAuth);

        $dispatcher = $this->newDispatcherWithoutConstructor();
        $method = new \ReflectionMethod(Dispatcher::class, 'resolveAcmeHttp01ChallengeBody');
        $method->setAccessible(true);

        self::assertNull($method->invoke($dispatcher, '', $token));
    }

    private function writeAcmeChallenge(
        string $domain,
        string $token,
        string $keyAuth,
        ?int $expiresAt = null,
    ): void
    {
        $dir = \rtrim(BP, \DIRECTORY_SEPARATOR)
            . \DIRECTORY_SEPARATOR . 'generated'
            . \DIRECTORY_SEPARATOR . 'acme-http01'
            . \DIRECTORY_SEPARATOR;
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }

        $wallNow = $expiresAt === null
            ? \time()
            : \max(1, $expiresAt - 900);
        $monotonicNow = \hrtime(true) / 1_000_000_000;
        if ($expiresAt !== null && $expiresAt <= \time()) {
            $monotonicNow = \max(0.001, $monotonicNow - 901.0);
        }
        $store = new ProjectAcmeHttp01ChallengeStore(
            $dir,
            static fn (): int => $wallNow,
            static fn (): float => $monotonicNow,
        );
        $store->register($domain, $token, $keyAuth);
        $this->cleanupFiles[] = $dir
            . SslCertificateService::domainToAcmeChallengeFilename($domain)
            . '.json';
        $this->cleanupFiles[] = $dir . '.desired.json';
        $this->cleanupFiles[] = $dir . '.desired.lock';
    }

    private function newDispatcherWithoutConstructor(): Dispatcher
    {
        $reflector = new \ReflectionClass(Dispatcher::class);
        /** @var Dispatcher $dispatcher */
        $dispatcher = $reflector->newInstanceWithoutConstructor();
        return $dispatcher;
    }
}
