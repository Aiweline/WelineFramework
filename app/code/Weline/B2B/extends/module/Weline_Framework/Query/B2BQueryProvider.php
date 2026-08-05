<?php

declare(strict_types=1);

namespace Weline\B2B\Extends\Module\Weline_Framework\Query;

use Weline\B2B\Service\B2BConflictException;
use Weline\B2B\Service\B2BQueryHarnessCatalog;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

/**
 * 前台 B2B 候选 Facade（TEST-P4C-01）。
 */
class B2BQueryProvider implements QueryProviderInterface
{
    public function getProviderName(): string
    {
        return 'b2b';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'resolve' => $this->resolve($params),
            default => throw new \InvalidArgumentException((string)__('B2B 接口不支持该操作：%{1}', $operation)),
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function resolve(array $params): array
    {
        try {
            $svc = B2BQueryHarnessCatalog::buildService();
            $request = [
                'customer_id' => (string)($params['customer_id'] ?? ''),
                'website_id' => (int)($params['website_id'] ?? 0),
                'sku' => (string)($params['sku'] ?? ''),
                'retail_amount_minor' => (int)($params['retail_amount_minor'] ?? 0),
            ];
            if (array_key_exists('channel_id', $params) && $params['channel_id'] !== null && $params['channel_id'] !== '') {
                $request['channel_id'] = (string)$params['channel_id'];
            }
            if (array_key_exists('claimed_price_list_id', $params)) {
                $request['claimed_price_list_id'] = (string)$params['claimed_price_list_id'];
            }
            if (array_key_exists('claimed_version', $params)) {
                $request['claimed_version'] = (int)$params['claimed_version'];
            }

            $result = $svc->resolve($request);

            return [
                'success' => (bool)($result['ok'] ?? false),
                'ok' => (bool)($result['ok'] ?? false),
                'source' => (string)($result['source'] ?? ''),
                'amount_minor' => (int)($result['amount_minor'] ?? 0),
                'price_list_id' => $result['price_list_id'] ?? null,
                'version' => $result['version'] ?? null,
                'group_id' => $result['group_id'] ?? null,
                'rule_stack' => $result['rule_stack'] ?? [],
                'error' => $result['error'] ?? null,
                'candidate' => $result,
            ];
        } catch (B2BConflictException $e) {
            return [
                'success' => false,
                'ok' => false,
                'error' => $e->errorCode,
                'message' => $e->getMessage(),
                'context' => $e->context,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'ok' => false,
                'error' => 'b2b_candidate_request_invalid',
                'message' => $e->getMessage(),
            ];
        }
    }

    public function getDescriptor(): array
    {
        return [
            'name' => $this->getProviderName(),
            'module' => 'Weline_B2B',
            'summary' => 'B2B price candidate resolve (group vs retail)',
            'operations' => [
                [
                    'name' => 'resolve',
                    'frontend' => true,
                    'auth' => 'any',
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 2,
                    'params' => [
                        'customer_id' => ['type' => 'string', 'required' => true, 'max_length' => 64],
                        'website_id' => ['type' => 'int', 'required' => true, 'min' => 0],
                        'sku' => ['type' => 'string', 'required' => true, 'max_length' => 128],
                        'retail_amount_minor' => ['type' => 'int', 'required' => true, 'min' => 0],
                        'channel_id' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'claimed_price_list_id' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'claimed_version' => ['type' => 'int', 'required' => false, 'min' => 0],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Resolve B2B price candidate for customer/sku',
                ],
            ],
        ];
    }
}
