<?php

declare(strict_types=1);

namespace Weline\Framework\System\Security;

/**
 * 跨实例配置包 AEAD 信封（TASK-P1D-003 / DEC-021）。
 *
 * 禁止回退到 {@see Encrypt}（MD5/random）；libsodium 不可用则 fail-closed。
 */
interface CryptoEnvelopeInterface
{
    public const SCHEMA_VERSION = 'weline-config-envelope/1';

    /**
     * @param array<string, mixed> $aadFields 进入 AAD 的业务字段（不含密文）
     * @return array<string, mixed> 可序列化的 envelope 容器
     */
    public function seal(
        string $plaintext,
        string $recipientPublicKeyBinary,
        string $recipientKid,
        array $aadFields,
    ): array;

    /**
     * @param array<string, mixed> $envelope
     */
    public function open(
        array $envelope,
        string $recipientKeyPairBinary,
        ?string $expectedFilename = null,
    ): string;

    /**
     * 仅校验结构与 AEAD，不要求业务侧写库。
     *
     * @param array<string, mixed> $envelope
     */
    public function preview(
        array $envelope,
        string $recipientKeyPairBinary,
        ?string $expectedFilename = null,
    ): string;
}
