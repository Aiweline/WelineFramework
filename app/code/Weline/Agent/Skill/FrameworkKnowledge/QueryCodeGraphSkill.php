<?php
declare(strict_types=1);

namespace Weline\Agent\Skill\FrameworkKnowledge;

use Weline\Agent\Service\SkillResult;

/**
 * 代码 3D 图查询（类/方法/调用关系/模块），底层走 bin/w code3d:query --json
 */
class QueryCodeGraphSkill extends FrameworkKnowledgeSkill
{
    public function getCode(): string
    {
        return 'query_code_graph';
    }

    public function getDescription(): string
    {
        return '查询框架代码 3D 图：按类名/方法/模块查找类、方法、调用关系与模块统计（code3d:query）。';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['stats', 'find', 'class', 'method', 'callers', 'callees', 'modules', 'flow'],
                    'description' => '查询类型',
                ],
                'target' => ['type' => 'string', 'description' => '类名/FQCN/方法（Class::method）或模块名，按 action 需要'],
                'limit' => ['type' => 'integer', 'default' => 20],
            ],
            'required' => ['action'],
        ];
    }

    protected function run(array $params): SkillResult
    {
        $action = (string)($params['action'] ?? '');
        $target = trim((string)($params['target'] ?? ''));
        $limit = max(1, min(100, (int)($params['limit'] ?? 20)));

        $args = match ($action) {
            'stats' => ['--stats'],
            'find' => $target === '' ? null : ['--find=' . $target, '--limit=' . $limit],
            'class' => $target === '' ? null : ['--class=' . $target],
            'method' => $target === '' ? null : ['--method=' . $target],
            'callers' => $target === '' ? null : ['--callers=' . $target],
            'callees' => $target === '' ? null : ['--callees=' . $target],
            'modules' => ['--modules'],
            'flow' => ['--flow' . ($target !== '' ? '=' . $target : '')],
            default => null,
        };
        if ($args === null) {
            return SkillResult::error('Invalid action or missing target for: ' . $action);
        }

        return $this->runCode3dQuery($args);
    }
}
