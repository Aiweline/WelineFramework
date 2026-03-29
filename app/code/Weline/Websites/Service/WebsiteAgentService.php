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
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Model\Website;

class WebsiteAgentService
{
    private const DEV_SIM_DOMAIN = 'weline-dev.local';

    public function __construct(
        private readonly DomainPurchaseService $purchaseService,
        private readonly DomainResolveService $resolveService,
        private readonly FrameworkQueryService $queryService
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
            $emit('progress', ['message' => __('本地域名 %{1} 跳过域名商流程，直接模拟建站成功', [$domain]), 'progress' => 90, 'simulated' => true]);
            $emit('done', [
                'message' => __('建站完成（本地模拟）'),
                'domain' => $domain,
                'website_id' => 0,
                'order_id' => 0,
                'https_ok' => false,
                'simulated' => true,
            ]);
            return [
                'success' => true,
                'message' => __('建站完成（本地模拟）：%{1}', [$domain]),
                'domain' => $domain,
                'website_id' => 0,
                'order_id' => 0,
            ];
        }

        // 2. 购买域名（含自动解析、自动建站）
        $emit('progress', ['message' => __('正在购买域名...'), 'progress' => 20]);
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
    public function recommendAvailableDomain(string $description, int $accountId, string $preferredDomain = ''): array
    {
        if ($accountId <= 0) {
            return [
                'success' => false,
                'message' => (string)__('请先选择服务商账号。'),
                'candidate_domains' => [],
                'checked_results' => [],
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

        $bases = $this->buildBrandBases($tokens);
        $tlds = ['.com', '.io', '.ai', '.co', '.net', '.site', '.cn'];
        $suggestions = [];

        foreach ($bases as $base) {
            foreach ($tlds as $tld) {
                $suggestions[] = $base . $tld;
                if (\count($suggestions) >= 30) {
                    break 2;
                }
            }
        }

        return \array_values(\array_unique($suggestions));
    }

    private function buildRecommendationCandidates(string $description, string $preferredDomain = '', int $maxCandidates = 12): array
    {
        $candidates = [];
        $appendCandidate = function (string $candidate) use (&$candidates): void {
            $normalized = $this->normalizeRecommendationCandidate($candidate);
            if ($normalized === '' || \in_array($normalized, $candidates, true)) {
                return;
            }

            $candidates[] = $normalized;
        };

        $preferredDomain = \strtolower(\trim($preferredDomain));
        if ($preferredDomain !== '') {
            $normalizedPreferred = $this->normalizeRecommendationCandidate($preferredDomain);
            if ($normalizedPreferred !== '') {
                $appendCandidate($normalizedPreferred);
                $base = (string)(\preg_replace('/\.[a-z]{2,}$/i', '', $normalizedPreferred) ?? '');
                foreach (['.com', '.io', '.ai', '.co', '.net', '.site', '.cn'] as $suffix) {
                    $appendCandidate($base . $suffix);
                }
            } elseif (\preg_match('/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/i', $preferredDomain)) {
                foreach (['.com', '.io', '.ai', '.co', '.net', '.site', '.cn'] as $suffix) {
                    $appendCandidate($preferredDomain . $suffix);
                }
            }
        }

        foreach ($this->suggestDomainsFromDescription($description) as $suggestion) {
            if (!\is_string($suggestion)) {
                continue;
            }

            $appendCandidate($suggestion);
        }

        return \array_slice($candidates, 0, $maxCandidates > 0 ? $maxCandidates : 12);
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
        $normalized = \strtolower((string)\preg_replace('/[^a-z0-9]+/i', ' ', $text));
        $normalized = \trim((string)\preg_replace('/\s+/', ' ', $normalized));
        $locationTokens = $this->extractLocationTokens($text);
        if ($normalized === '') {
            return $locationTokens;
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

        return \array_values(\array_unique(\array_merge($locationTokens, $tokens)));
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
            $appendBase($token);
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
        return \str_ends_with($domain, '.local') || \str_ends_with($domain, '.localhost');
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
