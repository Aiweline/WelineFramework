<?php
declare(strict_types=1);

/**
 * AI 建站工作台服务
 *
 * 根据描述执行一站式建站：购买域名、DNS 解析、HTTPS 申请、站点创建
 * 通过 onProgress 回调支持 SSE 实时推送进度
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\FrameworkQueryService;
use Weline\Server\Service\LocalDomainPolicy;
use Weline\Websites\Model\DomainRegistrarAccount;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Model\Website;
use Weline\Websites\Service\LocalWelineWildcardCertificateService;

class WebsiteAgentService
{
    private const DEV_SIM_DOMAIN = 'weline-dev.weline.test';
    private const RECOMMENDATION_MIN_LABEL_LENGTH = 10;

    public function __construct(
        private readonly DomainPurchaseService $purchaseService,
        private readonly DomainResolveService $resolveService,
        private readonly FrameworkQueryService $queryService,
        private readonly ?LocalWelineWildcardCertificateService $localWelineWildcardCertificateService = null,
        private readonly ?DomainPool $domainPoolModel = null,
        private readonly ?LocalWelineHostsSyncService $localWelineHostsSyncService = null,
    ) {
    }

    /**
     * 根据描述执行建站流程（购买→解析→HTTPS→站点）
     *
     * @param string $description 站点描述，用于站点名称
     * @param string $domain 要购买的域名（必填）
     * @param int $accountId 域名商账号 ID
     * @param callable|null $onProgress function(string $event, array $data): void
     * @param array<string, mixed> $itemExtras 合并进购买条目（如 user_client_ip、purchase_contact 片段）
     * @return array{success: bool, message: string, domain?: string, website_id?: int, order_id?: int}
     */
    public function buildFromDescription(
        string $description,
        string $domain,
        int $accountId,
        ?callable $onProgress = null,
        array $itemExtras = []
    ): array {
        $emit = function (string $event, array $data) use ($onProgress): void {
            if ($onProgress) {
                $onProgress($event, $data);
            }
        };

        $domain = \strtolower(\trim($domain));
        if ($domain === '') {
            return ['success' => false, 'message' => __('域名不能为空')];
        }
        $isLocalDomain = $this->isLocalDevelopmentDomain($domain);
        if ($accountId <= 0 && !$isLocalDomain) {
            return ['success' => false, 'message' => __('请选择域名商账号')];
        }

        $emit('start', ['message' => __('正在检查域名可用性...')]);

        if ($this->isDevSimulationDomain($domain) || $isLocalDomain) {
            $emit('progress', [
                'message' => __('开发环境模拟：域名 %{1} 视为可用', [$domain]),
                'progress' => 10,
                'simulated' => true,
            ]);
            $available = true;
        } else {
            // 1. 检查可用性
            $checkResult = $this->queryService->execute('websites', 'checkAvailability', [
                'account_id' => $accountId,
                'domains' => [$domain],
            ]);

            $available = false;
            if (\is_array($checkResult)) {
                foreach ($checkResult as $item) {
                    if (isset($item['domain']) && \strtolower((string) $item['domain']) === $domain && ($item['available'] ?? false)) {
                        $available = true;
                        break;
                    }
                }
            }
        }

        if (!$available) {
            $emit('error', ['message' => __('域名 %{1} 不可用，请更换后重试', [$domain])]);
            return ['success' => false, 'message' => __('域名不可用')];
        }
        $emit('progress', ['message' => __('域名 %{1} 可用', [$domain]), 'progress' => 10]);

        if ($isLocalDomain) {
            $localRecovery = $this->rememberManagedLocalDomain($domain, 0, 'build_from_description');
            $wildcardResult = \is_array($localRecovery['certificate'] ?? null) ? $localRecovery['certificate'] : [];
            $httpsOk = (bool)($wildcardResult['success'] ?? false);
            $wildcardDomain = (string)($wildcardResult['wildcard_domain'] ?? LocalDomainPolicy::currentWildcardDomain());
            $emit('progress', [
                'message' => $httpsOk
                    ? __('Local domain %{1} is now covered by the shared %{2} wildcard certificate', [$domain, $wildcardDomain])
                    : __('Local domain %{1} skipped registrar purchase, but the shared %{2} wildcard certificate is not ready yet', [$domain, $wildcardDomain]),
                'progress' => 90,
                'simulated' => true,
                'wildcard_domain' => $wildcardDomain,
                'https_ok' => $httpsOk,
            ]);
            $emit('done', [
                'message' => $httpsOk
                    ? __('Build finished with shared %{1} wildcard certificate', [$wildcardDomain])
                    : __('Build finished in local simulation mode'),
                'domain' => $domain,
                'website_id' => 0,
                'order_id' => 0,
                'https_ok' => $httpsOk,
                'simulated' => true,
                'wildcard_domain' => $wildcardDomain,
            ]);
            return [
                'success' => true,
                'message' => $httpsOk
                    ? __('Build finished with shared %{2} wildcard certificate: %{1}', [$domain, $wildcardDomain])
                    : __('Build finished in local simulation mode: %{1}', [$domain]),
                'domain' => $domain,
                'website_id' => 0,
                'order_id' => 0,
                'https_ok' => $httpsOk,
                'wildcard_domain' => $wildcardDomain,
            ];
        }
        $items = [
            \array_merge([
                'domain' => $domain,
                'years' => 1,
                'website_id' => 0,
                'auto_create_site' => 'yes',
                'resolve_to_local' => 'yes',
                'start_lifecycle' => '1',
                'subdomains' => ['@', 'www'],
            ], $itemExtras),
        ];
        $purchaseResult = $this->purchaseService->createAndProcessOrder($accountId, $items, true);
        if (!$purchaseResult['success']) {
            $emit('error', ['message' => $purchaseResult['message'] ?? __('购买失败')]);
            return ['success' => false, 'message' => $purchaseResult['message'] ?? __('购买失败')];
        }

        $orderId = $purchaseResult['order_id'] ?? 0;
        $results = $purchaseResult['results'] ?? [];
        $firstResult = $results[0] ?? [];
        if (!($firstResult['success'] ?? false)) {
            $msg = $firstResult['message'] ?? __('购买失败');
            $emit('error', ['message' => $msg]);
            return ['success' => false, 'message' => $msg];
        }

        $emit('progress', ['message' => __('域名购买成功'), 'progress' => 50]);

        // 3. 更新站点名称（若提供了描述）
        $websiteId = 0;
        $websiteModel = ObjectManager::getInstance(Website::class);
        $websiteModel->clearQuery()
            ->where(Website::schema_fields_NAME, $domain)
            ->find()
            ->fetch();
        if ($websiteModel->getWebsiteId()) {
            $websiteId = (int) $websiteModel->getWebsiteId();
            if (\trim($description) !== '') {
                $websiteModel->setData(Website::schema_fields_NAME, $description);
                $websiteModel->save();
                $emit('progress', ['message' => __('已根据描述设置站点名称'), 'progress' => 55]);
            }
        }

        // 4. 等待 DNS 解析生效后申请 HTTPS（异步解析由 Cron 处理，这里主动触发一次解析检查）
        $emit('progress', ['message' => __('正在检查 DNS 解析状态...'), 'progress' => 60]);
        $poolModel = ObjectManager::getInstance(DomainPool::class);
        $poolModel->loadByDomain($domain);
        $poolId = $poolModel->getPoolId() ?: 0;

        // 5. 申请 SSL 证书（DNS 可能尚未生效，失败时可稍后重试）
        $emit('progress', ['message' => __('正在申请 HTTPS 证书...'), 'progress' => 75]);
        $sslResult = $this->queryService->execute('server', 'requestCertificate', [
            'domain' => $domain,
            'website_id' => $websiteId,
            'pool_id' => $poolId,
        ]);

        if ($sslResult['success'] ?? false) {
            $emit('progress', ['message' => __('HTTPS 证书申请成功'), 'progress' => 95]);
        } else {
            $sslMsg = $sslResult['message'] ?? __('证书申请失败，请稍后在证书管理中手动申请');
            $emit('warning', ['message' => $sslMsg]);
        }

        $emit('done', [
            'message' => __('建站完成'),
            'domain' => $domain,
            'website_id' => $websiteId,
            'order_id' => $orderId,
            'https_ok' => $sslResult['success'] ?? false,
        ]);

        return [
            'success' => true,
            'message' => __('建站完成：%{1}', [$domain]),
            'domain' => $domain,
            'website_id' => $websiteId,
            'order_id' => $orderId,
        ];
    }

    /**
     * 从描述生成域名建议（简单规则，供前端展示）
     *
     * @param string $description 站点描述
     * @return array<string> 建议域名列表
     */
    public function recommendAvailableDomain(string $description, int $accountId, string $preferredDomain = '', bool $deferAvailabilityCheck = false): array
    {
        if ($deferAvailabilityCheck && $accountId > 0 && $this->isLocalTestRegistrarAccount($accountId)) {
            $fakeDomain = $this->buildLocalFlowDomainSuggestion($description, $preferredDomain);
            $this->rememberManagedLocalDomain($fakeDomain, 0, 'recommend_defer_local_account');
            return [
                'success' => true,
                'message' => (string)__('本地测试账号：已生成流程联调域名 %{domain}', ['domain' => $fakeDomain]),
                'domain' => $fakeDomain,
                'candidate_domains' => [$fakeDomain],
                'checked_results' => [],
                'simulated' => true,
                'availability_deferred' => true,
            ];
        }

        if ($deferAvailabilityCheck) {
            $candidates = $this->buildRecommendationCandidates($description, $preferredDomain);
            if ($candidates === []) {
                return [
                    'success' => false,
                    'message' => (string)__('请先描述建站目标，或先输入偏好域名。'),
                    'candidate_domains' => [],
                    'checked_results' => [],
                ];
            }

            $domain = (string)$candidates[0];

            return [
                'success' => true,
                'message' => (string)__('已生成域名建议；点击「确认并生成」时将检测可用性。'),
                'domain' => $domain,
                'candidate_domains' => $candidates,
                'checked_results' => [],
                'availability_deferred' => true,
            ];
        }

        if ($accountId <= 0) {
            return [
                'success' => false,
                'message' => (string)__('请先选择服务商账号。'),
                'candidate_domains' => [],
                'checked_results' => [],
            ];
        }

        if ($this->isLocalTestRegistrarAccount($accountId)) {
            $fakeDomain = $this->buildLocalFlowDomainSuggestion($description, $preferredDomain);
            $this->rememberManagedLocalDomain($fakeDomain, 0, 'recommend_local_account');
            return [
                'success' => true,
                'message' => (string)__('本地测试账号：已生成流程联调域名 %{domain}', ['domain' => $fakeDomain]),
                'domain' => $fakeDomain,
                'candidate_domains' => [$fakeDomain],
                'checked_results' => [
                    [
                        'domain' => $fakeDomain,
                        'available' => true,
                        'simulated' => true,
                    ],
                ],
                'simulated' => true,
            ];
        }

        $candidates = $this->buildRecommendationCandidates($description, $preferredDomain);
        if ($candidates === []) {
            return [
                'success' => false,
                'message' => (string)__('请先描述建站目标，或先输入偏好域名。'),
                'candidate_domains' => [],
                'checked_results' => [],
            ];
        }

        $availabilityResults = $this->queryService->execute('websites', 'checkAvailability', [
            'account_id' => $accountId,
            'domains' => $candidates,
        ]);

        $resultsByDomain = [];
        if (\is_array($availabilityResults)) {
            foreach ($availabilityResults as $result) {
                if (!\is_array($result)) {
                    continue;
                }

                $domain = $this->normalizeRecommendationCandidate((string)($result['domain'] ?? ''));
                if ($domain === '') {
                    continue;
                }

                $normalized = [
                    'domain' => $domain,
                    'available' => !empty($result['available']),
                ];
                $error = \trim((string)($result['error'] ?? ''));
                if ($error !== '') {
                    $normalized['error'] = $error;
                }

                $resultsByDomain[$domain] = $normalized;
            }
        }

        $checkedResults = [];
        $recommended = null;
        foreach ($candidates as $candidate) {
            $result = $resultsByDomain[$candidate] ?? [
                'domain' => $candidate,
                'available' => false,
            ];
            $checkedResults[] = $result;

            if ($recommended === null && !empty($result['available'])) {
                $recommended = $result;
            }
        }

        if ($recommended !== null) {
            return [
                'success' => true,
                'message' => (string)__('AI 找到可用域名：%{domain}', [
                    'domain' => (string)$recommended['domain'],
                ]),
                'domain' => (string)$recommended['domain'],
                'candidate_domains' => $candidates,
                'checked_results' => $checkedResults,
            ];
        }

        return [
            'success' => false,
            'message' => (string)__('未找到可用域名，请尝试更换简报或偏好域名。'),
            'candidate_domains' => $candidates,
            'checked_results' => $checkedResults,
        ];
    }

    /**
     * @return list<string>
     */
    public function getRecommendationCandidates(string $description, string $preferredDomain = '', int $limit = 60): array
    {
        $limit = $limit > 0 ? $limit : 60;
        return \array_slice($this->buildRecommendationCandidates($description, $preferredDomain, $limit), 0, $limit);
    }

    /**
     * @param list<string> $candidates
     * @return array<string, array{domain:string,available:bool,error?:string}>
     */
    public function checkCandidateAvailability(int $accountId, array $candidates): array
    {
        if ($accountId <= 0 || $candidates === []) {
            return [];
        }

        $availabilityResults = $this->queryService->execute('websites', 'checkAvailability', [
            'account_id' => $accountId,
            'domains' => \array_values($candidates),
        ]);

        $resultsByDomain = [];
        if (\is_array($availabilityResults)) {
            foreach ($availabilityResults as $result) {
                if (!\is_array($result)) {
                    continue;
                }
                $domain = $this->normalizeRecommendationCandidate((string)($result['domain'] ?? ''));
                if ($domain === '') {
                    continue;
                }
                $normalized = [
                    'domain' => $domain,
                    'available' => !empty($result['available']),
                ];
                $error = \trim((string)($result['error'] ?? ''));
                if ($error !== '') {
                    $normalized['error'] = $error;
                }
                $resultsByDomain[$domain] = $normalized;
            }
        }

        return $resultsByDomain;
    }

    public function suggestDomainsFromDescription(string $description): array
    {
        $tokens = $this->extractBrandTokens($description);
        if ($tokens === []) {
            $tokens = ['build'];
        }

        $bases = \array_values(\array_unique(\array_merge(
            $this->buildComplexBrandBases($tokens),
            $this->buildBrandBases($tokens)
        )));
        $tlds = ['.com', '.io', '.ai', '.co', '.net', '.site', '.cn'];
        $suggestions = [];

        // 先按后缀分组轮询，避免短热门词在前几位占满不同后缀。
        foreach ($tlds as $tld) {
            foreach ($bases as $base) {
                $suggestions[] = $base . $tld;
                if (\count($suggestions) >= 30) {
                    break 2;
                }
            }
        }

        return \array_values(\array_unique($suggestions));
    }

    private function buildRecommendationCandidates(string $description, string $preferredDomain = '', int $maxCandidates = 24): array
    {
        $maxCandidates = $maxCandidates > 0 ? $maxCandidates : 24;
        $candidates = [];
        $appendCandidate = function (string $candidate) use (&$candidates): void {
            $normalized = $this->normalizeRecommendationCandidate($candidate);
            if ($normalized === '' || \in_array($normalized, $candidates, true)) {
                return;
            }

            $candidates[] = $normalized;
        };

        $businessTokens = $this->extractBrandTokens($description);
        $preferredDomain = \strtolower(\trim($preferredDomain));
        if ($preferredDomain !== '') {
            $normalizedPreferred = $this->normalizeRecommendationCandidate($preferredDomain);
            if ($normalizedPreferred !== '') {
                $appendCandidate($normalizedPreferred);
                $base = (string)(\preg_replace('/\.[a-z]{2,}$/i', '', $normalizedPreferred) ?? '');
                if (!$this->isWeakGenericBrandToken($base)) {
                    foreach (['.com', '.io', '.ai', '.co', '.net', '.site', '.cn'] as $suffix) {
                        $appendCandidate($base . $suffix);
                    }
                }
                foreach ($this->expandPreferredBaseToLongTail($base, $businessTokens) as $expandedBase) {
                    foreach (['.com', '.io', '.ai', '.co', '.net', '.site', '.cn'] as $suffix) {
                        $appendCandidate($expandedBase . $suffix);
                    }
                }
            } elseif (\preg_match('/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/i', $preferredDomain)) {
                if (!$this->isWeakGenericBrandToken($preferredDomain)) {
                    foreach (['.com', '.io', '.ai', '.co', '.net', '.site', '.cn'] as $suffix) {
                        $appendCandidate($preferredDomain . $suffix);
                    }
                }
                foreach ($this->expandPreferredBaseToLongTail($preferredDomain, $businessTokens) as $expandedBase) {
                    foreach (['.com', '.io', '.ai', '.co', '.net', '.site', '.cn'] as $suffix) {
                        $appendCandidate($expandedBase . $suffix);
                    }
                }
            }
        }

        foreach ($this->suggestDomainsFromDescription($description) as $suggestion) {
            if (!\is_string($suggestion)) {
                continue;
            }

            $appendCandidate($suggestion);
        }

        return \array_slice($candidates, 0, $maxCandidates);
    }

    private function normalizeRecommendationCandidate(string $candidate): string
    {
        $candidate = \strtolower(\trim($candidate));
        if ($candidate === '') {
            return '';
        }

        return \preg_match('/^[a-z0-9][a-z0-9.-]+\.[a-z]{2,}$/', $candidate) ? $candidate : '';
    }

    /**
     * @return list<string>
     */
    private function extractBrandTokens(string $text): array
    {
        $localizedIntentTokens = $this->extractLocalizedIntentTokens($text);
        $normalized = \strtolower((string)\preg_replace('/[^a-z0-9]+/i', ' ', $text));
        $normalized = \trim((string)\preg_replace('/\s+/', ' ', $normalized));
        $locationTokens = $this->extractLocationTokens($text);
        if ($normalized === '') {
            return \array_values(\array_unique(\array_merge($locationTokens, $localizedIntentTokens)));
        }

        $stopWords = [
            'the', 'and', 'for', 'with', 'shop', 'store', 'site', 'website',
            'build', 'builder', 'online', 'platform', 'system', 'service',
            'solution', 'project', 'app', 'web',
        ];

        $tokens = [];
        foreach (\explode(' ', $normalized) as $part) {
            $part = \trim($part);
            if ($part === '' || \strlen($part) < 3 || \in_array($part, $stopWords, true)) {
                continue;
            }
            $tokens[] = $part;
            if (\count($tokens) >= 6) {
                break;
            }
        }

        return \array_values(\array_unique(\array_merge($locationTokens, $localizedIntentTokens, $tokens)));
    }

    /**
     * @param list<string> $tokens
     * @return list<string>
     */
    private function buildBrandBases(array $tokens): array
    {
        if ($tokens === []) {
            return ['build', 'sitehub', 'webcraft'];
        }

        $bases = [];
        $appendBase = static function (string $base) use (&$bases): void {
            $base = \strtolower(\trim((string)\preg_replace('/[^a-z0-9-]/i', '', $base)));
            if ($base === '' || \strlen($base) < 3 || \strlen($base) > 63 || \in_array($base, $bases, true)) {
                return;
            }
            $bases[] = $base;
        };

        foreach ($tokens as $token) {
            if (!$this->isWeakGenericBrandToken($token)) {
                $appendBase($token);
            }
        }

        $first = $tokens[0] ?? 'build';
        $second = $tokens[1] ?? '';
        $third = $tokens[2] ?? '';

        if ($second !== '') {
            $appendBase($first . $second);
            $appendBase($first . '-' . $second);
            $appendBase($second . $first);
        }
        if ($third !== '') {
            $appendBase($first . $third);
        }

        foreach (['hub', 'lab', 'go', 'now', 'cloud', 'app', 'pro'] as $suffix) {
            $appendBase($first . $suffix);
            if ($second !== '') {
                $appendBase($first . $second . $suffix);
            }
            if (\count($bases) >= 20) {
                break;
            }
        }

        if (\count($bases) < 10) {
            $hash = \substr(\md5(\implode('-', $tokens)), 0, 4);
            $appendBase($first . $hash);
            if ($second !== '') {
                $appendBase($first . $second . $hash);
            }
        }

        return \array_slice($bases, 0, 20);
    }

    /**
     * 组合更长、更少见的品牌词，优先 2-4 词组合并带可控短尾。
     *
     * @param list<string> $tokens
     * @return list<string>
     */
    private function buildComplexBrandBases(array $tokens): array
    {
        $strongTokens = [];
        foreach ($tokens as $token) {
            $token = (string)\preg_replace('/[^a-z0-9]/', '', \strtolower(\trim($token)));
            if ($token === '' || \strlen($token) < 3 || $this->isWeakGenericBrandToken($token)) {
                continue;
            }
            if (!\in_array($token, $strongTokens, true)) {
                $strongTokens[] = $token;
            }
            if (\count($strongTokens) >= 6) {
                break;
            }
        }

        if ($strongTokens === []) {
            return [];
        }

        $bases = [];
        $append = static function (string $base) use (&$bases): void {
            $base = (string)\preg_replace('/[^a-z0-9-]/', '', \strtolower(\trim($base)));
            if ($base === '' || \strlen($base) < self::RECOMMENDATION_MIN_LABEL_LENGTH || \strlen($base) > 63 || \in_array($base, $bases, true)) {
                return;
            }
            $bases[] = $base;
        };

        $first = $strongTokens[0] ?? '';
        $second = $strongTokens[1] ?? '';
        $third = $strongTokens[2] ?? '';

        if ($first !== '' && $second !== '') {
            $append($first . $second);
            $append($second . $first);
        }
        if ($first !== '' && $second !== '' && $third !== '') {
            $append($first . $second . $third);
            $append($second . $third . $first);
        }

        foreach (['hub', 'labs', 'works', 'studio', 'stack', 'global', 'online', 'world', 'zone'] as $suffix) {
            if ($first !== '' && $second !== '') {
                $append($first . $second . $suffix);
            }
            if ($first !== '' && $third !== '') {
                $append($first . $third . $suffix);
            }
        }

        $seed = \implode('-', $strongTokens);
        $hash = \substr(\md5($seed), 0, 4);
        if ($first !== '' && $second !== '') {
            $append($first . $second . $hash);
        } elseif ($first !== '') {
            $append($first . 'hub' . $hash);
        }

        return \array_slice($bases, 0, 30);
    }

    /**
     * 将偏好短词扩展为更可注册的长尾品牌词，避免 apk/seo 这类高占用短词直接撞库。
     *
     * @param list<string> $tokens
     * @return list<string>
     */
    private function expandPreferredBaseToLongTail(string $base, array $tokens): array
    {
        $base = \strtolower(\trim($base));
        $base = (string)\preg_replace('/[^a-z0-9-]/', '', $base);
        if ($base === '' || \strlen($base) < 3) {
            return [];
        }

        $normalizedTokens = [];
        foreach ($tokens as $token) {
            $token = (string)\preg_replace('/[^a-z0-9]/', '', \strtolower(\trim($token)));
            if ($token === '' || \strlen($token) < 3 || $this->isWeakGenericBrandToken($token)) {
                continue;
            }
            if (!\in_array($token, $normalizedTokens, true)) {
                $normalizedTokens[] = $token;
            }
        }

        $expansions = [];
        $appendExpansion = static function (string $value) use (&$expansions): void {
            $value = (string)\preg_replace('/[^a-z0-9-]/', '', \strtolower(\trim($value)));
            if ($value === '' || \strlen($value) < self::RECOMMENDATION_MIN_LABEL_LENGTH || \strlen($value) > 63 || \in_array($value, $expansions, true)) {
                return;
            }
            $expansions[] = $value;
        };

        foreach ($normalizedTokens as $token) {
            if ($token === $base) {
                continue;
            }
            $appendExpansion($token . $base);
            $appendExpansion($base . $token);
            $appendExpansion($token . $base . 'hub');
        }

        foreach (['hub', 'labs', 'works', 'studio', 'flow', 'stack', 'cloud', 'bridge', 'forge'] as $suffix) {
            $appendExpansion($base . $suffix);
        }

        $hash = \substr(\md5($base . '-' . \implode('-', $normalizedTokens)), 0, 3);
        $appendExpansion($base . $hash);
        if (($normalizedTokens[0] ?? '') !== '') {
            $appendExpansion($normalizedTokens[0] . $base . $hash);
        }

        return \array_slice($expansions, 0, 24);
    }

    private function isWeakGenericBrandToken(string $token): bool
    {
        return \in_array(\strtolower(\trim($token)), [
            'apk', 'seo', 'app', 'web', 'site', 'shop', 'store', 'ai',
            'build', 'online', 'digital', 'marketing', 'download',
            'india', 'ind', 'desi', 'bharat', 'hindi',
        ], true);
    }

    /**
     * 从中文/混合语言描述中提取业务与地域语义，避免仅依赖英文 token 导致推荐偏差。
     *
     * @return list<string>
     */
    private function extractLocalizedIntentTokens(string $text): array
    {
        $lowerText = \mb_strtolower($text);
        $tokens = [];
        $append = static function (string $token) use (&$tokens): void {
            $token = \strtolower(\trim($token));
            if ($token === '' || \strlen($token) < 2 || \in_array($token, $tokens, true)) {
                return;
            }
            $tokens[] = $token;
        };

        $keywordMap = [
            '印度' => ['india', 'ind', 'desi', 'bharat', 'hindi'],
            'india' => ['india', 'ind', 'desi', 'bharat', 'hindi'],
            'indian' => ['india', 'ind', 'desi', 'bharat', 'hindi'],
            '棋牌' => ['cardgame', 'boardgame', 'rummy', 'poker', 'teenpatti'],
            '棋牌室' => ['cardgame', 'rummy', 'teenpatti'],
            '扑克' => ['poker', 'cardgame'],
            '德州' => ['poker', 'texasholdem'],
            '拉米' => ['rummy'],
            'teen patti' => ['teenpatti', 'patti'],
            'teenpatti' => ['teenpatti', 'patti'],
            '下载' => ['download', 'getapp', 'install'],
            '推广' => ['promo', 'growth'],
        ];
        foreach ($keywordMap as $keyword => $mappedTokens) {
            if (\str_contains($lowerText, $keyword)) {
                foreach ($mappedTokens as $mappedToken) {
                    $append($mappedToken);
                }
            }
        }

        return $tokens;
    }

    private function isLocalTestRegistrarAccount(int $accountId): bool
    {
        if ($accountId >= 900000) {
            return true;
        }

        try {
            /** @var DomainRegistrarAccount $account */
            $account = ObjectManager::getInstance(DomainRegistrarAccount::class);
            $account->clearQuery();
            $account->load($accountId);
            if ($account->getAccountId() <= 0) {
                return false;
            }

            $registrarCode = \strtolower(\trim((string)$account->getRegistrarCode()));
            if (\in_array($registrarCode, ['local_demo', 'sandbox_demo', 'local', 'sandbox', 'mock'], true)) {
                return true;
            }

            $accountName = \mb_strtolower(\trim($account->getAccountName()));
            return \str_contains($accountName, '本地')
                || \str_contains($accountName, '测试')
                || \str_contains($accountName, 'demo')
                || \str_contains($accountName, 'sandbox')
                || \str_contains($accountName, 'local');
        } catch (\Throwable) {
            return false;
        }
    }

    private function buildLocalFlowDomainSuggestion(string $description, string $preferredDomain): string
    {
        $seed = \trim($preferredDomain) !== '' ? $preferredDomain : $description;
        $slug = \strtolower((string)\preg_replace('/[^a-z0-9]+/i', '-', $seed));
        $slug = \trim($slug, '-');
        if ($slug === '') {
            $slug = 'local-flow';
        }

        $parts = \array_values(\array_filter(\explode('-', $slug), static fn (string $part): bool => $part !== ''));
        $parts = \array_slice($parts, 0, 2);
        $base = \implode('-', $parts);
        if ($base === '' || \strlen($base) < 3) {
            $base = 'local-flow';
        }
        $suffix = \substr(\md5($base . '-' . \microtime(true)), 0, 6);

        return $base . '-' . $suffix . '.' . LocalDomainPolicy::TEST_ROOT_DOMAIN;
    }

    /**
     * 将本地托管域名写入域名池，并尽量恢复 hosts 与通配证书。
     *
     * @return array{
     *   success:bool,
     *   domain:string,
     *   pool_id:int,
     *   hosts?:array<string,mixed>,
     *   certificate?:array<string,mixed>
     * }
     */
    private function rememberManagedLocalDomain(string $domain, int $websiteId = 0, string $scene = ''): array
    {
        $domain = \strtolower(\trim($domain));
        if (!$this->isLocalDevelopmentDomain($domain)) {
            return [
                'success' => false,
                'domain' => $domain,
                'pool_id' => 0,
            ];
        }

        $result = [
            'success' => true,
            'domain' => $domain,
            'pool_id' => 0,
        ];

        try {
            $pool = $this->persistManagedLocalDomainIntoPool($domain);
            if ($pool instanceof DomainPool) {
                $result['pool_id'] = $pool->getPoolId();
            }
        } catch (\Throwable $throwable) {
            \w_log_warning(
                '[Websites\\WebsiteAgentService] persist local domain to pool failed: '
                . $domain . ' scene=' . $scene . ' err=' . $throwable->getMessage()
            );
        }

        try {
            $hostsService = $this->getLocalWelineHostsSyncService();
            if ($hostsService instanceof LocalWelineHostsSyncService) {
                $result['hosts'] = $hostsService->ensureHostsInjected($domain);
            }
        } catch (\Throwable $throwable) {
            \w_log_warning(
                '[Websites\\WebsiteAgentService] local hosts recovery failed: '
                . $domain . ' scene=' . $scene . ' err=' . $throwable->getMessage()
            );
        }

        try {
            $certificateResult = $this->getLocalWelineWildcardCertificateService()
                ->ensureWildcardCertificateForDomain($domain, \max(0, $websiteId));
            if (\is_array($certificateResult)) {
                $result['certificate'] = $certificateResult;
                $this->syncManagedLocalDomainCertificateStatus($domain, $certificateResult);
            }
        } catch (\Throwable $throwable) {
            \w_log_warning(
                '[Websites\\WebsiteAgentService] local wildcard certificate ensure failed: '
                . $domain . ' scene=' . $scene . ' err=' . $throwable->getMessage()
            );
        }

        return $result;
    }

    private function persistManagedLocalDomainIntoPool(string $domain): ?DomainPool
    {
        $poolModel = $this->getDomainPoolModel();
        if (!$poolModel instanceof DomainPool) {
            return null;
        }

        $pool = clone $poolModel;
        $pool->loadByDomain($domain);
        if ($pool->getPoolId() <= 0) {
            $pool->clearData()->clearQuery();
            $pool->setDomain($domain);
            $pool->setDescription((string)__('AI 本地推荐域名自动入池：%{domain}', ['domain' => $domain]));
        }

        $pool->setStatus(DomainPool::STATUS_ACTIVE);
        $pool->setResolveStatus(DomainPool::RESOLVE_STATUS_RESOLVED);
        $pool->setDnsStatus(DomainPool::INFRA_STATUS_READY);
        $pool->setCdnStatus(DomainPool::INFRA_STATUS_READY);
        $pool->setIsLocalServer(true);
        $pool->setResolveCheckedAt(\date('Y-m-d H:i:s'));
        $pool->setResolveError('');

        $httpsStatus = (string)$pool->getHttpsStatus();
        if ($httpsStatus === DomainPool::HTTPS_STATUS_VALID) {
            $pool->setPoolLifecycleStage(DomainPool::LIFECYCLE_CERT_VALID);
            $pool->calculateSiteReady();
        } else {
            $pool->setHttpsStatus(DomainPool::HTTPS_STATUS_PENDING);
            $pool->setPoolLifecycleStage(DomainPool::LIFECYCLE_CERT_PENDING);
            $pool->setSiteReady(false);
        }

        $pool->save();
        return $pool;
    }

    /**
     * @param array<string, mixed> $certificateResult
     */
    private function syncManagedLocalDomainCertificateStatus(string $domain, array $certificateResult): void
    {
        $poolModel = $this->getDomainPoolModel();
        if (!$poolModel instanceof DomainPool) {
            return;
        }

        $pool = clone $poolModel;
        $pool->loadByDomain($domain);
        if ($pool->getPoolId() <= 0) {
            return;
        }

        $ok = !empty($certificateResult['success']);
        $message = \trim((string)($certificateResult['message'] ?? ''));

        $pool->setStatus(DomainPool::STATUS_ACTIVE);
        $pool->setResolveStatus(DomainPool::RESOLVE_STATUS_RESOLVED);
        $pool->setDnsStatus(DomainPool::INFRA_STATUS_READY);
        $pool->setCdnStatus(DomainPool::INFRA_STATUS_READY);
        $pool->setIsLocalServer(true);
        $pool->setResolveCheckedAt(\date('Y-m-d H:i:s'));
        $pool->setResolveError('');

        if ($ok) {
            $pool->setHttpsStatus(DomainPool::HTTPS_STATUS_VALID);
            $pool->setHttpsError('');
            $pool->setPoolLifecycleStage(DomainPool::LIFECYCLE_CERT_VALID);
            $pool->calculateSiteReady();
        } else {
            $pool->setHttpsStatus(DomainPool::HTTPS_STATUS_PENDING);
            $pool->setHttpsError($message);
            $pool->setPoolLifecycleStage(DomainPool::LIFECYCLE_CERT_PENDING);
            $pool->setSiteReady(false);
        }

        $pool->save();
    }

    private function isDevSimulationDomain(string $domain): bool
    {
        return (\defined('DEV') && DEV)
            && \strtolower(\trim($domain)) === self::DEV_SIM_DOMAIN;
    }

    private function isLocalDevelopmentDomain(string $domain): bool
    {
        $domain = \strtolower(\trim($domain));
        if ($domain === 'localhost') {
            return true;
        }
        return LocalDomainPolicy::isManagedLocalDomain($domain);
    }

    private function getLocalWelineWildcardCertificateService(): LocalWelineWildcardCertificateService
    {
        return $this->localWelineWildcardCertificateService
            ?? ObjectManager::getInstance(LocalWelineWildcardCertificateService::class);
    }

    private function getDomainPoolModel(): ?DomainPool
    {
        if ($this->domainPoolModel instanceof DomainPool) {
            return $this->domainPoolModel;
        }

        try {
            return ObjectManager::getInstance(DomainPool::class);
        } catch (\Throwable) {
            return null;
        }
    }

    private function getLocalWelineHostsSyncService(): ?LocalWelineHostsSyncService
    {
        if ($this->localWelineHostsSyncService instanceof LocalWelineHostsSyncService) {
            return $this->localWelineHostsSyncService;
        }

        try {
            return ObjectManager::getInstance(LocalWelineHostsSyncService::class);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    private function extractLocationTokens(string $text): array
    {
        $tokens = [];
        $append = static function (string $token) use (&$tokens): void {
            $token = \strtolower(\trim($token));
            if ($token === '' || \strlen($token) < 2 || \in_array($token, $tokens, true)) {
                return;
            }
            $tokens[] = $token;
        };
        // 不维护城市白名单，直接从提示词语义连接词后提取地点/区域词组。
        if (\preg_match_all('/\b(?:in|at|from|for|to|near|within)\s+([a-z][a-z0-9\s-]{1,40})\b/i', $text, $matches) === 1) {
            foreach (($matches[1] ?? []) as $phrase) {
                if (!\is_string($phrase)) {
                    continue;
                }
                $raw = \strtolower(\trim($phrase));
                $raw = (string)\preg_replace('/\b(?:and|or|with|of|the)\b.*/i', '', $raw);
                $slug = (string)\preg_replace('/[^a-z0-9]+/', '', $raw);
                if ($slug === '' || \strlen($slug) < 2) {
                    continue;
                }
                $append($slug);
                $append('in' . $slug);
                $append($slug . 'in');
            }
        }

        // 支持 `xxx-in`、`in-xxx` 这类显式写法。
        if (\preg_match_all('/\b([a-z0-9]{2,20})-in\b/i', $text, $suffixMatches) === 1) {
            foreach (($suffixMatches[1] ?? []) as $word) {
                if (\is_string($word)) {
                    $append(\strtolower($word));
                    $append(\strtolower($word) . 'in');
                }
            }
        }
        if (\preg_match_all('/\bin-([a-z0-9]{2,20})\b/i', $text, $prefixMatches) === 1) {
            foreach (($prefixMatches[1] ?? []) as $word) {
                if (\is_string($word)) {
                    $append(\strtolower($word));
                    $append('in' . \strtolower($word));
                }
            }
        }

        return $tokens;
    }
}
