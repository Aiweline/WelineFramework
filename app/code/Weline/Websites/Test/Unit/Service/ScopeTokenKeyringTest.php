<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Service\ScopeTokenKeyring;

final class ScopeTokenKeyringTest extends TestCase
{
    private string|false $previousKeyring;

    protected function setUp(): void
    {
        $this->previousKeyring = getenv('WELINE_SCOPE_TOKEN_KEYRING_B64');
        $this->resetProcessVersion();
    }

    protected function tearDown(): void
    {
        if ($this->previousKeyring === false) {
            putenv('WELINE_SCOPE_TOKEN_KEYRING_B64');
        } else {
            putenv('WELINE_SCOPE_TOKEN_KEYRING_B64=' . $this->previousKeyring);
        }
        $this->resetProcessVersion();
    }

    public function testLoadsExactlyOneActiveKeyFromProcessInjection(): void
    {
        $this->install($this->snapshot(1));

        $active = (new ScopeTokenKeyring())->active();

        self::assertSame('active-v1', $active['kid']);
        self::assertSame('active-secret-material-at-least-32-bytes', $active['secret']);
        self::assertSame(1, $active['version']);
    }

    public function testRejectsMultipleActiveKeys(): void
    {
        $snapshot = $this->snapshot(1);
        $snapshot['keys']['other-active'] = [
            'status' => 'active',
            'signing_not_after' => 0,
            'verify_until' => 0,
            'secret_base64' => base64_encode('different-active-secret-material-32-bytes'),
        ];
        $this->install($snapshot);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('active_kid');
        (new ScopeTokenKeyring())->active();
    }

    public function testRejectsDuplicateSecretsAcrossKids(): void
    {
        $snapshot = $this->snapshot(1);
        $snapshot['keys']['retired-v0'] = [
            'status' => 'verify_only',
            'signing_not_after' => 1_000,
            'verify_until' => 2_800,
            'secret_base64' => $snapshot['keys']['active-v1']['secret_base64'],
        ];
        $this->install($snapshot);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('不允许多个 kid 共用密钥');
        (new ScopeTokenKeyring())->active();
    }

    public function testVerifyOnlyKeyExpiresAtItsConfiguredDeadline(): void
    {
        $snapshot = $this->snapshot(2);
        $snapshot['keys']['retired-v1'] = [
            'status' => 'verify_only',
            'signing_not_after' => 2_000,
            'verify_until' => 3_800,
            'secret_base64' => base64_encode('retired-secret-material-at-least-32-bytes'),
        ];
        $this->install($snapshot);
        $keyring = new ScopeTokenKeyring();

        $retired = $keyring->verification('retired-v1', 3_800);
        self::assertIsArray($retired);
        self::assertSame('verify_only', $retired['status']);
        self::assertSame(2, $retired['version']);
        self::assertNull($keyring->verification('retired-v1', 3_801));
        self::assertNull($keyring->verification('unknown-kid', 2_100));
    }

    public function testRejectsVersionRollbackWithinTheSameProcess(): void
    {
        $this->install($this->snapshot(2));
        self::assertSame(2, (new ScopeTokenKeyring())->active()['version']);

        $this->install($this->snapshot(1));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('version 不允许回退');
        (new ScopeTokenKeyring())->active();
    }

    /** @return array{active_kid:string,version:int,keys:array<string,array<string,int|string>>} */
    private function snapshot(int $version): array
    {
        return [
            'active_kid' => 'active-v1',
            'version' => $version,
            'keys' => [
                'active-v1' => [
                    'status' => 'active',
                    'signing_not_after' => 0,
                    'verify_until' => 0,
                    'secret_base64' => base64_encode('active-secret-material-at-least-32-bytes'),
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $snapshot */
    private function install(array $snapshot): void
    {
        $json = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        putenv('WELINE_SCOPE_TOKEN_KEYRING_B64=' . base64_encode($json));
    }

    private function resetProcessVersion(): void
    {
        $reflection = new \ReflectionClass(ScopeTokenKeyring::class);
        foreach (['processVersion', 'processVersionDigest'] as $propertyName) {
            $property = $reflection->getProperty($propertyName);
            $property->setValue(null, null);
        }
    }
}
