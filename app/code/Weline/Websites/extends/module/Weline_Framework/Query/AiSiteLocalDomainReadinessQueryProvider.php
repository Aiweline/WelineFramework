<?php

declare(strict_types=1);

namespace Weline\Websites\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Websites\Service\AiSiteLocalDomainReadinessService;

/**
 * Browser-facing local domain readiness gate for AI Site V2.
 *
 * inspect* remain read-only. prepare writes hosts/certificate after confirmed=true
 * (default auto path and manual assist click).
 */
class AiSiteLocalDomainReadinessQueryProvider implements QueryProviderInterface
{
    public function __construct(private readonly AiSiteLocalDomainReadinessService $readinessService)
    {
    }

    public function getProviderName(): string
    {
        return 'websites_ai_site_local_domain_readiness';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'inspect' => $this->readinessService->inspect((string)($params['domain'] ?? '')),
            'inspectCandidates' => $this->readinessService->inspectCandidates(
                $this->normalizeCandidates($params['candidates'] ?? $params['candidate_domains'] ?? []),
                (int)($params['limit'] ?? 50)
            ),
            'prepare' => $this->readinessService->prepare(
                (string)($params['domain'] ?? ''),
                $this->normalizeConfirmed($params['confirmed'] ?? false)
            ),
            default => throw new \InvalidArgumentException(
                (string)__('本地域名就绪查询器不支持的操作：%{1}', $operation)
            ),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => $this->getProviderName(),
            'name' => __('AI 建站本地域名就绪检查'),
            'description' => __('检查本地域名解析与通配证书；默认可自动写入 hosts/证书，失败后再人工协助'),
            'module' => 'Weline_Websites',
            'operations' => [[
                'name' => 'inspect',
                'description' => __('检查一个 *.weline.test 域名是否可以启动 AI 建站'),
                'frontend' => true,
                'mode' => 'read',
                'graph' => false,
                'cost' => 1,
                'auth' => 'backend',
                'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Websites::site_builder_agent'],
                'params' => [
                    ['name' => 'domain', 'type' => 'string', 'required' => true, 'max_length' => 76],
                ],
                'returns' => ['type' => 'array'],
            ], [
                'name' => 'inspectCandidates',
                'description' => __('合并已准备域名池和 AI 候选并逐个只读复核'),
                'frontend' => true,
                'mode' => 'read',
                'graph' => false,
                'cost' => 1,
                'auth' => 'backend',
                'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Websites::site_builder_agent'],
                'params' => [
                    ['name' => 'candidates', 'type' => 'array', 'required' => false],
                    ['name' => 'limit', 'type' => 'int', 'required' => false, 'min' => 1, 'max' => 50],
                ],
                'returns' => ['type' => 'array'],
            ], [
                'name' => 'prepare',
                'description' => __('确认后自动写入本机 hosts 与通配证书，并复检域名就绪状态'),
                'frontend' => true,
                'mode' => 'write',
                'graph' => false,
                'cost' => 2,
                'auth' => 'backend',
                'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Websites::site_builder_agent'],
                'params' => [
                    ['name' => 'domain', 'type' => 'string', 'required' => true, 'max_length' => 76],
                    ['name' => 'confirmed', 'type' => 'bool', 'required' => true],
                ],
                'returns' => ['type' => 'array'],
            ]],
        ];
    }

    /** @return list<mixed> */
    private function normalizeCandidates(mixed $candidates): array
    {
        if (\is_array($candidates)) {
            return \array_values($candidates);
        }
        if (\is_string($candidates) && \trim($candidates) !== '') {
            return [\trim($candidates)];
        }

        return [];
    }

    private function normalizeConfirmed(mixed $confirmed): bool
    {
        if (\is_bool($confirmed)) {
            return $confirmed;
        }
        if (\is_int($confirmed) || \is_float($confirmed)) {
            return (int)$confirmed === 1;
        }
        if (\is_string($confirmed)) {
            return \in_array(\strtolower(\trim($confirmed)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }
}
