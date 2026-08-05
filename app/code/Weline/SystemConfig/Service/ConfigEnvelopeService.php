<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Service;

use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\System\Security\CryptoEnvelopeInterface;
use Weline\Framework\System\Security\RecipientKeyDirectory;
use Weline\Framework\System\Security\SodiumCryptoEnvelope;

/**
 * 配置包导出/预览/导入（TASK-P1D-003）。
 *
 * - 导出：仅 active kid；短 TTL；返回 envelope（一次性下载由调用方负责）
 * - 导入：先验签密文/AAD/Scope，再 CAS claim ledger 与 apply；apply 失败标记 failed 且 UUID 仍占用
 * - source_instance / 无签名：仅审计，不构成信任
 * - 禁止回退明文 / Encrypt
 */
final class ConfigEnvelopeService
{
    public function __construct(
        private readonly CryptoEnvelopeInterface $crypto,
        private readonly RecipientKeyDirectory $keys,
        private readonly ConfigPackageConsumptionLedgerInterface $ledger,
        private readonly int $defaultTtlSeconds = 3600,
        private readonly int $maxPayloadBytes = 5_000_000,
    ) {
    }

    public static function fromEnv(?ConfigPackageConsumptionLedgerInterface $ledger = null): self
    {
        return new self(
            new SodiumCryptoEnvelope(),
            RecipientKeyDirectory::fromEnv(),
            $ledger ?? new OrmConfigPackageConsumptionLedger(),
        );
    }

    public function assertFeatureEnabled(): void
    {
        SodiumCryptoEnvelope::assertSodium();
        if (!$this->keys->isEnabled()) {
            throw new \RuntimeException('config_envelope_disabled');
        }
        $this->keys->instanceId();
    }

    /**
     * @param array<string, mixed> $payload 配置 payload（将被 JSON 编码后密封）
     * @return array<string, mixed> envelope（无明文）
     */
    public function export(
        array $payload,
        ScopeIdentity $scope,
        string $filename,
        ?string $recipientKid = null,
        ?int $ttlSeconds = null,
    ): array {
        $this->assertFeatureEnabled();
        $kid = $recipientKid ?: $this->keys->activeKid();
        if ($kid === null || $kid === '') {
            throw new \RuntimeException('config_envelope_no_active_kid');
        }
        $recipient = $this->keys->requireExportRecipient($kid);
        $json = \json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('config_envelope_payload_encode_failed');
        }
        if (\strlen($json) > $this->maxPayloadBytes) {
            throw new \RuntimeException('config_envelope_payload_too_large');
        }
        $ttl = $ttlSeconds ?? $this->defaultTtlSeconds;
        if ($ttl < 60 || $ttl > 86400) {
            throw new \InvalidArgumentException('config_envelope_ttl_invalid');
        }
        $now = \time();
        $envelope = $this->crypto->seal(
            $json,
            $recipient['public_key'],
            $kid,
            [
                'filename' => $filename,
                'scope' => $scope->canonicalKey(),
                'source_instance' => $this->keys->instanceId(),
                'created_at' => \gmdate('c', $now),
                'expires_at' => \gmdate('c', $now + $ttl),
            ],
        );
        // 审计：source_instance 进入 AAD，默认不验签名
        unset($json);

        return $envelope;
    }

    /**
     * 解密预览（不 claim、不写入）。
     *
     * @param array<string, mixed> $envelope
     * @return array{payload:array<string,mixed>,aad:array<string,mixed>,package_uuid:string,recipient_kid:string}
     */
    public function previewImport(array $envelope, ?string $expectedFilename = null): array
    {
        $this->assertFeatureEnabled();
        $kid = (string)($envelope['recipient_kid'] ?? '');
        $keypair = $this->keys->keyPairFor($kid);
        $plain = $this->crypto->preview($envelope, $keypair, $expectedFilename);
        $payload = \json_decode($plain, true);
        if (!\is_array($payload)) {
            throw new \RuntimeException('config_envelope_payload_corrupt');
        }
        $aad = \json_decode((string)($envelope['aad'] ?? '{}'), true);
        if (!\is_array($aad)) {
            $aad = [];
        }

        return [
            'payload' => $payload,
            'aad' => $aad,
            'package_uuid' => (string)($envelope['package_uuid'] ?? ''),
            'recipient_kid' => $kid,
            // 未签名来源仅审计
            'source_trusted' => false,
            'source_instance' => (string)($aad['source_instance'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $envelope
     * @param callable(array<string,mixed>, array<string,mixed>):void $applier payload, aad
     */
    public function import(
        array $envelope,
        callable $applier,
        ?string $expectedFilename = null,
        ?ScopeIdentity $expectedScope = null,
    ): void {
        $this->assertFeatureEnabled();
        $uuid = (string)($envelope['package_uuid'] ?? '');
        $kid = (string)($envelope['recipient_kid'] ?? '');
        if ($uuid === '' || $kid === '') {
            throw new \RuntimeException('config_envelope_incomplete');
        }
        // 先验密再 claim：畸形、篡改、错 kid、过期或文件名不符的包不得
        // 抢占合法 package_uuid。claim 后只包围真实业务 apply。
        $this->keys->requireDecryptRecipient($kid);
        $keypair = $this->keys->keyPairFor($kid);
        $plain = $this->crypto->open($envelope, $keypair, $expectedFilename);
        $payload = \json_decode($plain, true);
        if (!\is_array($payload)) {
            throw new \RuntimeException('config_envelope_payload_corrupt');
        }
        $aad = \json_decode((string)($envelope['aad'] ?? '{}'), true);
        if (!\is_array($aad)) {
            throw new \RuntimeException('config_envelope_aad_corrupt');
        }
        $scopeKey = (string)($aad['scope'] ?? '');
        if ($expectedScope !== null && $scopeKey !== $expectedScope->canonicalKey()) {
            throw new \RuntimeException('config_envelope_scope_mismatch');
        }
        $this->ledger->claim([
            'package_uuid' => $uuid,
            'recipient_kid' => $kid,
            'scope_key' => $scopeKey,
            'source_instance' => (string)($aad['source_instance'] ?? ''),
            'filename' => (string)($aad['filename'] ?? ''),
            'payload_hash' => (string)($envelope['payload_hash'] ?? ''),
        ]);
        try {
            $applier($payload, $aad);
            $this->ledger->markApplied($uuid);
        } catch (\Throwable $e) {
            $this->ledger->markFailed($uuid);
            throw $e;
        }
    }
}
