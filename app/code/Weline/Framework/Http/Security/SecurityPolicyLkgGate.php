<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Security;

use Weline\Framework\Manager\ObjectManager;

/**
 * 安全头策略 LKG（同 schema 版本已验证摘要才可激活；TASK-P1D-001）。
 *
 * 无 verified LKG 时激活阻断；绝不允许切换到更松或跨 schema 的策略。
 */
final class SecurityPolicyLkgGate
{
    public const ERROR_LKG_MISSING = 'security_policy_lkg_missing';
    public const ERROR_LKG_MISMATCH = 'security_policy_lkg_mismatch';
    public const ERROR_LKG_UNAVAILABLE = 'security_policy_lkg_unavailable';
    public const SCHEMA_VERSION = 'security-headers-v1';
    public const DEFAULT_SCOPE_KEY = 'global';

    private ?SecurityPolicyLkgRepositoryInterface $resolvedRepository = null;

    public function __construct(
        private readonly ?SecurityPolicyLkgRepositoryInterface $repository = null,
    ) {
    }

    public function clear(string $scopeKey = self::DEFAULT_SCOPE_KEY): void
    {
        $this->repository()->delete(self::SCHEMA_VERSION, $this->normalizeScopeKey($scopeKey));
    }

    /**
     * @return array{schema_version:string,scope_key:string,digest:string,verified_at:string}|null
     */
    public function getVerified(string $scopeKey = self::DEFAULT_SCOPE_KEY): ?array
    {
        return $this->repository()->find(self::SCHEMA_VERSION, $this->normalizeScopeKey($scopeKey));
    }

    public function digestFor(string $csp, string $cspReportOnly = '', string $corsOrigins = ''): string
    {
        return \hash('sha256', self::SCHEMA_VERSION . "\0" . $csp . "\0" . $cspReportOnly . "\0" . $corsOrigins);
    }

    public function verifyAndStore(
        string $csp,
        string $cspReportOnly = '',
        string $corsOrigins = '',
        string $scopeKey = self::DEFAULT_SCOPE_KEY,
    ): array {
        $digest = $this->digestFor($csp, $cspReportOnly, $corsOrigins);

        return $this->repository()->save(
            self::SCHEMA_VERSION,
            $this->normalizeScopeKey($scopeKey),
            $digest,
            \gmdate('c'),
        );
    }

    /**
     * 激活前：必须存在同 schema 的 verified LKG，且 digest 匹配（同版本切换）。
     */
    public function assertCanActivate(
        string $csp,
        string $cspReportOnly = '',
        string $corsOrigins = '',
        string $scopeKey = self::DEFAULT_SCOPE_KEY,
    ): void {
        $verified = $this->getVerified($scopeKey);
        if ($verified === null) {
            throw new \RuntimeException(self::ERROR_LKG_MISSING);
        }
        if (($verified['schema_version'] ?? '') !== self::SCHEMA_VERSION) {
            throw new \RuntimeException(self::ERROR_LKG_MISMATCH);
        }
        $digest = $this->digestFor($csp, $cspReportOnly, $corsOrigins);
        if (!\hash_equals((string)$verified['digest'], $digest)) {
            throw new \RuntimeException(self::ERROR_LKG_MISMATCH);
        }
    }

    private function repository(): SecurityPolicyLkgRepositoryInterface
    {
        if ($this->repository instanceof SecurityPolicyLkgRepositoryInterface) {
            return $this->repository;
        }
        if ($this->resolvedRepository instanceof SecurityPolicyLkgRepositoryInterface) {
            return $this->resolvedRepository;
        }

        try {
            $repository = ObjectManager::getInstance(SecurityPolicyLkgRepositoryInterface::class);
        } catch (\Throwable $e) {
            throw new \RuntimeException(self::ERROR_LKG_UNAVAILABLE, 0, $e);
        }
        if (!$repository instanceof SecurityPolicyLkgRepositoryInterface) {
            throw new \RuntimeException(self::ERROR_LKG_UNAVAILABLE);
        }

        return $this->resolvedRepository = $repository;
    }

    private function normalizeScopeKey(string $scopeKey): string
    {
        $scopeKey = \trim($scopeKey);
        if ($scopeKey === '' || \strlen($scopeKey) > 191 || \str_contains($scopeKey, "\0")) {
            throw new \InvalidArgumentException('security_policy_scope_key_invalid');
        }

        return $scopeKey;
    }
}
