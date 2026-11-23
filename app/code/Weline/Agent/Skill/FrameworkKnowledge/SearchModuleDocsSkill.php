<?php
declare(strict_types=1);

namespace Weline\Agent\Skill\FrameworkKnowledge;

use Weline\Agent\Service\SkillResult;

/**
 * 模块文档全文搜索（各模块 doc 目录下 Markdown）
 */
class SearchModuleDocsSkill extends FrameworkKnowledgeSkill
{
    public function getCode(): string
    {
        return 'search_module_docs';
    }

    public function getDescription(): string
    {
        return '在框架各模块 doc/ 目录的 Markdown 文档中按关键词全文搜索，返回路径与上下文片段。';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'keyword' => ['type' => 'string', 'description' => '搜索关键词'],
                'limit' => ['type' => 'integer', 'default' => 20, 'description' => '最大结果数'],
            ],
            'required' => ['keyword'],
        ];
    }

    protected function run(array $params): SkillResult
    {
        $keyword = trim((string)($params['keyword'] ?? ''));
        $limit = max(1, min(50, (int)($params['limit'] ?? 20)));
        if ($keyword === '') {
            return SkillResult::error('keyword is required');
        }
        $hits = $this->grepDocs($keyword, $limit);
        return SkillResult::success([
            'keyword' => $keyword,
            'count' => count($hits),
            'results' => $hits,
        ]);
    }
}
