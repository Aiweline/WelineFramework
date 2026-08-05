<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Websites\Model\DomainPool;
use Weline\Websites\Model\Website;

/**
 * Local *.weline.test readiness for AI Site V2.
 *
 * `inspect*` stays read-only. `prepare` writes hosts/certificate only after an
 * explicit confirmed=true (browser auto-prepare or manual assist click).
 */
class AiSiteLocalDomainReadinessService
{
    public const WILDCARD_DOMAIN = '*.weline.test';

    private const MAX_CANDIDATES = 50;

    /** @var null|\Closure(string):array */
    private ?\Closure $hostResolver;

    /** @var null|\Closure(string):mixed */
    private ?\Closure $certificateResolver;

    /** @var null|\Closure(int):array */
    private ?\Closure $candidateLoader;

    /**
     * Optional closures are deterministic unit-test seams. Production uses the
     * actual operating-system resolver, Server QueryProvider, and DomainPool.
     *
     * @param null|\Closure(string):array $hostResolver
     * @param null|\Closure(string):mixed $certificateResolver
     * @param null|\Closure(int):array $candidateLoader
     */
    public function __construct(
        private readonly DomainPool $domainPool,
        ?\Closure $hostResolver = null,
        ?\Closure $certificateResolver = null,
        ?\Closure $candidateLoader = null,
        private readonly ?LocalWelineHostsSyncService $hostsSyncService = null,
        private readonly ?LocalWelineWildcardCertificateService $certificateEnsureService = null,
    ) {
        $this->hostResolver = $hostResolver;
        $this->certificateResolver = $certificateResolver;
        $this->candidateLoader = $candidateLoader;
    }

    /**
     * @return array{
     *     can_start:bool,
     *     code:string,
     *     message:string,
     *     domain:string,
     *     resolved_ips:list<string>,
     *     certificate_ready:bool,
     *     requires_admin:bool,
     *     preparation_command:string
     * }
     */
    public function inspect(string $domain): array
    {
        $domain = $this->normalizeDomain($domain);
        if ($domain === '') {
            return $this->result(
                false,
                'TEST_DOMAIN_INVALID',
                (string)__('本地测试域名必须是单标签 *.weline.test。'),
                '',
                [],
                false,
                false,
                ''
            );
        }

        return $this->inspectDomain($domain, $this->resolveWildcardCertificate());
    }

    /**
     * Prepared DomainPool rows are merged with requested/AI candidates, but are
     * trusted only as candidate sources. Every item is rechecked against the
     * actual OS resolver and the current managed wildcard certificate.
     *
     * @param list<mixed> $candidates
     * @return array{
     *     can_start:bool,
     *     code:string,
     *     message:string,
     *     domain:string,
     *     resolved_ips:list<string>,
     *     certificate_ready:bool,
     *     requires_admin:bool,
     *     preparation_command:string,
     *     candidates:list<array<string,mixed>>
     * }
     */
    public function inspectCandidates(array $candidates = [], int $limit = self::MAX_CANDIDATES): array
    {
        $limit = \max(1, \min(self::MAX_CANDIDATES, $limit));
        $preparedDomains = $this->loadPreparedCandidateDomains($limit);
        $requestedDomains = $this->normalizeCandidates($candidates, $limit);
        $preparedSet = \array_fill_keys($preparedDomains, true);
        $requestedSet = \array_fill_keys($requestedDomains, true);
        $domains = \array_slice(
            \array_values(\array_unique(\array_merge($preparedDomains, $requestedDomains))),
            0,
            $limit
        );
        $certificate = $this->resolveWildcardCertificate();
        $items = [];
        foreach ($domains as $domain) {
            $item = $this->inspectDomain($domain, $certificate);
            $prepared = isset($preparedSet[$domain]);
            $item['prepared'] = $prepared;
            $item['requested'] = isset($requestedSet[$domain]);
            $item['source'] = $prepared ? 'prepared_pool' : 'ai_candidate';
            $item['preparation_status'] = $item['can_start'] ? 'ready' : 'unprepared';
            $items[] = $item;
        }

        \usort($items, static function (array $left, array $right): int {
            $priority = static function (array $item): int {
                if (($item['can_start'] ?? false) === true) {
                    return ($item['prepared'] ?? false) === true ? 0 : 1;
                }

                return ($item['prepared'] ?? false) === true ? 2 : 3;
            };
            $comparison = $priority($left) <=> $priority($right);
            if ($comparison !== 0) {
                return $comparison;
            }

            return (string)($left['domain'] ?? '') <=> (string)($right['domain'] ?? '');
        });

        $selected = null;
        foreach ($items as $item) {
            if (($item['can_start'] ?? false) === true) {
                $selected = $item;
                break;
            }
        }
        if ($selected === null) {
            $selected = $items[0] ?? null;
        }
        if ($selected === null) {
            $base = $this->result(
                false,
                'NO_READY_LOCAL_DOMAIN',
                (string)__('没有可检查的本地测试域名。'),
                '',
                [],
                $this->isCertificateReady($certificate),
                false,
                ''
            );

            return $base + ['candidates' => []];
        }

        $canStart = ($selected['can_start'] ?? false) === true;
        $base = [
            'success' => true,
            'can_start' => $canStart,
            'code' => $canStart ? 'OK' : 'NO_READY_LOCAL_DOMAIN',
            'message' => $canStart
                ? (string)__('已找到可直接启动建站的本地测试域名。')
                : (string)__('本地候选域名尚未完成解析与证书准备。'),
            'domain' => (string)($selected['domain'] ?? ''),
            'resolved_ips' => \array_values((array)($selected['resolved_ips'] ?? [])),
            'certificate_ready' => ($selected['certificate_ready'] ?? false) === true,
            'requires_admin' => ($selected['requires_admin'] ?? false) === true,
            'preparation_command' => (string)($selected['preparation_command'] ?? ''),
        ];

        return $base + ['candidates' => $items];
    }

    /**
     * Auto/manual preparation for one local test domain.
     *
     * Default workbench path calls this with confirmed=true after a failed
     * inspect. Manual assist uses the same path when auto preparation failed
     * once and the operator clicks again.
     *
     * @return array<string,mixed>
     */
    public function prepare(string $domain, bool $confirmed): array
    {
        $domain = $this->normalizeDomain($domain);
        if ($domain === '') {
            return $this->result(
                false,
                'TEST_DOMAIN_INVALID',
                (string)__('本地测试域名必须是单标签 *.weline.test。'),
                '',
                [],
                false,
                false,
                ''
            ) + ['prepared_now' => false, 'hosts' => null, 'certificate' => null];
        }
        if (!$confirmed) {
            return $this->result(
                false,
                'LOCAL_DOMAIN_PREPARE_CONFIRMATION_REQUIRED',
                (string)__('准备本地域名需要明确确认后再写入 hosts / 证书。'),
                $domain,
                [],
                false,
                true,
                $this->hostsPreparationCommand($domain)
            ) + ['prepared_now' => false, 'hosts' => null, 'certificate' => null];
        }

        $current = $this->inspect($domain);
        if (($current['can_start'] ?? false) === true) {
            return $current + [
                'prepared_now' => false,
                'hosts' => null,
                'certificate' => null,
            ];
        }

        $hostsService = $this->hostsSyncService ?? new LocalWelineHostsSyncService();
        $hosts = $hostsService->ensureHostsInjected($domain);
        if (($hosts['success'] ?? false) !== true) {
            if (($hosts['authorization_pending'] ?? false) === true) {
                $message = \trim((string)($hosts['message'] ?? ''))
                    ?: (string)__('正在等待 macOS 管理员批准精确 hosts 映射。');

                return $this->result(
                    false,
                    'TEST_DOMAIN_HOSTS_AUTHORIZATION_PENDING',
                    $message,
                    $domain,
                    \is_array($current['resolved_ips'] ?? null) ? $current['resolved_ips'] : [],
                    ($current['certificate_ready'] ?? false) === true,
                    true,
                    ''
                ) + [
                    'prepared_now' => false,
                    'hosts' => $hosts,
                    'certificate' => null,
                    'authorization_pending' => true,
                ];
            }
            $hostsMessage = \trim((string)($hosts['message'] ?? ''));
            // Browser/API responses expose only the stable project CLI entry;
            // platform-specific encoded elevation payloads stay server-side.
            $preparationCommand = $this->hostsPreparationCommand($domain);
            $message = $hostsMessage !== ''
                ? $hostsMessage
                : (string)__('本机 hosts 自动写入失败；可点击协助处理或由管理员手动准备。');
            if (!empty($hosts['needs_admin']) && $preparationCommand !== '') {
                $message .= ' ' . (string)__(
                    '请在本机终端执行：%{command}',
                    ['command' => $preparationCommand]
                );
            }

            return $this->result(
                false,
                'TEST_DOMAIN_HOSTS_FAILED',
                $message,
                $domain,
                \is_array($current['resolved_ips'] ?? null) ? $current['resolved_ips'] : [],
                ($current['certificate_ready'] ?? false) === true,
                true,
                $preparationCommand
            ) + [
                'prepared_now' => false,
                'hosts' => $hosts,
                'certificate' => null,
            ];
        }

        $certificateService = $this->certificateEnsureService ?? new LocalWelineWildcardCertificateService();
        $certificate = $certificateService->ensureWildcardCertificateForDomain($domain, Website::ID_DEFAULT);
        if (($certificate['success'] ?? false) !== true) {
            return $this->result(
                false,
                'TEST_DOMAIN_CERTIFICATE_FAILED',
                \trim((string)($certificate['message'] ?? ''))
                    ?: (string)__('本地通配证书自动准备失败；请确认 WLS 已启动后点击协助处理。'),
                $domain,
                \is_array($current['resolved_ips'] ?? null) ? $current['resolved_ips'] : [],
                false,
                true,
                'php bin/w server:start'
            ) + [
                'prepared_now' => false,
                'hosts' => $hosts,
                'certificate' => $certificate,
            ];
        }

        $ready = $this->inspect($domain);

        return $ready + [
            'prepared_now' => ($ready['can_start'] ?? false) === true,
            'hosts' => $hosts,
            'certificate' => $certificate,
            'message' => ($ready['can_start'] ?? false) === true
                ? (string)__('本地域名已自动完成 hosts 与证书准备，可以启动 AI 建站。')
                : (
                    \trim((string)($ready['message'] ?? ''))
                    ?: (string)__('已尝试自动准备，但系统复检仍未通过；请点击协助处理。')
                ),
            'requires_admin' => ($ready['can_start'] ?? false) !== true,
        ];
    }

    /** @return array<string,mixed> */
    private function inspectDomain(string $domain, mixed $certificate): array
    {
        $resolvedIps = $this->resolveSystemIps($domain);
        $certificateReady = $this->isCertificateReady($certificate);
        if ($resolvedIps === []) {
            return $this->result(
                false,
                'TEST_DOMAIN_RESOLUTION_MISSING',
                (string)__('本地测试域名尚未通过系统解析。'),
                $domain,
                [],
                $certificateReady,
                true,
                $this->hostsPreparationCommand($domain)
            );
        }
        foreach ($resolvedIps as $ip) {
            if (!$this->isLoopbackIp($ip)) {
                return $this->result(
                    false,
                    'TEST_DOMAIN_RESOLUTION_CONFLICT',
                    (string)__('本地测试域名解析到了非回环地址，已阻止启动。'),
                    $domain,
                    $resolvedIps,
                    $certificateReady,
                    true,
                    $this->hostsPreparationCommand($domain)
                );
            }
        }
        if (!$certificateReady) {
            return $this->result(
                false,
                'TEST_DOMAIN_CERTIFICATE_UNAVAILABLE',
                (string)__('本地 *.weline.test 通配证书未激活或已过期。'),
                $domain,
                $resolvedIps,
                false,
                false,
                'php bin/w server:start'
            );
        }

        return $this->result(
            true,
            'OK',
            (string)__('本地测试域名解析与通配证书均已就绪。'),
            $domain,
            $resolvedIps,
            true,
            false,
            ''
        );
    }

    /** @return list<string> */
    protected function resolveSystemIps(string $domain): array
    {
        if ($this->hostResolver instanceof \Closure) {
            try {
                return $this->normalizeIps(($this->hostResolver)($domain));
            } catch (\Throwable) {
                return [];
            }
        }
        if (!\function_exists('socket_addrinfo_lookup') || !\function_exists('socket_addrinfo_explain')) {
            return [];
        }

        $addresses = @\socket_addrinfo_lookup($domain, null, [
            'ai_socktype' => \SOCK_STREAM,
        ]);
        if (!\is_array($addresses)) {
            return [];
        }

        $ips = [];
        foreach ($addresses as $address) {
            $explained = @\socket_addrinfo_explain($address);
            if (!\is_array($explained)) {
                continue;
            }
            $socketAddress = $explained['ai_addr'] ?? null;
            if (!\is_array($socketAddress)) {
                continue;
            }
            $ip = (string)($socketAddress['sin_addr'] ?? $socketAddress['sin6_addr'] ?? '');
            if ($ip !== '') {
                $ips[] = $ip;
            }
        }

        return $this->normalizeIps($ips);
    }

    protected function resolveWildcardCertificate(): mixed
    {
        if ($this->certificateResolver instanceof \Closure) {
            try {
                return ($this->certificateResolver)(self::WILDCARD_DOMAIN);
            } catch (\Throwable) {
                return null;
            }
        }

        try {
            return \w_query('server', 'resolveManagedCertificate', [
                'hostname' => self::WILDCARD_DOMAIN,
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    protected function loadPreparedCandidateDomains(int $limit): array
    {
        if ($this->candidateLoader instanceof \Closure) {
            try {
                $rows = ($this->candidateLoader)($limit);
            } catch (\Throwable) {
                return [];
            }
        } else {
            $pool = clone $this->domainPool;
            $rows = $pool->clearData()->clearQuery()
                ->where(DomainPool::schema_fields_STATUS, DomainPool::STATUS_ACTIVE)
                ->where(DomainPool::schema_fields_SITE_READY, 1)
                ->where(DomainPool::schema_fields_SITE_CREATED, 0)
                ->where(DomainPool::schema_fields_IS_LOCAL_SERVER, 1)
                ->order(DomainPool::schema_fields_DOMAIN, 'ASC')
                ->limit($limit)
                ->select()
                ->fetchArray();
        }
        if (!\is_array($rows)) {
            return [];
        }

        $domains = [];
        foreach ($rows as $row) {
            $candidate = \is_array($row)
                ? (string)($row[DomainPool::schema_fields_DOMAIN] ?? $row['domain'] ?? '')
                : (string)$row;
            $candidate = $this->normalizeDomain($candidate);
            if ($candidate !== '') {
                $domains[] = $candidate;
            }
        }

        return \array_slice(\array_values(\array_unique($domains)), 0, $limit);
    }

    /** @param list<mixed> $candidates @return list<string> */
    private function normalizeCandidates(array $candidates, int $limit): array
    {
        $domains = [];
        foreach ($candidates as $candidate) {
            if (\is_array($candidate)) {
                $candidate = $candidate['domain'] ?? '';
            }
            if (!\is_scalar($candidate)) {
                continue;
            }
            $domain = $this->normalizeDomain((string)$candidate);
            if ($domain === '') {
                continue;
            }
            $domains[] = $domain;
            if (\count($domains) >= $limit) {
                break;
            }
        }

        return \array_values(\array_unique($domains));
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = \strtolower(\trim($domain));

        return \preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.weline\.test$/D', $domain) === 1
            ? $domain
            : '';
    }

    /** @param list<mixed> $ips @return list<string> */
    private function normalizeIps(array $ips): array
    {
        $normalized = [];
        foreach ($ips as $ip) {
            $ip = \strtolower(\trim((string)$ip));
            if ($ip !== '' && \filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                $normalized[] = $ip;
            }
        }
        $normalized = \array_values(\array_unique($normalized));
        \sort($normalized, SORT_STRING);

        return $normalized;
    }

    private function isLoopbackIp(string $ip): bool
    {
        if (\filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return \str_starts_with($ip, '127.');
        }
        if ($ip === '::1') {
            return true;
        }
        if (\str_starts_with($ip, '::ffff:')) {
            $mapped = \substr($ip, 7);

            return \filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                && \str_starts_with($mapped, '127.');
        }

        return false;
    }

    private function isCertificateReady(mixed $certificate): bool
    {
        if (!\is_array($certificate)
            || (int)($certificate['cert_id'] ?? 0) <= 0
            || (string)($certificate['status'] ?? '') !== 'active'
            || (bool)($certificate['is_expired'] ?? true)
        ) {
            return false;
        }
        $expiresAt = \trim((string)($certificate['expires_at'] ?? ''));
        $expiresTimestamp = $expiresAt === '' ? false : \strtotime($expiresAt);

        return \is_int($expiresTimestamp) && $expiresTimestamp > \time();
    }

    private function hostsPreparationCommand(string $domain): string
    {
        return 'php bin/w server:hosts:add ' . \escapeshellarg($domain);
    }

    /** @param list<string> $resolvedIps @return array<string,mixed> */
    private function result(
        bool $canStart,
        string $code,
        string $message,
        string $domain,
        array $resolvedIps,
        bool $certificateReady,
        bool $requiresAdmin,
        string $preparationCommand
    ): array {
        return [
            'success' => true,
            'can_start' => $canStart,
            'code' => $code,
            'message' => $message,
            'domain' => $domain,
            'resolved_ips' => $resolvedIps,
            'certificate_ready' => $certificateReady,
            'requires_admin' => $requiresAdmin,
            'preparation_command' => $preparationCommand,
        ];
    }
}
