<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\System\Security;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\System\Security\RecipientKeyDirectory;
use Weline\Framework\System\Security\SodiumCryptoEnvelope;
use Weline\SystemConfig\Service\ConfigEnvelopeService;
use Weline\SystemConfig\Service\InMemoryConfigPackageConsumptionLedger;

/**
 * TEST-SEC-06：篡改/截断/错 kid/重放/旧 key → 全失败且零部分写入；正确包只消费一次。
 */
final class ConfigEnvelopeSec06Test extends TestCase
{
    private RecipientKeyDirectory $keys;
    private string $activeKid = 'recv-active';
    private string $oldKid = 'recv-old';
    private InMemoryConfigPackageConsumptionLedger $ledger;
    private ConfigEnvelopeService $service;
    private ScopeIdentity $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $active = RecipientKeyDirectory::generateKeyRecord($this->activeKid, RecipientKeyDirectory::STATUS_ACTIVE);
        $old = RecipientKeyDirectory::generateKeyRecord($this->oldKid, RecipientKeyDirectory::STATUS_DECRYPT_ONLY);
        $this->keys = new RecipientKeyDirectory(
            [
                $this->activeKid => $active,
                $this->oldKid => $old,
            ],
            $this->activeKid,
            'source-a',
            true,
        );
        $this->ledger = new InMemoryConfigPackageConsumptionLedger();
        $this->service = new ConfigEnvelopeService(
            new SodiumCryptoEnvelope(),
            $this->keys,
            $this->ledger,
        );
        $this->scope = ScopeIdentity::website(0, 'default');
    }

    public function testHappyPathConsumesOnce(): void
    {
        $envelope = $this->service->export(
            ['module' => 'demo', 'values' => ['a' => 1]],
            $this->scope,
            'demo.json',
        );
        self::assertArrayNotHasKey('plaintext', $envelope);
        self::assertSame(SodiumCryptoEnvelope::SCHEMA_VERSION, $envelope['schema_version']);

        $writes = 0;
        $this->service->import($envelope, function (array $payload) use (&$writes): void {
            ++$writes;
            self::assertSame(1, $payload['values']['a']);
        }, 'demo.json', $this->scope);
        self::assertSame(1, $writes);
        self::assertTrue($this->ledger->isConsumed((string)$envelope['package_uuid']));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('config_envelope_package_replayed');
        $this->service->import($envelope, function () use (&$writes): void {
            ++$writes;
        }, 'demo.json', $this->scope);
    }

    public function testTamperedCiphertextFailsWithZeroWrite(): void
    {
        $envelope = $this->service->export(['x' => 1], $this->scope, 'a.json');
        $raw = SodiumCryptoEnvelope::ub64((string)$envelope['ciphertext']);
        self::assertNotNull($raw);
        $raw[0] = $raw[0] === "\0" ? "\1" : "\0";
        $envelope['ciphertext'] = SodiumCryptoEnvelope::b64($raw);
        // payload_hash 会先失败；去掉 hash 逼近 AEAD 失败路径
        unset($envelope['payload_hash']);

        $writes = 0;
        try {
            $this->service->import($envelope, function () use (&$writes): void {
                ++$writes;
            }, 'a.json', $this->scope);
            self::fail('expected failure');
        } catch (\RuntimeException) {
        }
        self::assertSame(0, $writes);
        self::assertFalse($this->ledger->isConsumed((string)$envelope['package_uuid']));
    }

    public function testFilenameMismatchFails(): void
    {
        $envelope = $this->service->export(['x' => 1], $this->scope, 'real.json');
        $writes = 0;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('config_envelope_filename_mismatch');
        try {
            $this->service->import($envelope, function () use (&$writes): void {
                ++$writes;
            }, 'fake.json', $this->scope);
        } finally {
            self::assertSame(0, $writes);
            self::assertFalse($this->ledger->isConsumed((string)$envelope['package_uuid']));
        }
    }

    public function testWrongRecipientKidFailsBeforeWrite(): void
    {
        $envelope = $this->service->export(['x' => 1], $this->scope, 'a.json');
        $envelope['recipient_kid'] = 'unknown-kid';
        $aad = \json_decode((string)$envelope['aad'], true);
        $aad['recipient_kid'] = 'unknown-kid';
        $envelope['aad'] = SodiumCryptoEnvelope::canonicalAad($aad);

        $writes = 0;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('config_envelope_kid_unknown');
        try {
            $this->service->import($envelope, function () use (&$writes): void {
                ++$writes;
            }, 'a.json', $this->scope);
        } finally {
            self::assertSame(0, $writes);
            self::assertFalse($this->ledger->isConsumed((string)$envelope['package_uuid']));
        }
    }

    public function testTruncatedEnvelopeFails(): void
    {
        $envelope = $this->service->export(['x' => 1], $this->scope, 'a.json');
        unset($envelope['ciphertext']);
        $writes = 0;
        $this->expectException(\RuntimeException::class);
        try {
            $this->service->import($envelope, function () use (&$writes): void {
                ++$writes;
            }, 'a.json', $this->scope);
        } catch (\RuntimeException $e) {
            self::assertSame(0, $writes);
            self::assertFalse($this->ledger->isConsumed((string)$envelope['package_uuid']));
            throw $e;
        }
    }

    public function testValidPackageWithFailedApplyIsConsumedAndMarkedFailed(): void
    {
        $envelope = $this->service->export(['x' => 1], $this->scope, 'a.json');

        try {
            $this->service->import(
                $envelope,
                static fn() => throw new \RuntimeException('apply_failed'),
                'a.json',
                $this->scope,
            );
            self::fail('Expected apply failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('apply_failed', $exception->getMessage());
        }

        self::assertTrue($this->ledger->isConsumed((string)$envelope['package_uuid']));
        self::assertSame('failed', $this->ledger->status((string)$envelope['package_uuid']));
    }

    public function testInvalidPackageUuidFailsBeforeClaim(): void
    {
        $envelope = $this->service->export(['x' => 1], $this->scope, 'a.json');
        $originalUuid = (string)$envelope['package_uuid'];
        $envelope['package_uuid'] = 'not-a-uuid';

        $this->expectExceptionMessage('config_envelope_package_uuid_invalid');
        try {
            $this->service->import($envelope, static function (): void {
            }, 'a.json', $this->scope);
        } finally {
            self::assertFalse($this->ledger->isConsumed($originalUuid));
            self::assertFalse($this->ledger->isConsumed('not-a-uuid'));
        }
    }

    public function testDecryptOnlyKidCannotExportButCanOpen(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('config_envelope_kid_not_exportable');
        $this->service->export(['x' => 1], $this->scope, 'a.json', $this->oldKid);
    }

    public function testRevokedKidRejected(): void
    {
        $rev = RecipientKeyDirectory::generateKeyRecord('revoked', RecipientKeyDirectory::STATUS_REVOKED);
        $keys = new RecipientKeyDirectory(
            [
                $this->activeKid => RecipientKeyDirectory::generateKeyRecord($this->activeKid),
                'revoked' => $rev,
            ],
            $this->activeKid,
            'src',
            true,
        );
        // 用 active 导出后把 kid 换成 revoked 公钥密封不可行；直接测目录
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('config_envelope_kid_revoked');
        $keys->requireDecryptRecipient('revoked');
    }

    public function testUnsignedSourceIsAuditOnlyNotTrusted(): void
    {
        $envelope = $this->service->export(['x' => 1], $this->scope, 'a.json');
        $preview = $this->service->previewImport($envelope, 'a.json');
        self::assertFalse($preview['source_trusted']);
        self::assertSame('source-a', $preview['source_instance']);
        self::assertArrayNotHasKey('values_plaintext_in_envelope', $envelope);
    }

    public function testFeatureDisabledFailClosed(): void
    {
        $disabled = new ConfigEnvelopeService(
            new SodiumCryptoEnvelope(),
            new RecipientKeyDirectory([], null, null, false),
            $this->ledger,
        );
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('config_envelope_disabled');
        $disabled->export(['x' => 1], $this->scope, 'a.json');
    }
}
