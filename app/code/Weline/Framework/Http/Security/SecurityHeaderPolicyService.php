<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Security;

use Weline\Framework\App\Env;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Manager\ObjectManager;

/**
 * 安全响应头策略编排（TASK-P1D-001）。
 *
 * - Global 基线来自 Env `security.headers.*`
 * - Scope 覆盖只能收紧；弱值 → `security_policy_weaker_than_baseline`
 * - 激活需同版本 LKG（无 LKG 阻断）
 */
final class SecurityHeaderPolicyService
{
    public function __construct(
        private readonly ContentSecurityPolicyNormalizer $cspNormalizer = new ContentSecurityPolicyNormalizer(),
        private readonly CorsOriginPolicyNormalizer $corsNormalizer = new CorsOriginPolicyNormalizer(),
        private readonly SecurityPolicyLkgGate $lkgGate = new SecurityPolicyLkgGate(),
        private readonly SecurityHeaderPolicyOverrideProviderInterface $overrideProvider = new EmptySecurityHeaderPolicyOverrideProvider(),
    ) {
    }

    /**
     * @return array{csp:string,csp_report_only:string,cors_origins:string}
     */
    public function baselineFromEnv(): array
    {
        return [
            'csp' => $this->cspNormalizer->canonicalize(
                \trim((string)Env::get('security.headers.csp', ''))
            ),
            'csp_report_only' => $this->cspNormalizer->canonicalize(
                \trim((string)Env::get('security.headers.csp_report_only', ''))
            ),
            'cors_origins' => $this->corsNormalizer->stringify(
                $this->corsNormalizer->parse(\trim((string)Env::get('security.headers.cors_origins', '')))
            ),
        ];
    }

    /**
     * @param array{csp?:string,csp_report_only?:string,cors_origins?:string} $override
     * @return array{csp:string,csp_report_only:string,cors_origins:string}
     */
    public function resolveEffective(array $override = []): array
    {
        $base = $this->baselineFromEnv();
        $cspOverride = \trim((string)($override['csp'] ?? ''));
        $reportOverride = \trim((string)($override['csp_report_only'] ?? ''));
        $corsOverride = \trim((string)($override['cors_origins'] ?? ''));

        return [
            'csp' => $cspOverride === ''
                ? $base['csp']
                : $this->cspNormalizer->intersect($base['csp'], $this->cspNormalizer->canonicalize($cspOverride)),
            'csp_report_only' => $reportOverride === ''
                ? $base['csp_report_only']
                : $this->cspNormalizer->intersect(
                    $base['csp_report_only'],
                    $this->cspNormalizer->canonicalize($reportOverride)
                ),
            'cors_origins' => $corsOverride === ''
                ? $base['cors_origins']
                : $this->corsNormalizer->intersect($base['cors_origins'], $corsOverride),
        ];
    }

    /**
     * Resolve the current request Scope override through the installed
     * provider, then intersect it with the immutable Env baseline.
     *
     * @return array{csp:string,csp_report_only:string,cors_origins:string}
     */
    public function resolveCurrentEffective(): array
    {
        $provider = $this->overrideProvider;
        if ($provider instanceof EmptySecurityHeaderPolicyOverrideProvider) {
            try {
                $resolved = ObjectManager::getInstance(SecurityHeaderPolicyOverrideProviderInterface::class);
                if ($resolved instanceof SecurityHeaderPolicyOverrideProviderInterface) {
                    $provider = $resolved;
                }
            } catch (\Throwable) {
                // Framework-only/bootstrap contexts have no provider registry.
                // The immutable Env baseline remains the safe effective policy.
            }
        }

        return $this->resolveEffective($provider->currentOverride());
    }

    /**
     * Build the security headers for the current request.
     *
     * These headers are intentionally resolved at response time instead of
     * being persisted in FPC payloads: Scope policy and Origin may change
     * while the cached body remains valid.
     *
     * @return array<string, string>
     */
    public function resolveCurrentResponseHeaders(?string $requestOrigin = null): array
    {
        $policy = $this->resolveCurrentEffective();
        $headers = [
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'X-XSS-Protection' => '1; mode=block',
        ];

        if ($policy['csp_report_only'] !== '') {
            $headers['Content-Security-Policy-Report-Only'] = $policy['csp_report_only'];
        }
        if ($policy['csp'] !== '') {
            $headers['Content-Security-Policy'] = $policy['csp'];
        }

        $cors = \trim((string)$policy['cors_origins']);
        if ($cors === '' || $cors === '*') {
            return $headers;
        }

        $requestOrigin = $requestOrigin === null
            ? $this->currentRequestOrigin()
            : \trim($requestOrigin);
        $allowed = \preg_split('/\s+/', $cors) ?: [];
        if ($requestOrigin !== '' && \in_array(\rtrim($requestOrigin, '/'), $allowed, true)) {
            $headers['Access-Control-Allow-Origin'] = $requestOrigin;
            $headers['Vary'] = 'Origin';
        }

        return $headers;
    }

    private function currentRequestOrigin(): string
    {
        $origin = \trim((string)WelineEnv::server('HTTP_ORIGIN', ''));
        if ($origin === '') {
            $origin = \trim((string)WelineEnv::get('server.http_origin', ''));
        }
        if ($origin === '' && isset($_SERVER['HTTP_ORIGIN'])) {
            $origin = \trim((string)$_SERVER['HTTP_ORIGIN']);
        }

        return $origin;
    }

    /**
     * 写路径：拒绝弱于 Global 基线的覆盖。
     *
     * @param array{csp?:string,csp_report_only?:string,cors_origins?:string} $candidate
     */
    public function assertOverrideNotWeaker(array $candidate): void
    {
        $base = $this->baselineFromEnv();
        if (isset($candidate['csp']) && \trim((string)$candidate['csp']) !== '') {
            $this->cspNormalizer->assertNotWeaker(
                $this->cspNormalizer->canonicalize((string)$candidate['csp']),
                $base['csp'],
            );
        }
        if (isset($candidate['csp_report_only']) && \trim((string)$candidate['csp_report_only']) !== '') {
            $this->cspNormalizer->assertNotWeaker(
                $this->cspNormalizer->canonicalize((string)$candidate['csp_report_only']),
                $base['csp_report_only'] !== '' ? $base['csp_report_only'] : $base['csp'],
            );
        }
        if (isset($candidate['cors_origins']) && \trim((string)$candidate['cors_origins']) !== '') {
            $this->corsNormalizer->assertNotWeaker((string)$candidate['cors_origins'], $base['cors_origins']);
        }
    }

    /**
     * 将当前策略登记为 verified LKG（观察/验收后调用）。
     *
     * @param array{csp?:string,csp_report_only?:string,cors_origins?:string}|null $policy
     * @return array{schema_version:string,digest:string,verified_at:string}
     */
    public function registerLkg(
        ?array $policy = null,
        string $scopeKey = SecurityPolicyLkgGate::DEFAULT_SCOPE_KEY,
    ): array
    {
        if ($policy !== null) {
            $this->assertOverrideNotWeaker($policy);
        }
        $effective = $policy === null ? $this->baselineFromEnv() : $this->resolveEffective($policy);

        return $this->lkgGate->verifyAndStore(
            $effective['csp'],
            $effective['csp_report_only'],
            $effective['cors_origins'],
            $scopeKey,
        );
    }

    /**
     * 激活策略：无 LKG 或 digest/schema 不匹配则阻断。
     *
     * @param array{csp?:string,csp_report_only?:string,cors_origins?:string} $policy
     */
    public function assertCanActivate(
        array $policy,
        string $scopeKey = SecurityPolicyLkgGate::DEFAULT_SCOPE_KEY,
    ): void
    {
        $effective = $this->resolveEffective($policy);
        $this->assertOverrideNotWeaker($policy);
        $this->lkgGate->assertCanActivate(
            $effective['csp'],
            $effective['csp_report_only'],
            $effective['cors_origins'],
            $scopeKey,
        );
    }

    public function lkgGate(): SecurityPolicyLkgGate
    {
        return $this->lkgGate;
    }
}
