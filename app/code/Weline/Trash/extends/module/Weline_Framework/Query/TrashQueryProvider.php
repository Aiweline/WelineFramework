<?php
declare(strict_types=1);

namespace Weline\Trash\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Trash\Service\TrashService;

class TrashQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly TrashService $trashService
    ) {
    }

    public function getProviderName(): string
    {
        return 'trash';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'delete' => $this->trashService->delete(
                (string)($params['code'] ?? $params['trash_code'] ?? ''),
                $this->arrayParam($params, 'data'),
                $this->arrayParam($params, 'context')
            ),
            'restore' => $this->trashService->restore(
                (int)($params['trash_id'] ?? $params['id'] ?? 0),
                $this->arrayParam($params, 'context')
            ),
            'purge' => $this->trashService->purge(
                (int)($params['trash_id'] ?? $params['id'] ?? 0),
                $this->arrayParam($params, 'context')
            ),
            'getItem' => $this->trashService->getItem((int)($params['trash_id'] ?? $params['id'] ?? 0)),
            'listItems' => $this->trashService->listItems($params),
            'listTypes' => $this->trashService->listTypes(),
            default => throw new \InvalidArgumentException(
                (string)__('Trash 查询器不支持的操作：%{1}', $operation)
            ),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => $this->getProviderName(),
            'name' => '回收站查询',
            'description' => '提供业务数据入箱、恢复、永久清理、列表与原始数据查看能力',
            'module' => 'Weline_Trash',
            'operations' => [
                [
                    'name' => 'delete',
                    'description' => '调用指定 TrashProvider 执行业务删除并写入回收站',
                    'params' => [
                        ['name' => 'code', 'type' => 'string', 'required' => true, 'description' => 'Provider code'],
                        ['name' => 'data', 'type' => 'array', 'required' => true, 'description' => '业务删除数据，由 provider 解释'],
                        ['name' => 'context', 'type' => 'array', 'required' => false, 'description' => '操作者、来源等上下文'],
                    ],
                ],
                [
                    'name' => 'restore',
                    'description' => '按回收站记录恢复业务数据',
                    'params' => [
                        ['name' => 'trash_id', 'type' => 'int', 'required' => true, 'description' => '回收站ID'],
                        ['name' => 'context', 'type' => 'array', 'required' => false, 'description' => '恢复上下文'],
                    ],
                ],
                [
                    'name' => 'purge',
                    'description' => '按回收站记录永久清理业务数据',
                    'params' => [
                        ['name' => 'trash_id', 'type' => 'int', 'required' => true, 'description' => '回收站ID'],
                        ['name' => 'context', 'type' => 'array', 'required' => false, 'description' => '清理上下文'],
                    ],
                ],
                [
                    'name' => 'getItem',
                    'description' => '读取单个回收站记录，包含原始 JSON 数据',
                    'params' => [
                        ['name' => 'trash_id', 'type' => 'int', 'required' => true, 'description' => '回收站ID'],
                    ],
                ],
                [
                    'name' => 'listItems',
                    'description' => '分页读取回收站记录',
                    'params' => [
                        ['name' => 'code', 'type' => 'string', 'required' => false, 'description' => 'Provider code'],
                        ['name' => 'status', 'type' => 'string', 'required' => false, 'description' => 'open/all/active/restored/purged/restore_failed'],
                        ['name' => 'search', 'type' => 'string', 'required' => false, 'description' => '搜索标题、实体键、code'],
                        ['name' => 'page', 'type' => 'int', 'required' => false, 'description' => '页码'],
                        ['name' => 'page_size', 'type' => 'int', 'required' => false, 'description' => '每页数量'],
                    ],
                ],
                [
                    'name' => 'listTypes',
                    'description' => '列出已注册的回收站 provider 类型',
                    'params' => [],
                ],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function arrayParam(array $params, string $key): array
    {
        $value = $params[$key] ?? [];
        return is_array($value) ? $value : [];
    }
}
