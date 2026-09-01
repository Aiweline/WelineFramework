<?php

declare(strict_types=1);

namespace Weline\Order\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Order\Service\OrderCheckoutRemarkSession;

final class OrderStorefrontQueryProvider implements QueryProviderInterface
{
    public function __construct(private readonly OrderCheckoutRemarkSession $remarkSession)
    {
    }

    public function getProviderName(): string
    {
        return 'order';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        try {
            return match ($operation) {
                'getCheckoutRemark' => [
                    'success' => true,
                    'remark' => $this->remarkSession->getRemark(),
                ],
                'saveCheckoutRemark' => $this->remarkSession->saveRemark((string)($params['remark'] ?? '')),
                'clearCheckoutRemark' => $this->remarkSession->clearRemark(),
                default => throw new \InvalidArgumentException((string)__('订单接口不支持操作：%{1}', [$operation])),
            };
        } catch (\InvalidArgumentException $exception) {
            return ['success' => false, 'message' => $exception->getMessage()];
        } catch (\Throwable) {
            return ['success' => false, 'message' => (string)__('订单服务暂时不可用，请稍后再试。')];
        }
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'order',
            'name' => (string)__('订单前台'),
            'description' => (string)__('订单留言等前台会话能力。'),
            'module' => 'Weline_Order',
            'operations' => [
                [
                    'name' => 'getCheckoutRemark',
                    'frontend' => true,
                    'mode' => 'read',
                    'params' => [],
                ],
                [
                    'name' => 'saveCheckoutRemark',
                    'frontend' => true,
                    'mode' => 'write',
                    'params' => [
                        'remark' => ['type' => 'string', 'max_length' => 1000],
                    ],
                ],
                [
                    'name' => 'clearCheckoutRemark',
                    'frontend' => true,
                    'mode' => 'write',
                    'params' => [],
                ],
            ],
        ];
    }
}
