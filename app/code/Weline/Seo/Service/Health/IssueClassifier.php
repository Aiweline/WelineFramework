<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Health;

/**
 * 把一次探测结果 + robots 判定 + 软 404 判定，归类成一个 issue_code。
 *
 * 顺序很重要：robots 屏蔽优先于内容判定；重定向按最终落点判定；
 * 403 先识别边缘/WAF 挑战，避免把所有 CF 拦截都当成站点 403。
 */
final class IssueClassifier
{
    /**
     * @param array<string, mixed> $probe
     * @param array{state?:string, rule?:string, line?:int, reason?:string} $robots
     * @param array{matched?:bool, reason?:string, markers?:list<string>} $soft404
     * @return array{issue_code:string, severity:string, evidence:array<string, mixed>}
     */
    public function classify(array $probe, array $robots = [], array $soft404 = []): array
    {
        $status = (int)($probe['status'] ?? 0);
        $errorType = (string)($probe['error_type'] ?? '');
        $redirects = is_array($probe['redirects'] ?? null) ? $probe['redirects'] : [];
        $redirectLoop = (bool)($probe['redirect_loop'] ?? false);
        $signals = HtmlSignals::extract((string)($probe['body'] ?? ''), (string)($probe['final_url'] ?? ''));

        $evidence = [
            'status' => $status,
            'final_url' => (string)($probe['final_url'] ?? ''),
            'requested_url' => (string)($probe['requested_url'] ?? ''),
            'redirect_count' => count($redirects),
            'redirects' => $redirects,
            'redirect_loop' => $redirectLoop,
            'robots' => [
                'state' => (string)($robots['state'] ?? 'unknown'),
                'rule' => (string)($robots['rule'] ?? ''),
                'line' => (int)($robots['line'] ?? 0),
                'reason' => (string)($robots['reason'] ?? ''),
            ],
            'soft_404' => [
                'matched' => (bool)($soft404['matched'] ?? false),
                'reason' => (string)($soft404['reason'] ?? ''),
                'markers' => $soft404['markers'] ?? [],
            ],
            'noindex' => $signals['noindex'],
            'canonical' => $signals['canonical'],
            'canonical_mismatch' => $signals['canonical_mismatch'],
            'error' => (string)($probe['error'] ?? ''),
            'error_type' => $errorType,
            'duration_ms' => (int)($probe['duration_ms'] ?? 0),
        ];

        if ($errorType === 'timeout') {
            return $this->make(HealthIssueCatalog::TIMEOUT, $evidence);
        }
        if ($errorType === 'dns' || $errorType === 'tls') {
            return $this->make(HealthIssueCatalog::DNS_TLS_ERROR, $evidence);
        }
        if ($errorType === 'redirect_loop' || $redirectLoop) {
            return $this->make(HealthIssueCatalog::REDIRECT_BROKEN, $evidence);
        }
        if ($status === 0) {
            return $this->make(
                $errorType === 'connect' ? HealthIssueCatalog::CONNECTION_ERROR : HealthIssueCatalog::UNKNOWN,
                $evidence
            );
        }

        $robotsBlocked = (string)($robots['state'] ?? '') === 'blocked';
        if ($robotsBlocked && $status < 400) {
            return $this->make(HealthIssueCatalog::ROBOTS_BLOCKED, $evidence);
        }

        if ($redirects !== []) {
            if ($status >= 400) {
                return $this->make(HealthIssueCatalog::REDIRECT_BROKEN, $evidence);
            }
            if ($status >= 200 && $status < 300) {
                $finalUrl = (string)($probe['final_url'] ?? '');
                $requestedUrl = (string)($probe['requested_url'] ?? '');
                return $this->make(
                    UrlHealthKey::sameOrigin($finalUrl, $requestedUrl)
                        ? HealthIssueCatalog::REDIRECT_OK
                        : HealthIssueCatalog::REDIRECT_EXTERNAL,
                    $evidence
                );
            }
            if ($status >= 300 && $status < 400) {
                return $this->make(HealthIssueCatalog::REDIRECT_BROKEN, $evidence);
            }
        }

        if ($status >= 200 && $status < 300) {
            if (!empty($soft404['matched'])) {
                return $this->make(HealthIssueCatalog::SOFT_404, $evidence);
            }
            if ($signals['noindex']) {
                return $this->make(HealthIssueCatalog::NOINDEX_IN_SITEMAP, $evidence);
            }
            if ($signals['canonical_mismatch']) {
                return $this->make(HealthIssueCatalog::CANONICAL_MISMATCH, $evidence);
            }

            return $this->make(HealthIssueCatalog::OK, $evidence);
        }

        if ($status === 401) {
            return $this->make(HealthIssueCatalog::UNAUTHORIZED, $evidence);
        }
        if ($status === 403) {
            return $this->make($this->looksLikeEdgeBlock($probe) ? HealthIssueCatalog::EDGE_BLOCKED : HealthIssueCatalog::FORBIDDEN, $evidence);
        }
        if ($status === 404) {
            return $this->make(HealthIssueCatalog::NOT_FOUND, $evidence);
        }
        if ($status === 410) {
            return $this->make(HealthIssueCatalog::GONE, $evidence);
        }
        if ($status === 429) {
            return $this->make(HealthIssueCatalog::RATE_LIMITED, $evidence);
        }
        if ($status >= 400 && $status < 500) {
            return $this->make(HealthIssueCatalog::OTHER_4XX, $evidence);
        }
        if ($status === 503) {
            return $this->make(HealthIssueCatalog::SERVER_503, $evidence);
        }
        if ($status >= 500 && $status < 600) {
            return $this->make(HealthIssueCatalog::SERVER_5XX, $evidence);
        }

        return $this->make(HealthIssueCatalog::UNKNOWN, $evidence);
    }

    /** @param array<string, mixed> $evidence @return array{issue_code:string, severity:string, evidence:array<string, mixed>} */
    private function make(string $code, array $evidence): array
    {
        return [
            'issue_code' => $code,
            'severity' => (string)(HealthIssueCatalog::meta($code)['severity'] ?? 'low'),
            'evidence' => $evidence,
        ];
    }

    /** @param array<string, mixed> $probe */
    private function looksLikeEdgeBlock(array $probe): bool
    {
        $headers = is_array($probe['headers'] ?? null) ? $probe['headers'] : [];
        // 只有明确的边缘介入标记才算，不能仅凭 server: cloudflare
        // （Cloudflare 代理的正常站点 403 也会带这个头，容易把站点 403 误判）。
        if (isset($headers['cf-mitigated'])
            || isset($headers['x-sucuri-block'])
            || isset($headers['x-waf-action'])
        ) {
            return true;
        }
        $body = strtolower((string)($probe['body'] ?? ''));

        return str_contains($body, 'just a moment')
            || str_contains($body, 'cf-challenge')
            || str_contains($body, 'cf_chl')
            || str_contains($body, 'attention required! | cloudflare');
    }
}
