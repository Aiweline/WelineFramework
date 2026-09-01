<?php

declare(strict_types=1);

namespace Weline\Promotion\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Promotion\Model\PromotionCampaignRun;
use Weline\Promotion\Service\PromotionDeskService;

final class PromotionAdminQueryProvider implements QueryProviderInterface
{
    public const ACL_SOURCE = 'Weline_Promotion::commerce:promotion:desk';

    public function __construct(
        private readonly PromotionDeskService $deskService,
    ) {
    }

    public function getProviderName(): string
    {
        return 'promotion_admin';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        try {
            return match ($operation) {
                'deskSnapshot' => $this->deskService->buildDeskView(),
                'listRuns' => $this->deskService->listCampaignRuns(),
                'saveRun' => $this->deskService->saveCampaignRun($params),
                default => throw new \InvalidArgumentException(
                    (string)__('促销后台接口不支持操作：%{1}', [$operation]),
                ),
            };
        } catch (\InvalidArgumentException $exception) {
            return ['success' => false, 'message' => $exception->getMessage()];
        } catch (\Throwable) {
            return ['success' => false, 'message' => (string)__('促销后台服务暂时不可用。')];
        }
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'promotion_admin',
            'name' => (string)__('万能促销运营'),
            'description' => (string)__('读取促销运营台快照与 campaign run 状态。'),
            'module' => 'Weline_Promotion',
            'operations' => [
                [
                    'name' => 'deskSnapshot',
                    'frontend' => true,
                    'backend' => true,
                    'mode' => 'read',
                    'auth' => 'backend',
                    'backend_acl' => ['kind' => 'source', 'source_id' => self::ACL_SOURCE],
                ],
                [
                    'name' => 'listRuns',
                    'frontend' => true,
                    'backend' => true,
                    'mode' => 'read',
                    'auth' => 'backend',
                    'backend_acl' => ['kind' => 'source', 'source_id' => self::ACL_SOURCE],
                ],
                [
                    'name' => 'saveRun',
                    'frontend' => true,
                    'backend' => true,
                    'mode' => 'write',
                    'auth' => 'backend',
                    'backend_acl' => ['kind' => 'source', 'source_id' => self::ACL_SOURCE],
                    'params' => [
                        ['name' => 'campaign_key', 'type' => 'string', 'required' => true],
                        ['name' => 'status', 'type' => 'string', 'required' => true],
                        ['name' => 'operator_id', 'type' => 'int', 'required' => false],
                        ['name' => 'handoff', 'type' => 'mixed', 'required' => false],
                    ],
                ],
            ],
        ];
    }
}
