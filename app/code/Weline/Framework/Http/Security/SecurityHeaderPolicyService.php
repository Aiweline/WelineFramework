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
        private readonly ?CspSourceContributionRegistry $cspContributions = null,
    ) {
    }

    /**
     * @return array{csp:string,csp_report_only:string,cors_origins:string}
     */
    public function baselineFromEnv(): array
    {
        $csp = \trim((string)Env::get('security.headers.csp', ''));
        $cspReportOnly = \trim((string)Env::get('security.headers.csp_report_only', ''));
        $corsOrigins = \trim((string)Env::get('security.headers.cors_origins', ''));

        $cspBase = $this->cspNormalizer->canonicalize(
            $csp !== '' ? $csp : SecurityHeaderDefaults::CSP
        );
        $reportBase = $this->cspNormalizer->canonicalize(
            $cspReportOnly !== '' ? $cspReportOnly : SecurityHeaderDefaults::CSP_REPORT_ONLY
        );
        $moduleFragment = $this->moduleCspFragment();
        if ($moduleFragment !== '') {
            $cspBase = $this->cspNormalizer->union($cspBase, $moduleFragment);
            // Report-Only 关闭（空）时不要被模块 fragment 重新撑开，否则会与强制 CSP 重复占头。
            if ($reportBase !== '') {
                $reportBase = $this->cspNormalizer->union($reportBase, $moduleFragment);
            }
        }

        return [
            'csp' => $cspBase,
            'csp_report_only' => $reportBase,
            'cors_origins' => $this->corsNormalizer->stringify(
                $this->corsNormalizer->parse(
                    $corsOrigins !== '' ? $corsOrigins : SecurityHeaderDefaults::CORS_ORIGINS
                )
            ),
        ];
    }

    private function cspRegistry(): ?CspSourceContributionRegistry
    {
        $registry = $this->cspContributions;
        if ($registry instanceof CspSourceContributionRegistry) {
            return $registry;
        }
        try {
            $resolved = ObjectManager::getInstance(CspSourceContributionRegistry::class);
            if ($resolved instanceof CspSourceContributionRegistry) {
                return $resolved;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function moduleCspFragment(): string
    {
        $registry = $this->cspRegistry();
        if ($registry === null) {
            return '';
        }
        try {
            return \trim($registry->aggregatePolicy());
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Application-default CSP floor from Extends (Scope cannot remove these sources).
     */
    public function appDefaultCsp(): string
    {
        $registry = $this->cspRegistry();
        if ($registry === null) {
            return '';
        }
        try {
            return \trim($registry->appDefaultPolicy());
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @param array{csp?:string,csp_report_only?:string,cors_origins?:string} $override
     * @return array{csp:string,csp_report_only:string,cors_origins:string}
     */
    public function resolveEffective(array $override = []): array
    {
        $base = $this->baselineFromEnv();
        $floor = $this->appDefaultCsp();
        $cspOverride = \trim((string)($override['csp'] ?? ''));
        $reportOverride = \trim((string)($override['csp_report_only'] ?? ''));
        $corsOverride = \trim((string)($override['cors_origins'] ?? ''));

        $csp = $cspOverride === ''
            ? $base['csp']
            : $this->cspNormalizer->intersect($base['csp'], $this->cspNormalizer->canonicalize($cspOverride));
        $report = $reportOverride === ''
            ? $base['csp_report_only']
            : $this->cspNormalizer->intersect(
                $base['csp_report_only'],
                $this->cspNormalizer->canonicalize($reportOverride)
            );
        if ($floor !== '') {
            $csp = $this->cspNormalizer->union($csp, $floor);
            if ($report !== '') {
                $report = $this->cspNormalizer->union($report, $floor);
            }
        }

        return [
            'csp' => $csp,
            'csp_report_only' => $report,
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
     * DEV/DEBUG 时若 Env `security.headers.csp_developer_tooling` 非空，则 union 进当次响应；
     * 不进入 baselineFromEnv / appDefaultCsp / LKG。
     *
     * CSP 交付：
     * - `csp_delivery=meta`（默认）：不把大 CSP 写入 HTTP 头，改由 HTML meta（见 ensureDocumentCspMeta）
     * - `csp_delivery=header`：写入 Content-Security-Policy；与强制 CSP 相同的 Report-Only 会被省略
     * - `$includeDocumentCsp=false`：API/REST 等非文档响应跳过 CSP 头
     *
     * @param bool|null $developerToolingEnabled null = 按 DEV||DEBUG 自动检测；单测可显式传入
     * @return array<string, string>
     */
    public function resolveCurrentResponseHeaders(
        ?string $requestOrigin = null,
        ?bool $developerToolingEnabled = null,
        ?bool $includeDocumentCsp = null,
    ): array
    {
        $policy = $this->withDeveloperToolingCsp(
            $this->resolveCurrentEffective(),
            $developerToolingEnabled ?? self::isDeveloperToolingEnabled(),
        );
        $headers = [
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'X-XSS-Protection' => '1; mode=block',
        ];

        $includeCsp = $includeDocumentCsp ?? true;
        $delivery = $this->cspDelivery();
        if ($includeCsp && $delivery === SecurityHeaderDefaults::CSP_DELIVERY_HEADER) {
            $csp = $this->cspNormalizer->compactForWire((string)$policy['csp']);
            $report = $this->cspNormalizer->compactForWire((string)$policy['csp_report_only']);
            // 与强制 CSP 完全相同时省略 Report-Only，避免响应头体积翻倍。
            if ($report !== '' && $report !== $csp) {
                $headers['Content-Security-Policy-Report-Only'] = $report;
            }
            if ($csp !== '') {
                $headers['Content-Security-Policy'] = $csp;
            }
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

    /**
     * Current CSP delivery mode (`header` | `meta`).
     */
    public function cspDelivery(): string
    {
        $configured = \strtolower(\trim((string)Env::get(
            'security.headers.csp_delivery',
            SecurityHeaderDefaults::CSP_DELIVERY
        )));
        if ($configured === SecurityHeaderDefaults::CSP_DELIVERY_HEADER) {
            return SecurityHeaderDefaults::CSP_DELIVERY_HEADER;
        }

        return SecurityHeaderDefaults::CSP_DELIVERY_META;
    }

    public function isMetaDelivery(): bool
    {
        return $this->cspDelivery() === SecurityHeaderDefaults::CSP_DELIVERY_META;
    }

    /**
     * Wire-ready enforcing CSP (compacted), including DEV tooling when enabled.
     */
    public function resolveCurrentDocumentCsp(?bool $developerToolingEnabled = null): string
    {
        $policy = $this->withDeveloperToolingCsp(
            $this->resolveCurrentEffective(),
            $developerToolingEnabled ?? self::isDeveloperToolingEnabled(),
        );

        return $this->cspNormalizer->compactForWire((string)$policy['csp']);
    }

    /**
     * FPC formatted-cache variant material: must include document CSP even in meta
     * delivery, otherwise policy changes would not bust precompressed bodies.
     */
    public function securityVariantMaterial(?bool $developerToolingEnabled = null): string
    {
        $headers = $this->resolveCurrentResponseHeaders(null, $developerToolingEnabled);
        \ksort($headers, \SORT_STRING);

        return \serialize($headers) . "\0" . $this->resolveCurrentDocumentCsp($developerToolingEnabled);
    }

    /**
     * Ensure HTML carries a single Weline CSP meta tag (meta delivery).
     * Idempotent: replaces any prior `data-weline-csp="1"` meta.
     */
    public function ensureDocumentCspMeta(string $html): string
    {
        if (!$this->isMetaDelivery() || $html === '') {
            return $html;
        }
        $csp = $this->resolveCurrentDocumentCsp();
        if ($csp === '') {
            return $html;
        }

        $html = \preg_replace(
            '/<meta\b[^>]*\bdata-weline-csp\s*=\s*(["\'])1\1[^>]*>\s*/i',
            '',
            $html
        ) ?? $html;

        $content = \htmlspecialchars($csp, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $tag = '<meta http-equiv="Content-Security-Policy" content="' . $content . '" data-weline-csp="1">';

        if (\preg_match('/<head\b[^>]*>/i', $html, $match, \PREG_OFFSET_CAPTURE) === 1) {
            $insertAt = (int)$match[0][1] + \strlen($match[0][0]);

            return \substr($html, 0, $insertAt) . "\n" . $tag . \substr($html, $insertAt);
        }

        return $tag . "\n" . $html;
    }

    /**
     * @param array{csp:string,csp_report_only:string,cors_origins:string} $policy
     * @return array{csp:string,csp_report_only:string,cors_origins:string}
     */
    public function withDeveloperToolingCsp(array $policy, bool $enabled): array
    {
        if (!$enabled) {
            return $policy;
        }

        $fragment = \trim((string)Env::get('security.headers.csp_developer_tooling', ''));
        if ($fragment === '') {
            return $policy;
        }

        $csp = \trim((string)($policy['csp'] ?? ''));
        $report = \trim((string)($policy['csp_report_only'] ?? ''));
        $policy['csp'] = $csp === ''
            ? $this->cspNormalizer->canonicalize($fragment)
            : $this->cspNormalizer->union($csp, $fragment);
        if ($report !== '') {
            $policy['csp_report_only'] = $this->cspNormalizer->union($report, $fragment);
        }

        return $policy;
    }

    public static function isDeveloperToolingEnabled(): bool
    {
        return (\defined('DEV') && DEV) || (\defined('DEBUG') && DEBUG);
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
        $floor = $this->appDefaultCsp();
        if (isset($candidate['csp']) && \trim((string)$candidate['csp']) !== '') {
            $csp = $this->cspNormalizer->canonicalize((string)$candidate['csp']);
            $this->cspNormalizer->assertNotWeaker($csp, $base['csp']);
            $this->cspNormalizer->assertContainsAppDefaults($csp, $floor);
        }
        if (isset($candidate['csp_report_only']) && \trim((string)$candidate['csp_report_only']) !== '') {
            $report = $this->cspNormalizer->canonicalize((string)$candidate['csp_report_only']);
            $this->cspNormalizer->assertNotWeaker(
                $report,
                $base['csp_report_only'] !== '' ? $base['csp_report_only'] : $base['csp'],
            );
            $this->cspNormalizer->assertContainsAppDefaults($report, $floor);
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
