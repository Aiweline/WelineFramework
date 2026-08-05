<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Websites\Service\MaintenancePreviewTokenService;
use Weline\Websites\Service\ScopeMaintenanceGate;
use Weline\Websites\Service\ScopeTokenKeyring;
use Weline\Websites\Test\Unit\Double\InMemoryScopeMaintenanceRepository;

/**
 * TEST-P1D-06: durable state, signed preview, generation and fail-closed rules.
 */
final class ScopeMaintenanceGateTest extends TestCase
{
    private const SECRET = 'scope-token-test-secret-material-32-bytes-minimum';

    private string|false $previousKeyring;
    private InMemoryScopeMaintenanceRepository $repository;
    private ScopeMaintenanceGate $gate;
    private MaintenancePreviewTokenService $tokens;

    protected function setUp(): void
    {
        $this->previousKeyring = getenv('WELINE_SCOPE_TOKEN_KEYRING_B64');
        $this->resetKeyringProcessVersion();
        putenv('WELINE_SCOPE_TOKEN_KEYRING_B64=' . $this->encodedKeyring());
        $this->repository = new InMemoryScopeMaintenanceRepository();
        $this->gate = new ScopeMaintenanceGate($this->repository);
        $this->tokens = new MaintenancePreviewTokenService(
            new ScopeTokenKeyring(),
            $this->repository,
        );
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

    public function testStoreAMaintenanceDoesNotAffectB(): void
    {
        $a = ScopeIdentity::store(1, 'shop', 'a', ScopeIdentity::MODE_NORMAL);
        $b = ScopeIdentity::store(1, 'shop', 'b', ScopeIdentity::MODE_NORMAL);
        $state = $this->gate->enable($a, 'upgrade', 1_000, 'unit');

        self::assertSame(1, $state['generation']);
        self::assertTrue($this->gate->isMaintenance($a));
        self::assertFalse($this->gate->isMaintenance($b));
        $this->gate->assertWritable($b);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('scope_maintenance_blocked');
        $this->gate->assertWritable($a);
    }

    public function testWebsiteMaintenanceIsInheritedByStoreAndChannel(): void
    {
        $website = ScopeIdentity::website(1, 'shop');
        $store = ScopeIdentity::store(1, 'shop', 'main', ScopeIdentity::MODE_NORMAL);
        $channel = ScopeIdentity::channel(
            1,
            'shop',
            'main',
            'web',
            ScopeIdentity::MODE_NORMAL,
        );
        $this->gate->enable($website, 'website-upgrade', 1_100);

        self::assertTrue($this->gate->isMaintenance($store));
        self::assertSame($website->canonicalKey(), $this->gate->maintenanceScope($channel)?->canonicalKey());
        $token = $this->tokens->issue($website, 60, 1_100);
        self::assertTrue($this->tokens->verify($token, $website, 1_101));
    }

    public function testSignedPreviewIsReadonlyScopeBoundAndExpires(): void
    {
        $scope = ScopeIdentity::store(2, 'shop', 'main', ScopeIdentity::MODE_NORMAL);
        $other = ScopeIdentity::store(2, 'shop', 'other', ScopeIdentity::MODE_NORMAL);
        $this->gate->enable($scope, '', 2_000);
        $token = $this->tokens->issue($scope, 60, 2_000, 'unit');

        self::assertTrue($this->tokens->verify($token, $scope, 2_001));
        self::assertFalse($this->tokens->verify($token, $other, 2_001));
        self::assertFalse($this->tokens->verify($token, $scope, 2_060));
        $this->gate->assertReadable($scope, true);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('scope_maintenance_preview_readonly');
        $this->gate->assertWritable($scope, true);
    }

    public function testRevokeAndGenerationChangeInvalidateImmediately(): void
    {
        $scope = ScopeIdentity::channel(
            3,
            'shop',
            'main',
            'web',
            ScopeIdentity::MODE_NORMAL,
        );
        $this->gate->enable($scope, '', 3_000);
        $revoked = $this->tokens->issue($scope, 60, 3_000);
        self::assertTrue($this->tokens->verify($revoked, $scope, 3_001));
        self::assertTrue($this->tokens->revoke($revoked, 3_002, 'unit'));
        self::assertFalse($this->tokens->verify($revoked, $scope, 3_003));

        $oldGeneration = $this->tokens->issue($scope, 60, 3_004);
        $disabled = $this->gate->disable($scope, 3_005);
        self::assertSame(2, $disabled['generation']);
        $enabled = $this->gate->enable($scope, '', 3_006);
        self::assertSame(3, $enabled['generation']);
        self::assertFalse($this->tokens->verify($oldGeneration, $scope, 3_007));
        $newGeneration = $this->tokens->issue($scope, 60, 3_007);
        self::assertTrue($this->tokens->verify($newGeneration, $scope, 3_008));

        self::assertSame(
            ['enabled', 'token_issued', 'token_revoked', 'token_issued', 'disabled', 'enabled', 'token_issued'],
            array_column($this->tokens->auditForScope($scope), 'action'),
        );
    }

    public function testTamperingAndStoreFailureFailClosed(): void
    {
        $scope = ScopeIdentity::website(0, 'default');
        $this->gate->enable($scope, '', 4_000);
        $token = $this->tokens->issue($scope, 60, 4_000);
        $parts = explode('.', $token);
        $parts[3][0] = $parts[3][0] === 'A' ? 'B' : 'A';
        self::assertFalse($this->tokens->verify(implode('.', $parts), $scope, 4_001));

        $this->repository->failReads = true;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('scope_maintenance_store_unavailable');
        $this->tokens->verify($token, $scope, 4_001);
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

    private function resetKeyringProcessVersion(): void
    {
        $reflection = new \ReflectionClass(ScopeTokenKeyring::class);
        foreach (['processVersion', 'processVersionDigest'] as $propertyName) {
            $property = $reflection->getProperty($propertyName);
            $property->setValue(null, null);
        }
    }
}
