<?php
declare(strict_types=1);

namespace Weline\Websites\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Websites\Extends\Module\Weline_Ai\Adapter\AiSiteDomainRecommendationAdapter;
use Weline\Websites\Service\AiSiteDomainPurchaseAccountService;

/**
 * Browser-safe AI domain recommendation boundary for PageBuilder V2.
 *
 * It exposes a credential-free purchase-account catalog and availability
 * checks. Paid purchase remains server-side in the Websites-owned Queue.
 */
class AiSiteDomainQueryProvider implements QueryProviderInterface
{
    private const LOCAL_ROOT_DOMAIN = 'weline.test';
    private const CANDIDATE_COUNT = 5;

    public function __construct(private readonly AiSiteDomainPurchaseAccountService $accountService)
    {
    }

    public function getProviderName(): string
    {
        return 'websites_ai_site_domain';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'recommendDomain' => $this->recommendDomain($params),
            'getPurchaseAccounts' => $this->getPurchaseAccounts(),
            'checkCandidateAvailability' => $this->checkCandidateAvailability($params),
            default => throw new \InvalidArgumentException(
                (string)__('AI 建站域名查询器不支持的操作：%{1}', $operation)
            ),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => $this->getProviderName(),
            'name' => __('AI 建站域名推荐'),
            'description' => __('提供域名推荐、脱敏购买账户和可用性检查；购买只由 Websites 队列执行'),
            'module' => 'Weline_Websites',
            'operations' => [[
                'name' => 'recommendDomain',
                'description' => __('通过已配置的 AI 场景根据建站描述推荐域名候选'),
                'frontend' => true,
                'mode' => 'read',
                'graph' => false,
                'cost' => 1,
                'auth' => 'backend',
                'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Websites::site_builder_agent'],
                'params' => [
                    ['name' => 'description', 'type' => 'string', 'required' => true, 'max_length' => 2000],
                    ['name' => 'preferred_domain', 'type' => 'string', 'required' => false, 'max_length' => 255],
                    ['name' => 'locale', 'type' => 'string', 'required' => false, 'max_length' => 32],
                    ['name' => 'domain_mode', 'type' => 'string', 'required' => true, 'enum' => ['test', 'purchase']],
                ],
                'returns' => ['type' => 'array'],
            ], [
                'name' => 'getPurchaseAccounts',
                'description' => __('获取可购买正式域名的脱敏注册商账户'),
                'frontend' => true,
                'mode' => 'read',
                'graph' => false,
                'cost' => 1,
                'auth' => 'backend',
                'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Websites::site_builder_agent'],
                'params' => [],
                'returns' => ['type' => 'array'],
            ], [
                'name' => 'checkCandidateAvailability',
                'description' => __('使用所选购买账户检查一个公开域名是否可购买'),
                'frontend' => true,
                'mode' => 'read',
                'graph' => false,
                'cost' => 2,
                'auth' => 'backend',
                'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Websites::site_builder_agent'],
                'params' => [
                    ['name' => 'account_id', 'type' => 'int', 'required' => true, 'min' => 1],
                    ['name' => 'domain', 'type' => 'string', 'required' => true, 'max_length' => 253],
                ],
                'returns' => ['type' => 'array'],
            ]],
        ];
    }

    /** @param array<string, mixed> $params */
    private function recommendDomain(array $params): array
    {
        $description = \trim((string)($params['description'] ?? ''));
        $preferredDomain = $this->normalizeDomain((string)($params['preferred_domain'] ?? ''));
        $locale = $this->normalizeLocale((string)($params['locale'] ?? 'zh_Hans_CN'));
        $domainMode = \strtolower(\trim((string)($params['domain_mode'] ?? 'test')));
        if (!\in_array($domainMode, ['test', 'purchase'], true)) {
            return [
                'success' => false,
                'code' => 'DOMAIN_MODE_UNSUPPORTED',
                'message' => (string)__('域名方式只能选择本地测试或正式购买。'),
                'candidate_domains' => [],
                'checked_results' => [],
            ];
        }
        if ($description === '' && $preferredDomain === '') {
            return [
                'success' => false,
                'code' => 'DOMAIN_BRIEF_REQUIRED',
                'message' => (string)__('请先描述建站目标，或先输入偏好域名。'),
                'candidate_domains' => [],
                'checked_results' => [],
            ];
        }

        $localRuntime = $this->isLocalRuntime();
        $fallbackCode = '';
        try {
            $labels = $this->generateSemanticLabels($description, $preferredDomain, $locale);
            if ($labels === []) {
                $fallbackCode = 'AI_RESPONSE_INVALID';
            }
        } catch (\Throwable $throwable) {
            $labels = [];
            $fallbackCode = $this->fallbackCode($throwable);
        }

        $fallbackUsed = $labels === [];
        if ($fallbackUsed) {
            $labels = $this->buildFallbackLabels($description, $preferredDomain);
        }

        $candidates = $domainMode === 'test'
            ? $this->buildManagedLocalCandidates($labels, $preferredDomain)
            : $this->buildPublicCandidates($labels, $preferredDomain);
        $domain = $candidates[0] ?? '';
        $source = $fallbackUsed ? 'fallback' : 'ai';

        return [
            'success' => true,
            'code' => $fallbackUsed ? 'OK_FALLBACK' : 'OK',
            'message' => $this->recommendationMessage($domain, $domainMode === 'test', $fallbackUsed),
            'domain' => $domain,
            'candidate_domains' => $candidates,
            'checked_results' => [],
            'availability_deferred' => true,
            'simulated' => $domainMode === 'test',
            'local_runtime' => $localRuntime,
            'domain_mode' => $domainMode,
            'recommendation_source' => $source,
            'fallback_used' => $fallbackUsed,
            'fallback_code' => $fallbackUsed ? ($fallbackCode ?: 'AI_UNAVAILABLE') : '',
            'ai_scenario' => AiSiteDomainRecommendationAdapter::SCENARIO_CODE,
            'side_effects' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function getPurchaseAccounts(): array
    {
        $accounts = $this->accountService->listSelectable();

        return [
            'success' => true,
            'code' => 'OK',
            'message' => $accounts === []
                ? (string)__('尚未配置可购买正式域名的注册商账户。')
                : (string)__('域名购买账户已加载。'),
            'accounts' => $accounts,
        ];
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function checkCandidateAvailability(array $params): array
    {
        $accountId = (int)($params['account_id'] ?? 0);
        $domain = $this->normalizeDomain((string)($params['domain'] ?? ''));
        if ($accountId <= 0 || $domain === '' || $this->isManagedLocalDomain($domain)) {
            return [
                'success' => false,
                'code' => 'DOMAIN_AVAILABILITY_INPUT_INVALID',
                'message' => (string)__('请选择域名购买账户并填写公开域名。'),
            ];
        }
        try {
            $availability = $this->accountService->checkAvailability($accountId, $domain);
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'code' => \method_exists($throwable, 'getErrorCode')
                    ? (string)$throwable->getErrorCode()
                    : 'DOMAIN_AVAILABILITY_CHECK_FAILED',
                'message' => $throwable->getMessage(),
            ];
        }

        return [
            'success' => true,
            'code' => 'OK',
            'message' => ($availability['available'] ?? false)
                ? (string)__('域名当前可以购买。')
                : (string)__('域名当前不可购买。'),
            'availability' => $availability,
        ];
    }

    /** @return list<string> */
    private function generateSemanticLabels(string $description, string $preferredDomain, string $locale): array
    {
        $readiness = $this->queryAi('inspectScenarioReadiness', [
            'scenario_code' => AiSiteDomainRecommendationAdapter::SCENARIO_CODE,
            'primary_modality' => 'text2text',
            'required_capabilities' => [],
        ]);
        if (!\is_array($readiness) || ($readiness['ready'] ?? false) !== true) {
            $code = \is_array($readiness) ? (string)($readiness['code'] ?? 'AI_SCENARIO_NOT_READY') : 'AI_SCENARIO_NOT_READY';
            throw new \RuntimeException($code !== '' ? $code : 'AI_SCENARIO_NOT_READY');
        }

        $response = $this->queryAi('generate', [
            'prompt' => $description !== '' ? $description : $preferredDomain,
            'scenario_code' => AiSiteDomainRecommendationAdapter::SCENARIO_CODE,
            'locale' => $locale,
            'params' => [
                'brief' => $description,
                'preferred_domain' => $preferredDomain,
                'locale' => $locale,
                // A connected account remains eligible when the local usage
                // ledger is only an estimate. The provider is still the final
                // authority and any real quota rejection falls back safely.
                'allow_zero_balance_provider' => true,
            ],
            'is_backend' => true,
        ]);

        return $this->labelsFromAiResponse($response);
    }

    /**
     * Cross-module AI reads deliberately use w_query. No provider, model,
     * account, endpoint, or credential is selected by Websites.
     */
    protected function queryAi(string $operation, array $params): mixed
    {
        return \w_query('ai', $operation, $params, 'backend');
    }

    protected function isLocalRuntime(): bool
    {
        $host = $this->normalizeHost((string)WelineEnv::server('HTTP_HOST', ''));
        if ($host === '') {
            $host = $this->normalizeHost((string)WelineEnv::get('server.http_host', ''));
        }

        $localHost = $host === 'localhost'
            || $host === '127.0.0.1'
            || $host === '::1'
            || $host === self::LOCAL_ROOT_DOMAIN
            || \str_ends_with($host, '.' . self::LOCAL_ROOT_DOMAIN)
            || $host === 'weline.localhost'
            || \str_ends_with($host, '.weline.localhost');

        return $localHost || (\defined('DEV') && DEV);
    }

    /** @return list<string> */
    private function labelsFromAiResponse(mixed $response): array
    {
        if (\is_array($response)) {
            $decoded = $response;
        } else {
            $content = \trim((string)$response);
            if (\preg_match('/```(?:json)?\s*(\{[\s\S]*\})\s*```/i', $content, $matches) === 1) {
                $content = \trim((string)($matches[1] ?? ''));
            } elseif (\preg_match('/(\{[\s\S]*\})/m', $content, $matches) === 1) {
                $content = \trim((string)($matches[1] ?? ''));
            }
            $decoded = \json_decode($content, true);
        }
        if (!\is_array($decoded) || !\is_array($decoded['labels'] ?? null)) {
            return [];
        }

        $labels = [];
        foreach ($decoded['labels'] as $candidate) {
            $label = $this->normalizeAiLabel((string)$candidate);
            if ($label !== '' && !\in_array($label, $labels, true)) {
                $labels[] = $label;
            }
            if (\count($labels) >= self::CANDIDATE_COUNT) {
                break;
            }
        }

        return $labels;
    }

    /** @return list<string> */
    private function buildFallbackLabels(string $description, string $preferredDomain): array
    {
        $preferredLabel = $this->labelFromDomain($preferredDomain);
        $seedLabel = $this->normalizeLabel($description);
        $base = $preferredLabel !== '' ? $preferredLabel : $seedLabel;
        if ($base === '') {
            $base = 'ai-site-' . \substr(\hash('sha256', $description . '|' . $preferredDomain), 0, 7);
        }

        $variants = [$base, $base . '-web', $base . '-studio', $base . '-online', $base . '-home'];
        $labels = [];
        foreach ($variants as $variant) {
            $label = $this->normalizeLabel($variant);
            if ($label !== '' && !\in_array($label, $labels, true)) {
                $labels[] = $label;
            }
        }

        return \array_slice($labels, 0, self::CANDIDATE_COUNT);
    }

    /** @param list<string> $labels @return list<string> */
    private function buildManagedLocalCandidates(array $labels, string $preferredDomain): array
    {
        $preferredLabel = $this->labelFromDomain($preferredDomain);
        if ($preferredLabel !== '') {
            \array_unshift($labels, $preferredLabel);
        }

        $candidates = [];
        foreach ($labels as $label) {
            $label = $this->normalizeLabel($label);
            if ($label === '') {
                continue;
            }
            $candidates[] = $label . '.' . self::LOCAL_ROOT_DOMAIN;
        }

        return \array_slice(\array_values(\array_unique($candidates)), 0, self::CANDIDATE_COUNT);
    }

    /** @param list<string> $labels @return list<string> */
    private function buildPublicCandidates(array $labels, string $preferredDomain): array
    {
        $candidates = [];
        if ($preferredDomain !== '' && !$this->isManagedLocalDomain($preferredDomain)) {
            $candidates[] = $preferredDomain;
        }

        $suffixes = ['.com', '.io', '.ai', '.co', '.site'];
        foreach ($labels as $index => $label) {
            $label = $this->normalizeLabel($label);
            if ($label === '') {
                continue;
            }
            $candidates[] = $label . $suffixes[$index % \count($suffixes)];
        }

        return \array_slice(\array_values(\array_unique($candidates)), 0, self::CANDIDATE_COUNT);
    }

    private function recommendationMessage(string $domain, bool $localRuntime, bool $fallbackUsed): string
    {
        if ($fallbackUsed) {
            return $localRuntime
                ? (string)__('AI 域名场景暂不可用，已使用明确标记的备用方案生成本地候选 %{domain}；不会购买真实域名。', ['domain' => $domain])
                : (string)__('AI 域名场景暂不可用，已使用明确标记的备用候选；不会检查可用性或购买域名。');
        }

        return $localRuntime
            ? (string)__('AI 已推荐本地联调域名 %{domain}；不会购买真实域名。', ['domain' => $domain])
            : (string)__('AI 已生成域名建议；不会检查可用性或购买域名。');
    }

    private function fallbackCode(\Throwable $throwable): string
    {
        $candidate = \strtoupper(\trim($throwable->getMessage()));
        if (\preg_match('/^[A-Z][A-Z0-9_]{2,63}$/D', $candidate) === 1) {
            return $candidate;
        }

        return 'AI_REQUEST_FAILED';
    }

    private function normalizeLocale(string $locale): string
    {
        $locale = \trim($locale);
        return \preg_match('/^[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,8}){0,2}$/D', $locale) === 1
            ? $locale
            : 'zh_Hans_CN';
    }

    private function normalizeHost(string $value): string
    {
        $value = \trim(\strtolower($value));
        if ($value === '') {
            return '';
        }
        $parsed = \parse_url(\str_contains($value, '://') ? $value : 'http://' . $value, PHP_URL_HOST);
        return \is_string($parsed) ? \trim($parsed, '.[]') : '';
    }

    private function normalizeDomain(string $value): string
    {
        $value = \trim(\strtolower($value));
        if ($value === '') {
            return '';
        }
        $parsed = \parse_url(\str_contains($value, '://') ? $value : 'http://' . $value, PHP_URL_HOST);
        $domain = \is_string($parsed) ? \trim($parsed, '.[]') : '';
        if ($domain === '' || \strlen($domain) > 253) {
            return '';
        }
        if ($domain === 'localhost' || \filter_var($domain, FILTER_VALIDATE_IP) !== false) {
            return '';
        }
        if (\preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $domain) !== 1) {
            return '';
        }

        return $domain;
    }

    private function labelFromDomain(string $domain): string
    {
        if ($domain === '') {
            return '';
        }

        return $this->normalizeLabel((string)(\explode('.', $domain, 2)[0] ?? ''));
    }

    private function normalizeLabel(string $value): string
    {
        $value = \strtolower(\trim($value));
        $value = (string)\preg_replace('/[^a-z0-9]+/', '-', $value);
        $value = \trim((string)\preg_replace('/-+/', '-', $value), '-');
        $value = \substr($value, 0, 54);
        $value = \rtrim($value, '-');
        if (\strlen($value) < 3 || \preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $value) !== 1) {
            return '';
        }

        return $value;
    }

    private function normalizeAiLabel(string $value): string
    {
        $value = \strtolower(\trim($value));
        if (\strlen($value) < 3 || \strlen($value) > 54) {
            return '';
        }
        if (\preg_match('/^[a-z0-9](?:[a-z0-9]|-(?=[a-z0-9]))*[a-z0-9]$/D', $value) !== 1) {
            return '';
        }

        return $value;
    }

    private function isManagedLocalDomain(string $domain): bool
    {
        return \preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.weline\.test$/D', $domain) === 1;
    }
}
