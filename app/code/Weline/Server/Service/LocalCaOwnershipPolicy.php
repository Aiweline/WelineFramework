<?php

declare(strict_types=1);

/**
 * Weline Server - 本机共享 Local CA 所有权判定
 *
 * 整机共享态（macOS System/login Keychain、Linux 系统信任锚目录）里可能同时存在
 * 多个仓各自签发的 `Weline Local Development CA`。CN 相同不代表同一把钥，
 * 更不代表归本仓所有（`machine_shared_side_effects_require_workspace_scope`）。
 *
 * 本类只做纯判定，不读写磁盘、不执行命令，便于单测覆盖：
 * - 只有「本仓生成 + 未共享给其他项目 + 有本仓签发证据」的指纹才允许从共享信任库删除；
 * - Linux 系统信任锚文件名带 CA 指纹，多个项目并存时互不覆盖。
 */
namespace Weline\Server\Service;

final class LocalCaOwnershipPolicy
{
    /** 本仓 WLS 自己生成的 CA。 */
    public const ORIGIN_GENERATED = 'generated';
    /** 从整机共享 _local_ca 目录继承来的 CA（可能由兄弟项目生成）。 */
    public const ORIGIN_ADOPTED_GLOBAL = 'adopted_global';
    /** 本功能上线前就存在的 CA，来源不可考。 */
    public const ORIGIN_LEGACY_UNKNOWN = 'legacy_unknown';

    public const SCHEMA = 'wls-local-ca-provenance/1';

    /** Linux 系统信任锚文件名前缀（其余为 CA 指纹片段）。 */
    public const LINUX_TRUST_ANCHOR_PREFIX = 'weline-local-development-ca-';

    /** 与历史实现兼容的固定文件名，只读、不再写入。 */
    public const LINUX_LEGACY_TRUST_ANCHOR = 'weline-local-development-ca.crt';

    public const DECISION_RETIREABLE = 'workspace_owned';
    public const DECISION_MISSING_FINGERPRINT = 'missing_fingerprint';
    public const DECISION_NO_PROVENANCE = 'no_workspace_provenance';
    public const DECISION_FOREIGN_OR_ADOPTED = 'foreign_or_adopted_origin';
    public const DECISION_SHARED = 'shared_with_other_projects';
    public const DECISION_NO_ISSUANCE_PROOF = 'no_workspace_issuance_proof';

    /**
     * 是否允许从整机共享信任库删除该 CA 指纹。
     *
     * 判据（全部满足才允许）：
     * 1. 本仓有来源记录，且来源是 `generated`（自家生成，而不是继承/未知）；
     * 2. 没有共享给其他项目（未发布到整机 _local_ca，也未被其他项目沿用）；
     * 3. 有本仓签发证据：本仓数据库记录/磁盘叶证能证明该 CA 确实签发过本仓域名。
     *
     * @param array<string,mixed>|null $provenance 本仓 CA 来源记录
     * @param list<string> $proofDomains 本仓可证明由该 CA 签发的域名
     * @return array{retireable:bool,reason:string}
     */
    public static function deletionDecision(
        ?array $provenance,
        array $proofDomains,
        string $fingerprint,
    ): array {
        $fingerprint = self::normalizeFingerprint($fingerprint);
        if ($fingerprint === '') {
            return ['retireable' => false, 'reason' => self::DECISION_MISSING_FINGERPRINT];
        }
        if ($provenance === null) {
            return ['retireable' => false, 'reason' => self::DECISION_NO_PROVENANCE];
        }
        if ((string)($provenance['origin'] ?? '') !== self::ORIGIN_GENERATED) {
            return ['retireable' => false, 'reason' => self::DECISION_FOREIGN_OR_ADOPTED];
        }
        if (!empty($provenance['shared'])) {
            return ['retireable' => false, 'reason' => self::DECISION_SHARED];
        }

        $recorded = self::normalizeDomains($provenance['domains'] ?? []);
        $proof = self::normalizeDomains($proofDomains);
        if ($recorded === [] && $proof === []) {
            return ['retireable' => false, 'reason' => self::DECISION_NO_ISSUANCE_PROOF];
        }

        return ['retireable' => true, 'reason' => self::DECISION_RETIREABLE];
    }

    /**
     * Linux 系统信任锚文件名。以 CA 指纹作后缀，兄弟项目各写各的文件，
     * 不会再互相覆盖 `/usr/local/share/ca-certificates/weline-local-development-ca.crt`。
     */
    public static function linuxTrustAnchorFilename(string $fingerprint): string
    {
        $normalized = self::normalizeFingerprint($fingerprint);
        $suffix = $normalized !== ''
            ? \strtolower(\substr($normalized, 0, 16))
            : \substr(\hash('sha256', $fingerprint), 0, 16);

        return self::LINUX_TRUST_ANCHOR_PREFIX . $suffix . '.crt';
    }

    public static function normalizeFingerprint(string $fingerprint): string
    {
        return \strtoupper((string)\preg_replace('/[^A-F0-9]/i', '', $fingerprint));
    }

    /**
     * @param mixed $domains
     * @return list<string>
     */
    public static function normalizeDomains(mixed $domains): array
    {
        if (!\is_array($domains)) {
            return [];
        }
        $normalized = [];
        foreach ($domains as $domain) {
            if (!\is_string($domain)) {
                continue;
            }
            $domain = \strtolower(\trim($domain));
            if ($domain === '' || \strlen($domain) > 255) {
                continue;
            }
            $normalized[$domain] = $domain;
        }

        return \array_values($normalized);
    }
}
