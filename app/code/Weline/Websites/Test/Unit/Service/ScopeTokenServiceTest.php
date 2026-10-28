<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Websites\Service\ScopeTokenKeyring;
use Weline\Websites\Service\ScopeTokenService;
use Weline\Websites\Service\Value\ScopeTokenVerification;

/** TEST-SEC-01 and TEST-SEC-02: signed scope claims fail closed on tamper or expiry. */
final class ScopeTokenServiceTest extends TestCase
{
    private const SECRET = 'scope-token-test-secret-material-32-bytes-minimum';

    private const PAGE_GENERATION = 'fixture-gen';

    private string|false $previousKeyring;
    private ScopeIdentity $scope;
    private ScopeTokenService $service;

    protected function setUp(): void
    {
        $this->previousKeyring = getenv('WELINE_SCOPE_TOKEN_KEYRING_B64');
        $this->resetKeyringProcessVersion();
        putenv('WELINE_SCOPE_TOKEN_KEYRING_B64=' . $this->encodedKeyring());

        $this->scope = ScopeIdentity::channel(0, 'default', 'main', 'web', ScopeIdentity::MODE_TEST);
        $this->service = new ScopeTokenService(new ScopeTokenKeyring());
    }

    protected function tearDown(): void
    {
        if ($this->previousKeyring === false) {
            putenv('WELINE_SCOPE_TOKEN_KEYRING_B64');
        } else {
            putenv('WELINE_SCOPE_TOKEN_KEYRING_B64=' . $this->previousKeyring);
        }
        $this->resetKeyringProcessVersion();
    }

    public function testIssuesCanonicalV1TokenWithFixedLifetime(): void
    {
        $token = $this->service->issue($this->scope, 'Shop.Example.Test:19740', self::PAGE_GENERATION, 1_000);
        $verification = $this->service->verify($token, 'shop.example.test:19740', $this->scope, self::PAGE_GENERATION, 1_001);

        self::assertSame(ScopeTokenVerification::STATUS_VALID, $verification->status);
        self::assertSame('test-active', $verification->kid);
        self::assertSame(1_000, $verification->issuedAt);
        self::assertSame(1_000 + ScopeTokenService::TTL_SECONDS, $verification->expiresAt);
        self::assertSame('shop.example.test:19740', $verification->host);
        self::assertSame(self::PAGE_GENERATION, $this->payloadFromToken($token)['page_generation']);
        self::assertTrue($verification->scope?->equals($this->scope) ?? false);
    }

    public function testTamperingSignatureAndIdentityClaimsAlwaysFailsClosed(): void
    {
        $token = $this->service->issue($this->scope, 'shop.example.test', self::PAGE_GENERATION, 2_000);
        foreach (['website_id', 'store_mode', 'channel_code'] as $claim) {
            $parts = explode('.', $token);
            $payload = $this->decodePayload($parts[2]);
            $payload[$claim] = match ($claim) {
                'website_id' => 9,
                'store_mode' => ScopeIdentity::MODE_DEV,
                default => 'mobile',
            };
            $parts[2] = $this->b64(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            $verification = $this->service->verify(
                implode('.', $parts),
                'shop.example.test',
                $this->scope,
                self::PAGE_GENERATION,
                2_001,
            );
            self::assertSame(ScopeTokenVerification::STATUS_INVALID, $verification->status, $claim);
            self::assertSame('bad_signature', $verification->reason, $claim);
            self::assertNull($this->service->renew($verification, $this->scope, 'shop.example.test', self::PAGE_GENERATION, 2_001));
        }

        $parts = explode('.', $token);
        $parts[3][0] = $parts[3][0] === 'A' ? 'B' : 'A';
        $verification = $this->service->verify(implode('.', $parts), 'shop.example.test', $this->scope, self::PAGE_GENERATION, 2_001);
        self::assertSame(ScopeTokenVerification::STATUS_INVALID, $verification->status);
        self::assertSame('bad_signature', $verification->reason);
    }

    public function testExpiredTokenCannotBeRenewedInPlace(): void
    {
        $token = $this->service->issue($this->scope, 'shop.example.test', self::PAGE_GENERATION, 3_000);
        $verification = $this->service->verify(
            $token,
            'shop.example.test',
            $this->scope,
            self::PAGE_GENERATION,
            3_000 + ScopeTokenService::TTL_SECONDS,
        );

        self::assertSame(ScopeTokenVerification::STATUS_EXPIRED, $verification->status);
        self::assertNull($this->service->renew(
            $verification,
            $this->scope,
            'shop.example.test',
            self::PAGE_GENERATION,
            3_000 + ScopeTokenService::TTL_SECONDS,
        ));
    }

    public function testSignedWrongHostAudienceAndContextVersionAreRejected(): void
    {
        $token = $this->service->issue($this->scope, 'shop.example.test', self::PAGE_GENERATION, 4_000);

        $wrongHost = $this->service->verify($token, 'other.example.test', $this->scope, self::PAGE_GENERATION, 4_001);
        self::assertSame(ScopeTokenVerification::STATUS_CONTEXT_CONFLICT, $wrongHost->status);
        self::assertSame('host_mismatch', $wrongHost->reason);
        // Token 声明的 Host 必须随拒绝结果带出，排障时才能与请求 authority 对照。
        self::assertSame('shop.example.test', $wrongHost->host);

        $payload = $this->payloadFromToken($token);
        $payload['aud'] = 'another.audience';
        $wrongAudience = $this->service->verify(
            $this->signPayload($payload),
            'shop.example.test',
            $this->scope,
            self::PAGE_GENERATION,
            4_001,
        );
        self::assertSame(ScopeTokenVerification::STATUS_CONTEXT_CONFLICT, $wrongAudience->status);
        self::assertSame('audience_mismatch', $wrongAudience->reason);

        $payload = $this->payloadFromToken($token);
        $payload['context_version'] = 'v2';
        $wrongContext = $this->service->verify(
            $this->signPayload($payload),
            'shop.example.test',
            $this->scope,
            self::PAGE_GENERATION,
            4_001,
        );
        self::assertSame(ScopeTokenVerification::STATUS_CONTEXT_CONFLICT, $wrongContext->status);
        self::assertSame('context_version_mismatch', $wrongContext->reason);
    }

    public function testSignedEquivalentButNonCanonicalJsonIsRejected(): void
    {
        $payload = $this->payloadFromToken(
            $this->service->issue($this->scope, 'shop.example.test', self::PAGE_GENERATION, 5_000),
        );
        ksort($payload, SORT_STRING);

        $verification = $this->service->verify(
            $this->signPayload($payload),
            'shop.example.test',
            $this->scope,
            self::PAGE_GENERATION,
            5_001,
        );

        self::assertSame(ScopeTokenVerification::STATUS_INVALID, $verification->status);
        self::assertSame('non_canonical_payload', $verification->reason);
    }

    public function testFutureIssueTimeUsesTheSharedSixtySecondSkewBoundary(): void
    {
        $accepted = $this->service->verify(
            $this->service->issue($this->scope, 'shop.example.test', self::PAGE_GENERATION, 6_060),
            'shop.example.test',
            $this->scope,
            self::PAGE_GENERATION,
            6_000,
        );
        self::assertSame(ScopeTokenVerification::STATUS_VALID, $accepted->status);

        $rejected = $this->service->verify(
            $this->service->issue($this->scope, 'shop.example.test', self::PAGE_GENERATION, 6_061),
            'shop.example.test',
            $this->scope,
            self::PAGE_GENERATION,
            6_000,
        );
        self::assertSame(ScopeTokenVerification::STATUS_INVALID, $rejected->status);
        self::assertSame('invalid_time_claims', $rejected->reason);
    }

    public function testPageGenerationMismatchAndMissingClaimAreRecoverableConflicts(): void
    {
        $token = $this->service->issue($this->scope, 'shop.example.test', 'gen-a', 7_000);
        $same = $this->service->verify($token, 'shop.example.test', $this->scope, 'gen-a', 7_001);
        self::assertSame(ScopeTokenVerification::STATUS_VALID, $same->status);

        $other = $this->service->verify($token, 'shop.example.test', $this->scope, 'gen-b', 7_001);
        self::assertSame(ScopeTokenVerification::STATUS_CONTEXT_CONFLICT, $other->status);
        self::assertSame('page_generation_mismatch', $other->reason);

        $payload = $this->payloadFromToken($token);
        unset($payload['page_generation']);
        $missing = $this->service->verify(
            $this->signPayload($payload),
            'shop.example.test',
            $this->scope,
            'gen-a',
            7_001,
        );
        self::assertSame(ScopeTokenVerification::STATUS_CONTEXT_CONFLICT, $missing->status);
        self::assertSame('page_generation_mismatch', $missing->reason);
    }

    /**
     * Warmup window: a token minted for a generation the Scope resolver still accepts
     * (current + recent sealed) must keep working; anything outside the window must not.
     */
    public function testAcceptableGenerationWindowKeepsRecentTokensUsable(): void
    {
        $token = $this->service->issue($this->scope, 'shop.example.test', 't3.v2', 9_000);
        $acceptCurrentAndPrevious = static fn(string $generation): bool
            => in_array($generation, ['t3.v3', 't3.v2', 't3.v1'], true);

        $accepted = $this->service->verifyCandidate(
            $token,
            'shop.example.test',
            't3.v3',
            9_001,
            $acceptCurrentAndPrevious,
        );
        self::assertSame(ScopeTokenVerification::STATUS_VALID, $accepted->status);

        $outsideWindow = $this->service->verifyCandidate(
            $token,
            'shop.example.test',
            't3.v3',
            9_001,
            static fn(string $generation): bool => $generation === 't3.v3',
        );
        self::assertSame(ScopeTokenVerification::STATUS_CONTEXT_CONFLICT, $outsideWindow->status);
        self::assertSame('page_generation_mismatch', $outsideWindow->reason);

        // A matcher that throws is fail-closed, never fail-open.
        $broken = $this->service->verifyCandidate(
            $token,
            'shop.example.test',
            't3.v3',
            9_001,
            static function (string $generation): bool {
                throw new \RuntimeException('resolver down');
            },
        );
        self::assertSame(ScopeTokenVerification::STATUS_CONTEXT_CONFLICT, $broken->status);
    }

    /** @return array<string, mixed> */
    private function payloadFromToken(string $token): array
    {
        $parts = explode('.', $token);
        self::assertCount(4, $parts);
        return $this->decodePayload($parts[2]);
    }

    /** @return array<string, mixed> */
    private function decodePayload(string $body): array
    {
        $padding = (4 - strlen($body) % 4) % 4;
        $json = base64_decode(strtr($body, '-_', '+/') . str_repeat('=', $padding), true);
        self::assertIsString($json);
        $payload = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function signPayload(array $payload): string
    {
        $body = $this->b64(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $input = "weline-scope-token\0v1\0test-active\0" . $body;
        $signature = $this->b64(hash_hmac('sha256', $input, self::SECRET, true));
        return 'v1.test-active.' . $body . '.' . $signature;
    }

    private function encodedKeyring(): string
    {
        return base64_encode(json_encode([
            'active_kid' => 'test-active',
            'version' => 1,
            'keys' => [
                'test-active' => [
                    'status' => 'active',
                    'signing_not_after' => 0,
                    'verify_until' => 0,
                    'secret_base64' => base64_encode(self::SECRET),
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function resetKeyringProcessVersion(): void
    {
        $reflection = new \ReflectionClass(ScopeTokenKeyring::class);
        foreach (['processVersion', 'processVersionDigest'] as $propertyName) {
            $property = $reflection->getProperty($propertyName);
            $property->setValue(null, null);
        }
    }
}
